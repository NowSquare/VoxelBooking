<?php

declare(strict_types=1);
namespace Tests\Integration;

use App\Agent\{ActorContext,OperationException};
use App\Application\Media\{MediaService,UploadJournal};
use App\Engine\{Database,ImageUpload,Ulid};
use PHPUnit\Framework\TestCase;

final class MediaUploadRecoveryTest extends TestCase
{
    private array $files=[];
    private ActorContext $actor;
    protected function setUp():void
    {
        TestFixtures::provision();
        $this->actor=new ActorContext('business_user',TestFixtures::BUSINESS_USER_ID,'business_owner',
            [TestFixtures::BUSINESS_TENANT_ID],['media:manage'],authMode:'session');
    }
    protected function tearDown():void
    {
        foreach($this->files as $path)ImageUpload::delete($path);
    }
    private function staged():array
    {
        $grant=MediaService::prepare($this->actor,TestFixtures::BUSINESS_TENANT_ID,'service');
        $slug=Database::query('SELECT slug FROM tenants WHERE id=?',[TestFixtures::BUSINESS_TENANT_ID])[0]['slug'];
        $path='uploads/'.$slug.'/cover/'.Ulid::generate().'.png';
        $this->files[]=$path;
        $journal=UploadJournal::begin(TestFixtures::BUSINESS_TENANT_ID,$grant['media_id'],$path);
        $absolute=dirname(__DIR__,2).'/public/'.$path;
        if(!is_dir(dirname($absolute)))mkdir(dirname($absolute),0755,true);
        file_put_contents($absolute,'synthetic incomplete image');
        return [$grant,$path,$journal,$absolute];
    }
    public function testPrepareRequiresMediaScopeEvenForCurrentOwner():void
    {
        $actor=new ActorContext('business_user',TestFixtures::BUSINESS_USER_ID,'business_owner',
            [TestFixtures::BUSINESS_TENANT_ID],['bookings:read'],authMode:'session');
        $this->expectException(OperationException::class);
        MediaService::prepare($actor,TestFixtures::BUSINESS_TENANT_ID,'logo');
    }
    public function testRolledBackUploadIsReconciledWithoutAnOrphan():void
    {
        [$grant,$path,$journal,$absolute]=$this->staged();
        try {
            Database::transaction(function()use($grant,$path):void{
                Database::execute('UPDATE agent_media_grants SET path=? WHERE id=?',[$path,$grant['media_id']]);
                throw new \RuntimeException('Simulated transaction failure before commit');
            });
        }catch(\RuntimeException){}
        $journal->release();
        $result=UploadJournal::reconcile(0);
        self::assertGreaterThanOrEqual(1,$result['removed']);
        self::assertFileDoesNotExist($absolute);
        self::assertNull(Database::query('SELECT path FROM agent_media_grants WHERE id=?',[$grant['media_id']])[0]['path']);
    }
    public function testLostCommitAcknowledgmentPreservesCommittedAndAttachedFiles():void
    {
        [$grant,$path,$journal,$absolute]=$this->staged();
        Database::transaction(fn()=>Database::execute('UPDATE agent_media_grants SET path=? WHERE id=?',[$path,$grant['media_id']]));
        $journal->release(); // Process lost acknowledgment and did not clear journal.
        self::assertGreaterThanOrEqual(1,UploadJournal::reconcile(0)['preserved']);
        self::assertFileExists($absolute);
        [$grant,$path,$journal,$absolute]=$this->staged();
        $service=Ulid::generate();
        Database::execute('INSERT INTO services(id,tenant_id,name,duration_minutes,cover_image_path) VALUES(?,?,?,30,?)',
            [$service,TestFixtures::BUSINESS_TENANT_ID,'Journal reference',$path]);
        Database::execute('DELETE FROM agent_media_grants WHERE id=?',[$grant['media_id']]);
        $journal->release();
        self::assertGreaterThanOrEqual(1,UploadJournal::reconcile(0)['preserved']);
        self::assertFileExists($absolute);
        Database::execute('DELETE FROM services WHERE id=?',[$service]);
    }
    public function testUnavailableDatabaseRetainsJournalAndFileUntilRetry():void
    {
        [$grant,$path,$journal,$absolute]=$this->staged();
        $journal->release();
        Database::execute('RENAME TABLE agent_media_grants TO vb_test_unavailable_media_grants');
        try {
            self::assertGreaterThanOrEqual(1,UploadJournal::reconcile(0)['deferred']);
            self::assertFileExists($absolute);
        }finally{
            Database::execute('RENAME TABLE vb_test_unavailable_media_grants TO agent_media_grants');
        }
        self::assertGreaterThanOrEqual(1,UploadJournal::reconcile(0)['removed']);
        self::assertFileDoesNotExist($absolute);
    }
    public function testActiveWriterIsNotCleaned():void
    {
        [$grant,$path,$journal,$absolute]=$this->staged();
        self::assertSame(0,UploadJournal::reconcile(0)['removed']);
        self::assertFileExists($absolute);
        $journal->release(); // Kernel releases the same flock when a writer exits.
        self::assertGreaterThanOrEqual(1,UploadJournal::reconcile(0)['removed']);
        self::assertFileDoesNotExist($absolute);
    }
    public function testProcessExitAfterFileMoveLeavesRecoverableIntent():void
    {
        if(!function_exists('pcntl_fork'))self::markTestSkipped('pcntl required for process-exit evidence.');
        $grant=MediaService::prepare($this->actor,TestFixtures::BUSINESS_TENANT_ID,'service');
        $slug=Database::query('SELECT slug FROM tenants WHERE id=?',[TestFixtures::BUSINESS_TENANT_ID])[0]['slug'];
        $path='uploads/'.$slug.'/cover/'.Ulid::generate().'.png';$this->files[]=$path;
        $absolute=dirname(__DIR__,2).'/public/'.$path;
        Database::reset();
        $pid=pcntl_fork();
        if($pid===0){
            $journal=UploadJournal::begin(TestFixtures::BUSINESS_TENANT_ID,$grant['media_id'],$path);
            if(!is_dir(dirname($absolute)))mkdir(dirname($absolute),0755,true);
            file_put_contents($absolute,'synthetic image moved before database update');
            exit(77); // No finish or DB path publication.
        }
        self::assertGreaterThan(0,$pid);pcntl_waitpid($pid,$status);
        self::assertSame(77,pcntl_wexitstatus($status));self::assertFileExists($absolute);
        self::assertGreaterThanOrEqual(1,UploadJournal::reconcile(0)['removed']);
        self::assertFileDoesNotExist($absolute);
    }
    public function testActualMultipartUploadAndIdenticalRetryClearCommittedJournal():void
    {
        $grant=MediaService::prepare($this->actor,TestFixtures::BUSINESS_TENANT_ID,'service');
        $temp=tempnam(sys_get_temp_dir(),'vb-image-');
        $image=imagecreatetruecolor(3,3);imagepng($image,$temp);
        try {
            for($attempt=0;$attempt<2;$attempt++){
                $curl=curl_init($grant['upload_url']);
                curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>10,
                    CURLOPT_HTTPHEADER=>['X-Upload-Token: '.$grant['upload_token']],
                    CURLOPT_POSTFIELDS=>['image'=>new \CURLFile($temp,'image/png','synthetic.png')]]);
                $body=curl_exec($curl);
                self::assertSame(200,curl_getinfo($curl,CURLINFO_HTTP_CODE),(string)$body);
                self::assertSame('ready',json_decode($body,true,flags:JSON_THROW_ON_ERROR)['state']);
            }
            $path=Database::query('SELECT path FROM agent_media_grants WHERE id=?',[$grant['media_id']])[0]['path'];
            $this->files[]=$path;
            self::assertFileExists(dirname(__DIR__,2).'/public/'.$path);
            self::assertSame(0,UploadJournal::reconcile(0)['removed']);
        }finally{@unlink($temp);}
    }
}

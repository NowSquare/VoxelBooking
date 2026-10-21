<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\{GitUpdater,Locale};
use PHPUnit\Framework\TestCase;

/** Local repositories only: never fetches a real remote or modifies a user checkout. */
final class GitUpdaterTest extends TestCase
{
    private string $directory;
    private string $source;
    private string $checkout;

    protected function setUp():void
    {
        Locale::init(dirname(__DIR__,3));Locale::setLocale('en');
        $this->directory=sys_get_temp_dir().'/vb-updater-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
        $this->source=$this->directory.'/source';$this->checkout=$this->directory.'/checkout';
        $this->git(['init','--bare','--initial-branch=main',$this->directory.'/remote.git']);
        $this->git(['init','--initial-branch=main',$this->source]);
        $this->commit('1');
        $this->git(['-C',$this->source,'remote','add','origin',$this->directory.'/remote.git']);
        $this->git(['-C',$this->source,'push','-u','origin','main']);
        $this->git(['clone',$this->directory.'/remote.git',$this->checkout]);
    }
    protected function tearDown():void
    {
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}
        rmdir($this->directory);
    }
    private function git(array $arguments):string
    {
        $process=proc_open(['git','-c','user.name=Fixture','-c','user.email=fixture@example.test','-c','commit.gpgsign=false',...$arguments],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$this->directory);
        self::assertIsResource($process);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$error);return trim($out);
    }
    private function commit(string $version):void
    {
        file_put_contents($this->source.'/VERSION',$version);
        $this->git(['-C',$this->source,'add','VERSION']);
        $this->git(['-C',$this->source,'commit','-m','Synthetic release '.$version]);
    }
    private function updater():GitUpdater {return new GitUpdater($this->checkout,$this->directory.'/update.lock');}

    public function testFailedMigrationCanResumeWhenTheCodeCommitIsAlreadyCurrent():void
    {
        $this->commit('2');$this->git(['-C',$this->source,'push']);$updater=$this->updater();$calls=0;
        $failed=$updater->update(static function()use(&$calls):void{$calls++;throw new \RuntimeException('Synthetic interrupted migration');});
        self::assertFalse($failed['ok']);self::assertSame('migration_failed',$failed['state']);
        self::assertSame('2',file_get_contents($this->checkout.'/VERSION'));
        $head=$this->git(['-C',$this->checkout,'rev-parse','HEAD']);
        $resumed=$updater->update(static function()use(&$calls):array{$calls++;return ['count'=>1];});
        self::assertSame(2,$calls,'A current code checkout must not skip the pending migration callback.');
        self::assertTrue($resumed['ok']);self::assertTrue($resumed['migrations']['ok']);
        self::assertSame(['count'=>1],$resumed['migrations']['result']);
        self::assertSame($head,$this->git(['-C',$this->checkout,'rev-parse','HEAD']));
        $again=$updater->update(static function()use(&$calls):void{$calls++;throw new \RuntimeException('Still unavailable');});
        self::assertFalse($again['ok']);self::assertSame('migration_failed',$again['state']);self::assertSame(3,$calls);
    }

    public function testCurrentCodeWithPendingSchemaExposesRetryOnlyForCleanCheckout():void
    {
        $updater=$this->updater();$normal=$updater->status(false,false);
        self::assertSame('up_to_date',$normal['state']);self::assertFalse($normal['can_update']);
        $pending=$updater->status(false,true);
        self::assertSame('migration_pending',$pending['state']);self::assertTrue($pending['can_update']);
        file_put_contents($this->checkout.'/VERSION','Local changes');
        $dirty=$updater->status(false,true);
        self::assertSame('dirty',$dirty['state']);self::assertFalse($dirty['can_update']);
    }

    public function testUpdaterKeepsOneLockInodeForAlreadyOpenedWaiters():void
    {
        $path=$this->directory.'/update.lock';$waiter=fopen($path,'c');
        self::assertIsResource($waiter);$inode=fstat($waiter)['ino'];
        try {
            self::assertTrue($this->updater()->update(static fn()=>['count'=>0])['ok']);
            clearstatcache(true,$path);
            self::assertFileExists($path,'Unlinking the lock lets another updater create a separate lock inode.');
            self::assertSame($inode,fileinode($path));
            self::assertTrue(flock($waiter,LOCK_EX|LOCK_NB));
            $called=false;
            $blocked=$this->updater()->update(static function()use(&$called):void{$called=true;});
            self::assertSame('locked',$blocked['state']);self::assertFalse($called);
        } finally {flock($waiter,LOCK_UN);fclose($waiter);}
    }
}

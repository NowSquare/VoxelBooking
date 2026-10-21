<?php
declare(strict_types=1);
namespace Tests\Unit\Agent;
use App\Agent\PayloadCipher;
use PHPUnit\Framework\TestCase;

final class PayloadCipherTest extends TestCase
{
    public function testConcurrentInitializersUseOneCompleteKey(): void
    {
        if(!function_exists('pcntl_fork')) { self::markTestSkipped('pcntl is required for the key creation race.'); }
        $directory=sys_get_temp_dir().'/vb-key-race-'.bin2hex(random_bytes(8));
        mkdir($directory,0700);$path=$directory.'/payload.key';$children=[];
        try {
            for($i=0;$i<8;$i++) {
                $pid=pcntl_fork();
                if($pid===0) {
                    try {
                        file_put_contents($directory.'/cipher-'.$i,(new PayloadCipher(keyPath:$path))->encrypt(['index'=>$i]));
                        exit(0);
                    }catch(\Throwable) { exit(1); }
                }
                self::assertGreaterThan(0,$pid);$children[]=$pid;
            }
            foreach($children as $pid) { pcntl_waitpid($pid,$status);self::assertSame(0,pcntl_wexitstatus($status)); }
            $cipher=new PayloadCipher(keyPath:$path);
            for($i=0;$i<8;$i++) { self::assertSame(['index'=>$i],$cipher->decrypt(file_get_contents($directory.'/cipher-'.$i))); }
            self::assertSame(0600,fileperms($path)&0777);
        }finally {
            foreach(glob($directory.'/*') as $file) { unlink($file); }rmdir($directory);
        }
    }
}

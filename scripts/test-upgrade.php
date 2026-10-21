<?php

declare(strict_types=1);

// Only invoked by the disposable runner before its HTTP server starts.
require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Engine\{AgentAuth,Database,Logger,Migrator};
use Tests\Integration\TestFixtures;
use Tests\Support\TestEnvironment;

TestEnvironment::assertDatabase();
$root=dirname(__DIR__);
$run=dirname(getenv('VOXELBOOKING_TEST_MANIFEST'));
Logger::init($root.'/storage');
$check=static function(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
};
$check(Database::query('SHOW TABLES')===[],'Upgrade rehearsal requires an empty disposable database.');
$baseline=$run.'/upgrade-v28';mkdir($baseline,0700);
foreach(glob($root.'/app/Migrations/*.php') as $file)if((int)basename($file)<=28)copy($file,$baseline.'/'.basename($file));
(new Migrator($baseline))->migrate();
TestFixtures::provision();
$raw='vb_'.bin2hex(random_bytes(32));
Database::execute("INSERT INTO api_keys (id,name,key_hash,key_prefix,scopes,role,created_by) VALUES ('upgrade-legacy-key','Synthetic old agent',?,?,?,'agent',?)",
    [hash('sha256',$raw),substr($raw,0,8),json_encode(['bookings:read','bookings:write','services:write']),TestFixtures::OPERATOR_ID]);
// v28 only has the IP endpoint log, not the old middleware's imagined per-key columns.
Database::execute("INSERT INTO rate_limits (ip,endpoint_group) VALUES ('127.0.0.1','agent-api')");
$snapshot=static fn():array=>[
    'booking'=>Database::query('SELECT id,tenant_id,customer_id,status,start_datetime,end_datetime FROM bookings WHERE id=?',[TestFixtures::BOOKING_ID])[0],
    'customer'=>Database::query('SELECT id,tenant_id,name,email FROM customers WHERE id=?',[TestFixtures::CUSTOMER_ID])[0],
    'key'=>Database::query("SELECT id,key_hash,scopes,role FROM api_keys WHERE id='upgrade-legacy-key'")[0],
];
$before=$snapshot();
$backup=[];
foreach(Database::query('SHOW TABLES') as $row){
    $table=(string)array_values($row)[0];
    $check((bool)preg_match('/^[a-z_]+$/D',$table),'Unexpected table name in synthetic backup.');
    $ddl=Database::query("SHOW CREATE TABLE `$table`")[0]['Create Table'];
    $backup[]=$ddl;
    foreach(Database::query("SELECT * FROM `$table`") as $record){
        $columns=implode(',',array_map(static fn($key)=>'`'.$key.'`',array_keys($record)));
        $values=implode(',',array_map(static fn($value)=>$value===null?'NULL':Database::pdo()->quote((string)$value),array_values($record)));
        $backup[]="INSERT INTO `$table` ($columns) VALUES ($values)";
    }
}
file_put_contents($run.'/upgrade-v28-backup.json',json_encode($backup,JSON_THROW_ON_ERROR));chmod($run.'/upgrade-v28-backup.json',0600);
$migrator=new Migrator($root.'/app/Migrations');$migrator->migrate();$current=$migrator->getCurrentVersion();
// The newest migration file is the expected checkpoint, so new migrations need no edit here.
$latest=max(array_map(static fn($file)=>(int)basename($file),glob($root.'/app/Migrations/[0-9]*.php')));
$check($current===$latest,'Expected the current schema version after v28 upgrade.');
$check($before===$snapshot(),'Upgrade changed existing booking, customer or key data.');
$key=AgentAuth::validate($raw);
$check($key!==null && AgentAuth::hasScope($key,'bookings:read') && !AgentAuth::hasScope($key,'bookings:write') && !AgentAuth::hasScope($key,'services:write'),'Upgrade broadened dormant legacy write authority.');
$budget=new \App\Agent\RateBudget();
$check($budget->consume(['upgrade-key-budget'=>1])['allowed'] && !$budget->consume(['upgrade-key-budget'=>1])['allowed'],'New per-key budget does not work on the migrated v28 rate schema.');
$check(count(Database::query("SELECT * FROM rate_limits WHERE endpoint_group='agent-api'"))===1,'Upgrade modified the independent IP request log.');

// Reproduce a process stopping after committed DDL but before its version checkpoint.
Database::upsertSetting('db_version','28');
$migrator->migrate();
$check($migrator->getCurrentVersion()===$current && $snapshot()===$before,'Committed DDL replay must preserve data and complete.');

// Simulate stopping partway through the individual booking column additions.
Database::execute('ALTER TABLE bookings DROP COLUMN customer_email_snapshot, DROP COLUMN price_currency');
Database::upsertSetting('db_version','33');
$migrator->migrate();
$columns=array_column(Database::query('SHOW COLUMNS FROM bookings'),'Field');
$check(in_array('customer_email_snapshot',$columns,true) && in_array('price_currency',$columns,true) && $snapshot()===$before,'Partially applied booking migration must add only missing columns.');

// MySQL DDL is not transactional. Retry a partially applied migration safely.
$recovery=$run.'/upgrade-recovery';mkdir($recovery,0700);
$file=$recovery.'/042_recovery_probe.php';
file_put_contents($file,"<?php return ".var_export(["CREATE TABLE IF NOT EXISTS upgrade_recovery_probe (id INT PRIMARY KEY, value VARCHAR(40))","INSERT INTO deliberately_missing_upgrade_table VALUES (1)"],true).';');
$failed=false;
try{(new Migrator($recovery))->migrate();}catch(\PDOException){$failed=true;}
$check($failed && $migrator->getCurrentVersion()===$current,'Failed migration must not advance its checkpoint.');
$check(Database::tableExists('upgrade_recovery_probe'),'Rehearsal must exercise committed partial DDL.');
file_put_contents($file,"<?php return ".var_export(["CREATE TABLE IF NOT EXISTS upgrade_recovery_probe (id INT PRIMARY KEY, value VARCHAR(40))","INSERT INTO upgrade_recovery_probe VALUES (1,'recovered') ON DUPLICATE KEY UPDATE value='recovered'"],true).';');
(new Migrator($recovery))->migrate();
$check($migrator->getCurrentVersion()===42 && Database::query('SELECT value FROM upgrade_recovery_probe')[0]['value']==='recovered','Corrected migration did not resume successfully.');

// Restore the actual v28 schema/data backup, then prove it can upgrade again.
Database::execute('SET FOREIGN_KEY_CHECKS=0');
foreach(Database::query('SHOW TABLES') as $row)Database::execute('DROP TABLE `'.array_values($row)[0].'`');
foreach(json_decode((string)file_get_contents($run.'/upgrade-v28-backup.json'),true,flags:JSON_THROW_ON_ERROR) as $statement)Database::execute($statement);
Database::execute('SET FOREIGN_KEY_CHECKS=1');
$check($migrator->getCurrentVersion()===28 && $snapshot()===$before,'Backup restoration did not reproduce the pre-upgrade schema and data.');
$migrator->migrate();
$check($migrator->getCurrentVersion()===$current && $snapshot()===$before,'Restored installation did not upgrade again.');
$evidence=['mysql'=>Database::query('SELECT VERSION() AS version')[0]['version'],'php'=>PHP_VERSION,'from'=>28,'to'=>$current,
    'legacy_read_only'=>true,'rate_schema_repaired'=>true,'committed_ddl_replay'=>true,'partial_booking_columns_recovery'=>true,'partial_failure_recovery'=>true,'backup_restore_and_reupgrade'=>true,'existing_data_preserved'=>true];
file_put_contents($run.'/upgrade-evidence.json',json_encode($evidence,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
fwrite(STDOUT,json_encode($evidence,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");

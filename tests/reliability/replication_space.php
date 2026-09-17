<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/replication-plan.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$p=['replication'=>['sourceSnapshot'=>'tank/data@next','destination'=>'backup/data'],'inspection'=>['mode'=>'incremental','base'=>['snapshot'=>'tank/data@base']],'freeSpaceFloor'=>'1G'];
$available='1200000000';$calls=[];
$read=static function($args)use(&$available,&$calls){$calls[]=$args;return $args[0]==='send'?"size\t100000000\n":$available;};
$result=zfsas_replication_space($p,$read,fn()=>'0');
check($result['outcome']==='success'&&$result['requiredBytes']===1173741824,'Configured free-space floor was ignored');
$available='1100000000';$result=zfsas_replication_space($p,$read,fn()=>'0');
check($result['outcome']==='validation_failure'&&$result['reason']==='space','Insufficient post-transfer headroom was accepted');
$waiting=zfsas_replication_space($p,$read,fn()=>'1048576');check($waiting['outcome']==='wait'&&$waiting['reason']==='space','Measured freeing work consumed a failure');
$available='-';try{zfsas_replication_space($p,$read,fn()=>'0');throw new Exception('Missing space metadata accepted');}catch(RuntimeException $e){}
foreach(['-1G','1.5G','999999999999999T','1Q'] as $bad){try{zfsas_replication_space_floor($bad);throw new Exception('Unsafe space floor accepted');}catch(InvalidArgumentException $e){}}
check(!array_filter($calls,fn($a)=>!in_array($a[0],['send','get'],true)),'Space approval mutated ZFS');
echo "PASS: measured space plus captured headroom, insufficient-space reason, incomplete metadata and overflow rejection\n";

<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/send-cleanup-policy.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/replication-cleanup.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function reject($fn){try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe policy accepted');}
$job=['id'=>'abcdef123456','transport'=>'local'];
check(zfsas_send_cleanup_mode([],$job)==='retention_only','Missing policy enabled cleanup');
foreach (['{}','[]','{"version":2,"jobs":{}}','{"version":1,"jobs":[]}','{"version":1,"jobs":{"abcdef123456":true}}','{'] as $raw) {
    reject(fn()=>zfsas_send_cleanup_policies(['SEND_CLEANUP_POLICIES'=>$raw]));
}
$saved=zfsas_send_cleanup_save([],[$job],[$job['id']=>'older_anchors']);
$config=['SEND_CLEANUP_POLICIES'=>$saved];
check(zfsas_send_cleanup_save($config,[$job],[])===$saved,'Unrelated save changed authorization');
check(zfsas_send_cleanup_mode($config,$job)==='older_anchors','Explicit opt-in lost');
reject(fn()=>zfsas_send_cleanup_mode($config,['id'=>$job['id'],'transport'=>'ssh']));
check(zfsas_send_cleanup_mode($config,['id'=>'fedcba654321','transport'=>'local'])==='retention_only','New job inherited authorization');
check(zfsas_send_cleanup_policies(['SEND_CLEANUP_POLICIES'=>zfsas_send_cleanup_save($config,[],[])])===[],'Removed job retained authority');
$now=2000000000;$prefix='backup/data@snapsync-send-abcdef123456-';
$request=['sourceSnapshot'=>'tank/source@next','sourceGuid'=>'100','destination'=>'backup/data'];
$inspection=['mode'=>'incremental','destinationDatasetGuid'=>'20','references'=>[['snapshot'=>$prefix.'base','guid'=>'200']]];
$policy=['mode'=>'older_anchors','scheduleId'=>$job['id'],'prefix'=>'snapsync-send-','sendConfigHash'=>str_repeat('a',64),'keepAll'=>14,'keepDaily'=>30,'keepWeekly'=>183];
$rows='';$txg=1000;
foreach ([['newest',1,'300','0','-'],['base',10,'200','0','-'],['recent',13,'301','0','-'],['boundary',14,'302','0','-'],['daily',20,'303','0','-'],['weekly',40,'304','0','-'],['held',60,'305','1','-'],['cloned',80,'306','0','backup/clone'],['expired',200,'307','0','-']] as [$name,$age,$guid,$holds,$clones]) {
    $rows.=$prefix.$name."\t$guid\t".$txg--."\t".($now-$age*86400)."\t0\t$holds\t$clones\n";
}
$rows.="backup/data@unrelated\t399\t1\t1\t100\t0\t-\n";
$read=static fn($args)=>$args[0]==='get'?'20':$rows;
$candidates=zfsas_replication_anchor_candidates($request,$inspection,$policy,$read,$now);
$names=array_map(fn($task)=>$task['parameters']['deleteJob']['SNAPSHOT'],array_values($candidates));
check($names===[$prefix.'weekly',$prefix.'daily'],'Anchor boundaries, scope or oldest-first order incorrect');
check(!zfsas_replication_anchor_candidates($request,$inspection,array_replace($policy,['mode'=>'retention_only']),$read,$now),'Disabled policy produced candidates');
$inspection['references'][]=['snapshot'=>'other/dataset@shared','guid'=>'304'];
check(count(zfsas_replication_anchor_candidates($request,$inspection,$policy,$read,$now))===1,'Shared GUID reference was not protected');
check(!zfsas_replication_anchor_candidates($request,['mode'=>'full'],$policy,$read,$now),'New receiver invented cleanup authority');
$largePolicy=array_replace($policy,['keepAll'=>1,'keepDaily'=>20000,'keepWeekly'=>30000]);
$largeRows='';
for($i=0;$i<10000;$i++) {
    $largeRows.=$prefix.'scale-'.$i."\t".(10000+$i)."\t".(20000-$i)."\t".($now-$i*86400)."\t0\t0\t-\n";
}
$largeRead=static fn($args)=>$args[0]==='get'?'20':$largeRows;
$large=zfsas_replication_anchor_candidates($request,$inspection,$largePolicy,$largeRead,$now);
check(count($large)===9998,'10,000-snapshot inventory lost eligible daily anchors');
check(reset($large)['parameters']['deleteJob']['SNAPSHOT']===$prefix.'scale-9999','Large inventory order changed');
echo "PASS: explicit local opt-in, save preservation, exact scope, keep-all boundary, oldest-first anchors and immutable protections\n";

<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/replication-schedule-plan.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function reject($fn){try{$fn();}catch(InvalidArgumentException|RuntimeException $error){return;}throw new RuntimeException('Unsafe membership accepted');}
$job=['source'=>'tank/root','destination'=>'backup/copy','children'=>'1','transport'=>'local'];
$rows="tank/root/child\t18446744073709551615\ntank/root\t10\ntank/root/child/deep\t12\n";
$calls=[];$read=static function($args)use(&$calls,&$rows){$calls[]=$args;return $rows;};
$members=zfsas_replication_membership($job,$read);
check(array_column($members,'destination')===['backup/copy','backup/copy/child','backup/copy/child/deep'],'Membership lost ancestor order or exact receiver mapping');
check($members[1]['sourceDatasetGuid']==='18446744073709551615','Large dataset GUID lost precision');
check(count($calls)===2 && !array_filter($calls,fn($a)=>$a[0]!=='list'),'Capture mutated or repeatedly scanned inventory');
reject(fn()=>zfsas_replication_membership(array_replace($job,['children'=>'0']),$read));
foreach (["tank/root/child\t11\n","tank/root\t10\ntank/root/a/b\t12\n","tank/root\t10\ntank/root\t11\n","tank/root\t10\ntank/other\t11\n"] as $bad) {
 reject(fn()=>zfsas_replication_membership($job,fn()=>$bad));
}
$count=0;$race=static function()use(&$count,$rows){return ++$count===1?$rows:$rows."tank/root/new\t13\n";};
reject(fn()=>zfsas_replication_membership($job,$race));
$rows="tank/root\t10\n";for($i=1;$i<10000;$i++)$rows.="tank/root/c$i\t".(100+$i)."\n";
check(count(zfsas_replication_membership($job,$read))===10000,'Bounded large membership lost datasets');
$plan=zfsas_replication_schedule_plan(['job'=>$job+['id'=>'abcdef123456'],'revision'=>str_repeat('a',64),'rateLimit'=>'0','snapshotName'=>'snapsync-send-fixture','occurrence'=>100],$read);
check(count($plan['tasks'])===20001,'Large membership graph omitted tasks');
check(count($plan['tasks']['verify-run']['dependencies'])===10000,'Finalizer lost expected members');
$rows.="tank/root/overflow\t99999\n";reject(fn()=>zfsas_replication_membership($job,$read));
echo "PASS: bounded recursive membership, exact destination mapping, GUID precision, missing parents, changed enumeration and 10,000 datasets\n";

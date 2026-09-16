<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/send-helpers.php';
function check($condition,$message) { if (!$condition) { throw new RuntimeException($message); } }
$zone=new DateTimeZone('America/New_York'); $now=1793491200;
$job=['id'=>'abcdef123456','frequency'=>'7d'];
$previous=['SEND_JOBS'=>'abcdef123456|tank/data|backup/data|7d|1G|0|local','SEND_SCHEDULE_SPECS'=>'{}'];
$legacy=zfsas_send_schedule_spec($previous,$job);
check($legacy['kind']==='legacy_window','Missing legacy spec');
foreach ([1704067200,1710053999,1710054000,1730613599,1730613600] as $instant) {
    $offset=$zone->getOffset(new DateTimeImmutable('@'.$instant)); $local=$instant+$offset;
    check(ZfsasSchedule::occurrence($legacy,$instant,$zone,false)===$local-($local%604800)-$offset,'Legacy epoch/week alignment changed at DST');
}
$changed=['SEND_JOBS'=>'abcdef123456|tank/data|backup/data|6h|2G|0|local'];
$preserved=json_decode(zfsas_send_schedule_specs_save($previous,$changed,[], $now),true);
check($preserved[$job['id']]===$legacy,'Unrelated save converted legacy timing');
$changed['SEND_SCHEDULE_SPECS']=json_encode($preserved);
check(json_decode(zfsas_send_schedule_specs_save($changed,$changed,[], $now+100),true)===$preserved,'Second save converted legacy timing');
$converted=json_decode(zfsas_send_schedule_specs_save($previous,$changed,[$job['id']=>['convert'=>'1']],$now),true)[$job['id']];
check($converted['kind']==='interval' && $converted['anchor']===$now,'Conversion did not anchor at Save');
check(ZfsasSchedule::occurrence($converted,$now,$zone,false)===null,'New interval ran on Save');
check(ZfsasSchedule::occurrence($converted,$now,$zone,true)===$now+21600,'First interval is not one interval after Save');
$job['frequency']='1d';
$daily=zfsas_send_schedule_save($previous,$job,['convert'=>'1','time'=>'01:30'],false,$now);
check($daily['kind']==='daily' && $daily['hour']===1 && $daily['minute']===30,'Daily controls ignored');
check(ZfsasSchedule::occurrence($daily,$now-1,$zone,false)===null,'New calendar schedule caught up before Save');
$job['frequency']='7d';
$weekly=zfsas_send_schedule_save($previous,$job,['convert'=>'1','time'=>'02:30','day'=>'1'],false,$now);
check($weekly['kind']==='weekly' && $weekly['day']===1,'Weekly controls ignored');
$previous['SEND_SCHEDULE_SPECS']=json_encode([$job['id']=>$weekly]);
check(zfsas_send_schedule_save($previous,$job,[],false,$now+100)===$weekly,'Unrelated calendar save moved cadence');
try { zfsas_send_schedule_save($previous,$job,['time'=>'25:60'],false,$now); throw new RuntimeException('Bad time accepted'); }
catch (InvalidArgumentException $expected) {}
echo "PASS: Send legacy epoch/week and DST alignment, repeated unrelated saves, explicit conversion, Save anchors and daily/weekly controls\n";

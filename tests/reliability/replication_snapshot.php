<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/replication-snapshot.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function reject($fn){try{$fn();}catch(InvalidArgumentException|RuntimeException $error){return;}throw new RuntimeException('Unsafe snapshot intent accepted');}
$intent=['source'=>'tank/data','sourceDatasetGuid'=>'10','snapshotName'=>'snapsync-send-abc-100','scheduleId'=>'abcdef123456','occurrence'=>'100'];
$exists=false;$mutations=[];$properties=[];$datasetGuid='10';$race=false;
$execute=static function($args)use(&$exists,&$mutations,&$properties,&$datasetGuid,&$race){
 $target=end($args);
 if($args[0]==='list')return $exists ? "tank/data@snapsync-send-abc-100\n" : '';
 if($args[0]==='snapshot'){
  $mutations[]=$args;$exists=true;
  foreach($args as $i=>$value)if($value==='-o'){[$key,$property]=explode('=',$args[$i+1],2);$properties[$key]=$property;}
  if($race)throw new RuntimeException('Interrupted after successful creation');
  return '';
 }
 $property=$args[array_search('value',$args,true)+1];
 if($property==='guid')return $target==='tank/data'?$datasetGuid:'18446744073709551615';
 return $properties[$property]??'-';
};
$result=zfsas_replication_snapshot($intent,$execute);
check(count($mutations)===1 && $result['reference']['guid']==='18446744073709551615','Creation lacks exact identity');
zfsas_replication_snapshot($intent,$execute);check(count($mutations)===1,'Recovered snapshot was recreated');
$properties['org.zfs.snapsync:occurrence']='99';reject(fn()=>zfsas_replication_snapshot($intent,$execute));
check(count($mutations)===1,'Foreign snapshot was replaced');
$exists=false;$properties=[];$race=true;reject(fn()=>zfsas_replication_snapshot($intent,$execute));$race=false;
zfsas_replication_snapshot($intent,$execute);check(count($mutations)===2,'Interrupted mutation replayed after metadata proved creation');
$datasetGuid='11';reject(fn()=>zfsas_replication_snapshot($intent,$execute));
check(count($mutations)===2,'Replacement dataset was mutated');
echo "PASS: snapshot intent properties, exact GUID evidence, interrupted creation adoption, foreign snapshot and replacement rejection\n";

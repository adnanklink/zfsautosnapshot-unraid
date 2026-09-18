<?php
if (!is_file('/.dockerenv')) { exit(77); }
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/send-helpers.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$root='/tmp/snapsync-pressure-preflight-'.bin2hex(random_bytes(5));mkdir($root);
$config='/boot/config/plugins/zfs.snapsync';@mkdir($config,0770,true);
file_put_contents($config.'/zfs_snapsync.conf','PREFIX=auto-');file_put_contents($config.'/zfs_send.conf','SEND_SNAPSHOT_PREFIX=send-');
$task='preflight-fixture';$dir='/tmp/zfs-snapsync-coordinator/attempt-inputs';@mkdir($dir,0700,true);
$path=$dir.'/'.hash('sha256',$task).'.job.pressure.json';
$script=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/replication-pressure-check.php');
$mock=<<<'CODE'
#!/usr/bin/env php
<?php
$args=array_slice($argv,1);file_put_contents(getenv('PRESSURE_MOCK_LOG'),json_encode($args)."\n",FILE_APPEND);
if (($args[0] ?? '')!=='get') { exit(99); }
$map=json_decode(file_get_contents(getenv('PRESSURE_MOCK_DATA')),true);$key=$args[5].'|'.end($args);
if (!array_key_exists($key,$map)) { fwrite(STDERR,'Unexpected probe '.$key);exit(98); }
echo $map[$key],"\n";
CODE;
foreach(['zfs','zpool'] as $binary){file_put_contents($root.'/'.$binary,$mock);chmod($root.'/'.$binary,0700);}
$job=['DATASET'=>'backup/data','DATASET_GUID'=>'20','SNAPSHOT'=>'backup/data@old','SNAPSHOT_GUID'=>'30','PRESSURE_NEWEST_SNAPSHOT'=>'backup/data@new','PRESSURE_NEWEST_GUID'=>'40','SNAPSHOT_EPOCH'=>'100','PRESSURE_CUTOFF'=>'200','DELETE_POOL'=>'backup'];
$base=['taskId'=>$task,'job'=>$job,'pressure'=>['revision'=>zfsas_config_revision($config),'policy'=>['mode'=>'older_anchors'],'requiredBytes'=>100,'inspection'=>['references'=>[['snapshot'=>'backup/data@base','guid'=>'50']]]]];
$measure=['guid|backup/data'=>'20','guid|backup/data@old'=>'30','guid|backup/data@new'=>'40','guid|backup/data@base'=>'50','creation|backup/data@old'=>'100','available|backup/data'=>'10','refquota,referenced|backup/data'=>"0\n10",'quota|backup/data'=>'0','quota|backup'=>'0','freeing|backup'=>'0'];
function invoke($capture,$values,$expected,$message){
 global $path,$root,$task,$script;
 file_put_contents($path,json_encode($capture));file_put_contents($root.'/data.json',json_encode($values));file_put_contents($root.'/calls','');
 $env=getenv();$env['PATH']=$root.':'.$env['PATH'];$env['ZFSAS_TASK_ID']=$task;$env['PRESSURE_MOCK_DATA']=$root.'/data.json';$env['PRESSURE_MOCK_LOG']=$root.'/calls';
 $p=proc_open([PHP_BINARY,$script,$path],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
 $out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
 check($code===$expected,$message.': '.$code.' '.$out);
 $calls=file($root.'/calls',FILE_IGNORE_NEW_LINES);
 foreach($calls as $line){check(json_decode($line,true)[0]==='get','Preflight mutated ZFS');}
 return $calls;
}
try {
 invoke($base,$measure,0,'Eligible anchor denied');
 $calls=invoke($base,array_replace($measure,['available|backup/data'=>'100']),2,'Recovered space did not preserve anchor');
 check(count($calls)===6,'Recovered space performed unnecessary quota or pool probes');
 foreach(['backup/data','backup/data@old','backup/data@new','backup/data@base'] as $target)invoke($base,array_replace($measure,['guid|'.$target=>'999']),1,'Changed identity accepted');
 invoke($base,array_replace($measure,['available|backup/data'=>'-']),1,'Incomplete space metadata accepted');
 invoke($base,array_replace($measure,['refquota,referenced|backup/data'=>"100\n99"]),1,'Impossible refquota allowed deletion');
 invoke($base,array_replace($measure,['quota|backup'=>'99']),1,'Impossible ancestor quota allowed deletion');
 invoke($base,array_replace($measure,['freeing|backup'=>'1']),4,'Freeing failed to postpone mutation');
 invoke($base,array_replace($measure,['freeing|backup'=>'-']),1,'Unknown freeing accepted');
 $changed=$base;$changed['pressure']['revision']=str_repeat('0',64);
 check(!invoke($changed,$measure,1,'Changed revision accepted'),'Changed revision probed ZFS');
 $changed=$base;$changed['job']['PRESSURE_CUTOFF']='100';invoke($changed,$measure,1,'Keep-all boundary deleted');
 $changed=$base;$changed['pressure']['inspection']['references'][]=['snapshot'=>'other/data@shared','guid'=>'30'];invoke($changed,$measure,1,'Shared GUID reference deleted');
 echo "PASS: actual pressure preflight, externally recovered space, dataset/snapshot/checkpoint/base replacement, keep-all boundary, shared references, changed revision, incomplete metadata and quota/freeing limits\n";
} finally {unlink($path);foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);}

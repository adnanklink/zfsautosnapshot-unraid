<?php
if (!is_file('/.dockerenv')) { throw new RuntimeException('Requires disposable container.'); }
$plugin=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.autosnapshot');
require $plugin.'/php/snapshot-manager-helpers.php';
require $plugin.'/php/coordinator-state.php';
require $plugin.'/php/coordinator-deletion.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$root='/tmp/zfs-autosnapshot-coordinator';
$journal=new ZfsasCoordinatorState($root);
zfsas_ops_ensure_storage_dirs();
$legacy = "PENDING_COUNT=1\nJOB\tlegacy-unapproved\n";
file_put_contents(zfsas_ops_delete_queue_state_path(), $legacy);
$deletion=new ZfsasCoordinatorDeletion($journal,$root);
check(file_get_contents($root . '/legacy-deletion-review/' . hash('sha256', $legacy) . '.state') === $legacy,
    'Legacy queue evidence was overwritten');
check(count($journal->state['runs']) === 0, 'Legacy projection recreated execution authority');
$job=['JOB_ID'=>'fixture-delete','REQUESTED_EPOCH'=>'1','QUEUE_SORT'=>'1','DATASET'=>'tank/data','SNAPSHOT'=>'tank/data@auto-old','SNAPSHOT_NAME'=>'auto-old','SNAPSHOT_EPOCH'=>'1','SNAPSHOT_GUID'=>'123','SNAPSHOT_CREATETXG'=>'1','DELETE_POOL'=>'tank','ESTIMATED_RECLAIM_BYTES'=>'10','SEND_PROTECTED'=>'0','DELETE_SCOPE'=>'snapshot','SEND_SCHEDULE_JOB_ID'=>'','SEND_CONFIG_HASH'=>str_repeat('a',64)];
$line=zfsas_ops_delete_queue_command_line($job);
check(str_starts_with($line,"ENQUEUE3\t"),'Producer lost versioned authority');
zfsas_ops_append_delete_queue_inbox($line);$deletion->tick(1);
check(count($journal->state['runs'])===1,'Submission did not become a task');
$taskId=array_key_first($journal->state['tasks']);
$path=$deletion->command($journal->state['tasks'][$taskId])[2];
check(zfsas_ops_parse_job_file($path)['SNAPSHOT_GUID']==='123','Captured adapter input changed identity');
$ref = ['role'=>'source','endpoint'=>'local','dataset'=>'tank/data','datasetGuid'=>'42',
    'snapshot'=>$job['SNAPSHOT'],'guid'=>$job['SNAPSHOT_GUID']];
$protected = $journal->submit('reference-owner', ['tasks'=>['send'=>['kind'=>'send','references'=>[$ref]]]], 1);
check(($deletion->command($journal->state['tasks'][$taskId])['reason'] ?? '') === 'dependency', 'Deletion ignored registered reference');
$journal->cancel($protected['runId'],1);
check(isset($deletion->command($journal->state['tasks'][$taskId])[0]), 'Stopped owner kept deletion blocked');
// Terminal retention removes the fixture owner, preserving its receipt.
$journal->prune(32 * 86400);
// Cursor loss after commit replays the same acceptance, never another task.
file_put_contents($root.'/deletion-inbox',$line."\n");file_put_contents($root.'/deletion-inbox.cursor','0');
$deletion->request();$deletion->tick(2);
check(count($journal->state['runs'])===1,'Interrupted draining duplicated deletion authority');
// Old or identity-conflicting queue records are evidence only.
$changed=$job;$changed['SNAPSHOT_GUID']='999';
zfsas_ops_append_delete_queue_inbox(zfsas_ops_delete_queue_command_line($changed));
zfsas_ops_append_delete_queue_inbox(str_replace('ENQUEUE3','ENQUEUE',$line));
$deletion->request();$deletion->tick(3);
check(count($journal->state['runs'])===1 && is_file($root.'/deletion-review-required.log'),'Legacy/conflicting deletion executed');
$token=$journal->claim($taskId,4,4,'gen');$journal->started($taskId,$token,123,'123');$deletion->changed($taskId);$deletion->tick(4);
check(zfsas_ops_delete_queue_status_counts()['running']===1,'Running projection lost ownership');
$journal->result($taskId,$token,['outcome'=>'wait','reason'=>'resource','delay'=>1],4,4,true);$deletion->changed($taskId);$deletion->tick(5);
check($journal->state['tasks'][$taskId]['attemptCount']===0,'Dependency wait spent retry');
$token=$journal->claim($taskId,5,5,'gen');$journal->started($taskId,$token,124,'124');
check(!is_file(zfsas_ops_status_dir().'/delete-results/fixture-delete.result'),'Outcome published before shutdown');
$journal->result($taskId,$token,['outcome'=>'success','itemState'=>'completed','message'=>'Deleted exact snapshot.'],6,6,true);$deletion->changed($taskId);$deletion->tick(6);
check(str_starts_with(file_get_contents(zfsas_ops_status_dir().'/delete-results/fixture-delete.result'),'completed'),'Verified outcome not projected');
check(zfsas_ops_delete_queue_status_counts()['pending']===0,'Completed deletion stayed queued');
$second=$job;$second['JOB_ID']='late-delete';$second['SNAPSHOT']='tank/data@auto-late';$second['SNAPSHOT_NAME']='auto-late';
zfsas_ops_append_delete_queue_inbox(zfsas_ops_delete_queue_command_line($second));$deletion->request();$deletion->tick(7);
check(count($journal->state['runs'])===2,'Late enqueue lost after old work completed');
// A valid batch owner grants authority; its cancellation revokes future grants.
$batchToken = str_repeat('c', 32);
$owner = $journal->submit('batch-' . $batchToken, ['tasks' => ['batch' => ['kind' => 'batch']]], 8);
$owned = $job; $owned['JOB_ID'] = 'sm-' . $batchToken . '-item'; $owned['SEND_CONFIG_HASH'] = '';
zfsas_ops_append_delete_queue_inbox(zfsas_ops_delete_queue_command_line($owned));
$deletion->request(); $deletion->tick(8);
$child = $journal->state['commands']['delete-' . hash('sha256', $owned['JOB_ID'])]['runId'] . ':snapshot';
check($journal->state['tasks'][$child]['parameters']['ownerRunId'] === $owner['runId'], 'Batch ownership lost');
check(!empty($deletion->command($journal->state['tasks'][$child])['recoveryRequired']), 'Legacy batch gained authority without journal items');
$journal->cancel($owner['runId'], 9);
check(($deletion->command($journal->state['tasks'][$child])['outcome'] ?? '') === 'validation_failure', 'Canceled owner retained deletion authority');
echo "PASS: versioned deletion admission, captured identities, interrupted import replay, legacy quarantine, verified status/results, waits and late submissions\n";

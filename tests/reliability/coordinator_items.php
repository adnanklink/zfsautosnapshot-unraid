<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/coordinator-state.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function rejected($fn) { try { $fn(); } catch (InvalidArgumentException $error) { return; } throw new RuntimeException('Unauthorized publication accepted'); }
$root = '/tmp/coordinator-items-' . bin2hex(random_bytes(8));
try {
    $journal = new ZfsasCoordinatorState($root); $items = [];
    for ($i=0;$i<51;$i++) { $items[]=['identity'=>'tank/data@s'.$i.'#'.$i,'snapshot'=>'tank/data@s'.$i,'guid'=>(string)$i,'state'=>'queued']; }
    $run=$journal->submit('batch',['manual'=>true,'tasks'=>['items'=>['kind'=>'batch','items'=>$items]]],1)['runId'];
    $task=$run.':items';$token=$journal->claim($task,1,1,'gen');$journal->started($task,$token,123,'123');
    $sequence=1;
    $report=function($type,$payload) use (&$journal,&$sequence,$task,&$token) {
        return $journal->workerReport(['taskId'=>$task,'token'=>$token,'generation'=>'gen','sequence'=>$sequence++,'type'=>$type,'payload'=>$payload],'gen',1);
    };
    $grant=$report('item_chunk',[]);check(count($grant['items'])===50,'Chunk bound lost');
    $first=$grant['items'][0];$second=$grant['items'][1];
    $request=['taskId'=>$task,'token'=>$token,'generation'=>'gen','sequence'=>$sequence,'type'=>'item_start','payload'=>['itemId'=>$task.':item:50','fingerprint'=>'bad']];
    rejected(fn()=>$journal->workerReport($request,'gen',1));
    $start=['itemId'=>$first['id'],'fingerprint'=>$first['fingerprint']];
    $report('item_start',$start);
    $replay=$request;$replay['sequence']=$sequence-1;$replay['payload']=$start;
    check($journal->workerReport($replay,'gen',1)['authorized'],'Lost start acknowledgment cannot replay');
    $request['sequence']=$sequence;$request['payload']=['itemId'=>$second['id'],'fingerprint'=>$second['fingerprint']];
    rejected(fn()=>$journal->workerReport($request,'gen',1));
    $report('item_result',$start+['result'=>['state'=>'completed']]);
    $report('item_start',['itemId'=>$second['id'],'fingerprint'=>$second['fingerprint']]);
    // Crash recovery only after verified shutdown: completed evidence survives,
    // ambiguous active item is never reissued, untouched siblings remain eligible.
    $journal=null;$journal=new ZfsasCoordinatorState($root);
    $journal->stopped($token,2,2);
    check($journal->state['items'][$first['id']]['state']==='completed','Recovery lost success');
    check($journal->state['items'][$second['id']]['result']['recoveryRequired'],'Interrupted item replayed');
    $token=$journal->claim($task,2,2,'gen');$journal->started($task,$token,124,'124');$sequence=1;
    $grant=$report('item_chunk',[]);check(count($grant['items'])===49,'Recovery repeated finished or ambiguous items');
    $journal->cancel($run,3);
    rejected(fn()=>$report('item_start',['itemId'=>$grant['items'][0]['id'],'fingerprint'=>$grant['items'][0]['fingerprint']]));
    $journal->stopped($token,3,3);
    check($journal->state['runs'][$run]['state']==='canceled','Cancellation released authority incorrectly');
    $journal->prune(4000000);
    check(isset($journal->state['items'][$second['id']]), 'Retention pruned unresolved recovery evidence');
    echo "PASS: approved membership, bounded grant, start acknowledgment replay, serial item authority, committed results, crash recovery and cancellation fence\n";
} finally { $journal=null; foreach(glob($root.'/*')?:[] as $file)unlink($file);if(is_dir($root))rmdir($root); }

<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/coordinator-worker-client.php';
require_once __DIR__ . '/replication-plan.php';
require_once __DIR__ . '/send-helpers.php';
require_once __DIR__ . '/replication-pressure.php';
try {
    zfsas_coordinator_worker_report('progress',2,['phase'=>'replication_validation','message'=>'Revalidating captured replication identities.']);
    $path = $argv[1] ?? ''; $task = getenv('ZFSAS_TASK_ID');
    if ($path !== '/tmp/zfs-snapsync-coordinator/attempt-inputs/'.hash('sha256',(string)$task).'.replication.json' || is_link($path)) {
        throw new InvalidArgumentException('Invalid replication capture path.');
    }
    $input = json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if (($input['taskId'] ?? '') !== $task) { throw new InvalidArgumentException('Replication input belongs to another task.'); }
    $parameters = $input['parameters']; $phase = $parameters['phase'];
    try {
        if ($parameters['revision'] !== zfsas_config_revision('/boot/config/plugins/zfs.snapsync')) {
            throw new InvalidArgumentException('Configuration changed; review replication again.');
        }
        $result = zfsas_replication_revalidate($parameters);
        if ($result['outcome'] === 'success') {
            $complete = $result['inspection']['mode'] === 'already_received';
            if ($phase === 'replication_verify') {
                if (!$complete) { throw new InvalidArgumentException('Expected receiver checkpoint is absent; replication is not complete.'); }
                $result['message'] = 'Verified expected receiver snapshot and dataset GUIDs.';
            } elseif (in_array($phase,['replication_space','replication_transfer'],true)) {
                if (!$complete) { $result = zfsas_replication_space($parameters); }
                if (!$complete && $phase === 'replication_space' && ($result['outcome'] ?? '')==='validation_failure' && ($result['reason'] ?? '')==='space') {
                    $sequence=3;
                    $result=zfsas_replication_pressure_proposal($parameters,$result,$sequence);
                }
                if (!$complete && $phase === 'replication_transfer' && $result['outcome'] === 'success') {
                    $rate = $parameters['rateLimit'] ?? '0';
                    if (zfsas_send_normalize_rate_limit($rate) === null || ($rate !== '0' && !trim((string)shell_exec('command -v mbuffer 2>/dev/null')))) {
                        throw new InvalidArgumentException('Configured transfer rate requires a valid rate and installed mbuffer.');
                    }
                    // Ownership is rechecked immediately before the mutation. The
                    // pipeline uses one incremental target, no intermediates or -F.
                    zfsas_coordinator_worker_report('progress',3,['phase'=>'transfer','message'=>'Transferring the verified incremental snapshot.']);
                    $sequence = 4;
                    $request = $parameters['replication'];
                    $resumeToken = $parameters['inspection']['mode'] === 'resume' ? zfsas_replication_resume_token($parameters) : '';
                    $process = proc_open(['/bin/bash','-o','pipefail','-c',
                        'if [[ -n "$5" ]]; then zfs send -t "$5"; elif [[ -n "$1" ]]; then zfs send -i "$1" "$2"; else zfs send "$2"; fi | { if [[ "$4" == 0 ]]; then cat; else mbuffer -q -R "$4"; fi; } | zfs receive -s -u -- "$3"','snapsync-transfer',
                        $parameters['inspection']['base']['snapshot'] ?? '',$request['sourceSnapshot'],$request['destination'],$rate,$resumeToken],
                        [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']],$pipes);
                    if (!is_resource($process)) { throw new RuntimeException('Cannot launch replication pipeline.'); }
                    // Drain diagnostics without retaining unbounded output or any
                    // stream payload. The coordinator remains responsive separately.
                    $diagnostic = '';
                    while (!feof($pipes[2])) { $diagnostic = substr($diagnostic . fread($pipes[2],8192),-4096); }
                    fclose($pipes[2]); $code = proc_close($process);
                    if ($code !== 0) {
                        $result = ['outcome'=>'transient_failure','recoveryRequired'=>true,'message'=>'Replication pipeline failed; receiver recovery must be validated before another mutation.','exitCode'=>$code];
                    } else {
                        $result = zfsas_replication_revalidate($parameters);
                        if ($result['outcome'] === 'success' && $result['inspection']['mode'] !== 'already_received') {
                            throw new InvalidArgumentException('Pipeline exited successfully without the expected receiver checkpoint.');
                        }
                    }
                }
            } else { throw new InvalidArgumentException('Unknown native replication phase.'); }
        }
    } catch (InvalidArgumentException $error) { $result=['outcome'=>'validation_failure','message'=>$error->getMessage()]; }
      catch (RuntimeException $error) { $result=['outcome'=>'transient_failure','message'=>$error->getMessage()]; }
    zfsas_coordinator_worker_report('result',$sequence ?? 3,$result);
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n"); exit(1); }

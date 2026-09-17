<?php
/** Read-only preparation for the standalone replication pipeline. */
final class ZfsasReplicationInspection
{
    public static function validate(array $request): void
    {
        if (array_diff(array_keys($request), ['sourceSnapshot','sourceGuid','destination','destinationGuid','destinationParentGuid','createDestination','allowResume','transport'])
            || ($request['transport'] ?? 'local') !== 'local') {
            throw new InvalidArgumentException('This inspection phase requires a local receiver.');
        }
        foreach (['sourceSnapshot','destination'] as $field) {
            if (!is_string($request[$field] ?? null)) { throw new InvalidArgumentException('Missing replication target.'); }
        }
        $source = explode('@', $request['sourceSnapshot']);
        if (count($source) !== 2 || !preg_match('/^[A-Za-z0-9_.:+-]+$/D', $source[1])) { throw new InvalidArgumentException('Invalid source snapshot.'); }
        foreach ([$source[0], $request['destination']] as $dataset) {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:+-]*(?:\/[A-Za-z0-9_.:+-]+)*$/D', $dataset)) { throw new InvalidArgumentException('Invalid dataset.'); }
        }
        if ($source[0] === $request['destination'] || str_starts_with($request['destination'], $source[0] . '/')
            || str_starts_with($source[0], $request['destination'] . '/')) {
            throw new InvalidArgumentException('Source and destination trees overlap.');
        }
        foreach (['sourceGuid','destinationGuid','destinationParentGuid'] as $field) {
            if (!isset($request[$field]) && $field !== 'sourceGuid') { continue; }
            if (!is_string($request[$field] ?? null) || !preg_match('/^[0-9]{1,20}$/D', $request[$field])) { throw new InvalidArgumentException('Invalid captured GUID.'); }
        }
    }

    private static function receiverAbsent(array $request, callable $read): bool
    {
        if (empty($request['createDestination'])) { return false; }
        if ($request['createDestination'] !== true || isset($request['destinationGuid']) || !isset($request['destinationParentGuid'])
            || !str_contains($request['destination'],'/')) { throw new InvalidArgumentException('New receiver requires an existing, captured parent dataset.'); }
        $parent = substr($request['destination'],0,strrpos($request['destination'],'/'));
        if (self::guid($read,$parent) !== $request['destinationParentGuid']) { throw new InvalidArgumentException('Receiver parent identity changed.'); }
        $text = trim($read(['list','-H','-o','name','-r','-d','1','--',$parent]));
        $names = $text === '' ? [] : explode("\n",$text);
        if (!in_array($parent,$names,true) || count($names)>50000) { throw new RuntimeException('Incomplete receiver parent inventory.'); }
        foreach ($names as $name) {
            if ($name !== $parent && (!str_starts_with($name,$parent.'/') || str_contains(substr($name,strlen($parent)+1),'/'))) {
                throw new RuntimeException('Unexpected receiver parent inventory.');
            }
        }
        return !in_array($request['destination'],$names,true);
    }

    /** Each command is bounded and remains in the granted worker process group. */
    public static function command(array $arguments): string
    {
        $process = proc_open(array_merge(['/usr/bin/timeout','--foreground','--signal=TERM','--kill-after=2','15','zfs'], $arguments),
            [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Unable to start ZFS inspection.'); }
        foreach ($pipes as $pipe) { stream_set_blocking($pipe, false); }
        $output = ''; $error = ''; $exit = null;
        try {
            while (true) {
                $output .= stream_get_contents($pipes[1]); $error .= stream_get_contents($pipes[2]);
                if (strlen($output) > 8 * 1048576 || strlen($error) > 65536) {
                    // timeout's TERM handler forwards the signal to its command;
                    // the executor retains group ownership until all children stop.
                    proc_terminate($process, 15);
                    throw new RuntimeException('Inspection output exceeds the bounded inventory limit.');
                }
                $status = proc_get_status($process);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                $read = [$pipes[1],$pipes[2]]; $write = $except = null;
                @stream_select($read, $write, $except, 0, 100000);
            }
            $output .= stream_get_contents($pipes[1]); $error .= stream_get_contents($pipes[2]);
            if (strlen($output) > 8 * 1048576 || strlen($error) > 65536) { throw new RuntimeException('Inspection output exceeds the bounded inventory limit.'); }
            if ($exit !== 0) { throw new RuntimeException(in_array($exit,[124,137],true) ? 'ZFS inspection timed out.' : 'ZFS inspection failed; receiver or dataset metadata is unavailable.'); }
            return in_array('-nvt',$arguments,true) ? $output . $error : $output;
        } finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_close($process); }
    }

    private static function guid(callable $read, string $name): string
    {
        $value = trim($read(['get','-H','-p','-o','value','guid','--',$name]));
        if (!preg_match('/^[0-9]{1,20}$/D', $value)) { throw new RuntimeException('Incomplete dataset identity metadata.'); }
        return $value;
    }

    private static function inventory(callable $read, string $dataset): array
    {
        $text = $read(['list','-H','-p','-t','snapshot','-o','name,guid,createtxg','-d','1','--',$dataset]);
        $rows = [];
        foreach (explode("\n", rtrim($text, "\n")) as $line) {
            if ($line === '') { continue; }
            $fields = explode("\t",$line);
            if (count($fields) !== 3 || !str_starts_with($fields[0], $dataset . '@')
                || !preg_match('/^[A-Za-z0-9_.:+-]+$/D',substr($fields[0],strlen($dataset)+1))
                || !preg_match('/^[0-9]{1,20}$/D',$fields[1]) || !preg_match('/^[0-9]{1,20}$/D',$fields[2])
                || isset($rows[$fields[0]])) { throw new RuntimeException('Incomplete or conflicting snapshot inventory.'); }
            $rows[$fields[0]] = ['snapshot'=>$fields[0], 'guid'=>$fields[1], 'txg'=>$fields[2]];
            if (count($rows) > 50000) { throw new RuntimeException('Snapshot inventory exceeds the preparation limit.'); }
        }
        return $rows;
    }

    private static function tokenGuid(string $value): string
    {
        if (preg_match('/^[0-9]{1,20}$/D',$value)) { return ltrim($value,'0') ?: '0'; }
        if (!preg_match('/^0x[0-9a-fA-F]{1,16}$/D',$value)) { throw new InvalidArgumentException('Incomplete resume GUID metadata.'); }
        $decimal = '0';
        foreach (str_split(substr($value,2)) as $digit) {
            $carry = hexdec($digit); $next = '';
            for ($i=strlen($decimal)-1;$i>=0;$i--) { $n=(int)$decimal[$i]*16+$carry; $next=($n%10).$next; $carry=intdiv($n,10); }
            $decimal=($carry ? (string)$carry : '').$next;
        }
        return ltrim($decimal,'0') ?: '0';
    }

    private static function inspectResume(array $request, callable $read, string $token, string $sourceGuid, string $destinationGuid): array
    {
        if (($request['allowResume'] ?? false) !== true) {
            return ['outcome'=>'validation_failure','recoveryRequired'=>true,
                'message'=>'Receiver has an interrupted transfer. Explicit validated Retry is required.',
                'inspection'=>['sourceDatasetGuid'=>$sourceGuid,'destinationDatasetGuid'=>$destinationGuid,'resumeRequired'=>true]];
        }
        $metadata = $read(['send','-nvt',$token]); $fields = [];
        foreach (explode("\n",$metadata) as $line) {
            if (preg_match('/^\s*(toname|toguid|fromguid)\s*=\s*(\S+)\s*$/D',$line,$match)) {
                if (isset($fields[$match[1]])) { throw new InvalidArgumentException('Conflicting resume metadata.'); }
                $fields[$match[1]]=$match[2];
            }
        }
        if (($fields['toname'] ?? '') !== $request['sourceSnapshot'] || self::tokenGuid($fields['toguid'] ?? '') !== $request['sourceGuid']) {
            throw new InvalidArgumentException('Resume token targets a different snapshot identity.');
        }
        $source=explode('@',$request['sourceSnapshot'])[0]; $destination=$request['destination'];
        if (self::guid($read,$request['sourceSnapshot']) !== $request['sourceGuid']) { throw new InvalidArgumentException('Resume source snapshot identity changed.'); }
        $reference = static fn($role,$dataset,$datasetGuid,$snapshot,$guid)=>compact('role','dataset','datasetGuid','snapshot','guid')+['endpoint'=>'local'];
        $references=[$reference('source',$source,$sourceGuid,$request['sourceSnapshot'],$request['sourceGuid'])];
        $from=self::tokenGuid($fields['fromguid'] ?? '0'); $base=null;
        if ($from !== '0') {
            $sources=self::inventory($read,$source); $destinations=self::inventory($read,$destination);
            foreach ($sources as $row) {
                if ($row['guid'] !== $from) { continue; }
                $target=$destination.'@'.explode('@',$row['snapshot'])[1];
                if (($destinations[$target]['guid'] ?? '') !== $from) { continue; }
                $base=$row+['destinationSnapshot'=>$target]; break;
            }
            if (!$base || self::guid($read,$base['snapshot']) !== $from || self::guid($read,$base['destinationSnapshot']) !== $from) {
                throw new InvalidArgumentException('Resume base is absent or its source/receiver GUID differs.');
            }
            $references[]=$reference('base',$source,$sourceGuid,$base['snapshot'],$from);
            $references[]=$reference('resume',$destination,$destinationGuid,$base['destinationSnapshot'],$from);
        }
        if (self::guid($read,$source) !== $sourceGuid || self::guid($read,$destination) !== $destinationGuid
            || trim($read(['get','-H','-o','value','receive_resume_token','--',$destination])) !== $token) {
            throw new InvalidArgumentException('Resume identities changed during validation.');
        }
        return ['outcome'=>'success','inspection'=>['mode'=>'resume','sourceDatasetGuid'=>$sourceGuid,'destinationDatasetGuid'=>$destinationGuid,
            'sourceSnapshot'=>$request['sourceSnapshot'],'sourceGuid'=>$request['sourceGuid'],'base'=>$base,
            'destinationSnapshot'=>$destination.'@'.explode('@',$request['sourceSnapshot'])[1],
            'resumeRequired'=>true,'resumeHash'=>hash('sha256',$token),'references'=>$references]];
    }

    public static function inspect(array $request, ?callable $read = null): array
    {
        self::validate($request); $read ??= [self::class,'command'];
        $source = explode('@',$request['sourceSnapshot'])[0]; $destination = $request['destination'];
        $sourceGuid = self::guid($read,$source);
        if (self::receiverAbsent($request,$read)) {
            if (self::guid($read,$request['sourceSnapshot']) !== $request['sourceGuid']) { throw new InvalidArgumentException('Selected source snapshot identity changed.'); }
            if (!self::receiverAbsent($request,$read) || self::guid($read,$source) !== $sourceGuid) { throw new InvalidArgumentException('Replication identities changed during new receiver inspection.'); }
            return ['outcome'=>'success','inspection'=>['mode'=>'full','sourceDatasetGuid'=>$sourceGuid,'destinationDatasetGuid'=>null,
                'sourceSnapshot'=>$request['sourceSnapshot'],'sourceGuid'=>$request['sourceGuid'],'destinationSnapshot'=>$destination.'@'.explode('@',$request['sourceSnapshot'])[1],
                'base'=>null,'sourceCount'=>1,'destinationCount'=>0,'resumeRequired'=>false,
                'references'=>[['role'=>'source','endpoint'=>'local','dataset'=>$source,'datasetGuid'=>$sourceGuid,'snapshot'=>$request['sourceSnapshot'],'guid'=>$request['sourceGuid']]]]];
        }
        $destinationGuid = self::guid($read,$destination);
        if ($sourceGuid === $destinationGuid) { throw new InvalidArgumentException('Source and receiver identify the same dataset.'); }
        if (isset($request['destinationGuid']) && $request['destinationGuid'] !== $destinationGuid) { throw new InvalidArgumentException('Destination identity changed; review replication again.'); }
        $resume = trim($read(['get','-H','-o','value','receive_resume_token','--',$destination]));
        if ($resume === '') { throw new RuntimeException('Incomplete receiver resume metadata.'); }
        if ($resume !== '-') {
            return self::inspectResume($request,$read,$resume,$sourceGuid,$destinationGuid);
        }
        $sources = self::inventory($read,$source); $destinations = self::inventory($read,$destination);
        $selected = $sources[$request['sourceSnapshot']] ?? null;
        if (!$selected || $selected['guid'] !== $request['sourceGuid']) { throw new InvalidArgumentException('Selected source snapshot is missing or its GUID changed.'); }
        $bases = []; $conflicts = [];
        foreach ($sources as $snapshot => $row) {
            $target = $destination . '@' . substr($snapshot,strlen($source)+1);
            if (!isset($destinations[$target])) { continue; }
            if ($destinations[$target]['guid'] !== $row['guid']) { $conflicts[] = $target; continue; }
            if (self::compareDecimal($row['txg'],$selected['txg']) < 0) { $bases[] = $row + ['destinationSnapshot'=>$target]; }
        }
        usort($bases,fn($a,$b)=>self::compareDecimal($b['txg'],$a['txg']));
        $base = $bases[0] ?? null;
        $reference = static fn($role,$dataset,$datasetGuid,$snapshot,$guid) => compact('role','dataset','datasetGuid','snapshot','guid') + ['endpoint'=>'local'];
        $references = [$reference('source',$source,$sourceGuid,$selected['snapshot'],$selected['guid'])];
        if ($base) {
            $references[] = $reference('base',$source,$sourceGuid,$base['snapshot'],$base['guid']);
            $references[] = $reference('checkpoint',$destination,$destinationGuid,$base['destinationSnapshot'],$base['guid']);
        }
        // Receiver TXGs are comparable only within the receiver pool. Never compare
        // them with source TXGs or infer that rollback is safe from name ordering.
        $target = $destination . '@' . substr($selected['snapshot'],strlen($source)+1);
        $completed = $destinations[$target] ?? null;
        if ($completed && $completed['guid'] !== $selected['guid']) {
            throw new InvalidArgumentException('Receiver snapshot name has a different GUID; automatic replacement is forbidden.');
        }
        if (!empty($request['createDestination']) && !$completed) { throw new InvalidArgumentException('New receiver path is now occupied; existing datasets will not be replaced.'); }
        $latest = null;
        foreach ($destinations as $row) {
            if ($latest === null || self::compareDecimal($row['txg'],$latest['txg']) > 0) { $latest = $row; }
        }
        if ($completed) {
            $mode = 'already_received';
            $references[] = $reference('checkpoint',$destination,$destinationGuid,$target,$completed['guid']);
        } elseif ($base) {
            $checkpoint = $destinations[$base['destinationSnapshot']];
            foreach ($destinations as $row) {
                if ($row['snapshot'] !== $checkpoint['snapshot']
                    && self::compareDecimal($row['txg'],$checkpoint['txg']) >= 0) {
                    throw new InvalidArgumentException('Receiver has snapshots at or after the incremental base; review divergence without forced rollback.');
                }
            }
            $mode = 'incremental';
        } elseif ($destinations) {
            throw new InvalidArgumentException('Receiver has snapshots but no verified common base; automatic reseeding is forbidden.');
        } else {
            // Empty snapshot inventory does not prove an existing filesystem is
            // empty. Full receive needs a separate, explicit receiver admission.
            $mode = 'full_requires_receiver_approval';
        }
        // Identity is checked again after inventory to reject replacement races.
        if (self::guid($read,$source) !== $sourceGuid || self::guid($read,$destination) !== $destinationGuid
            || self::guid($read,$selected['snapshot']) !== $selected['guid']) { throw new InvalidArgumentException('Replication identities changed during inspection.'); }
        if ($base && (self::guid($read,$base['snapshot']) !== $base['guid']
            || self::guid($read,$base['destinationSnapshot']) !== $base['guid'])) {
            throw new InvalidArgumentException('Incremental base identities changed during inspection.');
        }
        if ($completed && self::guid($read,$target) !== $selected['guid']) {
            throw new InvalidArgumentException('Completed receiver snapshot changed during inspection.');
        }
        if (self::inventory($read,$destination) !== $destinations) {
            throw new InvalidArgumentException('Receiver snapshot inventory changed during inspection; replan before mutation.');
        }
        if (trim($read(['get','-H','-o','value','receive_resume_token','--',$destination])) !== '-') {
            throw new InvalidArgumentException('Receiver resume state changed during inspection; review the interrupted transfer.');
        }
        return ['outcome'=>'success','message'=>'Read-only replication inspection completed; transfer admission still requires a validated plan.',
            'inspection'=>['sourceDatasetGuid'=>$sourceGuid,'destinationDatasetGuid'=>$destinationGuid,
                'sourceSnapshot'=>$selected['snapshot'],'sourceGuid'=>$selected['guid'], 'base'=>$base,
                'mode'=>$mode,'destinationSnapshot'=>$target,'latestDestinationSnapshot'=>$latest,
                'sourceCount'=>count($sources),'destinationCount'=>count($destinations),
                'conflictCount'=>count($conflicts),'resumeRequired'=>false,'references'=>$references]];
    }

    private static function compareDecimal(string $a, string $b): int
    {
        $a=ltrim($a,'0'); $b=ltrim($b,'0');
        return strlen($a) <=> strlen($b) ?: strcmp($a,$b);
    }
}

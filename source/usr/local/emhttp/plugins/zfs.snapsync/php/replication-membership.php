<?php
require_once __DIR__.'/replication-inspection.php';

/** Read-only, bounded membership capture for future scheduled snapshot tasks. */
function zfsas_replication_membership(array $job, ?callable $read=null): array
{
    if (!is_string($job['source'] ?? null) || !is_string($job['destination'] ?? null)
        || !in_array($job['children'] ?? '0',['0','1'],true) || ($job['transport'] ?? 'local') !== 'local') {
        throw new InvalidArgumentException('Invalid local replication membership request.');
    }
    $source=$job['source'];$destination=$job['destination'];$recursive=($job['children'] ?? '0')==='1';
    ZfsasReplicationInspection::validate(['sourceSnapshot'=>$source.'@probe','sourceGuid'=>'0','destination'=>$destination]);
    $read ??= [ZfsasReplicationInspection::class,'command'];
    $query=array_merge(['list','-H','-p','-o','name,guid','-t','filesystem,volume'],$recursive ? ['-r'] : ['-d','0'],['--',$source]);
    $parse=static function(string $text)use($source,$destination,$recursive):array {
        if (strlen($text)>8*1048576) { throw new RuntimeException('Source membership exceeds the output limit.'); }
        $rows=[];
        foreach (explode("\n",rtrim($text,"\n")) as $line) {
            $fields=explode("\t",$line);
            if (count($fields)!==2 || ($fields[0]!==$source && (!$recursive || !str_starts_with($fields[0],$source.'/')))
                || !preg_match('/^[0-9]{1,20}$/D',$fields[1]) || isset($rows[$fields[0]])) {
                throw new RuntimeException('Incomplete or conflicting replication membership.');
            }
            $target=$destination.substr($fields[0],strlen($source));
            ZfsasReplicationInspection::validate(['sourceSnapshot'=>$fields[0].'@probe','sourceGuid'=>'0','destination'=>$target]);
            $rows[$fields[0]]=['source'=>$fields[0],'sourceDatasetGuid'=>$fields[1],'destination'=>$target];
            if (count($rows)>10000) { throw new InvalidArgumentException('Replication membership exceeds 10,000 datasets.'); }
        }
        if (!isset($rows[$source])) { throw new RuntimeException('Source root is absent from membership.'); }
        ksort($rows,SORT_STRING);
        foreach ($rows as $dataset=>$row) {
            if ($dataset!==$source && !isset($rows[substr($dataset,0,strrpos($dataset,'/'))])) {
                throw new RuntimeException('Recursive membership lacks an intermediate dataset.');
            }
        }
        return $rows;
    };
    $members=$parse($read($query));
    // A changed enumeration cannot silently alter a reviewed snapshot set. The
    // future mutation task must still check each captured GUID under its gate.
    if ($parse($read($query))!==$members) { throw new InvalidArgumentException('Dataset membership changed during capture; prepare again.'); }
    return array_values($members);
}

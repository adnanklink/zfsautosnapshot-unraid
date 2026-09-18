<?php
require_once __DIR__.'/send-schedule.php';

/** Cache occurrences; no inventories or job-directory scans on the idle path. */
function zfsas_coordinator_send_tick(ZfsasCoordinatorState $journal, array $config, int $wall, array &$calendars): ?int
{
    $key=$config['revision'].'|'.$config['timezone']->getName();$next=null;
    if (($calendars['key'] ?? '')!==$key) { $calendars=['key'=>$key,'jobs'=>[]]; }
    foreach (zfsas_send_parse_jobs($config['send']['SEND_JOBS'] ?? '') as $job) {
        if (($job['transport'] ?? 'local')!=='local') { continue; }
        $id=$job['id'];$calendar=$calendars['jobs'][$id] ?? [];
        if (!$calendar || $wall<($calendar['lastWall'] ?? 0) || $wall>=($calendar['next'] ?? PHP_INT_MAX)) {
            $spec=zfsas_send_schedule_spec($config['send'],$job);
            $calendar=['due'=>ZfsasSchedule::occurrence($spec,$wall,$config['timezone'],false),
                'next'=>ZfsasSchedule::occurrence($spec,$wall,$config['timezone'],true)];
        }
        $calendar['lastWall']=$wall;$calendars['jobs'][$id]=$calendar;
        if ($calendar['next']!==null) { $next=min($next ?? PHP_INT_MAX,$calendar['next']); }
        if ($calendar['due']===null || ($journal->state['schedules'][$id]['accepted'] ?? PHP_INT_MIN)>=$calendar['due']
            || is_file(zfsas_ops_control_path('paused',$id))) { continue; }
        zfsas_coordinator_submit_schedule($journal,$job,$config,$calendar['due']);
    }
    return $next;
}

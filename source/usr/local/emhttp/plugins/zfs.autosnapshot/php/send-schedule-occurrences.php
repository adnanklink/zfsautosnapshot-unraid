<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/send-schedule.php';
try {
    $specs=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
    if (!is_array($specs)) { throw new InvalidArgumentException('Invalid send schedule specifications.'); }
    $now=(int)($argv[1] ?? time()); $zone=ZfsasSchedule::hostTimezone();
    foreach ($specs as $id=>$spec) {
        if (!preg_match('/^[a-f0-9]{12}$/D',(string)$id)) { throw new InvalidArgumentException('Invalid schedule ID.'); }
        $due=ZfsasSchedule::occurrence($spec,$now,$zone,false);
        echo $id, '|', $due === null ? 'not_due' : $due, "\n";
    }
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n"); exit(1); }

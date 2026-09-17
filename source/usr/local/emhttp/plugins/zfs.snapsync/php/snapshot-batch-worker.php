<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Compatibility entry point retained for old launchers. A manifest and an old
// worker grant cannot recreate execution authority after the ownership handoff.
fwrite(STDERR, "Legacy batch execution is disabled. Review unfinished selections in Snapshot Manager and submit them through the coordinator.\n");
exit(1);

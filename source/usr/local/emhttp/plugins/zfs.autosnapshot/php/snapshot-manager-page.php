<?php
if (!defined('ZFSAS_WORKSPACE')) { $_GET['section'] = 'snapshots'; require __DIR__ . '/workspace.php'; return; }
require __DIR__ . '/views/snapshots.php';

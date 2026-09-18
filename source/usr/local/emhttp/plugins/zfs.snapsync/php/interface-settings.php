<?php
require_once __DIR__ . '/config-service.php';
function zfsas_interface_read($dir)
{
    $raw = (string) @file_get_contents($dir . '/show-tab');
    return ['enabled' => trim($raw) === '1', 'revision' => hash('sha256', $raw)];
}
function zfsas_interface_save($dir, $enabled, $revision)
{
    $lock = zfsas_config_lock($dir);
    if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Unable to lock interface settings.'); }
    try {
        $current = zfsas_interface_read($dir);
        if (!is_string($revision) || !hash_equals($current['revision'], $revision)) {
            throw new RuntimeException('Interface settings changed. Reload this page before saving.');
        }
        if ($current['enabled'] === $enabled) { return $current; }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { throw new RuntimeException('Unable to create configuration directory.'); }
        $path = $dir . '/show-tab';
        $tmp = @tempnam($dir, '.show-tab-');
        if ($tmp === false) { throw new RuntimeException('Unable to prepare interface settings.'); }
        try {
            $data = $enabled ? "1\n" : "0\n";
            if (file_put_contents($tmp, $data) !== strlen($data) || !chmod($tmp, 0644) || !rename($tmp, $path)) {
                throw new RuntimeException('Unable to save interface settings.');
            }
        } finally { if (is_file($tmp)) { unlink($tmp); } }
        return zfsas_interface_read($dir);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

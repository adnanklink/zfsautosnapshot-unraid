<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.autosnapshot/php/snapshot-manager-helpers.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$lines = [];
for ($i = 0; $i < 10000; $i++) { $lines[] = 'tank/data@auto-' . sprintf('%05d', $i) . "\t" . (2000000000 + intdiv($i, 2)) . "\t" . ($i % 3 ? 0 : 1024) . "\t" . ($i % 7 ? 0 : 4096) . "\t0\t" . (100000 + $i) . "\t" . (20000 + $i) . "\t-"; }
$start = microtime(true);
$rows = zfsas_sm_inventory_rows('tank/data', $lines, []);
foreach ($rows as &$row) { $row += ['origin' => 'auto', 'pendingAction' => '', 'pendingDelete' => false, 'sendProtected' => false, 'activeTransfer' => false]; } unset($row);
$page = zfsas_sm_page($rows, []);
check($page['total'] === 10000 && count($page['snapshots']) === 100, '10k pagination failed');
check($page['snapshots'][0]['snapshotName'] === 'auto-09998', 'Stable identity tie break failed');
check(zfsas_sm_page($rows, ['page_size' => 250, 'page' => 40])['pages'] === 40, '250-page sizing failed');
check(count(zfsas_sm_filter_rows($rows, ['used_max' => '0'])) === 6666, 'Used range filter failed');
check(count(zfsas_sm_filter_rows($rows, ['used_max' => '0', 'written_min' => '1'])) > 0, 'Used and Written were conflated');
check(count(zfsas_sm_filter_rows($rows, ['search' => 'auto-0999'])) === 10, 'Name search failed');
check(count(zfsas_sm_filter_rows($rows, ['prefix' => 'auto-000'])) === 100, 'Prefix filter failed');
check(count(zfsas_sm_filter_rows($rows, ['origin' => 'other'])) === 0, 'Origin filter failed');
check(count(zfsas_sm_filter_rows($rows, ['age_min' => '1'], 2000001000)) === 0, 'Age filter failed');
$small = array_slice($rows, 0, 8);
foreach ($small as $i => &$row) { $row['createdEpoch'] = 1000 + $i; $row['writtenBytes'] = 0; } unset($row);
$small[0]['writtenBytes'] = 4096; $small[0]['usedBytes'] = 0;
$small[1]['metadataComplete'] = false; $small[2]['held'] = true;
$small[3]['clones'] = ['tank/clone']; $small[4]['sendProtected'] = true; $small[5]['activeTransfer'] = true;
$plan = zfsas_sm_cleanup_plan($small, 'zero_change', zfsas_auto_defaults(), 2000);
$candidates = array_values(array_filter($plan, fn($item) => $item['candidate']));
check(count($candidates) === 1 && $candidates[0]['snapshot'] === $small[6]['snapshot'], 'Cleanup did not protect metadata, holds, clones, bases, transfers, newest or nonzero Written');
$small[6]['pendingDelete'] = true;
check(!array_filter(zfsas_sm_cleanup_plan($small, 'zero_change', zfsas_auto_defaults(), 2000), fn($item) => $item['candidate']), 'Pending delete included');
check(zfsas_sm_retention_week(strtotime('2021-01-01 UTC')) === '2021-00', 'Retention calendar week mismatch');
$batch = zfsas_sm_new_batch('tank/data', 'hold');
$batch['items'] = [['snapshot' => $rows[0]['snapshot'], 'guid' => 'different', 'identity' => $rows[0]['snapshot'] . '#different', 'state' => 'queued']];
zfsas_sm_batch_review($batch, $rows);
check($batch['items'][0]['state'] === 'skipped', 'Changed GUID was eligible');
check(zfsas_sm_exclusion('release', array_merge($rows[0], ['held' => true, 'pluginHeld' => false])) !== '', 'External holds could be released');
check(zfsas_sm_exclusion('hold', array_merge($rows[0], ['held' => true, 'pluginHeld' => false])) === '', 'External hold incorrectly prevents adding a plugin hold');
echo 'PASS: 10,000 snapshots, stable sort, filters, pagination, cleanup protections, changed GUIDs (' . round(microtime(true) - $start, 3) . "s)\n";

<?php
// One-off: publishes the current on-demand request count to requests.json in the GitHub repo.
require __DIR__ . '/core.php';
$rep = with_state(fn(array &$s) => 'pending ' . (int)($s['write_pending'] ?? 0) . ' github ' . json_encode(write_flag($s)), 60);
file_put_contents(__DIR__ . '/flag-report.txt', $rep . "\n");

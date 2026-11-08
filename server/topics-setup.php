<?php
// installs the refreshed panel and adds a GH_TOKEN placeholder to config.php
declare(strict_types=1);
$cfgf = __DIR__ . '/config.php';
$cfg = file_get_contents($cfgf);
if (!str_contains($cfg, 'GH_TOKEN')) file_put_contents($cfgf, rtrim($cfg) . "\n// GitHub fine-grained token for mnazif1992/nikmind-autoblog (Contents: Read and write) — lets the panel edit the keyword list\ndefine('GH_TOKEN', 'PASTE_GITHUB_TOKEN_HERE');\n");
require __DIR__ . '/core.php';
$dir = PUB . '/' . PANEL_DIR;
copy(__DIR__ . '/panel/app.html', "$dir/app/index.html");
copy(__DIR__ . '/panel/api.php', "$dir/app/api.php");
require __DIR__ . '/topics.php';
$v = topics_view();
file_put_contents(__DIR__ . '/topics-report.txt', 'queued ' . count($v['queued']) . ' done ' . count($v['done']) . ' can_edit ' . json_encode($v['can_edit']) . "\n");

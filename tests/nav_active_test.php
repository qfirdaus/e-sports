<?php
// CLI helper to test nav active detection logic.
// Usage: php nav_active_test.php /pages/contingent.php

$path = $argv[1] ?? '/index.php';

// simulate typical server variables used by config.php
$_SERVER['REQUEST_URI'] = $path;
$_SERVER['SCRIPT_NAME']  = $path;
$_SERVER['PHP_SELF']     = $path;
$_SERVER['QUERY_STRING'] = '';

require_once __DIR__ . '/../config.php';

// Output JSON with active flags for manual inspection
$out = [
    'REQUEST_URI' => $_SERVER['REQUEST_URI'],
    'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'],
    'PHP_SELF' => $_SERVER['PHP_SELF'],
    'nav_items' => $nav_items ?? null,
    'nav_sections' => $nav_sections ?? null,
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

// Also print a short human summary
foreach (($nav_items ?? []) as $ni) {
    if (!empty($ni['active'])) echo "[ITEM ACTIVE] " . ($ni['title'] ?? $ni['url']) . "\n";
}
if (!empty($nav_sections) && is_array($nav_sections)) {
    foreach ($nav_sections as $sec) {
        foreach (($sec['children'] ?? []) as $ch) {
            if (!empty($ch['active'])) echo "[SECTION ACTIVE] " . ($sec['title'] ?? '(sec)') . " -> " . ($ch['title'] ?? $ch['url']) . "\n";
        }
    }
}

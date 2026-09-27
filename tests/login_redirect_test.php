<?php
// Simple test to simulate login redirect logic used in auth/login.php and auth/ajax-login.php
require_once __DIR__ . '/../../config.php';

$roles = ['ADMIN','ORGANIZER','JUDGE','CONTINGENT','VIEWER','UNKNOWN'];

function computeRedirect($providedReturn, $role) {
    // Simulate the logic: if return provided, use it; otherwise default to dashboard for all roles
    $returnUrl = $providedReturn ?? null;
    if (!$returnUrl) {
        $returnUrl = url('pages/dashboard.php');
    }
    return $returnUrl;
}

foreach ($roles as $r) {
    $url = computeRedirect(null, $r);
    echo "$r -> $url\n";
}

// Also test when a return param is provided
$custom = 'pages/results.php';
echo "\nWith return provided ($custom):\n";
foreach ($roles as $r) {
    $url = computeRedirect($custom, $r);
    echo "$r -> $url\n";
}

?>
<?php
/**
 * Menu Access Upsert Script
 * Reads app/config/menu_access.json and upserts page access rules.
 *
 * Usage (web): /database/update_menu_access.php?key=update_menu_access_2026
 * Usage (CLI): php app/database/update_menu_access.php
 */

$installKey = 'update_menu_access_2026';
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    if (!isset($_GET['key']) || $_GET['key'] !== $installKey) {
        die('Invalid installation key. Add ?key=update_menu_access_2026 to the URL');
    }
    header('Content-Type: text/html; charset=utf-8');
}

require_once __DIR__ . '/../config/database.php';

$configPath = __DIR__ . '/../config/menu_access.json';
if (!file_exists($configPath)) {
    die('Config file not found: ' . $configPath);
}

$configRaw = file_get_contents($configPath);
$config = json_decode($configRaw, true);
if ($config === null && json_last_error() !== JSON_ERROR_NONE) {
    die('Invalid JSON: ' . json_last_error_msg());
}

$pages = $config['pages'] ?? [];
$publicPages = $config['public_pages'] ?? [];
$ensureAdmin = !empty($config['ensure_admin']);

if (!is_array($pages) || empty($pages)) {
    die('No pages defined in menu_access.json');
}

$publicMap = [];
foreach ($publicPages as $pagePath) {
    $publicMap[$pagePath] = true;
}

try {
    $db = getDB();

    $roleStmt = $db->query("SELECT id, role_code FROM roles");
    $roles = $roleStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $upsertPageStmt = $db->prepare("
        INSERT INTO page_access_rules (page_path, is_public, requires_auth, created_by)
        VALUES (:page_path, :is_public, :requires_auth, NULL)
        ON DUPLICATE KEY UPDATE
            is_public = VALUES(is_public),
            requires_auth = VALUES(requires_auth)
    ");

    $linkStmt = $db->prepare("
        INSERT INTO page_role_access (page_rule_id, role_id, created_by)
        SELECT par.id, :role_id, NULL
        FROM page_access_rules par
        WHERE par.page_path = :page_path
        AND NOT EXISTS (
            SELECT 1 FROM page_role_access pra
            WHERE pra.page_rule_id = par.id AND pra.role_id = :role_id
        )
    ");

    $pageCount = 0;
    $linkCount = 0;
    $errors = [];

    foreach ($pages as $pagePath => $roleList) {
        if (!is_array($roleList)) {
            $errors[] = "Invalid role list for {$pagePath}";
            continue;
        }

        $roleList = array_values(array_unique($roleList));
        if ($ensureAdmin && !in_array('ADMIN', $roleList, true)) {
            $roleList[] = 'ADMIN';
        }

        $isPublic = isset($publicMap[$pagePath]) ? 1 : 0;
        $requiresAuth = $isPublic ? 0 : 1;

        $upsertPageStmt->execute([
            ':page_path' => $pagePath,
            ':is_public' => $isPublic,
            ':requires_auth' => $requiresAuth
        ]);
        $pageCount++;

        foreach ($roleList as $roleCode) {
            $roleId = $roles[$roleCode] ?? null;
            if (!$roleId) {
                $errors[] = "Role not found: {$roleCode} (page: {$pagePath})";
                continue;
            }

            $linkStmt->execute([
                ':page_path' => $pagePath,
                ':role_id' => $roleId
            ]);
            $linkCount += $linkStmt->rowCount();
        }
    }

    if ($isCli) {
        echo "Pages processed: {$pageCount}\n";
        echo "Links inserted: {$linkCount}\n";
        if (!empty($errors)) {
            echo "Errors:\n- " . implode("\n- ", $errors) . "\n";
        }
    } else {
        echo "<h3>Menu Access Update</h3>";
        echo "<p>Pages processed: <strong>{$pageCount}</strong></p>";
        echo "<p>Links inserted: <strong>{$linkCount}</strong></p>";
        if (!empty($errors)) {
            echo "<h5>Errors</h5><ul>";
            foreach ($errors as $error) {
                echo "<li>" . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "</li>";
            }
            echo "</ul>";
        }
    }
} catch (PDOException $e) {
    $message = 'Database error: ' . $e->getMessage();
    if ($isCli) {
        fwrite(STDERR, $message . "\n");
    } else {
        echo "<p style='color:red;'>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</p>";
    }
}

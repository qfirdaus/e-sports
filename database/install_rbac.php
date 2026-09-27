<?php
/**
 * RBAC Migration Installation Script
 * Run this script to set up dynamic RBAC tables
 * 
 * Usage: http://localhost/sam2026/database/install_rbac.php?key=install_rbac_2026
 */

$installKey = 'install_rbac_2026';

if (!isset($_GET['key']) || $_GET['key'] !== $installKey) {
    die('Invalid installation key. Add ?key=install_rbac_2026 to the URL');
}

require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RBAC Migration - SAM 2026</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">RBAC Migration - Dynamic Access Control</h4>
                    </div>
                    <div class="card-body">
                        <?php
                        try {
                            $db = getDB();
                            
                            echo '<h5>Starting RBAC migration...</h5>';
                            echo '<ul class="list-group mb-3">';
                            
                            // Read migration SQL file
                            $sqlFile = __DIR__ . '/rbac_migration.sql';
                            if (!file_exists($sqlFile)) {
                                throw new Exception("Migration file not found: $sqlFile");
                            }
                            
                            $sql = file_get_contents($sqlFile);
                            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
                            
                            // Split SQL into individual statements
                            $statements = array_filter(
                                array_map('trim', explode(';', $sql)),
                                function($stmt) {
                                    return !empty($stmt);
                                }
                            );
                            
                            $successCount = 0;
                            $errorCount = 0;
                            
                            foreach ($statements as $statement) {
                                if (empty(trim($statement))) continue;
                                
                                try {
                                    $db->exec($statement);
                                    $successCount++;
                                    echo '<li class="list-group-item list-group-item-success">✓ Successful: ' . 
                                         htmlspecialchars(substr($statement, 0, 60)) . '...</li>';
                                } catch (PDOException $e) {
                                    // Check if error is due to table already existing
                                    if (strpos($e->getMessage(), 'already exists') !== false) {
                                        echo '<li class="list-group-item list-group-item-warning">⚠ Already exists: ' . 
                                             htmlspecialchars(substr($statement, 0, 60)) . '...</li>';
                                    } else {
                                        $errorCount++;
                                        echo '<li class="list-group-item list-group-item-danger">✗ Errors: ' . 
                                             htmlspecialchars($e->getMessage()) . '</li>';
                                    }
                                }
                            }
                            
                            echo '</ul>';
                            
                            echo '<div class="alert alert-info">';
                            echo '<strong>Summary:</strong><br>';
                            echo "Successful: $successCount<br>";
                            echo "Errors: $errorCount<br>";
                            echo '</div>';
                            
                            // Verify installation
                            echo '<h5>Verifying installation...</h5>';
                            $tables = [
                                'user_roles',
                                'page_access_rules',
                                'page_role_access',
                                'action_permissions',
                                'action_permission_rules',
                                'rbac_cache'
                            ];
                            
                            $allTablesExist = true;
                            foreach ($tables as $table) {
                                try {
                                    $stmt = $db->query("SHOW TABLES LIKE '$table'");
                                    if ($stmt->rowCount() > 0) {
                                        echo '<div class="alert alert-success">✓ Table <code>' . $table . '</code> exists</div>';
                                    } else {
                                        echo '<div class="alert alert-danger">✗ Table <code>' . $table . '</code> does not exist</div>';
                                        $allTablesExist = false;
                                    }
                                } catch (PDOException $e) {
                                    echo '<div class="alert alert-danger">✗ Error checking table <code>' . $table . '</code>: ' . 
                                         htmlspecialchars($e->getMessage()) . '</div>';
                                    $allTablesExist = false;
                                }
                            }
                            
                            if ($allTablesExist) {
                                echo '<div class="alert alert-success mt-3">';
                                echo '<h5>✓ RBAC installation complete!</h5>';
                                echo '<p>Dynamic RBAC is ready to use. You can:</p>';
                                echo '<ul>';
                                echo '<li>Manage roles on the Settings page</li>';
                                echo '<li>Assign roles to users</li>';
                                echo '<li>Configure page access rules</li>';
                                echo '</ul>';
                                echo '<p><a href="' . BASE_URL . 'pages/settings.php" class="btn btn-primary">Go to Settings</a></p>';
                                echo '</div>';
                            } else {
                                echo '<div class="alert alert-warning mt-3">';
                                echo '<h5>⚠ Installation incomplete</h5>';
                                echo '<p>Please review the errors above and try again.</p>';
                                echo '</div>';
                            }
                            
                        } catch (Exception $e) {
                            echo '<div class="alert alert-danger">';
                            echo '<h5>Installation Error</h5>';
                            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
                            echo '</div>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

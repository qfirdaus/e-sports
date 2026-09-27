<?php
/**
 * Checklist Page - Admin
 * Roles: ADMIN only
 * Shows distinct athlete counts per sport per contingent in matrix format
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();
$rbac = getRBAC();
// ADMIN only
$rbac->requireMinimumRole('ADMIN');

$page_title = 'Athlete Checklist';

// Fetch all active sports
$sports = [];
try {
    $pdo = getDB();
    $sql = "SELECT id, nama_sukan, kod_sukan 
            FROM table_sukan 
            WHERE deleted_at IS NULL AND status = 1 
            ORDER BY nama_sukan ASC";
    $stmt = $pdo->query($sql);
    $sports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('[checklist.php] DB error fetching sports: ' . $e->getMessage());
    $sports = [];
}

// Fetch all active contingents
$contingents = [];
try {
    $pdo = getDB();
    $sql = "SELECT k.id, k.kod_universiti, u.nama_universiti
            FROM table_kontinjen k
            INNER JOIN table_ref_universiti u ON k.kod_universiti = u.kod_universiti
            WHERE k.deleted_at IS NULL AND k.status = 1 
              AND u.status = 1
            ORDER BY u.nama_universiti ASC";
    $stmt = $pdo->query($sql);
    $contingents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('[checklist.php] DB error fetching contingents: ' . $e->getMessage());
    $contingents = [];
}

// Fetch distinct athlete counts per sport per contingent per gender
$athleteCounts = [];
$athleteNames = [];
try {
    $pdo = getDB();
    
    // First, get counts - fixed to show all combinations
    $sql = "SELECT 
                s.id AS sukan_id,
                s.nama_sukan,
                k.id AS kontinjen_id,
                k.kod_universiti,
                CASE WHEN MOD(CAST(RIGHT(REPLACE(COALESCE(pa.no_kad_pengenalan, ''), '-', ''), 1) AS UNSIGNED), 2) = 0 THEN 'PEREMPUAN' ELSE 'LELAKI' END AS gender,
                COUNT(DISTINCT CASE 
                    WHEN pa.no_kad_pengenalan IS NOT NULL 
                    AND TRIM(pa.no_kad_pengenalan) <> '' 
                    THEN REPLACE(pa.no_kad_pengenalan, '-', '') 
                    ELSE NULL 
                END) AS athlete_count
            FROM table_sukan s
            CROSS JOIN table_kontinjen k
            INNER JOIN table_ref_universiti u ON k.kod_universiti = u.kod_universiti
            LEFT JOIN table_pasukan p ON p.sukan_id = s.id 
                AND p.kontinjen_id = k.id 
                AND p.deleted_at IS NULL 
                AND p.status = 1
            LEFT JOIN table_pasukan_atlet pa ON pa.pasukan_id = p.id 
                AND pa.deleted_at IS NULL 
                AND (pa.no_kad_pengenalan IS NOT NULL AND TRIM(pa.no_kad_pengenalan) <> '')
            WHERE s.deleted_at IS NULL AND s.status = 1
              AND k.deleted_at IS NULL AND k.status = 1
              AND u.status = 1
            GROUP BY s.id, s.nama_sukan, k.id, k.kod_universiti, gender
            ORDER BY s.nama_sukan ASC, k.kod_universiti ASC, gender ASC";
    
    $stmt = $pdo->query($sql);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Transform into matrix format: [sukan_id][kontinjen_id][gender] = count
    foreach ($results as $row) {
        $sukanId = (int)$row['sukan_id'];
        $kontinjenId = (int)$row['kontinjen_id'];
        $gender = strtoupper($row['gender'] ?? 'LELAKI');
        $count = (int)$row['athlete_count'];
        if (!isset($athleteCounts[$sukanId])) {
            $athleteCounts[$sukanId] = [];
        }
        if (!isset($athleteCounts[$sukanId][$kontinjenId])) {
            $athleteCounts[$sukanId][$kontinjenId] = ['LELAKI' => 0, 'PEREMPUAN' => 0];
        }
        $athleteCounts[$sukanId][$kontinjenId][$gender] = $count;
    }
    
    // Now fetch athlete names for tooltips
    $sqlNames = "SELECT 
                    s.id AS sukan_id,
                    k.id AS kontinjen_id,
                    CASE WHEN MOD(CAST(RIGHT(REPLACE(pa.no_kad_pengenalan, '-', ''), 1) AS UNSIGNED), 2) = 0 THEN 'PEREMPUAN' ELSE 'LELAKI' END AS gender,
                    GROUP_CONCAT(DISTINCT pa.nama ORDER BY pa.nama SEPARATOR ', ') AS names
                FROM table_sukan s
                CROSS JOIN table_kontinjen k
                INNER JOIN table_ref_universiti u ON k.kod_universiti = u.kod_universiti
                LEFT JOIN table_pasukan p ON p.sukan_id = s.id 
                    AND p.kontinjen_id = k.id 
                    AND p.deleted_at IS NULL 
                    AND p.status = 1
                LEFT JOIN table_pasukan_atlet pa ON pa.pasukan_id = p.id 
                    AND pa.deleted_at IS NULL 
                    AND pa.no_kad_pengenalan IS NOT NULL 
                    AND TRIM(pa.no_kad_pengenalan) <> ''
                WHERE s.deleted_at IS NULL AND s.status = 1
                  AND k.deleted_at IS NULL AND k.status = 1
                  AND u.status = 1
                  AND pa.nama IS NOT NULL
                GROUP BY s.id, k.id, gender
                ORDER BY s.id, k.id, gender";
    
    $stmtNames = $pdo->query($sqlNames);
    $nameResults = $stmtNames->fetchAll(PDO::FETCH_ASSOC);
    
    // Transform into matrix format: [sukan_id][kontinjen_id][gender] = array of names
    foreach ($nameResults as $row) {
        $sukanId = (int)$row['sukan_id'];
        $kontinjenId = (int)$row['kontinjen_id'];
        $gender = strtoupper($row['gender'] ?? 'LELAKI');
        $names = $row['names'] ?? '';
        
        if (!isset($athleteNames[$sukanId])) {
            $athleteNames[$sukanId] = [];
        }
        if (!isset($athleteNames[$sukanId][$kontinjenId])) {
            $athleteNames[$sukanId][$kontinjenId] = ['LELAKI' => [], 'PEREMPUAN' => []];
        }
        
        if ($names) {
            $nameList = explode(', ', $names);
            foreach ($nameList as $name) {
                $name = trim($name);
                if ($name && !in_array($name, $athleteNames[$sukanId][$kontinjenId][$gender])) {
                    $athleteNames[$sukanId][$kontinjenId][$gender][] = $name;
                }
            }
        }
    }
} catch (Exception $e) {
    error_log('[checklist.php] DB error fetching athlete counts: ' . $e->getMessage());
    $athleteCounts = [];
    $athleteNames = [];
}

ob_start();
?>
<div class="w-100 px-3">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-light border-0 shadow-sm overflow-hidden">
                <div class="card-body py-4 d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                    <div>
                        <h2 class="mb-1">Athlete Checklist</h2>
                        <p class="text-muted mb-0">Athlete counts vary by sport and contingent. Each athlete is counted once even if they enter multiple categories in the same sport.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card mb-4 shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Sports and Athlete Counts</strong>
                    <button type="button" id="copyTableBtn" class="btn btn-sm btn-outline-primary" title="Copy data to paste into Excel or a spreadsheet">
                        <i class="cil-copy me-1"></i> Copy Data
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="checklistTable" class="table table-hover table-striped align-middle data-table-export" style="width:100%">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" style="width:3%;">#</th>
                                    <th scope="col" style="min-width:200px;">Sport</th>
                                    <th scope="col" style="min-width:120px;">Gender</th>
                                    <?php foreach ($contingents as $contingent): ?>
                                        <th scope="col" class="text-center" style="min-width:100px;" title="<?php echo htmlspecialchars($contingent['nama_universiti'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($contingent['kod_universiti'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                        </th>
                                    <?php endforeach; ?>
                                    <th scope="col" class="text-center fw-bold" style="min-width:100px;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sports)): ?>
                                    <tr>
                                        <td colspan="<?php echo 3 + count($contingents) + 1; ?>" class="text-center text-muted py-4">
                                            No sports to display.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    $bil = 1;
                                    $columnTotals = [];
                                    foreach ($contingents as $contingent) {
                                        $columnTotals[$contingent['id']] = ['LELAKI' => 0, 'PEREMPUAN' => 0];
                                    }
                                    $grandTotal = ['LELAKI' => 0, 'PEREMPUAN' => 0];
                                    ?>
                                    <?php foreach ($sports as $sport): ?>
                                        <?php
                                        $sukanId = (int)$sport['id'];
                                        $sportName = htmlspecialchars(english_sport_label($sport['nama_sukan'] ?? '-'), ENT_QUOTES, 'UTF-8');
                                        $sportCode = !empty($sport['kod_sukan']) ? htmlspecialchars($sport['kod_sukan'], ENT_QUOTES, 'UTF-8') : '';
                                        
                                        // Create two rows: one for Male, one for Female
                                        foreach (['LELAKI', 'PEREMPUAN'] as $gender):
                                            $rowTotal = 0;
                                        ?>
                                            <tr data-sport="<?php echo htmlspecialchars($sportName, ENT_QUOTES, 'UTF-8'); ?>" data-gender="<?php echo $gender; ?>">
                                                <td class="text-center">
                                                    <?php if ($gender === 'LELAKI'): ?>
                                                        <?php echo $bil; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?php echo $sportName; ?></strong>
                                                    <?php if ($sportCode): ?>
                                                        <br><small class="text-muted"><?php echo $sportCode; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($gender === 'LELAKI'): ?>
                                                        <span class="badge badge-pill badge-primary"><i class="cil-male me-1"></i>Male</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-pill badge-danger"><i class="cil-child me-1"></i>Female</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php foreach ($contingents as $idx => $contingent): ?>
                                                    <?php
                                                    $kontinjenId = (int)$contingent['id'];
                                                    $count = isset($athleteCounts[$sukanId][$kontinjenId][$gender]) ? (int)$athleteCounts[$sukanId][$kontinjenId][$gender] : 0;
                                                    $rowTotal += $count;
                                                    $columnTotals[$kontinjenId][$gender] += $count;
                                                    
                                                    // Get athlete names for this cell
                                                    $names = isset($athleteNames[$sukanId][$kontinjenId][$gender]) ? $athleteNames[$sukanId][$kontinjenId][$gender] : [];
                                                    $namesList = !empty($names) ? implode(', ', $names) : '';
                                                    $namesListHtml = !empty($names) ? htmlspecialchars($namesList, ENT_QUOTES, 'UTF-8') : '';
                                                    ?>
                                                    <td class="text-center athlete-count-cell" 
                                                        data-count="<?php echo $count; ?>"
                                                        data-names="<?php echo htmlspecialchars(json_encode($names), ENT_QUOTES, 'UTF-8'); ?>"
                                                        <?php if ($count > 0 && !empty($namesList)): ?>
                                                            data-bs-toggle="tooltip" 
                                                            data-bs-placement="top" 
                                                            data-bs-html="false"
                                                            title="<?php echo $namesListHtml; ?>"
                                                            style="cursor: help;"
                                                        <?php endif; ?>>
                                                        <?php if ($count > 0): ?>
                                                            <span class="badge badge-pill <?php echo $gender === 'LELAKI' ? 'badge-primary' : 'badge-danger'; ?>"><?php echo number_format($count); ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">0</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                                <td class="text-center fw-bold" data-total="<?php echo $rowTotal; ?>">
                                                    <?php 
                                                    $grandTotal[$gender] += $rowTotal;
                                                    if ($rowTotal > 0): 
                                                    ?>
                                                        <span class="badge badge-pill <?php echo $gender === 'LELAKI' ? 'badge-primary' : 'badge-danger'; ?>"><?php echo number_format($rowTotal); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">0</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php 
                                        endforeach;
                                        $bil++;
                                        ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="2" class="text-end">Grand Total</th>
                                    <th></th>
                                    <?php foreach ($contingents as $contingent): ?>
                                        <th class="text-center fw-bold">
                                            <?php 
                                            $colTotal = ($columnTotals[$contingent['id']]['LELAKI'] ?? 0) + ($columnTotals[$contingent['id']]['PEREMPUAN'] ?? 0);
                                            if ($colTotal > 0): 
                                            ?>
                                                <span class="badge badge-pill badge-info"><?php echo number_format($colTotal); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">0</span>
                                            <?php endif; ?>
                                        </th>
                                    <?php endforeach; ?>
                                    <th class="text-center fw-bold">
                                        <span class="badge badge-pill badge-success"><?php echo number_format($grandTotal['LELAKI'] + $grandTotal['PEREMPUAN']); ?></span>
                                    </th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
#checklistTable th {
    white-space: nowrap;
    vertical-align: middle;
}
#checklistTable td {
    vertical-align: middle;
}
.badge-pill {
    padding: 0.35em 0.65em;
    font-size: 0.875rem;
}
#copyTableBtn {
    transition: all 0.3s ease;
}
#copyTableBtn.copied {
    background-color: #28a745;
    border-color: #28a745;
    color: white;
}
</style>

<script>
(function() {
    function whenJQ(cb) {
        if (window.jQuery) {
            cb(window.jQuery);
        } else {
            setTimeout(function() { whenJQ(cb); }, 50);
        }
    }
    
    // Initialize Bootstrap tooltips
    function initTooltips() {
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl, {
                    html: true,
                    placement: 'top',
                    trigger: 'hover',
                    container: 'body'
                });
            });
        }
    }
    
    whenJQ(function($) {
        // Initialize tooltips after page load
        $(document).ready(function() {
            initTooltips();
        });
        
        var $copyBtn = $('#copyTableBtn');
        
        $copyBtn.on('click', function() {
            var $table = $('#checklistTable');
            var data = [];
            
            // Get header row - simple column headers
            var headerRow = ['#', 'Sport', 'Gender'];
            $table.find('thead tr:first th').each(function(index) {
                if (index >= 3) { // Skip #, Sport, Gender columns
                    var $th = $(this);
                    var text = $th.text().trim();
                    if (text && text !== 'Total') {
                        headerRow.push(text);
                    }
                }
            });
            headerRow.push('Total');
            data.push(headerRow.join('\t'));
            
            // Get data rows - clean and simple
            $table.find('tbody tr').each(function() {
                var $row = $(this);
                var rowData = [];
                
                // Get row number (only for Male rows)
                var rowNum = $row.find('td:first').text().trim();
                if (!rowNum || rowNum === '') {
                    rowNum = ''; // Empty for Female rows
                }
                rowData.push(rowNum);
                
                // Get sport name (always present now)
                var sportName = $row.find('td:eq(1)').text().trim();
                // Remove line breaks and extra spaces
                sportName = sportName.replace(/\s+/g, ' ').trim();
                rowData.push(sportName);
                
                // Get gender
                var gender = $row.find('td:eq(2)').text().trim();
                // Clean gender text
                gender = gender.replace(/[^\w\s]/g, '').trim();
                rowData.push(gender);
                
                // Get contingent counts
                $row.find('td').slice(3, -1).each(function() {
                    var $cell = $(this);
                    var count = $cell.attr('data-count') || $cell.text().trim();
                    
                    // Clean the count value
                    count = count.toString().replace(/[^\d]/g, '');
                    if (count === '' || count === '0') {
                        count = '0';
                    }
                    rowData.push(count);
                });
                
                // Get total
                var total = $row.find('td:last').attr('data-total') || $row.find('td:last').text().trim();
                total = total.toString().replace(/[^\d]/g, '');
                if (total === '' || total === '0') {
                    total = '0';
                }
                rowData.push(total);
                
                data.push(rowData.join('\t'));
            });
            
            // Get footer row (totals)
            var footerRow = ['', 'Grand Total', ''];
            $table.find('tfoot tr th').each(function(index) {
                if (index >= 3) { // Skip first 3 columns
                    var $th = $(this);
                    var text = $th.text().trim();
                    var value = text.replace(/[^\d]/g, '');
                    if (value === '') {
                        value = '0';
                    }
                    footerRow.push(value);
                }
            });
            data.push(footerRow.join('\t'));
            
            var textToCopy = data.join('\n');
            
            // Copy to clipboard
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(textToCopy).then(function() {
                    // Show success feedback
                    var originalText = $copyBtn.html();
                    $copyBtn.addClass('copied').html('<i class="cil-check me-1"></i> Disalin!');
                    setTimeout(function() {
                        $copyBtn.removeClass('copied').html(originalText);
                    }, 2000);
                }).catch(function(err) {
                    console.error('Failed to copy:', err);
                    fallbackCopy(textToCopy);
                });
            } else {
                // Fallback for older browsers
                fallbackCopy(textToCopy);
            }
        });
        
        function fallbackCopy(text) {
            var textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            textArea.style.top = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            
            try {
                var successful = document.execCommand('copy');
                if (successful) {
                    var originalText = $('#copyTableBtn').html();
                    $('#copyTableBtn').addClass('copied').html('<i class="cil-check me-1"></i> Disalin!');
                    setTimeout(function() {
                        $('#copyTableBtn').removeClass('copied').html(originalText);
                    }, 2000);
                } else {
                    alert('Unable to copy the data. Please copy it manually.');
                }
            } catch (err) {
                console.error('Fallback copy failed:', err);
                alert('Unable to copy the data. Please copy it manually.');
            }
            
            document.body.removeChild(textArea);
        }
    });
})();
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>


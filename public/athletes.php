<?php
/**
 * Public Athletes listing with filters by contingent and event
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

$page_title = 'Athletes';
$kontingenList = [];
$kategoriList = [];
$athletes = [];

try {
    $db = getDB();
    // contingents for filter
    $kStmt = $db->query("SELECT DISTINCT k.kod_universiti, COALESCE(r.nama_pendek, r.nama_universiti, k.kod_universiti) AS nama FROM table_kontinjen k JOIN table_ref_universiti r ON r.kod_universiti = k.kod_universiti WHERE k.deleted_at IS NULL AND k.status = 1 AND r.status = 1 ORDER BY nama ASC");
    $kontingenList = $kStmt ? $kStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    // categories (events)
    $cStmt = $db->query("SELECT id, nama_kategori FROM table_kategori WHERE deleted_at IS NULL ORDER BY nama_kategori ASC");
    $kategoriList = $cStmt ? $cStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    // filters from GET
    $filterKod = isset($_GET['kod_universiti']) ? trim($_GET['kod_universiti']) : '';
    $filterKat = isset($_GET['kategori_id']) ? intval($_GET['kategori_id']) : 0;
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';

    // base query: athletes joined to team and kontingen
    $sql = "
        SELECT
            pa.id AS athlete_id,
            COALESCE(pa.nama, pa.nama_penuh, pa.name) AS athlete_name,
            COALESCE(pa.no_kad_pengenalan, pa.ic_no, '') AS ic_no,
            p.id AS team_id,
            p.nama_pasukan,
            k.kod_universiti,
            COALESCE(r.nama_pendek, r.nama_universiti, k.kod_universiti) AS kontingen_name,
            s.nama_sukan
        FROM table_pasukan_atlet pa
        LEFT JOIN table_pasukan p ON p.id = pa.pasukan_id AND p.deleted_at IS NULL
        LEFT JOIN table_kontinjen k ON k.id = p.kontinjen_id AND k.deleted_at IS NULL
        LEFT JOIN table_ref_universiti r ON r.kod_universiti = k.kod_universiti
        LEFT JOIN table_sukan s ON s.id = p.sukan_id
        WHERE pa.deleted_at IS NULL
    ";

    $params = [];
    if ($filterKod !== '') {
        $sql .= " AND COALESCE(k.kod_universiti, '') = :kod";
        $params[':kod'] = $filterKod;
    }
    if ($filterKat > 0) {
        // Filter directly by athlete's kategori (show athletes registered for the category)
        $sql .= " AND pa.kategori_id = :kat";
        $params[':kat'] = $filterKat;
    }
    if ($q !== '') {
        $sql .= " AND (LOWER(COALESCE(pa.nama, pa.nama_penuh, pa.name, '')) LIKE :q OR LOWER(COALESCE(p.nama_pasukan, '')) LIKE :q)";
        $params[':q'] = '%' . strtolower($q) . '%';
    }

    $sql .= " ORDER BY kontingen_name ASC, athlete_name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('[public/athletes] ' . $e->getMessage());
    $athletes = [];
}

// Debug helper: show SQL, params and GET when requested
if (isset($_GET['debug_athletes']) && $_GET['debug_athletes']) {
    echo "<div style='background:#fff;padding:12px;border:1px solid #ddd;margin:12px 0;font-family:monospace;white-space:pre-wrap;'>";
    echo "GET => " . htmlspecialchars(var_export($_GET, true), ENT_QUOTES, 'UTF-8') . "\n\n";
    echo "SQL => " . htmlspecialchars($sql, ENT_QUOTES, 'UTF-8') . "\n\n";
    echo "Params => " . htmlspecialchars(var_export($params, true), ENT_QUOTES, 'UTF-8') . "\n\n";
    echo "Rows => " . (is_array($athletes) ? count($athletes) : 0);
    echo "</div>";
}

ob_start();
?>
<div class="container mt-3 mb-4">
    <div class="row align-items-center mb-3">
        <div class="col-md-8 col-12">
            <h2 class="mb-0">Athletes</h2>
            <div class="text-muted small">Filter by contingent and event</div>
        </div>
    </div>

    <form id="athleteFilters" class="row g-2 mb-3" method="get" action="<?php echo url('public/athletes.php'); ?>">
        <div class="col-sm-5 col-12">
            <input type="search" name="q" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" class="form-control" placeholder="Search athlete or team..." />
        </div>
        <div class="col-sm-3 col-6">
            <select name="kod_universiti" class="form-select">
                <option value="">All Contingents</option>
                <?php foreach ($kontingenList as $k): ?>
                    <option value="<?php echo htmlspecialchars($k['kod_universiti'], ENT_QUOTES, 'UTF-8'); ?>" <?php if (($filterKod ?? '') === ($k['kod_universiti'] ?? '')) echo 'selected'; ?>><?php echo htmlspecialchars($k['nama'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-3 col-6">
            <select name="kategori_id" class="form-select">
                <option value="0">All Events</option>
                <?php foreach ($kategoriList as $kat): ?>
                    <option value="<?php echo (int)$kat['id']; ?>" <?php if (($filterKat ?? 0) === (int)$kat['id']) echo 'selected'; ?>><?php echo htmlspecialchars(english_sport_label($kat['nama_kategori']), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-1 col-12 d-grid">
            <button type="submit" class="btn btn-primary">Filter</button>
        </div>
    </form>

    <div class="athlete-list">
        <?php if (empty($athletes)): ?>
            <div class="empty-state">No athletes found.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th style="width:4%">#</th>
                            <th>Name</th>
                            <th>IC</th>
                            <th>Team</th>
                            <th>Contingent</th>
                            <th>Sport</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i=1; foreach ($athletes as $a): ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td><?php echo htmlspecialchars($a['athlete_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($a['ic_no'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($a['nama_pasukan'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($a['kontingen_name'] ?? ($a['kod_universiti'] ?? '-')); ?></td>
                                <td><?php echo htmlspecialchars(english_sport_label($a['nama_sukan'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.empty-state{ padding:36px; text-align:center; color:#6b7280 }
.table thead th{ background:transparent }
.form-select, .form-control{ height:44px }
@media(max-width:576px){ .form-select, .form-control{ height:40px } }
</style>

<?php
$content = ob_get_clean();
$content .= "\n<script>\n// Ensure filter works even if other scripts intercept form submission\n(function(){\n    var form = document.getElementById('athleteFilters');\n    if (!form) return;\n    form.addEventListener('submit', function(e){\n        try {\n            var params = new URLSearchParams();\n            var q = form.querySelector('input[name=\\\"q\\\"]');\n            var kod = form.querySelector('select[name=\\\"kod_universiti\\\"]');\n            var kat = form.querySelector('select[name=\\\"kategori_id\\\"]');\n            if (q && q.value) params.set('q', q.value);\n            if (kod && kod.value) params.set('kod_universiti', kod.value);\n            if (kat && kat.value) params.set('kategori_id', kat.value);\n            var action = form.getAttribute('action') || window.location.pathname;\n            window.location.href = action + (params.toString() ? ('?' + params.toString()) : '');\n        } catch(err){ /* fallback to default submit */ }\n        e.preventDefault();\n        return false;\n    }, { passive: false });\n})();\n</script>\n";
require_once __DIR__ . '/../includes/layout_public.php';
?>
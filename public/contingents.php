<?php
/**
 * Public Contingents page - grid of contingent logos
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

$page_title = 'Contingents';
$contingents = [];
try {
    $db = getDB();
    $sql = "
        SELECT DISTINCT
            k.kod_universiti,
            COALESCE(r.nama_pendek, r.nama_universiti, k.kod_universiti) AS nama
        FROM table_kontinjen k
        JOIN table_ref_universiti r ON r.kod_universiti = k.kod_universiti
        WHERE k.deleted_at IS NULL AND k.status = 1 AND r.status = 1
        ORDER BY nama ASC
    ";
    $stmt = $db->query($sql);
    $contingents = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
    error_log('[public/contingents] ' . $e->getMessage());
    $contingents = [];
}

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/public-contingents.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/public-contingents.css'); ?>">
<main class="public-contingents">
    <div class="contingents-heading">
        <div><span class="contingents-eyebrow">SAM 2026 · PARTICIPATING CONTINGENTS</span><h1>Meet the contingents.</h1><p>The institutions taking part in Sukan Asasi Malaysia 2026.</p></div>
        <span class="contingents-count"><?php echo count($contingents); ?> kontinjen</span>
    </div>
    <div class="contingent-grid">
        <?php if (empty($contingents)): ?>
            <div class="empty-state">No contingents to display.</div>
        <?php else: ?>
            <?php foreach ($contingents as $c):
                $rawCode = trim($c['kod_universiti'] ?? '');
                $kod = htmlspecialchars($rawCode, ENT_QUOTES, 'UTF-8');
                $name = htmlspecialchars($c['nama'] ?? $kod, ENT_QUOTES, 'UTF-8');
                $logo = asset('img/logos/UA/' . strtoupper($rawCode) . '.svg');
                $link = url('public/medal-standings.php') . '?kod_universiti=' . urlencode($rawCode);
            ?>
                <a class="contingent-item" href="<?php echo $link; ?>" title="<?php echo $name; ?>">
                    <div class="contingent-logo-wrap">
                        <img src="<?php echo $logo; ?>" alt="<?php echo $name; ?>" loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false;" />
                        <span class="contingent-logo-fallback" hidden><?php echo $kod; ?></span>
                    </div>
                    <div class="contingent-caption"><?php echo $name; ?></div>
                    <span class="contingent-card-link">Medal standings <span aria-hidden="true">↗</span></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>


<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_public.php';
?>

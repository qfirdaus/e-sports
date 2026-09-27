<?php
/**
 * Public Layout Template
 * Lightweight layout that reuses header/footer but hides admin sidebar and auth checks.
 */
require_once __DIR__ . '/header-public.php';
?>

<!-- Content Body Start -->
<div class="content-body flex-grow-1">
    <div class="container-fluid mt-0">
        <?php echo $content; ?>
        <?php require_once __DIR__ . '/footer.php'; ?>
    </div>
</div>

</div>

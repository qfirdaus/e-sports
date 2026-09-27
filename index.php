<?php
/**
 * Dashboard Page
 */
require_once 'config.php';
// Redirect default landing
header('Location: ' . url('pages/dashboard.php'));
exit;
// Authentication is handled in layout.php

$page_title = 'Papan Pemuka';

// Start output buffering to capture content
ob_start();
?>
<div class="w-100 px-3">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="mb-0">Papan Pemuka</h2>
            <p class="text-muted">Selamat datang ke papan pemuka anda</p>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="fs-4 fw-semibold">0</div>
                            <div class="text-medium-emphasis small">Jumlah Kontinjen</div>
                        </div>
                        <div>
                            <i class="icon cil cil-people text-primary" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="fs-4 fw-semibold">0</div>
                            <div class="text-medium-emphasis small">Jumlah Sukan</div>
                        </div>
                        <div>
                            <i class="icon cil cil-gamepad text-success" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="fs-4 fw-semibold">0</div>
                            <div class="text-medium-emphasis small">Jumlah Pasukan</div>
                        </div>
                        <div>
                            <i class="icon cil cil-user text-info" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="fs-4 fw-semibold">0</div>
                            <div class="text-medium-emphasis small">Acara Selesai</div>
                        </div>
                        <div>
                            <i class="icon cil cil-check-circle text-warning" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
<?php
$content = ob_get_clean();

// Include the layout
require_once 'includes/layout.php';
?>

<?php
/**
 * Public Index / Home
 * Simple landing page linking to public sections.
 */
require_once __DIR__ . '/../config.php';

$page_title = 'Home';

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/public-home.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/public-home.css'); ?>">
<main class="public-home">
            <?php
            // Build banner slide list (reuse assets/img/banners if present)
            $bannerDir = __DIR__ . '/../assets/img/banners';
            $bannerFiles = [];
            if (is_dir($bannerDir)) {
                $files = glob($bannerDir . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE);
                if ($files) {
                    usort($files, function($a,$b){ return strcmp(basename($a), basename($b)); });
                    foreach ($files as $f) {
                        $bannerFiles[] = asset('img/banners/' . basename($f));
                    }
                }
            }
            if (empty($bannerFiles)) {
                $bannerFiles = [ asset('img/banners/fallback/sam2026-banner.jpg') ];
            }
            $slidesJson = json_encode($bannerFiles);
            ?>
    <section class="home-hero" aria-labelledby="home-title">
        <div class="home-intro">
            <span class="home-eyebrow">SUKAN ASASI MALAYSIA · 9TH EDITION</span>
            <h1 id="home-title">Igniting Spirit,<br><span>Inspiring Excellence.</span></h1>
            <p>Welcome to SAM 2026. Meet the participating contingents and follow the medal standings throughout the championship.</p>
            <div class="home-actions">
                <a class="home-button home-primary" href="<?php echo url('public/medal-standings.php'); ?>">View Medal Standings <span aria-hidden="true">↗</span></a>
                <a class="home-secondary" href="<?php echo url('public/contingents.php'); ?>">Explore Contingents <span aria-hidden="true">→</span></a>
            </div>
            <div class="home-host"><span class="home-host-icon" aria-hidden="true"><i class="fa fa-university"></i></span><div><small>HOST UNIVERSITY</small><strong>Universiti Pertahanan Nasional Malaysia</strong></div></div>
        </div>
        <div class="home-art">
            <div id="publicBanner" class="public-banner" aria-label="SAM 2026 Gallery">
                <?php foreach ($bannerFiles as $i => $src): ?>
                    <img src="<?php echo htmlspecialchars($src, ENT_QUOTES, 'UTF-8'); ?>" alt="Banner SAM 2026 — <?php echo $i + 1; ?>" class="<?php echo $i === 0 ? 'active' : ''; ?>" <?php echo $i > 0 ? 'loading="lazy"' : 'fetchpriority="high"'; ?>>
                <?php endforeach; ?>
            </div>
            <div class="home-gallery-footer"><span>SAM 2026 <small>· Championship gallery</small></span>
                <?php if (count($bannerFiles) > 1): ?><div class="home-gallery-controls"><button type="button" id="bannerPrev" aria-label="Previous banner">←</button><span id="bannerCount" aria-live="polite">1 / <?php echo count($bannerFiles); ?></span><button type="button" id="bannerNext" aria-label="Next banner">→</button></div><?php endif; ?>
            </div>
        </div>
    </section>
    <section class="home-explore" aria-labelledby="explore-title">
        <div class="home-section-heading"><div><span class="home-eyebrow">EXPLORE THE CHAMPIONSHIP</span><h2 id="explore-title">Everything you need, in one place.</h2></div><p>Choose what you would like to explore.</p></div>
        <div class="home-links">
            <a class="home-link-card" href="<?php echo url('public/contingents.php'); ?>"><span class="home-card-icon" aria-hidden="true"><i class="fa fa-flag"></i></span><h3>Contingent</h3><p>Meet the contingents taking part in SAM 2026.</p><span class="home-card-action">View contingents <span aria-hidden="true">→</span></span></a>
            <a class="home-link-card" href="<?php echo url('public/medal-standings.php'); ?>"><span class="home-card-icon home-gold" aria-hidden="true"><i class="fa fa-trophy"></i></span><h3>Medal Standings</h3><p>Explore medal standings and medal winners for every event.</p><span class="home-card-action">View standings <span aria-hidden="true">→</span></span></a>
            <a class="home-link-card" href="https://sam2026.upnm.edu.my/" target="_blank" rel="noopener noreferrer"><span class="home-card-icon home-teal" aria-hidden="true"><i class="fa fa-globe"></i></span><h3>Official Website</h3><p>Find official information and announcements for SAM 2026.</p><span class="home-card-action">Visit official website <span aria-hidden="true">↗</span><span class="sr-only"> (new tab)</span></span></a>
        </div>
    </section>
    <footer class="home-footer"><strong>SAM 2026</strong><span>© 2026 Sukan Asasi Malaysia · Universiti Pertahanan Nasional Malaysia</span></footer>
</main>
<script>
(function () {
    var slides = document.querySelectorAll('#publicBanner img');
    var next = document.getElementById('bannerNext');
    var prev = document.getElementById('bannerPrev');
    var count = document.getElementById('bannerCount');
    if (!next || !prev || slides.length < 2) return;
    var current = 0;
    function show(offset) {
        slides[current].classList.remove('active');
        current = (current + offset + slides.length) % slides.length;
        slides[current].classList.add('active');
        count.textContent = (current + 1) + ' / ' + slides.length;
    }
    next.addEventListener('click', function () { show(1); });
    prev.addEventListener('click', function () { show(-1); });
})();
</script>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout_public.php';
?>

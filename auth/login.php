<?php
$version = time();

// Security headers and session setup
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
ini_set('session.cookie_samesite', 'Strict');

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdn.tailwindcss.com; style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; font-src https://fonts.gstatic.com; img-src 'self' data:");

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

Session::start();
$auth = getAuth();

if ($auth->isLoggedIn()) {
    header('Location: ' . url('pages/dashboard.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please complete all required fields';
    } else {
        $result = $auth->login($email, $password);
        if ($result['success']) {
            $returnUrl = $_GET['return'] ?? null;
            if (!$returnUrl) {
                $returnUrl = url('pages/dashboard.php');
            }
            if ($returnUrl && !preg_match('#^https?://#i', $returnUrl)) {
                $returnUrl = '/' . ltrim($returnUrl, '/');
                if (BASE_URL !== '' && strpos($returnUrl, BASE_URL . '/') !== 0 && $returnUrl !== BASE_URL) {
                    $returnUrl = BASE_URL . $returnUrl;
                }
            }
            $returnUrl = filter_var($returnUrl, FILTER_SANITIZE_URL);
            if ($returnUrl && (strpos($returnUrl, BASE_URL) === 0 || strpos($returnUrl, '/') === 0)) {
                header('Location: ' . $returnUrl . '?login=success');
            } else {
                header('Location: ' . url('pages/dashboard.php?login=success'));
            }
            exit;
        }
        $error = $result['message'] ?? 'Sign-in failed. Please try again.';
    }
}

$logoMain = asset('img/logos/logo-main.png');
$favicon = asset('img/favicon.ico');
  $bannerDir = __DIR__ . '/../assets/img/banners';
$bannerFiles = [];
if (is_dir($bannerDir)) {
  $dh = opendir($bannerDir);
  if ($dh) {
    while (($f = readdir($dh)) !== false) {
      if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['jpg','jpeg','png','webp','gif'])) {
                $bannerFiles[] = asset('img/banners/' . $f);
      }
    }
    closedir($dh);
  }
}
// fallback to single banner if none found (use fallback subfolder)
if (empty($bannerFiles)) {
  $bannerFiles = [ asset('img/banners/fallback/sam2026-banner.jpg') ];
}
$slidesJs = json_encode($bannerFiles);
$siteTitle = defined('SITE_NAME') ? SITE_NAME : 'SAM 2026';
// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($siteTitle) ?> - Sign In</title>
  <link rel="icon" href="<?= htmlspecialchars($favicon) ?>" type="image/x-icon">

  <script>
    // Silence Tailwind CDN console warning for local bundle
    window.__origWarn = console.warn;
    console.warn = function(msg, ...rest) {
      if (typeof msg === 'string' && msg.includes('cdn.tailwindcss.com')) return;
      return window.__origWarn.apply(console, [msg, ...rest]);
    };
  </script>
  <script src="<?= asset('js/tailwindcdn.js') ?>?v=<?= $version ?>"></script>
  <script>console.warn = window.__origWarn;</script>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js?v=<?= $version ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11?v=<?= $version ?>"></script>

  <style>
    body { font-family: 'Poppins', sans-serif; font-size: 13px; }
  </style>
  <style>
    .tab-btn{ position:relative; display:inline-block; padding:0.5rem 0.75rem; color: #6b7280; text-decoration:none; }
    .tab-btn::after{ content:''; position:absolute; left:0; right:0; height:3px; bottom:-10px; background:transparent; transition:all .18s ease; border-radius:2px; }
    .tab-btn:hover{ color: #0babcd; }
    .tab-btn:hover::after{ background: #0babcd; transform:translateY(3px); }
    .tab-btn.active{ color:#0babcd; }
    .tab-btn.active::after{ background:#0babcd; transform:translateY(3px); }
  </style>
<link rel="stylesheet" href="<?= asset('css/login.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/login.css') ?>">
</head>
<body class="bg-gray-100 login-page" x-data>

<!-- Header -->
<header class="bg-white shadow">
  <div class="max-w-7xl mx-auto p-4">
    <img src="<?= htmlspecialchars($logoMain) ?>" alt="Logo" class="w-20">
    <nav class="flex flex-wrap gap-4 border-b border-gray-300 mt-4" aria-label="Main navigation">
      <a href="<?php echo url('public/index.php'); ?>" class="tab-btn active">Home</a>
      <a href="<?php echo url('public/contingents.php'); ?>" class="tab-btn">Contingent</a>
      <a href="<?php echo url('public/medal-standings.php'); ?>" class="tab-btn">Medal Tally</a>
    </nav>
  </div>
</header>

<!-- Main -->
<main class="login-layout max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-6 mt-6 px-4">

  <!-- Banner -->
  <div class="login-showcase md:col-span-2 bg-white rounded-xl shadow overflow-hidden">
    <div x-data="carousel()" x-init="initCarousel(JSON.parse($el.getAttribute('data-slides')))" data-slides='<?= htmlspecialchars($slidesJs, ENT_QUOTES) ?>' @mouseenter="pause()" @mouseleave="play()" class="login-banner relative h-[420px]">
      <template x-for="(slide, index) in slides" :key="index">
        <img x-bind:src="slide" class="absolute inset-0 w-full h-full object-cover transition-opacity duration-700" :class="active === index ? 'opacity-100 z-20 is-active' : 'opacity-0 z-0'" />
      </template>

      <!-- Prev/Next -->
      <button type="button" aria-label="Previous banner" @click="prev()" class="absolute left-3 top-1/2 -translate-y-1/2 bg-black/40 text-white rounded-full p-2 z-30 hover:bg-black/60">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
      </button>
      <button type="button" aria-label="Next banner" @click="next()" class="absolute right-3 top-1/2 -translate-y-1/2 bg-black/40 text-white rounded-full p-2 z-30 hover:bg-black/60">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      </button>

      <!-- Dots -->
      <div class="absolute bottom-3 left-1/2 transform -translate-x-1/2 flex space-x-2 z-30">
        <template x-for="(slide, index) in slides" :key="index">
          <div @click="go(index)" :class="active === index ? 'bg-white' : 'bg-white/50'" class="w-3 h-3 rounded-full cursor-pointer border"></div>
        </template>
      </div>
    </div>

    <script>
    function carousel(){
      return {
        slides: [], active: 0, timer: null, interval: 4000,
        initCarousel(list){ this.slides = list || []; this.play(); },
        play(){ var self=this; this.stop(); this.timer = setInterval(()=>{ self.active = (self.active+1) % (self.slides.length || 1); }, this.interval); },
        pause(){ this.stop(); },
        stop(){ if(this.timer) { clearInterval(this.timer); this.timer = null; } },
        prev(){ this.active = (this.active - 1 + this.slides.length) % this.slides.length; },
        next(){ this.active = (this.active + 1) % this.slides.length; },
        go(i){ this.active = i % this.slides.length; }
      };
    }
    </script>

    <section class="login-contacts p-6 text-sm" x-data="{ tab: 'urusetia' }">
      <h2 class="text-lg font-semibold mb-4">Contact Us</h2>
      <div class="flex flex-wrap gap-2 mb-4">
        <button @click="tab='urusetia'" :class="tab==='urusetia' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'" class="px-3 py-2 rounded-md text-sm font-semibold">Secretariat</button>
        <button @click="tab='pengarah'" :class="tab==='pengarah' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'" class="px-3 py-2 rounded-md text-sm font-semibold">Programme Director</button>
        <button @click="tab='sekretariat'" :class="tab==='sekretariat' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'" class="px-3 py-2 rounded-md text-sm font-semibold">Head of Secretariat</button>
        <button @click="tab='teknikal'" :class="tab==='teknikal' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'" class="px-3 py-2 rounded-md text-sm font-semibold">Technical Lead</button>
      </div>
      <div class="space-y-4 text-sm">
        <template x-if="tab==='urusetia'">
          <div class="border rounded-lg p-4 bg-gray-50">
            <p class="font-semibold">Secretariat SAM2026</p>
            <p class="text-gray-700">Pn. Norjulia binti Mohd Johan</p>
            <p class="text-gray-700">Tel: 019-6349072</p>
            <p class="text-gray-700">urusetia_sam@upnm.edu.my</p>
          </div>
        </template>
        <template x-if="tab==='pengarah'">
          <div class="border rounded-lg p-4 bg-gray-50">
            <p class="font-semibold">Programme Director</p>
            <p class="text-gray-700">Dr. Ahmad Farid bin Mohd Azmi</p>
            <p class="text-gray-700">Tel: 013-3658381</p>
            <p class="text-gray-700">ahmad.farid@upnm.edu.my</p>
          </div>
        </template>
        <template x-if="tab==='sekretariat'">
          <div class="border rounded-lg p-4 bg-gray-50">
            <p class="font-semibold">Head of Secretariat</p>
            <p class="text-gray-700">Pn. Norjulia binti Mohd Johan</p>
            <p class="text-gray-700">Tel: 019-6349072</p>
            <p class="text-gray-700">norjulia@upnm.edu.my</p>
          </div>
        </template>
        <template x-if="tab==='teknikal'">
          <div class="border rounded-lg p-4 bg-gray-50">
            <p class="font-semibold">Technical Lead</p>
            <p class="text-gray-700">En. Mohd Razlan Shah bin Mohamad Rabii</p>
            <p class="text-gray-700">Tel: 013-2079833</p>
            <p class="text-gray-700">razlan@upnm.edu.my</p>
          </div>
        </template>
      </div>
    </section>
  </div>

  <!-- Login Card -->
  <div class="login-card bg-white p-8 rounded-xl shadow">
    <div class="text-center mb-6">
      <img src="<?= htmlspecialchars($logoMain) ?>" class="mx-auto h-20 mb-2" alt="Logo">
      <span class="login-eyebrow">SAM 2026 MANAGEMENT PORTAL</span><h2 class="text-lg font-bold text-gray-700">Welcome back.</h2><p class="login-intro">Sign in to manage your participation and championship.</p>
    </div>
    <form method="POST" action="" autocomplete="off" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <div>
        <label for="email" class="block font-medium text-gray-700">Email</label>
        <input id="email" name="email" type="email" required class="w-full px-4 py-2 border rounded-md focus:ring-2 focus:ring-blue-500" autocomplete="username" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
      </div>
      <div>
        <label for="password" class="block font-medium text-gray-700">Password</label>
        <input id="password" name="password" type="password" required class="w-full px-4 py-2 border rounded-md focus:ring-2 focus:ring-blue-500" autocomplete="current-password">
      </div>
      <div class="text-right">
        <a href="#" onclick="return false;" class="text-sm text-blue-600 hover:underline">Forgot Password?</a>
      </div>
      <p class="text-sm text-gray-600 text-center">Sign in with your registered email address and password.</p>
      <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded-md font-semibold hover:bg-blue-700 transition">Sign In</button>
    </form>
    <p class="text-center text-gray-500 text-xs mt-10">
      SAM 2026<br>Hak Cipta Pusat Teknologi Maklumat dan Komunikasi (PTMK), UPNM
    </p>
  </div>

  <!-- Modal FAQ -->
  <div x-show="$store.faq.openFaq" x-transition.opacity class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center" style="display: none;">
    <div class="bg-white w-full max-w-3xl mx-4 p-6 rounded-xl shadow-lg relative">
      <button @click="$store.faq.openFaq = false" class="absolute top-3 right-3 text-gray-500 hover:text-red-500">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
      <h2 class="text-xl font-semibold text-blue-700 mb-4">Frequently Asked Questions (FAQ)</h2>
      <div class="max-h-[60vh] overflow-y-auto pr-2 text-sm leading-relaxed text-gray-700" x-html="$store.faq.contentFaq"></div>
    </div>
  </div>
</main>

<?php if ($error): ?>
<script>
Swal.fire({
  icon: 'error',
  title: 'Sign-In Error',
  text: <?= json_encode($error) ?>,
  timer: 4000,
  timerProgressBar: true,
  showConfirmButton: false
});
</script>
<?php endif; ?>

<!-- Alpine Store Init -->
<script>
document.addEventListener('alpine:init', () => {
  Alpine.store('faq', {
    openFaq: false,
    contentFaq: '',
    async showFaq() {
      try {
        const res = await fetch('faq.html');
        this.contentFaq = await res.text();
        this.openFaq = true;
      } catch (e) {
        this.contentFaq = '<p class="text-red-600">Unable to load FAQ content.</p>';
        this.openFaq = true;
      }
    }
  });
});
</script>
</body>
</html>

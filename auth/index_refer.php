<?php
$version = time();

// ✅ Session Security
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
ini_set('session.cookie_samesite', 'Strict');
session_start();

// ✅ No cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// ✅ CSP Mesra AlpineJS
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdn.tailwindcss.com; style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; font-src https://fonts.gstatic.com; img-src 'self' data:");

// ✅ Load config
require_once __DIR__ . '/_setting/helper/config_helper.php';
require_once __DIR__ . '/_setting/helper/url_helper.php';

$login_failed = isset($_GET['login']) && $_GET['login'] === 'fail';
$attempt_left = $_SESSION['login_attempts_left'] ?? 5;

// ✅ CSRF Token
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars(app_config('site.title', 'System e-Smartcard UPNM')) ?></title>
  <link rel="icon" href="<?= base_url(app_config('site.favicon', 'img/default.ico')) ?>" type="image/x-icon">

  <script src="https://cdn.tailwindcss.com?v=<?= $version ?>"></script>
  <!--<link href="/css/output.css" rel="stylesheet">-->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js?v=<?= $version ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11?v=<?= $version ?>"></script>

  <style>
    body { font-family: 'Poppins', sans-serif; font-size: 13px; }
  </style>
</head>
<body class="bg-gray-100" x-data>

<!-- Header -->
<header class="bg-white shadow">
  <div class="max-w-7xl mx-auto p-4">
    <img src="img/esmart.png" alt="UPNM Logo" class="w-44">
    <nav class="flex space-x-4 border-b border-gray-300 mt-4">
      <button class="tab-btn px-4 py-2 font-semibold text-[#0babcd] border-b-4 border-[#0babcd]">Primary</button>
      <button @click="$store.faq.showFaq()" class="tab-btn px-4 py-2 text-[#0babcd] hover:font-semibold hover:border-b-4 hover:border-[#0babcd]">Frequently Asked Questions</button>
      <a href="direktori_upnm.php" target="new" class="px-4 py-2 text-[#0babcd] hover:font-semibold hover:border-b-4 hover:border-[#0babcd]">UPNM Directory</a>
    </nav>
  </div>
</header>

<!-- Main -->
<main class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-6 mt-6 px-4">

  <!-- Banner -->
  <div class="md:col-span-2 bg-white rounded-xl shadow overflow-hidden">
    <div x-data="{
      activeSlide: 0,
      slides: ['img/banner1.jpg', 'img/banner2.jpg', 'img/banner3.jpg', 'img/banner4.jpg'],
      init() {
        setInterval(() => {
          this.activeSlide = (this.activeSlide + 1) % this.slides.length;
        }, 4000);
      }
    }" class="relative h-[400px]">
      <template x-for="(slide, index) in slides" :key="index">
        <img :src="slide" class="absolute inset-0 w-full h-full object-cover transition-opacity duration-1000" :class="activeSlide === index ? 'opacity-100 z-10' : 'opacity-0 z-0'">
      </template>
      <button @click="activeSlide = (activeSlide - 1 + slides.length) % slides.length" class="absolute left-2 top-1/2 transform -translate-y-1/2 bg-white p-2 rounded-full shadow">‹</button>
      <button @click="activeSlide = (activeSlide + 1) % slides.length" class="absolute right-2 top-1/2 transform -translate-y-1/2 bg-white p-2 rounded-full shadow">›</button>
      <div class="absolute bottom-2 left-1/2 transform -translate-x-1/2 flex space-x-2 z-20">
        <template x-for="(slide, index) in slides" :key="index">
          <div @click="activeSlide = index" :class="activeSlide === index ? 'bg-blue-600' : 'bg-gray-300'" class="w-3 h-3 rounded-full cursor-pointer"></div>
        </template>
      </div>
    </div>

    <section class="p-6 text-sm">
      <h2 class="text-lg font-semibold mb-4">📢 Contact Us</h2>
      <div class="grid md:grid-cols-3 gap-6">
        <div><p class="font-semibold">Encik Abdul Farriz Saupi</p><p class="text-gray-600">📞 03-9051 3400 (ext 761 4491)</p></div>
        <div><p class="font-semibold">Puan Herdahidayu Abu Hashim</p><p class="text-gray-600">📞 03-9051 3400 (ext 761 4553)</p></div>
        <div><p class="font-semibold">Encik Zainuddin Mursid</p><p class="text-gray-600">📞 03-9051 3400 (ext 762 4926)</p></div>
      </div>
      <div class="mt-4">
        <p class="font-semibold">Email: <a href="mailto:bkp@upnm.edu.my" class="text-blue-600 underline">bkp@upnm.edu.my</a></p>
        <p class="mt-2 font-semibold">Address:</p>
        <p class="text-gray-600">Management Services Division,<br>Registrar Office,<br>Universiti Pertahanan Nasional Malaysia (UPNM),<br>Kem Perdana Sungai Besi,<br>57000 Kuala Lumpur</p>
      </div>
    </section>
  </div>

  <!-- Login Card -->
  <div class="bg-white p-8 rounded-xl shadow">
    <div class="text-center mb-6">
      <img src="img/esmart.png" class="mx-auto h-20 mb-2" alt="Logo">
      <h2 class="text-lg font-bold text-gray-700">Sign In E-Smartcard</h2>
    </div>
    <form method="POST" action="login_exe.php" autocomplete="off" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <div>
        <label for="f_stafID" class="block font-medium text-gray-700">User ID</label>
        <input id="f_stafID" name="f_stafID" type="text" required class="w-full px-4 py-2 border rounded-md focus:ring-2 focus:ring-blue-500" autocomplete="username">
      </div>
      <div>
        <label for="f_password" class="block font-medium text-gray-700">Password</label>
        <input id="f_password" name="f_password" type="password" required class="w-full px-4 py-2 border rounded-md focus:ring-2 focus:ring-blue-500" autocomplete="current-password">
      </div>
      <div class="text-right">
        <a href="#" onclick="return false;" class="text-sm text-blue-600 hover:underline">Forgot Password?</a>
      </div>
      <p class="text-sm text-gray-600 text-center">For your first sign-in, use your identity card number without hyphens as the password.</p>
      <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded-md font-semibold hover:bg-blue-700 transition">Sign In</button>
    </form>
    <p class="text-center text-gray-400 text-xs mt-10">
      <?= htmlspecialchars(app_config('system.name', 'e-Smartcard')) ?> <?= htmlspecialchars(app_config('system.version', '2.0.0')) ?><br>
      <?= htmlspecialchars(app_config('system.author', 'Hak Cipta © UPNM')) ?>
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
      <h2 class="text-xl font-semibold text-blue-700 mb-4">❓ Frequently Asked Questions (FAQ)</h2>
      <div class="max-h-[60vh] overflow-y-auto pr-2 text-sm leading-relaxed text-gray-700" x-html="$store.faq.contentFaq"></div>
    </div>
  </div>
</main>

<?php if ($login_failed): ?>
<script>
Swal.fire({
  icon: 'error',
  title: 'Sign-In Error',
  text: 'Invalid user ID or password!',
  footer: 'Percubaan tinggal: <?= $attempt_left ?>',
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
        const res = await fetch('soalan_lazim_content.php');
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

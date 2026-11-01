<?php
/**
 * EMS — Maintenance Page
 * ─────────────────────────────────────────────────────────────────
 * This page can be reached two different ways, and has to work for
 * both:
 *
 *   1. Mid-request, `require`'d by includes/maintenance-guard.php
 *      when maintenance mode is active. In that case the 503 header
 *      is already sent and $GLOBALS['__maintenance_message'] /
 *      ['__maintenance_eta'] are already set — this file just renders.
 *
 *   2. Visited directly by URL (someone's bookmark, or the admin
 *      previewing it) — in which case it resolves everything itself.
 *
 * Deliberately resilient: the whole point of this page is "something
 * is wrong, please be patient" — so if the database itself is what's
 * down for maintenance, this page must still render correctly. Every
 * optional enhancement (school name, logo, contact info) is wrapped
 * so a DB hiccup falls back to sensible defaults instead of a fatal
 * error on the one page that absolutely cannot afford to break.
 */

$viaGuard = isset($GLOBALS['__maintenance_message']);

if ($viaGuard) {
    $message = $GLOBALS['__maintenance_message'];
    $eta     = $GLOBALS['__maintenance_eta'];
} else {
    $message = '';
    $eta     = '';
    $statusFile = __DIR__ . '/includes/maintenance-status.php';
    if (is_file($statusFile)) {
        try {
            $status = include $statusFile;
            if (is_array($status)) {
                $message = $status['message'] ?? '';
                $eta     = $status['eta']     ?? '';
            }
        } catch (Throwable $e) { /* keep defaults */ }
    }
    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 1800');
    }
}

// Best-effort branding — never let this break the page.
$schoolName  = 'Our School';
$schoolPhone = '';
$schoolEmail = '';
$logoUrl     = '';
try {
    require_once __DIR__ . '/includes/functions.php';
    $n = getSchoolName();
    if ($n !== '') $schoolName = $n;
    $schoolPhone = getSchoolPhone();
    $schoolEmail = getSchoolEmail();
    $logoUrl     = getSchoolLogoUrl();
} catch (Throwable $e) { /* keep defaults, page still renders fine */ }

// Local escape helper — never depends on functions.php having loaded.
function mh($str) { return htmlspecialchars((string)$str, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

$hasMessage = trim($message) !== '';
// $eta is now stored as a datetime-local value (e.g. "2026-07-20T18:30")
// coming from the Settings panel's date+time picker. Only treat it as
// "set" if it actually parses to a real, still-in-the-future-or-now date —
// this keeps the page resilient even if the stored value is old/garbled.
$hasEta = false;
$etaTimestamp = null;
if (trim($eta) !== '') {
    $etaTimestamp = strtotime($eta);
    $hasEta = $etaTimestamp !== false;
}
$hasContact = trim($schoolPhone) !== '' || trim($schoolEmail) !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Under Maintenance - <?= EMS_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  ::selection { background-color: #7d59ff; color: #fff; text-shadow: none; }

  html, body { height: 100%; background: linear-gradient(135deg, #1a1a2e, #16213e, #0f3460); }

  body {
    font-family: 'Inter', system-ui, sans-serif;
    -webkit-font-smoothing: antialiased;
    min-height: 100vh;
    background: linear-gradient(135deg, #1a1a2e, #16213e, #0f3460);
    color: #fff;
    position: relative;
    overflow-x: hidden;
    overflow-y: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
  }

html,
body {
    overflow-y: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

html::-webkit-scrollbar,
body::-webkit-scrollbar {
    display: none;
}

  .overlay {
    position: fixed;
    inset: 0;
    background-color: rgba(0, 0, 0, 0.7);
    z-index: 1;
  }

  .container {
    position: relative;
    z-index: 2;
    max-width: 720px;
    width: 100%;
    margin: 0 auto;
    padding: 2rem;
    min-height: 100vh;
    min-height: 100dvh;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
  }

  .logo img,
  .logo-fallback {
    width: 96px;
    height: 96px;
    border-radius: 20%;
    margin-bottom: 22px;
    object-fit: cover;
  }
  .logo-fallback {
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #7d59ff, #00b4db);
    font-size: 34px;
  }

  h1 {
    font-size: 2.75rem;
    font-weight: 800;
    margin-bottom: 1.2rem;
    line-height: 1.2;
    background: linear-gradient(120deg, #a1c4fd, #7d59ff);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-size: 200% auto;
    animation: gradientFlow 4s ease infinite;
  }
  h1 i { -webkit-text-fill-color: initial; color: #a1c4fd; margin-right: .3em; }

  @keyframes gradientFlow {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
  }

  .maint-message {
    font-size: 1.15rem;
    font-weight: 400;
    margin-bottom: 2rem;
    max-width: 560px;
    line-height: 1.6;
    color: rgba(255,255,255,.9);
  }

  .maint-eyebrow {
    display: inline-flex; align-items: center; gap: 7px;
    font-size: 12px; font-weight: 700; letter-spacing: .08em;
    text-transform: uppercase; color: #a1c4fd;
    background: rgba(129,140,248,.12); border: 1px solid rgba(129,140,248,.3);
    padding: 6px 14px; border-radius: 20px;
    margin-bottom: 20px;
  }
  .maint-eyebrow .dot { width: 6px; height: 6px; border-radius: 50%; background: #a1c4fd; animation: dotBlink 1.4s ease-in-out infinite; }
  @keyframes dotBlink { 0%,100% { opacity: 1; } 50% { opacity: .3; } }

  /* ── Countdown (shown only when an Expected-back date/time is set) ─── */
  .timer-text {
    font-size: 1.05rem;
    font-weight: 600;
    margin-bottom: 1.1rem;
    opacity: .9;
  }

  .countdown {
    display: flex;
    justify-content: center;
    gap: 1.25rem;
    margin: 0 0 2.2rem;
    flex-wrap: wrap;
  }

  .countdown-item {
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  .countdown-value {
    font-size: 2.6rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    background: rgba(255, 255, 255, 0.1);
    padding: 0.5rem 1.4rem;
    border-radius: 10px;
    min-width: 92px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
  }

  .countdown-label {
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin-top: 0.5rem;
    opacity: 0.75;
  }

  /* ── Working indicator (shown when no Expected-back date/time is set) ─ */
  .working-block {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    margin: 0 0 2.2rem;
    background: rgba(255, 255, 255, 0.08);
    padding: 1rem 1.8rem;
    border-radius: 14px;
  }
  .working-text {
    font-size: 1.1rem;
    font-weight: 600;
    letter-spacing: .02em;
  }
  .working-dots { display: flex; gap: 6px; }
  .working-dots span {
    width: 8px; height: 8px; border-radius: 50%;
    background: #a1c4fd;
    animation: workBounce 1.2s ease-in-out infinite;
  }
  .working-dots span:nth-child(2) { animation-delay: .15s; }
  .working-dots span:nth-child(3) { animation-delay: .3s; }
  @keyframes workBounce {
    0%, 60%, 100% { transform: translateY(0); opacity: .4; }
    30%           { transform: translateY(-7px); opacity: 1; }
  }

  /* ── Contact row (replaces social links) ────────────────────────────── */
  .contact-links {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 1rem;
    margin: 1.6rem 0 0;
  }

  .contact-links a {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    color: #fff;
    text-decoration: none;
    font-size: .95rem;
    font-weight: 500;
    background: rgba(255,255,255,.08);
    padding: .6rem 1.1rem;
    border-radius: 10px;
    transition: all .25s ease;
  }
  .contact-links a i { font-size: 1rem; color: #a1c4fd; }
  .contact-links a:hover {
    background: rgba(255,255,255,.16);
    transform: translateY(-2px);
  }

  footer {
    position: absolute;
    bottom: 1rem;
    width: 100%;
    z-index: 2;
    text-align: center;
    font-size: 0.9rem;
    opacity: 0.6;
}

  /* ── Rotating gears background ───────────────────────────────────────── */
  .gear {
    position: fixed;
    font-size: 8rem;
    color: rgba(255, 255, 255, 0.4);
    z-index: 0;
    animation-duration: 10s;
    animation-iteration-count: infinite;
    animation-timing-function: linear;
    pointer-events: none;
  }
  .gear-1 { top: 10%; left: 10%; animation-name: spin-clockwise; }
  .gear-2 { top: 20%; right: 15%; font-size: 12rem; animation-name: spin-counter-clockwise; animation-duration: 15s; }
  .gear-3 { bottom: 15%; left: 20%; font-size: 6rem; animation-name: spin-clockwise; animation-duration: 8s; }
  .gear-4 { bottom: 25%; right: 10%; font-size: 10rem; animation-name: spin-counter-clockwise; animation-duration: 20s; }
  .gear-5 { top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 15rem; animation-name: spin-clockwise; animation-duration: 25s; opacity: 0.5; }

  @keyframes spin-clockwise { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
  @keyframes spin-counter-clockwise { from { transform: rotate(0deg); } to { transform: rotate(-360deg); } }

  @media (max-width: 768px) {
    h1 { font-size: 1.9rem; }
    .maint-message { font-size: 1rem; }
    .countdown { gap: 0.6rem; }
    .countdown-value { font-size: 1.5rem; min-width: 62px; padding: 0.5rem; }
    .countdown-label { font-size: 0.62rem; }
    .working-block { padding: .8rem 1.2rem; }
    .working-text { font-size: .95rem; }
    .gear { font-size: 4rem; }
    .gear-2 { font-size: 6rem; }
    .gear-4 { font-size: 5rem; }
    .gear-5 { font-size: 8rem; }
    .timer-text { font-size: 0.9rem; }
    .contact-links a { font-size: .85rem; padding: .55rem .9rem; }
  }

  @media (prefers-reduced-motion: reduce) {
    .gear, h1, .maint-eyebrow .dot, .working-dots span {
      animation: none !important;
    }
  }
</style>
</head>
<body>

  <!-- Rotating gears background -->
  <i class="fas fa-cog gear gear-1"></i>
  <i class="fas fa-cog gear gear-2"></i>
  <i class="fas fa-cog gear gear-3"></i>
  <i class="fas fa-cog gear gear-4"></i>
  <i class="fas fa-cog gear gear-5"></i>

  <div class="overlay"></div>

  <div class="container">

    <div class="logo">
      <?php if ($logoUrl): ?>
        <img src="<?= EMS_LOGO ?>" alt="<?= EMS_NAME ?>">
      <?php else: ?>
        <div class="logo-fallback"><i class="fas fa-school"></i></div>
      <?php endif; ?>
    </div>

    <div class="maint-eyebrow"><span class="dot"></span> <?= mh($schoolName) ?></div>

    <h1><i class="fas fa-tools"></i>Under Maintenance</h1>

    <p class="maint-message">
      <?php if ($hasMessage): ?>
        <?= mh($message) ?>
      <?php else: ?>
        We're currently working hard to improve the system and provide you a better experience. Thank you for your patience!
      <?php endif; ?>
    </p>

    <?php if ($hasEta): ?>
      <div class="timer-text">We will be back in:</div>
      <div class="countdown">
        <div class="countdown-item">
          <div class="countdown-value" id="days">00</div>
          <div class="countdown-label">Days</div>
        </div>
        <div class="countdown-item">
          <div class="countdown-value" id="hours">00</div>
          <div class="countdown-label">Hours</div>
        </div>
        <div class="countdown-item">
          <div class="countdown-value" id="minutes">00</div>
          <div class="countdown-label">Minutes</div>
        </div>
        <div class="countdown-item">
          <div class="countdown-value" id="seconds">00</div>
          <div class="countdown-label">Seconds</div>
        </div>
      </div>
    <?php else: ?>
      <div class="working-block">
        <span class="working-text">Working on it</span>
        <span class="working-dots"><span></span><span></span><span></span></span>
      </div>
    <?php endif; ?>

    <?php if ($hasContact): ?>
    <div class="contact-links">
      <?php if (trim($schoolPhone) !== ''): ?>
        <a href="tel:<?= mh(preg_replace('/\s+/', '', $schoolPhone)) ?>">
          <i class="fas fa-phone"></i> <?= mh($schoolPhone) ?>
        </a>
      <?php endif; ?>
      <?php if (trim($schoolEmail) !== ''): ?>
        <a href="mailto:<?= mh($schoolEmail) ?>">
          <i class="fas fa-envelope"></i> <?= mh($schoolEmail) ?>
        </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>

  <footer>&copy; <?= date('Y') ?> <?= EMS_NAME ?>. All Rights Reserved.</footer>

<?php if ($hasEta): ?>
<script>
    // Target date/time comes from Admin -> Settings -> Maintenance Mode -> Expected back.
    // Stored as a datetime-local value (e.g. "2026-07-20T18:30"), which the
    // JS Date constructor parses directly as a local date/time.
    const targetDate = new Date(<?= json_encode($eta) ?>);

    function updateCountdown() {
        const now = new Date();
        const difference = targetDate - now;

        if (isNaN(difference) || difference <= 0) {
            document.getElementById('days').textContent = '00';
            document.getElementById('hours').textContent = '00';
            document.getElementById('minutes').textContent = '00';
            document.getElementById('seconds').textContent = '00';
            return;
        }

        const days    = Math.floor(difference / (1000 * 60 * 60 * 24));
        const hours   = Math.floor((difference % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((difference % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((difference % (1000 * 60)) / 1000);

        document.getElementById('days').textContent    = days.toString().padStart(2, '0');
        document.getElementById('hours').textContent   = hours.toString().padStart(2, '0');
        document.getElementById('minutes').textContent = minutes.toString().padStart(2, '0');
        document.getElementById('seconds').textContent = seconds.toString().padStart(2, '0');
    }

    updateCountdown();
    setInterval(updateCountdown, 1000);
</script>
<?php endif; ?>

</body>
</html>
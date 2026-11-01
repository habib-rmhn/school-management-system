<?php
/**
 * EMS Teacher — Universal Sidebar & Shell Include  v1.0
 * ─────────────────────────────────────────────────────────────────
 * USAGE — at the top of EVERY teacher page, set these variables
 * BEFORE require_once-ing this file (teacher-session.php must
 * already have been required before this, so $_SESSION is ready):
 *
 *   $pageTitle  = 'Attendance';        // <title> tag + topbar
 *   $activeNav  = 'attendance';        // highlights the nav item
 *   $breadcrumb = ['Attendance'];      // string[] for breadcrumb
 *   $extraCss   = 'assets/css/pages/attendance.css'; // optional
 *
 * Then at the very bottom of the page:
 *   require_once __DIR__ . '/includes/teacher-footer.php';
 * ─────────────────────────────────────────────────────────────────
 */

$pageTitle  = $pageTitle  ?? 'Teacher Panel';
$activeNav  = $activeNav  ?? '';
$breadcrumb = $breadcrumb ?? [$pageTitle];
$extraCss   = $extraCss   ?? '';

$teacherName = h($_SESSION['ems_display_name'] ?? 'Teacher');
$teacherUid  = $_SESSION['ems_uid'] ?? '';
$designation = h($_SESSION['ems_designation'] ?? 'Teacher');

$assetBase = rtrim(BASE_URL, '/') . '/teacher/assets';

// ── Resolve teacher photo (real photo, else gender-based avatar) ──
$photoUrl   = '';
$hasRealPic = false;

try {
    $stmt = dbQuery(
        "SELECT t.photo_path, t.gender, u.username
         FROM teachers t
         LEFT JOIN system_users u ON u.sys_uid = t.sys_uid
         WHERE t.sys_uid = ? LIMIT 1",
        [$teacherUid]
    );
    $tRow = $stmt->fetch();
} catch (Throwable $e) {
    $tRow = null;
}

$teacherGender = $tRow['gender'] ?? ($_SESSION['ems_gender'] ?? 'male');
$photoPath     = $tRow['photo_path'] ?? ($_SESSION['ems_photo'] ?? '');
$teacherUsername = h($tRow['username'] ?? ($_SESSION['ems_username'] ?? ''));

if (!empty($photoPath)) {
    $diskPath = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/' . ltrim($photoPath, '/');
    if (@file_exists($diskPath)) {
        $photoUrl   = rtrim(BASE_URL, '/') . '/' . ltrim($photoPath, '/');
        $hasRealPic = true;
    }
}

if (!$hasRealPic) {
    $fallback = (strtolower($teacherGender) === 'female')
        ? '/assets/images/teacher-avatar-f.jpg'
        : '/assets/images/teacher-avatar-m.jpg';
    $photoUrl = rtrim(BASE_URL, '/') . $fallback;
}

// ── Nav item renderer ──────────────────────────────────────────────
function tNavLink(string $key, string $active, string $href, string $icon, string $label, string $badge = '', string $tip = ''): string {
    $isActive  = ($key === $active);
    $cls       = 'nav-item' . ($isActive ? ' active' : '');
    $badgeHtml = $badge ? "<span class='nav-badge'>{$badge}</span>" : '';
    $tipAttr   = $tip ? " data-tip='" . htmlspecialchars($tip, ENT_QUOTES) . "'" : '';
    $url       = BASE_URL . '/teacher/' . $href;
    return "<a href='{$url}' class='{$cls}'{$tipAttr}>
              <i class='fas {$icon}'></i>
              <span class='nav-label'>{$label}</span>
              {$badgeHtml}
            </a>";
}

// ── Fetch current session label ────────────────────────────────────
$_sbSession = null;
try {
    $_sbSession = dbQuery("SELECT session_year FROM sessions WHERE is_current = 1 LIMIT 1")->fetch();
} catch (Throwable $e) {}
$sessionLabel = $_sbSession ? 'Session ' . $_sbSession['session_year'] : 'No session';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?> - <?= h(EMS_NAME) ?> Teacher</title>
  <link rel="apple-touch-icon" sizes="180x180" href="<?= h(EMS_FAVICONS) ?>/apple-touch-icon.png"><link rel="icon" type="image/png" sizes="32x32" href="<?= h(EMS_FAVICONS) ?>/favicon-32x32.png"><link rel="icon" type="image/png" sizes="16x16" href="<?= h(EMS_FAVICONS) ?>/favicon-16x16.png"><link rel="manifest" href="<?= h(EMS_FAVICONS) ?>/site.webmanifest">

  <!-- Preconnect for Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <!-- Universal Teacher CSS -->
  <link rel="stylesheet" href="<?= $assetBase ?>/css/teacher.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/datepicker.css">

  <?php if ($extraCss): ?>
  <!-- Page-specific CSS -->
  <link rel="stylesheet" href="<?= $assetBase . '/' . h($extraCss) ?>">
  <?php endif; ?>

  <!-- Page-level <style> overrides, if any, go in the page file
       right after this include — the HTML head is still open here
       only up until </head> below, so keep page styles AFTER
       the footer's closing tags, or inline within main content. -->
</head>
<body data-logout-url="<?= BASE_URL ?>/logout.php">

<div class="mob-overlay" id="mobOverlay"></div>

<div class="ems-shell">

<!-- ══════════════════════════════════════════════════════════════
     SIDEBAR
     ══════════════════════════════════════════════════════════════ -->
<aside class="ems-sidebar" id="emsSidebar" aria-label="Teacher navigation">

  <!-- Brand -->
  <div class="sidebar-brand">
    <div class="brand-icon"><img src="<?= h(EMS_LOGO) ?>" alt="<?= h(EMS_NAME) ?>" class="brand-logo-img"></div>
    <div class="brand-text">
      <div class="brand-name"><?= h(EMS_NAME) ?></div>
      <div class="brand-sub">Teacher Portal</div>
    </div>
  </div>

  <!-- Profile card -->
  <a href="<?= BASE_URL ?>/teacher/profile.php" class="sidebar-profile" style="text-decoration:none">
    <img src="<?= h($photoUrl) ?>" alt="<?= $teacherName ?>" class="sp-photo"
         onerror="this.onerror=null;this.src='<?= rtrim(BASE_URL,'/') ?>/assets/images/teacher-avatar-m.jpg';">
    <div class="sp-info">
      <div class="sp-name" title="<?= $teacherName ?>"><?= $teacherName ?></div>
      <div class="sp-role"><?= $designation ?></div>
      <?php if ($teacherUsername): ?>
        <span class="sp-username">@<?= $teacherUsername ?></span>
      <?php endif; ?>
    </div>
  </a>

  <!-- Nav -->
  <nav class="sidebar-nav">

    <div class="nav-section">Overview</div>
    <?= tNavLink('dashboard', $activeNav, 'dashboard.php', 'fa-house',      'Dashboard',      '', 'Dashboard') ?>
    <?= tNavLink('today',     $activeNav, 'today.php',     'fa-sun',        "Today's Classes",'', "Today's Classes") ?>

    <div class="nav-section">My Work</div>
    <?= tNavLink('routine',    $activeNav, 'routine.php',    'fa-calendar-week',  'My Routine',    '', 'My Routine') ?>
    <?= tNavLink('attendance', $activeNav, 'attendance.php', 'fa-calendar-check', 'Attendance',    '', 'Attendance') ?>
    <?= tNavLink('marks',      $activeNav, 'marks.php',      'fa-pencil',         'Upload Marks',  '', 'Upload Marks') ?>
    <?= tNavLink('students',   $activeNav, 'students.php',   'fa-users',          'Student List',  '', 'Student List') ?>

    <div class="nav-section">Account</div>
    <?= tNavLink('profile', $activeNav, 'profile.php', 'fa-user', 'My Profile', '', 'My Profile') ?>

  </nav><!-- /nav -->

  <!-- Logout footer -->
  <div class="sidebar-footer">
    <button type="button" class="sidebar-logout-btn" id="sidebarLogoutBtn">
      <i class="fas fa-right-from-bracket"></i>
      <span class="logout-label">Logout</span>
    </button>
  </div>

</aside><!-- /sidebar -->

<!-- ══════════════════════════════════════════════════════════════
     TOPBAR
     ══════════════════════════════════════════════════════════════ -->
<header class="ems-topbar" role="banner">
  <div class="topbar-left">
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation" aria-expanded="false">
      <i class="fas fa-bars"></i>
    </button>

    <nav class="topbar-breadcrumb" aria-label="Breadcrumb">
      <span>Teacher</span>
      <i class="fas fa-chevron-right"></i>
      <?php
      if (is_array($breadcrumb) && count($breadcrumb) > 1) {
          foreach ($breadcrumb as $k => $crumb) {
              if (is_array($crumb)) {
                  echo '<a href="' . h($crumb['url']) . '">' . h($crumb['label']) . '</a>';
              } elseif ($k === array_key_last($breadcrumb)) {
                  echo '<span class="bc-current">' . h($crumb) . '</span>';
              } else {
                  echo '<span>' . h($crumb) . '</span>';
              }
              if ($k !== array_key_last($breadcrumb)) echo '<i class="fas fa-chevron-right"></i>';
          }
      } else {
          $crumb = is_array($breadcrumb) ? ($breadcrumb[0] ?? $pageTitle) : $breadcrumb;
          echo '<span class="bc-current">' . h($crumb) . '</span>';
      }
      ?>
    </nav>
  </div>

  <div class="topbar-right">
    <!-- Session chip -->
    <div class="session-chip">
      <i class="fas fa-calendar-alt"></i>
      <?= h($sessionLabel) ?>
    </div>

    <!-- Live clock (BST) -->
    <span class="topbar-time" id="liveClock" title="Bangladesh Standard Time">—</span>

    <!-- Profile avatar + name → links to profile page -->
    <a href="<?= BASE_URL ?>/teacher/profile.php" class="topbar-avatar-link">
      <img src="<?= h($photoUrl) ?>" alt="<?= $teacherName ?>"
           onerror="this.onerror=null;this.src='<?= rtrim(BASE_URL,'/') ?>/assets/images/teacher-avatar-m.jpg';">
      <span><?= ucfirst($teacherUsername) ?></span>
    </a>
  </div>
</header>

<!-- ══════════════════════════════════════════════════════════════
     MAIN — page content goes here, closed by teacher-footer.php
     ══════════════════════════════════════════════════════════════ -->
<main class="ems-main" id="emsMain">
<div class="main-inner">
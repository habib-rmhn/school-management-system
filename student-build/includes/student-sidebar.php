<?php
/**
 * EMS Student — Universal Sidebar & Shell Include  v1.0
 * ─────────────────────────────────────────────────────────────────
 * USAGE — at the top of EVERY student page, set these BEFORE
 * require_once-ing this file:
 *
 *   $pageTitle  = 'My Results';      // <title> tag + topbar
 *   $activeNav  = 'results';         // highlights the nav item
 *   $extraCss   = 'assets/css/pages/results.css'; // optional
 *
 * Then at the very bottom of the page:
 *   require_once __DIR__ . '/includes/student-footer.php';
 * ─────────────────────────────────────────────────────────────────
 */

$pageTitle = $pageTitle ?? 'Student Portal';
$activeNav = $activeNav ?? '';
$extraCss  = $extraCss  ?? '';

$studentName  = h($_SESSION['ems_display_name'] ?? 'Student');
$studentIdNo  = h($_SESSION['ems_student_id']   ?? '');
$studentUid   = $_SESSION['ems_uid'] ?? '';

$assetBase = rtrim(BASE_URL, '/') . '/student/assets';

// ── Fetch a bit more profile info for the sidebar card ────────────
$studentClass  = '';
$studentPhoto  = '';
$studentGender = 'male';
try {
    $row = dbQuery(
        "SELECT s.photo_path, s.gender, c.label AS class_label, sess.session_year
         FROM students s
         LEFT JOIN enrollments e ON e.sys_uid = s.sys_uid AND e.status = 'active'
         LEFT JOIN classes c ON c.class_id = e.class_id
         LEFT JOIN sessions sess ON sess.session_id = e.session_id
         WHERE s.sys_uid = ?
         ORDER BY e.enrollment_id DESC LIMIT 1",
        [$studentUid]
    )->fetch();
    if ($row) {
        $studentClass  = $row['class_label'] ? ($row['class_label'] . ($row['session_year'] ? ' · ' . $row['session_year'] : '')) : '';
        $studentPhoto  = $row['photo_path'] ?? '';
        $studentGender = $row['gender'] ?? 'male';
    }
} catch (Throwable $e) {}

// ── Resolve avatar: real photo > gendered default avatar ─────────
function studentAvatarUrl(string $photoPath, string $gender, string $assetBase): string {
    if ($photoPath && file_exists(__DIR__ . '/../../uploads/student/photos/' . basename($photoPath))) {
        return rtrim(BASE_URL, '/') . '/uploads/student/photos/' . basename($photoPath);
    }
    return $assetBase . '/img/avatar-' . ($gender === 'female' ? 'female' : 'male') . '.svg';
}
$avatarUrl = studentAvatarUrl($studentPhoto, $studentGender, $assetBase);

// ── Active group detection (only "Exams" is grouped) ──────────────
$examItems = ['results', 'admit-card', 'seat-plan'];
$examOpen  = in_array($activeNav, $examItems, true);

// ── Nav item renderer ─────────────────────────────────────────────
function snavLink(string $key, string $active, string $href, string $icon, string $label, string $tip = ''): string {
    $isActive = ($key === $active);
    $cls      = 'nav-item' . ($isActive ? ' active' : '');
    $tipAttr  = $tip ? " data-tip='" . htmlspecialchars($tip, ENT_QUOTES) . "'" : '';
    $url      = BASE_URL . '/student/' . $href;
    return "<a href='{$url}' class='{$cls}'{$tipAttr}>
              <i class='fas {$icon}'></i>
              <span class='nav-label'>{$label}</span>
            </a>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <title><?= h($pageTitle) ?> — <?= h(EMS_SCHOOL) ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <!-- Universal Student CSS -->
  <link rel="stylesheet" href="<?= $assetBase ?>/css/student.css">

  <?php if ($extraCss): ?>
  <link rel="stylesheet" href="<?= $assetBase . '/' . h($extraCss) ?>">
  <?php endif; ?>
</head>
<body>

<div class="mob-overlay" id="mobOverlay"></div>

<div class="ems-shell">

<!-- ══════════════════════════════════════════════════════════════
     SIDEBAR
     ══════════════════════════════════════════════════════════════ -->
<aside class="ems-sidebar" id="emsSidebar" aria-label="Student navigation">

  <!-- Brand -->
  <div class="sidebar-brand">
    <div class="brand-icon"><i class="fas fa-graduation-cap"></i></div>
    <div class="brand-text">
      <div class="brand-name"><?= h(EMS_SCHOOL) ?></div>
      <div class="brand-sub">Student Portal</div>
    </div>
  </div>

  <!-- Student identity card -->
  <a href="<?= BASE_URL ?>/student/profile.php" class="sidebar-student-card" style="text-decoration:none">
    <img src="<?= h($avatarUrl) ?>" alt="My Photo" class="ssc-avatar">
    <div class="ssc-info">
      <div class="ssc-name"><?= $studentName ?></div>
      <div class="ssc-meta"><?= $studentClass ? h($studentClass) : 'No active class' ?></div>
      <?php if ($studentIdNo): ?>
        <span class="ssc-id">ID: <?= $studentIdNo ?></span>
      <?php endif; ?>
    </div>
  </a>

  <!-- Nav -->
  <nav class="sidebar-nav">

    <div class="nav-section">Main</div>
    <?= snavLink('dashboard', $activeNav, 'dashboard.php', 'fa-house', 'Home', 'Home') ?>
    <?= snavLink('routine',   $activeNav, 'routine.php',   'fa-calendar-week', 'Class Routine', 'Class Routine') ?>
    <?= snavLink('attendance',$activeNav, 'attendance.php','fa-calendar-check', 'My Attendance', 'My Attendance') ?>

    <div class="nav-section">Exams</div>
    <a href="#" class="nav-item nav-parent<?= $examOpen ? ' open' : '' ?>">
      <i class="fas fa-file-pen"></i>
      <span class="nav-label">Exams</span>
      <i class="fas fa-chevron-right nav-chevron"></i>
    </a>
    <div class="nav-sub<?= $examOpen ? ' open' : '' ?>">
      <?= snavLink('results',    $activeNav, 'results.php',    'fa-chart-simple', 'My Results') ?>
      <?= snavLink('admit-card', $activeNav, 'admit-card.php', 'fa-id-card',      'Admit Card') ?>
      <?= snavLink('seat-plan',  $activeNav, 'seat-plan.php',  'fa-chair',        'Seat Plan') ?>
    </div>

    <div class="nav-section">Other</div>
    <?= snavLink('payments', $activeNav, 'payments.php', 'fa-money-bill-wave', 'My Payments', 'My Payments') ?>
    <?= snavLink('profile',  $activeNav, 'profile.php',  'fa-user',            'My Profile',  'My Profile') ?>

  </nav>

  <!-- Logout -->
  <div class="sidebar-footer">
    <button type="button" class="sidebar-logout-btn" data-logout-trigger>
      <i class="fas fa-right-from-bracket"></i>
      <span>Log Out</span>
    </button>
  </div>

</aside><!-- /sidebar -->

<!-- ══════════════════════════════════════════════════════════════
     TOPBAR
     ══════════════════════════════════════════════════════════════ -->
<header class="ems-topbar" role="banner">
  <div class="topbar-left">
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle menu">
      <i class="fas fa-bars"></i>
    </button>
    <div class="topbar-page-title"><?= h($pageTitle) ?></div>
  </div>

  <div class="topbar-right">
    <span class="topbar-time" id="liveClock">—</span>
    <a href="<?= BASE_URL ?>/student/profile.php" class="topbar-avatar-link">
      <img src="<?= h($avatarUrl) ?>" alt="Me">
      <span><?= explode(' ', $studentName)[0] ?></span>
    </a>
  </div>
</header>

<!-- ══════════════════════════════════════════════════════════════
     MAIN — closed by student-footer.php
     ══════════════════════════════════════════════════════════════ -->
<main class="ems-main" id="emsMain">
<div class="main-inner">
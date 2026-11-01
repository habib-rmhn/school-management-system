<?php
/**
 * EMS Admin — Universal Sidebar & Shell Include  v2.0
 * ─────────────────────────────────────────────────────────────────
 * USAGE — at the top of EVERY admin page, set these variables
 * BEFORE require_once-ing this file:
 *
 *   $pageTitle  = 'Students';          // <title> tag + topbar
 *   $activeNav  = 'students';          // highlights the nav item
 *   $breadcrumb = ['Students'];        // string[] or ['label'=>'url'] pairs
 *   $extraCss   = 'assets/css/pages/students.css'; // optional page CSS
 *
 * Then at the very bottom of the page:
 *   require_once __DIR__ . '/includes/admin-footer.php';
 * ─────────────────────────────────────────────────────────────────
 */

$pageTitle  = $pageTitle  ?? 'Admin Panel';
$activeNav  = $activeNav  ?? '';
$breadcrumb = $breadcrumb ?? [$pageTitle];
$extraCss   = $extraCss   ?? '';

$adminName = h($_SESSION['ems_display_name'] ?? 'Administrator');
$adminUid  = $_SESSION['ems_uid'] ?? '';

$assetBase = rtrim(BASE_URL, '/') . '/admin/assets';

// ── Active group detection ────────────────────────────────────────
$groupMap = [
    'students'  => ['students','admission'],
    'teachers'  => ['teachers','assign-teacher'],
    'academic'  => ['routine','subjects','sessions'],
    'exams'     => ['exams','marks','results','seat-plan'],
    'finance'   => ['payments'],
    'system'    => ['activity-log','login-logs'],
];
$openGroup = '';
foreach ($groupMap as $g => $items) {
    if (in_array($activeNav, $items, true)) { $openGroup = $g; break; }
}

// ── Nav item renderer ─────────────────────────────────────────────
function navLink(string $key, string $active, string $href, string $icon, string $label, string $badge = '', string $tip = ''): string {
    $isActive  = ($key === $active);
    $cls       = 'nav-item' . ($isActive ? ' active' : '');
    $badgeHtml = $badge ? "<span class='nav-badge'>{$badge}</span>" : '';
    $tipAttr   = $tip ? " data-tip='" . htmlspecialchars($tip, ENT_QUOTES) . "'" : '';
    $url       = BASE_URL . '/admin/' . $href;
    return "<a href='{$url}' class='{$cls}'{$tipAttr}>
              <i class='fas {$icon}'></i>
              <span class='nav-label'>{$label}</span>
              {$badgeHtml}
            </a>";
}

// ── Collapsible group renderer ────────────────────────────────────
function navGroup(string $icon, string $label, bool $open): string {
    $cls = 'nav-item nav-parent' . ($open ? ' open' : '');
    return "<a href='#' class='{$cls}'>
              <i class='fas {$icon}'></i>
              <span class='nav-label'>{$label}</span>
              <i class='fas fa-chevron-right nav-chevron'></i>
            </a>";
}

function navSub(bool $open): string {
    return "<div class='nav-sub" . ($open ? ' open' : '') . "'>";
}

// ── Fetch current session label ───────────────────────────────────
$_sbSession = null;
try {
    $_sbSession = dbQuery("SELECT session_year FROM sessions WHERE is_current = 1 LIMIT 1")->fetch();
} catch (Throwable $e) {}
$sessionLabel = $_sbSession ? 'Session ' . $_sbSession['session_year'] : 'No session';

// ── Fetch admin username (admin-session.php doesn't store it in session) ──
$_sbUsername = '';
try {
    $_sbUsername = dbQuery("SELECT username FROM system_users WHERE sys_uid = ? LIMIT 1", [$adminUid])->fetchColumn();
} catch (Throwable $e) {}
$adminUsername  = $_sbUsername ? '@' . $_sbUsername : '';
$adminAvatarUrl = rtrim(BASE_URL, '/') . '/assets/images/admin-avatar.jpg';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($pageTitle) ?> - <?= h(EMS_NAME) ?> Admin</title>
  <link rel="apple-touch-icon" sizes="180x180" href="<?= h(EMS_FAVICONS) ?>/apple-touch-icon.png"><link rel="icon" type="image/png" sizes="32x32" href="<?= h(EMS_FAVICONS) ?>/favicon-32x32.png"><link rel="icon" type="image/png" sizes="16x16" href="<?= h(EMS_FAVICONS) ?>/favicon-16x16.png"><link rel="manifest" href="<?= h(EMS_FAVICONS) ?>/site.webmanifest">

  <!-- Preconnect for Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">

  <!-- Universal Admin CSS -->
  <link rel="stylesheet" href="<?= $assetBase ?>/css/admin.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/datepicker.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/timepicker.css">

  <?php if ($extraCss): ?>
  <!-- Page-specific CSS -->
  <link rel="stylesheet" href="<?= $assetBase . '/' . h($extraCss) ?>">
  <?php endif; ?>

  <!-- Inline page-level overrides go in a <style> block in the page file,
       AFTER this include. The HTML is still open at this point. -->
</head>
<body>

<div class="mob-overlay" id="mobOverlay"></div>

<div class="ems-shell">

<!-- ══════════════════════════════════════════════════════════════
     SIDEBAR
     ══════════════════════════════════════════════════════════════ -->
<aside class="ems-sidebar" id="emsSidebar" aria-label="Admin navigation">

  <!-- Brand -->
  <div class="sidebar-brand">
    <div class="brand-icon"><img src="<?= h(EMS_LOGO) ?>" alt="<?= h(EMS_NAME) ?>" class="brand-logo-img"></div>
    <div class="brand-text">
      <div class="brand-name"><?= h(EMS_NAME) ?></div>
      <div class="brand-sub">Admin Panel</div>
    </div>
  </div>

  <!-- Nav -->
  <nav class="sidebar-nav">

    <!-- ── Overview ── -->
    <div class="nav-section">Overview</div>
    <?= navLink('dashboard', $activeNav, 'dashboard.php', 'fa-chart-pie', 'Dashboard', '', 'Dashboard') ?>
    

    <!-- ── People ── -->
    <div class="nav-section">People</div>

    <?= navGroup('fa-users', 'Students', $openGroup === 'students') ?>
    <?= navSub($openGroup === 'students') ?>
      <?= navLink('students',       $activeNav, 'students.php',       'fa-list-ul',       'All Students',    '', 'All Students') ?>
      <?= navLink('admission',      $activeNav, 'admission.php',      'fa-user-plus',     'New Admission',   '', 'Admission') ?>
      
    </div>

    <?= navGroup('fa-chalkboard-teacher', 'Teachers', $openGroup === 'teachers') ?>
    <?= navSub($openGroup === 'teachers') ?>
      <?= navLink('teachers',       $activeNav, 'teachers.php',       'fa-list-ul', 'All Teachers',   '', 'All Teachers') ?>
      <?= navLink('assign-teacher', $activeNav, 'assign-teacher.php', 'fa-link',    'Assign Teacher', '', 'Assign Teacher') ?>
    </div>

    <!-- ── Academics ── -->
    <div class="nav-section">Academics</div>

    <?= navLink('attendance', $activeNav, 'attendance.php', 'fa-calendar-check', 'Attendance', '', 'Attendance') ?>

    <?= navGroup('fa-book-open', 'Academic Setup', $openGroup === 'academic') ?>
    <?= navSub($openGroup === 'academic') ?>
      <?= navLink('routine',  $activeNav, 'routine.php',  'fa-calendar-week', 'Class Routine', '', 'Class Routine') ?>
      <?= navLink('subjects', $activeNav, 'subjects.php', 'fa-book',          'Subjects',      '', 'Subjects') ?>
      <?= navLink('sessions', $activeNav, 'sessions.php', 'fa-rotate',        'Sessions',      '', 'Sessions') ?>
    </div>
    <?= navLink('promote', $activeNav, 'promote.php', 'fa-arrow-trend-up','Class Upgrade', '', 'Class Upgrade') ?>

    <!-- ── Examinations ── -->
    <div class="nav-section">Examinations</div>

    <?= navGroup('fa-file-alt', 'Exams', $openGroup === 'exams') ?>
    <?= navSub($openGroup === 'exams') ?>
      <?= navLink('exams',     $activeNav, 'exams.php',     'fa-circle-plus',  'Create Exam',    '', 'Create Exam') ?>
      <?= navLink('marks',     $activeNav, 'marks.php',     'fa-pencil',       'Marks Entry',    '', 'Marks Entry') ?>
      <?= navLink('results',   $activeNav, 'results.php',   'fa-poll',         'Publish Results','', 'Publish Results') ?>
      <?= navLink('seat-plan', $activeNav, 'seat-plan.php', 'fa-chair',        'Seat Plan',      '', 'Seat Plan') ?>
    </div>

    <!-- ── Finance ── -->
    <div class="nav-section">Finance</div>
    <?= navLink('payments', $activeNav, 'payments.php', 'fa-money-bill-wave', 'Manage Payments', '', 'Payments') ?>

    <!-- ── System ── -->
    <div class="nav-section">System</div>

    <?= navGroup('fa-shield-halved', 'Logs & Security', $openGroup === 'system') ?>
    <?= navSub($openGroup === 'system') ?>
      <?= navLink('activity-log', $activeNav, 'activity-log.php', 'fa-clock-rotate-left',      'Activity Logs', '', 'Activity Log') ?>
      <?= navLink('login-logs',   $activeNav, 'login-logs.php',   'fa-right-to-bracket', 'Login Logs',  '', 'Login Logs') ?>
    </div>

    <?= navLink('reset-password', $activeNav, 'reset-password.php', 'fa-key', 'Reset Passwords', '', 'Reset Passwords') ?>
    <?= navLink('settings', $activeNav, 'settings.php', 'fa-gear', 'Settings', '', 'Settings') ?>

  </nav><!-- /nav -->

  <!-- User footer -->
  <div class="sidebar-footer">
    <a href="<?= BASE_URL ?>/logout.php"
       class="sidebar-user"
       data-logout-confirm="Are you sure you want to log out?">
      <div class="su-avatar"><img src="<?= h($adminAvatarUrl) ?>" alt="<?= h($adminName) ?>" class="su-avatar-img"></div>
      <div class="su-info">
        <div class="su-name"><?= $adminName ?></div>
        <div class="su-role"><?= h($adminUsername ?: 'Administrator') ?></div>
      </div>
      <i class="fas fa-right-from-bracket su-logout" title="Logout"></i>
    </a>
  </div>

</aside><!-- /sidebar -->

<!-- ══════════════════════════════════════════════════════════════
     TOPBAR
     ══════════════════════════════════════════════════════════════ -->
<header class="ems-topbar" role="banner">
  <div class="topbar-left">
    <!-- Hamburger / collapse toggle -->
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation" aria-expanded="false">
      <i class="fas fa-bars"></i>
    </button>

    <!-- Breadcrumb -->
    <nav class="topbar-breadcrumb" aria-label="Breadcrumb">
      <span>Admin</span>
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
    <!-- Session pill -->
    <div class="session-pill">
      <i class="fas fa-calendar-alt"></i>
      <?= h($sessionLabel) ?>
    </div>

    <!-- Live clock (BST) -->
    <span class="topbar-time" id="liveClock" title="Bangladesh Standard Time">—</span>

    <!-- Notification bell + dropdown -->
    <div class="notif-wrap" id="notifWrap">
      <button type="button" class="topbar-btn" id="notifBtn" title="Notifications" aria-haspopup="true" aria-expanded="false">
        <i class="fas fa-bell"></i>
        <span class="notif-badge" id="notifBadge" style="display:none">0</span>
      </button>
      <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-dd-head"><span>Notifications</span></div>
        <div class="notif-dd-body" id="notifDdBody">
          <div class="notif-dd-empty"><i class="fas fa-spinner spin"></i></div>
        </div>
        <a href="<?= BASE_URL ?>/admin/activity-log.php" class="notif-dd-footer">View All</a>
      </div>
    </div>

    <a href="<?= BASE_URL ?>/logout.php" class="topbar-btn" title="Logout" data-logout-confirm="Log out of admin panel?">
      <i class="fas fa-right-from-bracket"></i>
    </a>
  </div>
</header>

<!-- Notification AJAX endpoint (used by admin.js). No CSRF token needed —
     see the top-of-file comment in activity-log-ajax.php for why. -->
<script>window.EMS_NOTIF_URL = '<?= BASE_URL ?>/admin/activity-log-ajax.php';</script>

<!-- ══════════════════════════════════════════════════════════════
     MAIN — page content goes here, closed by admin-footer.php
     ══════════════════════════════════════════════════════════════ -->
<main class="ems-main" id="emsMain">
<div class="main-inner">
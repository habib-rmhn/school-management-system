<?php
/**
 * EMS Admin — Dashboard  v2.0
 * Full live stats, quick actions, class breakdown, upcoming exams,
 * recent logins and activity feed.
 */
require_once __DIR__ . '/../includes/admin-session.php';

$pageTitle  = 'Dashboard';
$activeNav  = 'dashboard';
$breadcrumb = ['Dashboard'];
$extraCss   = 'css/dashboard.css';

// ── Helpers ───────────────────────────────────────────────────────
function safeCount(string $sql, array $p = []): int {
    try { return (int)(dbQuery($sql,$p)->fetchColumn() ?? 0); }
    catch (Throwable $e) { return 0; }
}
function safeVal(string $sql, array $p = []): mixed {
    try { $v = dbQuery($sql,$p)->fetchColumn(); return $v !== false ? $v : null; }
    catch (Throwable $e) { return null; }
}
// ── Current session ───────────────────────────────────────────────
$session = null;
try { $session = dbQuery("SELECT * FROM sessions WHERE is_current=1 LIMIT 1")->fetch(); } catch(Throwable $e){}
$sid   = $session['session_id']   ?? null;
$sYear = $session['session_year'] ?? date('Y');

// ── Core stats ────────────────────────────────────────────────────
$totalStudents  = safeCount("SELECT COUNT(DISTINCT sys_uid) FROM enrollments WHERE session_id=? AND status='active'", [$sid]);
$totalTeachers  = safeCount("SELECT COUNT(*) FROM teachers t JOIN system_users su ON su.sys_uid=t.sys_uid WHERE su.is_active=1");
$draftExams     = safeCount("SELECT COUNT(*) FROM exams WHERE session_id=? AND status='draft'",     [$sid]);
$pubExams       = safeCount("SELECT COUNT(*) FROM exams WHERE session_id=? AND status='published'", [$sid]);
$today          = date('Y-m-d');
$todayPresent   = safeCount("SELECT COUNT(*) FROM attendance a JOIN enrollments e ON e.enrollment_id=a.enrollment_id WHERE a.att_date=? AND a.status='present'  AND e.session_id=?", [$today,$sid]);
$todayAbsent    = safeCount("SELECT COUNT(*) FROM attendance a JOIN enrollments e ON e.enrollment_id=a.enrollment_id WHERE a.att_date=? AND a.status='absent'   AND e.session_id=?", [$today,$sid]);
$todayLate      = safeCount("SELECT COUNT(*) FROM attendance a JOIN enrollments e ON e.enrollment_id=a.enrollment_id WHERE a.att_date=? AND a.status='late'     AND e.session_id=?", [$today,$sid]);
$attMarked      = ($todayPresent + $todayAbsent + $todayLate) > 0;
$attPct         = ($todayPresent + $todayAbsent) > 0 ? round($todayPresent/($todayPresent+$todayAbsent)*100) : null;
$unpaidAmt      = safeVal("SELECT COALESCE(SUM(pd.amount_due - COALESCE(pr.paid,0)),0) FROM payment_dues pd JOIN enrollments e ON e.enrollment_id=pd.enrollment_id LEFT JOIN (SELECT due_id,SUM(amount_paid) AS paid FROM payment_records GROUP BY due_id) pr ON pr.due_id=pd.due_id WHERE e.session_id=? AND pd.status!='paid'", [$sid]);
$unpaidCount    = safeCount("SELECT COUNT(*) FROM payment_dues pd JOIN enrollments e ON e.enrollment_id=pd.enrollment_id WHERE e.session_id=? AND pd.status!='paid'", [$sid]);

// ── Class breakdown ───────────────────────────────────────────────
$classStats = [];
try {
    $classStats = dbQuery(
        "SELECT c.class_num, c.label, COUNT(e.enrollment_id) AS cnt
         FROM classes c
         LEFT JOIN enrollments e ON e.class_id=c.class_id AND e.session_id=? AND e.status='active'
         GROUP BY c.class_id ORDER BY c.class_num", [$sid]
    )->fetchAll();
} catch(Throwable $e){}

// ── Exams ─────────────────────────────────────────────────────────
$exams = [];
try {
    $exams = dbQuery(
        "SELECT ex.exam_id, ex.exam_name, ex.status, c.label AS cls,
                MIN(es.exam_date) AS first_date, MAX(es.exam_date) AS last_date,
                COUNT(es.exam_subject_id) AS sub_count
         FROM exams ex
         JOIN classes c ON c.class_id=ex.class_id
         LEFT JOIN exam_subjects es ON es.exam_id=ex.exam_id
         WHERE ex.session_id=?
         GROUP BY ex.exam_id ORDER BY first_date ASC LIMIT 6", [$sid]
    )->fetchAll();
} catch(Throwable $e){}

// ── Recent logins ─────────────────────────────────────────────────
$logins = [];
try {
    $logins = dbQuery(
        "SELECT ll.login_at, ll.ip_address, ll.device_type, ll.location, ll.success, su.role,
                COALESCE(s.full_name, t.full_name, 'Admin') AS uname,
                COALESCE(s.student_id, su.username) AS uid_label
         FROM login_logs ll
         JOIN system_users su ON su.sys_uid=ll.sys_uid
         LEFT JOIN students s ON s.sys_uid=ll.sys_uid
         LEFT JOIN teachers t ON t.sys_uid=ll.sys_uid
         ORDER BY ll.login_at DESC LIMIT 7"
    )->fetchAll();
} catch(Throwable $e){}

// ── Include shell (opens <html>…<main>) ───────────────────────────
require_once __DIR__ . '/includes/admin-sidebar.php';

// ── Formatted numbers ─────────────────────────────────────────────
$fmt = fn($n) => number_format((float)($n ?? 0), 0);
$fmtTk = fn($n) => '৳ ' . number_format((float)($n ?? 0), 0);
?>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="page-header-top">
    <div>
      <div class="page-title">Dashboard</div>
      <div class="page-sub">
        <i class="fas fa-school"></i> <?= h(EMS_SCHOOL) ?>
        <i class="fas fa-circle" style="font-size:4px"></i>
        <?php if ($session): ?>
          <strong style="color:var(--text)"><?= h('Session ' . $sYear) ?></strong>
        <?php else: ?>
          <span style="color:var(--red);font-weight:600"><i class="fas fa-triangle-exclamation"></i> No active session — go to Settings → Sessions</span>
        <?php endif; ?>
        <i class="fas fa-circle" style="font-size:4px"></i>
        <?= date('l, d F Y') ?>
      </div>
    </div>
    <div class="page-actions">
      <a href="<?= BASE_URL ?>/admin/admission.php" class="btn btn-primary">
        <i class="fas fa-user-plus"></i> New Admission
      </a>
      <a href="<?= BASE_URL ?>/admin/exams.php" class="btn btn-outline">
        <i class="fas fa-plus"></i> Create Exam
      </a>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     STAT CARDS
     ═══════════════════════════════════════════════════════════════ -->
<div class="stats-grid mb-4">

  <div class="stat-card sc-blue">
    <div class="stat-top">
      <div class="stat-icon si-blue"><i class="fas fa-users"></i></div>
      <?php if ($totalStudents > 0): ?>
        <span class="stat-trend st-up"><i class="fas fa-arrow-up"></i> Active</span>
      <?php endif; ?>
    </div>
    <div>
      <div class="stat-value" data-count><?= $fmt($totalStudents) ?></div>
      <div class="stat-label">Students Enrolled</div>
    </div>
    <div class="stat-foot">
      <i class="fas fa-calendar-alt"></i> Session <?= h($sYear) ?>
      <a href="<?= BASE_URL ?>/admin/students.php" style="margin-left:auto;color:var(--primary);font-weight:600;font-size:11.5px">View all →</a>
    </div>
  </div>

  <div class="stat-card sc-green">
    <div class="stat-top">
      <div class="stat-icon si-green"><i class="fas fa-chalkboard-teacher"></i></div>
    </div>
    <div>
      <div class="stat-value" data-count><?= $fmt($totalTeachers) ?></div>
      <div class="stat-label">Active Teachers</div>
    </div>
    <div class="stat-foot">
      <i class="fas fa-circle-check"></i> All active accounts
      <a href="<?= BASE_URL ?>/admin/teachers.php" style="margin-left:auto;color:var(--green);font-weight:600;font-size:11.5px">View all →</a>
    </div>
  </div>

  <div class="stat-card sc-sky">
    <div class="stat-top">
      <div class="stat-icon si-sky"><i class="fas fa-calendar-check"></i></div>
      <?php if ($attMarked): ?>
        <span class="stat-trend st-up" style="font-size:10.5px"><?= $attPct ?>% present</span>
      <?php else: ?>
        <span class="stat-trend st-neu" style="font-size:10.5px">Not marked</span>
      <?php endif; ?>
    </div>
    <div>
      <div class="stat-value" data-count><?= $fmt($todayPresent) ?></div>
      <div class="stat-label">Present Today</div>
    </div>
    <div class="stat-foot">
      <i class="fas fa-user-xmark" style="color:var(--red)"></i>
      <span style="color:var(--red)"><?= $fmt($todayAbsent) ?> absent</span>
      <span style="color:var(--amber);margin-left:8px"><i class="fas fa-clock"></i> <?= $fmt($todayLate) ?> late</span>
      <a href="<?= BASE_URL ?>/admin/attendance.php" style="margin-left:auto;color:var(--sky);font-weight:600;font-size:11.5px">Mark →</a>
    </div>
  </div>

  <div class="stat-card sc-amber">
    <div class="stat-top">
      <div class="stat-icon si-amber"><i class="fas fa-file-alt"></i></div>
      <?php if ($draftExams > 0): ?>
        <span class="stat-trend st-neu" style="font-size:10.5px"><?= $draftExams ?> draft</span>
      <?php endif; ?>
    </div>
    <div>
      <div class="stat-value" data-count><?= $fmt($pubExams) ?></div>
      <div class="stat-label">Exams Published</div>
    </div>
    <div class="stat-foot">
      <i class="fas fa-clock"></i> <?= $fmt($draftExams) ?> still in draft
      <a href="<?= BASE_URL ?>/admin/exams.php" style="margin-left:auto;color:var(--amber);font-weight:600;font-size:11.5px">Manage →</a>
    </div>
  </div>

  <div class="stat-card sc-red">
    <div class="stat-top">
      <div class="stat-icon si-red"><i class="fas fa-money-bill-wave"></i></div>
      <?php if ($unpaidCount > 0): ?>
        <span class="stat-trend st-down"><i class="fas fa-arrow-down"></i> <?= $fmt($unpaidCount) ?></span>
      <?php endif; ?>
    </div>
    <div>
      <div class="stat-value" style="font-size:22px;letter-spacing:-.02em"><?= $fmtTk($unpaidAmt) ?></div>
      <div class="stat-label">Unpaid Dues</div>
    </div>
    <div class="stat-foot">
      <i class="fas fa-triangle-exclamation"></i> <?= $fmt($unpaidCount) ?> pending records
      <a href="<?= BASE_URL ?>/admin/payments.php" style="margin-left:auto;color:var(--red);font-weight:600;font-size:11.5px">Manage →</a>
    </div>
  </div>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     ROW: Class breakdown + Quick actions
     ═══════════════════════════════════════════════════════════════ -->
<div class="grid-2 mb-4">

  <!-- Class breakdown -->
  <div class="card mb-0">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-layer-group"></i> Class Breakdown — Session <?= h($sYear) ?></div>
      <a href="<?= BASE_URL ?>/admin/students.php" class="btn btn-sm btn-outline">All Students</a>
    </div>
    <div class="card-body">
      <?php
      if (empty($classStats)):
      ?>
        <div class="empty-state" style="padding:28px 16px">
          <i class="fas fa-users-slash"></i><p>No students enrolled yet.</p>
        </div>
      <?php else:
        $maxCnt = max(array_column($classStats,'cnt') ?: [1]);
        $colors = ['#2563eb','#059669','#d97706','#7c3aed','#0284c7'];
        foreach ($classStats as $i => $row):
          $pct = $maxCnt > 0 ? round((int)$row['cnt'] / $maxCnt * 100) : 0;
          $col = $colors[$i % count($colors)];
      ?>
        <div class="progress-wrap">
          <div class="progress-meta">
            <span class="progress-label"><?= h($row['label']) ?></span>
            <span class="progress-count"><?= (int)$row['cnt'] ?> students</span>
          </div>
          <div class="progress-bar">
            <div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <div class="card-footer">
      <span style="font-size:12.5px;color:var(--text-muted)">
        Total: <strong style="color:var(--text)"><?= $fmt($totalStudents) ?></strong> active students
      </span>
      <a href="<?= BASE_URL ?>/admin/promote.php" class="btn btn-sm btn-ghost">
        <i class="fas fa-arrow-trend-up"></i> Promote Class
      </a>
    </div>
  </div>

  <!-- Quick Actions -->
  <div class="card mb-0">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-bolt"></i> Quick Actions</div>
    </div>
    <div class="card-body">
      <div class="quick-grid">
        <a href="<?= BASE_URL ?>/admin/admission.php"      class="quick-card"><div class="qc-icon si-blue"  ><i class="fas fa-user-plus"></i></div><div class="qc-label">New Admission</div></a>
        <a href="<?= BASE_URL ?>/admin/attendance.php"     class="quick-card"><div class="qc-icon si-green" ><i class="fas fa-calendar-check"></i></div><div class="qc-label">Attendance</div></a>
        <a href="<?= BASE_URL ?>/admin/marks.php"          class="quick-card"><div class="qc-icon si-amber" ><i class="fas fa-pencil"></i></div><div class="qc-label">Add Marks</div></a>
        <a href="<?= BASE_URL ?>/admin/exams.php"          class="quick-card"><div class="qc-icon si-purple"><i class="fas fa-file-alt"></i></div><div class="qc-label">Create Exam</div></a>
        <a href="<?= BASE_URL ?>/admin/seat-plan.php"      class="quick-card"><div class="qc-icon si-teal"  ><i class="fas fa-chair"></i></div><div class="qc-label">Seat Plan</div></a>
        <a href="<?= BASE_URL ?>/admin/payments.php"       class="quick-card"><div class="qc-icon si-green" ><i class="fas fa-money-bill-wave"></i></div><div class="qc-label">Payments</div></a>
        <a href="<?= BASE_URL ?>/admin/results.php"        class="quick-card"><div class="qc-icon si-sky"   ><i class="fas fa-poll"></i></div><div class="qc-label">Publish Result</div></a>
        <a href="<?= BASE_URL ?>/admin/promote.php"        class="quick-card"><div class="qc-icon si-amber" ><i class="fas fa-arrow-trend-up"></i></div><div class="qc-label">Class Upgrade</div></a>
        <a href="<?= BASE_URL ?>/admin/routine.php"        class="quick-card"><div class="qc-icon si-blue"  ><i class="fas fa-calendar-week"></i></div><div class="qc-label">Class Routine</div></a>
        <a href="<?= BASE_URL ?>/admin/reset-password.php" class="quick-card"><div class="qc-icon si-red"   ><i class="fas fa-key"></i></div><div class="qc-label">Reset Password</div></a>
        <a href="<?= BASE_URL ?>/admin/subjects.php"       class="quick-card"><div class="qc-icon si-purple"><i class="fas fa-book"></i></div><div class="qc-label">Subjects</div></a>
        <a href="<?= BASE_URL ?>/admin/sessions.php"       class="quick-card"><div class="qc-icon si-teal"  ><i class="fas fa-rotate"></i></div><div class="qc-label">Sessions</div></a>
      </div>
    </div>
  </div>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     ROW: Exams + Recent Logins
     ═══════════════════════════════════════════════════════════════ -->
<div class="grid-2 mb-4">

  <!-- Exams -->
  <div class="card mb-0">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-calendar-alt"></i> Exams — <?= h($sYear) ?></div>
      <a href="<?= BASE_URL ?>/admin/exams.php" class="btn btn-sm btn-outline">Manage</a>
    </div>
    <?php if (empty($exams)): ?>
      <div class="empty-state"><i class="fas fa-file-circle-question"></i><p>No exams created for this session yet.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="ems-table">
          <thead><tr><th>Exam Name</th><th>Class</th><th>Date</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($exams as $ex): ?>
            <tr>
              <td>
                <div style="font-weight:600;font-size:13.5px"><?= h($ex['exam_name']) ?></div>
                <div style="font-size:11.5px;color:var(--text-muted)"><?= (int)$ex['sub_count'] ?> subjects</div>
              </td>
              <td><?= h($ex['cls']) ?></td>
              <td style="font-size:12.5px;white-space:nowrap">
                <?php if ($ex['first_date']): ?>
                  <?= date('d M', strtotime($ex['first_date'])) ?>
                  <?= $ex['last_date'] !== $ex['first_date'] ? ' – ' . date('d M', strtotime($ex['last_date'])) : '' ?>
                <?php else: ?>
                  <span style="color:var(--text-light)">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($ex['status'] === 'published'): ?>
                  <span class="badge badge-green"><i class="fas fa-circle-check"></i> Published</span>
                <?php else: ?>
                  <span class="badge badge-amber"><i class="fas fa-clock"></i> Draft</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Recent Logins -->
  <div class="card mb-0">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-shield-halved"></i> Recent Logins</div>
      <a href="<?= BASE_URL ?>/admin/login-logs.php" class="btn btn-sm btn-outline">Full Log</a>
    </div>
    <?php if (empty($logins)): ?>
      <div class="empty-state"><i class="fas fa-clock-rotate-left"></i><p>No login records yet.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="ems-table">
          <thead><tr><th>User</th><th>Role</th><th>Device & Place</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($logins as $lg): ?>
            <tr>
              <td>
                <div style="font-weight:600;font-size:13px"><?= h($lg['uname']) ?></div>
                <div style="font-size:11.5px;color:var(--text-muted)"><?= date('d M, h:i A', strtotime($lg['login_at'])) ?></div>
              </td>
              <td>
                <span class="badge <?= $lg['role']==='admin' ? 'badge-purple' : ($lg['role']==='teacher' ? 'badge-green' : 'badge-blue') ?>">
                  <?= ucfirst(h($lg['role'])) ?>
                </span>
              </td>
              <td style="font-size:12px;color:var(--text-muted);">
                <?= h(ucfirst(strtolower($lg['device_type'] ?? '—'))) ?> <?= $lg['location'] ? '· ' . h($lg['location']) : '' ?>
              </td>
              <td>
              <?= $lg['success']
                ? '<span class="badge badge-green login-status"><i class="fas fa-check"></i> <span class="status-text">Success</span></span>'
                : '<span class="badge badge-red login-status"><i class="fas fa-xmark"></i> <span class="status-text">Failed</span></span>' ?>
            </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     ATTENDANCE SUMMARY CHART
     ═══════════════════════════════════════════════════════════════ -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-chart-line"></i> Attendance Summary</div>
    <div class="chart-period-group" id="attChartPeriod" role="group" aria-label="Select date range">
      <button type="button" class="chart-period-btn" data-days="7">7 Days</button>
      <button type="button" class="chart-period-btn" data-days="30">30 Days</button>
      <button type="button" class="chart-period-btn" data-days="90">90 Days</button>
    </div>
  </div>

  <div class="card-body">
    <div class="chart-canvas-wrap" id="attChartWrap">
      <canvas id="attSummaryChart"></canvas>
      <div class="chart-loading" id="attChartLoading"><i class="fas fa-spinner spin"></i></div>
      <div class="chart-empty" id="attChartEmpty" style="display:none">
        <i class="fas fa-chart-simple"></i>
        <p>No attendance has been taken this period for the current session.</p>
      </div>
    </div>
  </div>

  <div class="card-footer">
    <span style="font-size:12.5px;color:var(--text-muted)">
      Students present, per day &bull; Current session
    </span>
    <a href="<?= BASE_URL ?>/admin/attendance.php" class="btn btn-sm btn-ghost">
      Mark Attendance <i class="fas fa-arrow-right"></i>
    </a>
  </div>
</div>

<script>
(function () {
  'use strict';

  var STORAGE_KEY      = 'ems_att_chart_period';
  var AJAX_URL          = '<?= BASE_URL ?>/admin/attendance-summary-ajax.php';
  var ALLOWED_PERIODS   = [7, 30, 90];

  var wrap    = document.getElementById('attChartWrap');
  var canvas  = document.getElementById('attSummaryChart');
  var loading = document.getElementById('attChartLoading');
  var emptyEl = document.getElementById('attChartEmpty');
  var emptyP  = emptyEl ? emptyEl.querySelector('p') : null;
  var emptyIcon = emptyEl ? emptyEl.querySelector('i') : null;
  var group   = document.getElementById('attChartPeriod');

  if (!wrap || !canvas || !loading || !emptyEl || !group) {
    console.error('[Attendance Chart] Required DOM elements missing — markup did not render as expected.');
    return;
  }

  // ── Surface a real error instead of spinning forever ─────────────
  function showState(kind, message) {
    // kind: 'loading' | 'empty' | 'error'
    loading.style.display = kind === 'loading' ? 'flex' : 'none';
    canvas.style.display  = 'none';
    emptyEl.style.display = (kind === 'empty' || kind === 'error') ? 'flex' : 'none';
    if (emptyP) emptyP.textContent = message || 'No attendance has been taken this period for the current session.';
    if (emptyIcon) {
      emptyIcon.className = kind === 'error' ? 'fas fa-triangle-exclamation' : 'fas fa-chart-simple';
    }
    if (kind === 'error') console.error('[Attendance Chart] ' + message);
  }

  // ── Load Chart.js: local copy first (works fully offline), then a
  //    few different CDN hosts in case only one domain is blocked ───
  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.onload  = function () { resolve(src); };
      s.onerror = function () { reject(new Error('failed: ' + src)); };
      document.head.appendChild(s);
    });
  }

  function ensureChartLib() {
    if (typeof Chart !== 'undefined') return Promise.resolve('already loaded');

    // Drop chart.umd.min.js into <?= $assetBase ?>/js/ to load fully
    // offline — that path is tried first and, if present, none of the
    // CDN fallbacks below are ever contacted.
    var sources = [
      '<?= $assetBase ?>/js/chart.umd.min.js',
      'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js',
      'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js',
      'https://unpkg.com/chart.js@4.4.4/dist/chart.umd.min.js'
    ];

    var i = 0;
    function tryNext() {
      if (i >= sources.length) {
        return Promise.reject(new Error(
          'Chart.js could not be loaded — no local copy at ' + sources[0] +
          ' and all CDN fallbacks failed (offline machine?). ' +
          'Fix: download chart.umd.min.js from a machine with internet and save it to that local path.'
        ));
      }
      var src = sources[i++];
      return loadScript(src).catch(tryNext);
    }
    return tryNext();
  }

  showState('loading');
  ensureChartLib()
    .then(init)
    .catch(function (err) {
      showState('error', err && err.message ? err.message : 'Chart library failed to load.');
    });

  function init() {

  var chart = null;
  var lateData = [];
  var currentRequestId = 0;
  var LOAD_TIMEOUT_MS = 12000;

  function getStoredPeriod() {
    var v = parseInt(localStorage.getItem(STORAGE_KEY), 10);
    return ALLOWED_PERIODS.indexOf(v) !== -1 ? v : 30;
  }

  function setActiveButton(days) {
    var btns = group.querySelectorAll('.chart-period-btn');
    for (var i = 0; i < btns.length; i++) {
      var d = parseInt(btns[i].dataset.days, 10);
      btns[i].classList.toggle('active', d === days);
    }
  }

  function hexToRgba(hex, alpha) {
    hex = (hex || '#2563eb').trim().replace('#', '');
    if (hex.length === 3) hex = hex.split('').map(function (c) { return c + c; }).join('');
    var r = parseInt(hex.substring(0, 2), 16);
    var g = parseInt(hex.substring(2, 4), 16);
    var b = parseInt(hex.substring(4, 6), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
  }

  function tickLimit() {
    var w = window.innerWidth;
    if (w < 480) return 5;
    if (w < 768) return 7;
    return 12;
  }

  function renderChart(labels, data, late) {
    var hasData = data && data.length > 0;
    lateData = late || [];

    if (!hasData) {
      if (chart) { chart.destroy(); chart = null; }
      showState('empty', 'No attendance has been taken this period for the current session.');
      return;
    }

    loading.style.display = 'none';
    emptyEl.style.display = 'none';
    canvas.style.display  = 'block';

    var ctx     = canvas.getContext('2d');
    var primary = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#2563eb';
    var h       = wrap.clientHeight || 260;

    var gradient = ctx.createLinearGradient(0, 0, 0, h);
    gradient.addColorStop(0, hexToRgba(primary, 0.28));
    gradient.addColorStop(1, hexToRgba(primary, 0.02));

    if (chart) chart.destroy();
    chart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [{
          label: 'Students Present',
          data: data,
          borderColor: primary,
          backgroundColor: gradient,
          borderWidth: 2,
          pointRadius: labels.length > 45 ? 0 : 3,
          pointHoverRadius: 5,
          pointBackgroundColor: primary,
          pointBorderColor: '#fff',
          pointBorderWidth: 1.5,
          fill: true,
          tension: 0.35
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#101828',
            padding: 10,
            titleFont: { size: 12, weight: '600' },
            bodyFont: { size: 12 },
            cornerRadius: 8,
            callbacks: {
              label: function (ctx) {
                var lateCount = lateData[ctx.dataIndex] || 0;
                var line = ' ' + ctx.parsed.y.toLocaleString() + ' present';
                if (lateCount > 0) line += ' (' + lateCount.toLocaleString() + ' late)';
                return line;
              }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: {
              autoSkip: true,
              maxRotation: 0,
              maxTicksLimit: tickLimit(),
              font: { size: 11 },
              color: '#667085'
            }
          },
          y: {
            beginAtZero: true,
            grid: { color: '#f0f2f6' },
            ticks: { font: { size: 11 }, color: '#667085', precision: 0 }
          }
        }
      }
    });
  }

  function loadPeriod(days) {
    setActiveButton(days);
    showState('loading');

    var reqId = ++currentRequestId;
    var url = AJAX_URL + '?days=' + encodeURIComponent(days);

    var timedOut = false;
    var timer = setTimeout(function () {
      timedOut = true;
      if (reqId === currentRequestId) {
        showState('error', 'Request to attendance-summary-ajax.php timed out. Check the file exists in /admin/ and that the URL below is reachable: ' + url);
      }
    }, LOAD_TIMEOUT_MS);

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status + ' from ' + url);
        return r.text(); // read as text first so a non-JSON (e.g. login-page HTML) response is diagnosable
      })
      .then(function (text) {
        var res;
        try {
          res = JSON.parse(text);
        } catch (parseErr) {
          throw new Error('Response was not valid JSON (got HTML/other — likely a login redirect or a PHP warning before the JSON). First 200 chars: ' + text.slice(0, 200));
        }
        return res;
      })
      .then(function (res) {
        clearTimeout(timer);
        if (timedOut || reqId !== currentRequestId) return; // superseded or already timed out
        if (!res || !res.ok) {
          showState('error', 'Server responded but reported an error. Check attendance-summary-ajax.php.');
          return;
        }
        if (res.has_session === false) {
          if (chart) { chart.destroy(); chart = null; }
          showState('empty', 'No active session is set — go to Settings → Sessions to set one, then attendance data will appear here.');
          return;
        }
        renderChart(res.labels || [], res.data || [], res.late || []);
      })
      .catch(function (err) {
        clearTimeout(timer);
        if (timedOut || reqId !== currentRequestId) return;
        showState('error', err && err.message ? err.message : 'Could not load attendance data.');
      });
  }

  group.addEventListener('click', function (e) {
    var btn = e.target.closest('.chart-period-btn');
    if (!btn) return;
    var days = parseInt(btn.dataset.days, 10);
    if (ALLOWED_PERIODS.indexOf(days) === -1) return;
    localStorage.setItem(STORAGE_KEY, days);
    loadPeriod(days);
  });

  // Keep tick density sensible across breakpoints without refetching data
  var resizeTO;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTO);
    resizeTO = setTimeout(function () {
      if (chart) {
        chart.options.scales.x.ticks.maxTicksLimit = tickLimit();
        chart.update();
      }
    }, 200);
  });

  loadPeriod(getStoredPeriod());

  } // end init()
})();
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
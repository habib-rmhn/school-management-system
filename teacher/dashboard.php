<?php
/**
 * EMS Teacher — Dashboard  v1.0
 * ─────────────────────────────────────────────────────────────────
 * Shows: today's classes (live timeline), pending marks entry,
 * quick stats, quick-action tiles, and a recent activity feed.
 *
 * All data loads via AJAX (dashboard-ajax.php) — no page reload.
 * The PHP below renders only the static shell + skeleton loaders;
 * every number and list is filled in by JS after the fetch resolves.
 */
require_once __DIR__ . '/../includes/teacher-session.php';

$pageTitle  = 'Dashboard';
$activeNav  = 'dashboard';
$breadcrumb = ['Dashboard'];

$firstFullName = h($_SESSION['ems_display_name'] ?? 'Teacher');

// Greeting based on server time (Asia/Dhaka, already set in db.php)
$hour = (int)date('G');
if ($hour < 12)      { $greeting = 'Good Morning';   $greetIcon = 'fa-sun'; }
elseif ($hour < 17)  { $greeting = 'Good Afternoon'; $greetIcon = 'fa-cloud-sun'; }
else                 { $greeting = 'Good Evening';   $greetIcon = 'fa-moon'; }

$todayLabel = date('l, d F Y');

require_once __DIR__ . '/includes/teacher-sidebar.php';
?>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="page-header-top">
    <div>
      <div class="page-title"><i class="fas <?= $greetIcon ?>" style="color:var(--primary);margin-right:8px"></i><?= $greeting ?>, <?= $firstFullName ?>!</div>
      <div class="page-sub">
        <i class="fas fa-calendar-day"></i> <?= h($todayLabel) ?>
        <span id="lastUpdatedWrap" style="display:none">
          &nbsp;·&nbsp;<i class="fas fa-rotate"></i> Updated <span id="lastUpdatedText">just now</span>
        </span>
      </div>
    </div>
    <div class="page-actions">
      <button type="button" class="btn btn-outline" id="refreshBtn">
        <i class="fas fa-rotate" id="refreshIcon"></i> Refresh
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     ERROR STATE  (hidden unless the fetch fails)
     ═══════════════════════════════════════════════════════════════ -->
<div id="dashError" class="alert alert-danger" style="display:none">
  <i class="fas fa-circle-exclamation"></i>
  <span id="dashErrorText">Something went wrong while loading your dashboard.</span>
  <button type="button" class="btn btn-sm btn-outline" id="dashErrorRetry" style="margin-left:auto">
    <i class="fas fa-rotate"></i> Try Again
  </button>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     NO ACTIVE SESSION WARNING (hidden unless flagged by server)
     ═══════════════════════════════════════════════════════════════ -->
<div id="noSessionAlert" class="alert alert-warning" style="display:none">
  <i class="fas fa-triangle-exclamation"></i>
  <span>There's no active academic session set right now. Some information below may be incomplete — please contact your school admin office.</span>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     STAT CARDS  (skeleton → real values after fetch)
     ═══════════════════════════════════════════════════════════════ -->
<div class="stats-grid" id="statsGrid">

  <div class="stat-card sc-green" data-stat-card="today_classes">
    <div class="stat-top">
      <div class="stat-icon si-green"><i class="fas fa-chalkboard"></i></div>
    </div>
    <div>
      <div class="stat-value skel-value"><span class="skel-line" style="width:44px"></span></div>
      <div class="stat-label">Today's Classes</div>
    </div>
    <div class="stat-foot"><i class="fas fa-clock"></i><span class="stat-foot-text">Loading…</span></div>
  </div>

  <div class="stat-card sc-amber" data-stat-card="pending_marks">
    <div class="stat-top">
      <div class="stat-icon si-amber"><i class="fas fa-pencil"></i></div>
    </div>
    <div>
      <div class="stat-value skel-value"><span class="skel-line" style="width:34px"></span></div>
      <div class="stat-label">Pending Marks Entry</div>
    </div>
    <div class="stat-foot"><i class="fas fa-clock"></i><span class="stat-foot-text">Loading…</span></div>
  </div>

  <div class="stat-card sc-sky" data-stat-card="my_students">
    <div class="stat-top">
      <div class="stat-icon si-sky"><i class="fas fa-users"></i></div>
    </div>
    <div>
      <div class="stat-value skel-value"><span class="skel-line" style="width:54px"></span></div>
      <div class="stat-label">My Students</div>
    </div>
    <div class="stat-foot"><i class="fas fa-clock"></i><span class="stat-foot-text">Loading…</span></div>
  </div>

  <div class="stat-card sc-purple" data-stat-card="attendance_rate">
    <div class="stat-top">
      <div class="stat-icon si-purple"><i class="fas fa-calendar-check"></i></div>
    </div>
    <div>
      <div class="stat-value skel-value"><span class="skel-line" style="width:50px"></span></div>
      <div class="stat-label">Attendance This Month</div>
    </div>
    <div class="stat-foot"><i class="fas fa-clock"></i><span class="stat-foot-text">Loading…</span></div>
  </div>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     TODAY'S CLASSES  +  PENDING MARKS  (two columns)
     ═══════════════════════════════════════════════════════════════ -->
<div class="grid-2">

  <!-- Today's Classes -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-sun"></i> Today's Classes</div>
      <a href="<?= BASE_URL ?>/teacher/routine.php" class="btn btn-sm btn-outline">Full Routine</a>
    </div>
    <div id="todayBody">
      <!-- Skeleton rows while loading -->
      <div class="timeline" id="todaySkeleton">
        <?php for ($i = 0; $i < 3; $i++): ?>
        <div class="tl-item">
          <div class="tl-time"><span class="skel-line" style="width:48px"></span></div>
          <div class="tl-line"><span class="tl-dot"></span><span class="tl-connector"></span></div>
          <div class="tl-body">
            <div class="skel-line" style="width:120px;height:14px;margin-bottom:6px"></div>
            <div class="skel-line" style="width:80px;height:11px"></div>
          </div>
        </div>
        <?php endfor; ?>
      </div>
    </div>
  </div>

  <!-- Pending Marks -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-pencil"></i> Pending Marks Entry</div>
      <a href="<?= BASE_URL ?>/teacher/marks.php" class="btn btn-sm btn-outline">Enter Marks</a>
    </div>
    <div id="pendingBody">
      <div class="activity-feed" id="pendingSkeleton">
        <?php for ($i = 0; $i < 3; $i++): ?>
        <div class="activity-item">
          <div class="ai-icon" style="background:var(--border-light)"></div>
          <div class="ai-body">
            <div class="skel-line" style="width:70%;height:14px;margin-bottom:6px"></div>
            <div class="skel-line" style="width:40%;height:11px"></div>
          </div>
        </div>
        <?php endfor; ?>
      </div>
    </div>
  </div>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     QUICK ACTIONS  (static — no need to wait for data)
     ═══════════════════════════════════════════════════════════════ -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-bolt"></i> Quick Actions</div>
  </div>
  <div class="card-body">
    <div class="quick-grid">
      <a href="<?= BASE_URL ?>/teacher/today.php" class="quick-card">
        <div class="qc-icon si-green"><i class="fas fa-sun"></i></div>
        <div class="qc-label">Today's Classes</div>
      </a>
      <a href="<?= BASE_URL ?>/teacher/routine.php" class="quick-card">
        <div class="qc-icon si-sky"><i class="fas fa-calendar-week"></i></div>
        <div class="qc-label">My Routine</div>
      </a>
      <a href="<?= BASE_URL ?>/teacher/attendance.php" class="quick-card">
        <div class="qc-icon si-teal"><i class="fas fa-calendar-check"></i></div>
        <div class="qc-label">Attendance</div>
      </a>
      <a href="<?= BASE_URL ?>/teacher/marks.php" class="quick-card">
        <div class="qc-icon si-amber"><i class="fas fa-pencil"></i></div>
        <div class="qc-label">Upload Marks</div>
      </a>
      <a href="<?= BASE_URL ?>/teacher/students.php" class="quick-card">
        <div class="qc-icon si-purple"><i class="fas fa-users"></i></div>
        <div class="qc-label">Student List</div>
      </a>
      <a href="<?= BASE_URL ?>/teacher/profile.php" class="quick-card">
        <div class="qc-icon si-gray"><i class="fas fa-user"></i></div>
        <div class="qc-label">My Profile</div>
      </a>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     RECENT ACTIVITY
     ═══════════════════════════════════════════════════════════════ -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-clock-rotate-left"></i> Recent Activity</div>
  </div>
  <div id="activityBody">
    <div class="activity-feed" id="activitySkeleton">
      <?php for ($i = 0; $i < 3; $i++): ?>
      <div class="activity-item">
        <div class="ai-icon" style="background:var(--border-light)"></div>
        <div class="ai-body">
          <div class="skel-line" style="width:80%;height:14px;margin-bottom:6px"></div>
          <div class="skel-line" style="width:30%;height:11px"></div>
        </div>
      </div>
      <?php endfor; ?>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE-SPECIFIC STYLES
     ═══════════════════════════════════════════════════════════════ -->
<style>
  /* ── Skeleton shimmer ─────────────────────────────────────────── */
  .skel-line {
    display: inline-block;
    height: 22px;
    border-radius: 6px;
    background: linear-gradient(90deg, var(--border-light) 25%, var(--border) 37%, var(--border-light) 63%);
    background-size: 400% 100%;
    animation: skelShimmer 1.4s ease infinite;
    vertical-align: middle;
  }
  @keyframes skelShimmer {
    0%   { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
  }
  .skel-value { line-height: 1; }

  /* ── Refresh button spin while loading ───────────────────────── */
  #refreshIcon.spinning { animation: refreshSpin .8s linear infinite; }
  @keyframes refreshSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

  /* ── Today timeline empty / off-day state ────────────────────── */
  .today-empty-wrap { padding: 40px 24px; text-align: center; color: var(--text-muted); }
  .today-empty-wrap i { font-size: 40px; color: var(--text-light); display: block; margin-bottom: 12px; }
  .today-empty-wrap p { font-size: 14px; line-height: 1.6; }

  /* ── Pending marks list item ──────────────────────────────────── */
  .pm-item {
    display: flex; align-items: center; gap: 13px;
    padding: 14px 20px;
    border-bottom: 1px solid var(--border-light);
  }
  .pm-item:last-child { border-bottom: none; }
  .pm-icon {
    width: 40px; height: 40px; border-radius: 10px;
    background: var(--amber-bg); color: var(--amber);
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
  }
  .pm-body { flex: 1; min-width: 0; }
  .pm-title { font-size: 13.5px; font-weight: 700; color: var(--text); }
  .pm-meta  { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
  .pm-meta strong { color: var(--amber); }
  .pm-action { flex-shrink: 0; }

  /* ── Stat trend colour helpers used by JS ─────────────────────── */
  .stat-foot-text.good  { color: var(--green); font-weight: 600; }
  .stat-foot-text.warn  { color: var(--amber); font-weight: 600; }
  .stat-foot-text.bad   { color: var(--red);   font-weight: 600; }
</style>

<!-- CSRF token for AJAX requests -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<?php
// ── Page-specific JS — passed to the footer's $pageJs slot ────────
$pageJs = <<<'JS'
(function () {
  "use strict";

  const AJAX_URL = "dashboard-ajax.php";
  function getToken() { return document.getElementById("csrfToken")?.value || ""; }
  let csrfToken = getToken();

  const $ = (id) => document.getElementById(id);
  const escapeHtml = (str) => String(str ?? "").replace(/[&<>"']/g, (c) => ({
    "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;"
  }[c]));

  async function post(action, extra) {
    const body = new URLSearchParams({ action, csrf_token: csrfToken, ...(extra || {}) });
    let res, data;
    try {
      res  = await fetch(AJAX_URL, { method: "POST", body, credentials: "same-origin" });
      data = await res.json();
    } catch (err) {
      return null;
    }
    if (data && data.csrf) {
      csrfToken = data.csrf;
      const tokenInput = $("csrfToken");
      if (tokenInput) tokenInput.value = data.csrf;
    }
    return data;
  }

  function fmtTime(t) {
    if (!t) return "";
    const [h, m] = t.split(":").map(Number);
    const ampm = h >= 12 ? "PM" : "AM";
    const h12  = (h % 12) || 12;
    return String(h12).padStart(2,"0") + ":" + String(m).padStart(2,"0") + " " + ampm;
  }

  // ── Render: Stat cards ───────────────────────────────────────────
  function renderStats(stats) {
    const cards = {
      today_classes:    { value: stats.today_classes,    suffix: "", foot: stats.today_classes > 0 ? stats.today_classes + " class" + (stats.today_classes === 1 ? "" : "es") + " scheduled" : "No classes today" },
      pending_marks:    { value: stats.pending_marks,     suffix: "", foot: stats.pending_marks > 0 ? "Needs your attention" : "All caught up!" },
      my_students:      { value: stats.my_students,       suffix: "", foot: "Across your classes" },
      attendance_rate:  { value: stats.attendance_rate === null ? "—" : stats.attendance_rate, suffix: stats.attendance_rate === null ? "" : "%", foot: stats.attendance_rate === null ? "No records yet" : "This month" },
    };

    Object.keys(cards).forEach((key) => {
      const card = document.querySelector('[data-stat-card="' + key + '"]');
      if (!card) return;
      const valEl  = card.querySelector(".stat-value");
      const footEl = card.querySelector(".stat-foot-text");
      const cfg    = cards[key];
      valEl.innerHTML = escapeHtml(String(cfg.value)) + (cfg.suffix ? '<span style="font-size:16px;font-weight:700">' + cfg.suffix + '</span>' : "");
      valEl.classList.remove("skel-value");
      if (footEl) {
        footEl.textContent = cfg.foot;
        footEl.classList.remove("good", "warn", "bad");
        if (key === "pending_marks") footEl.classList.add(stats.pending_marks > 0 ? "warn" : "good");
        if (key === "attendance_rate" && stats.attendance_rate !== null) {
          if (stats.attendance_rate >= 85) footEl.classList.add("good");
          else if (stats.attendance_rate >= 70) footEl.classList.add("warn");
          else footEl.classList.add("bad");
        }
      }
    });
  }

  // ── Render: Today's classes timeline ─────────────────────────────
  function renderToday(today, isFriday) {
    const wrap = $("todayBody");

    if (isFriday) {
      wrap.innerHTML = '<div class="today-empty-wrap"><i class="fas fa-mug-hot"></i><p>It\'s Friday — no classes today. Enjoy your day off!</p></div>';
      return;
    }
    if (!today || today.length === 0) {
      wrap.innerHTML = '<div class="today-empty-wrap"><i class="fas fa-calendar-xmark"></i><p>No classes have been scheduled for you today.</p></div>';
      return;
    }

    let html = '<div class="timeline">';
    today.forEach((cls, idx) => {
      const isLast = idx === today.length - 1;
      const statusCls = cls.status === "current" ? " tl-current" : (cls.status === "done" ? " tl-done" : "");
      html += '<div class="tl-item' + statusCls + '">';
      html += '<div class="tl-time"><div class="tl-time-start">' + escapeHtml(fmtTime(cls.start_time)) + '</div><div class="tl-time-end">' + escapeHtml(fmtTime(cls.end_time)) + '</div></div>';
      html += '<div class="tl-line"><span class="tl-dot"></span>' + (isLast ? '' : '<span class="tl-connector"></span>') + '</div>';
      html += '<div class="tl-body">';
      html += '<div class="tl-subject">' + escapeHtml(cls.subject_name || "—") + '</div>';
      html += '<div class="tl-class"><i class="fas fa-users"></i> ' + escapeHtml(cls.class_label || "") + (cls.group_name ? ' (' + escapeHtml(cls.group_name) + ')' : '') + '</div>';
      html += '</div>';
      if (cls.status === "current") {
        html += '<div class="tl-actions"><span class="now-badge"><i class="fas fa-circle"></i> Now</span></div>';
      }
      html += '</div>';
    });
    html += '</div>';
    wrap.innerHTML = html;
  }

  // ── Render: Pending marks list ────────────────────────────────────
  function renderPending(list) {
    const wrap = $("pendingBody");
    if (!list || list.length === 0) {
      wrap.innerHTML = '<div class="empty-state"><i class="fas fa-circle-check"></i><h3>All caught up!</h3><p>No pending marks entry right now.</p></div>';
      return;
    }
    let html = "";
    list.forEach((p) => {
      html += '<div class="pm-item">';
      html += '<div class="pm-icon"><i class="fas fa-file-pen"></i></div>';
      html += '<div class="pm-body">';
      html += '<div class="pm-title">' + escapeHtml(p.exam_name) + ' — ' + escapeHtml(p.subject_name) + '</div>';
      html += '<div class="pm-meta">' + escapeHtml(p.class_label) + ' &middot; <strong>' + p.marked_count + '/' + p.total_students + '</strong> marked</div>';
      html += '</div>';
      html += '<div class="pm-action"><a href="' + "marks.php?exam_subject_id=" + p.exam_subject_id + '" class="btn btn-sm btn-primary">Enter</a></div>';
      html += '</div>';
    });
    wrap.innerHTML = html;
  }

  // ── Render: Recent activity feed ──────────────────────────────────
  const COLOR_MAP = {
    green:  ["var(--primary-light)", "var(--green-dark)"],
    amber:  ["var(--amber-bg)", "var(--amber)"],
    sky:    ["var(--sky-bg)", "var(--sky)"],
    red:    ["var(--red-bg)", "var(--red)"],
    gray:   ["var(--border-light)", "var(--text-muted)"],
  };

  function renderActivity(list) {
    const wrap = $("activityBody");
    if (!list || list.length === 0) {
      wrap.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><h3>No recent activity</h3><p>Your recent actions will show up here.</p></div>';
      return;
    }
    let html = '<div class="activity-feed">';
    list.forEach((a) => {
      const colors = COLOR_MAP[a.color] || COLOR_MAP.gray;
      html += '<div class="activity-item">';
      html += '<div class="ai-icon" style="background:' + colors[0] + ';color:' + colors[1] + '"><i class="fas ' + escapeHtml(a.icon) + '"></i></div>';
      html += '<div class="ai-body">';
      html += '<div class="ai-text">' + escapeHtml(a.text) + '</div>';
      html += '<div class="ai-time"><i class="fas fa-clock"></i> ' + escapeHtml(a.time_ago) + '</div>';
      html += '</div></div>';
    });
    html += '</div>';
    wrap.innerHTML = html;
  }

  // ── Master load function ──────────────────────────────────────────
  let isLoading = false;

  async function loadDashboard(isManualRefresh) {
    if (isLoading) return;
    isLoading = true;

    const refreshIcon = $("refreshIcon");
    if (refreshIcon) refreshIcon.classList.add("spinning");
    $("dashError").style.display = "none";

    const data = await post("fetch_dashboard", {});

    if (refreshIcon) refreshIcon.classList.remove("spinning");
    isLoading = false;

    if (!data) {
      $("dashErrorText").textContent = "Could not reach the server. Please check your connection.";
      $("dashError").style.display = "flex";
      return;
    }
    if (!data.ok) {
      $("dashErrorText").textContent = data.msg || "Something went wrong while loading your dashboard.";
      $("dashError").style.display = "flex";
      return;
    }

    $("noSessionAlert").style.display = data.no_session ? "flex" : "none";

    renderStats(data.stats);
    renderToday(data.today, data.is_friday);
    renderPending(data.pending_marks_list);
    renderActivity(data.activity);

    const lastUpdatedWrap = $("lastUpdatedWrap");
    const lastUpdatedText = $("lastUpdatedText");
    if (lastUpdatedWrap && lastUpdatedText) {
      lastUpdatedWrap.style.display = "inline";
      lastUpdatedText.textContent = "just now";
    }

    if (isManualRefresh && window.showToast) {
      window.showToast("success", "Refreshed", "Your dashboard is up to date.");
    }
  }

  // ── Wire up refresh button ────────────────────────────────────────
  const refreshBtn = $("refreshBtn");
  if (refreshBtn) refreshBtn.addEventListener("click", () => loadDashboard(true));

  const retryBtn = $("dashErrorRetry");
  if (retryBtn) retryBtn.addEventListener("click", () => loadDashboard(false));

  // ── Initial load ──────────────────────────────────────────────────
  loadDashboard(false);

  // ── Silent auto-refresh every 3 minutes (keeps "Now" indicator accurate) ──
  setInterval(() => loadDashboard(false), 180000);

})();
JS;

require_once __DIR__ . '/includes/teacher-footer.php';
?>
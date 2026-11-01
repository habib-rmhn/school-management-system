<?php
/**
 * EMS Teacher — Today's Classes  v1.0
 * ─────────────────────────────────────────────────────────────────
 * The single most-used page for a teacher: "What do I teach right
 * now, and what's next?" Shows only TODAY's schedule (full week view
 * lives on routine.php) with a live "Now Teaching" banner and one-tap
 * Attendance / Marks buttons on every period card.
 *
 * All data loads via AJAX (today-ajax.php) — no page reload. Status
 * (current / done / upcoming) is computed client-side against
 * Bangladesh time and re-ticks every 20 seconds, so the page stays
 * accurate as the day goes on without needing another server call.
 */
require_once __DIR__ . '/../includes/teacher-session.php';

$pageTitle  = "Today's Classes";
$activeNav  = 'today';
$breadcrumb = ["Today's Classes"];

require_once __DIR__ . '/includes/teacher-sidebar.php';
?>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="page-header-top">
    <div>
      <div class="page-title"><i class="fas fa-sun" style="color:var(--primary);margin-right:8px"></i>Today's Classes</div>
      <div class="page-sub">
        <i class="fas fa-calendar-day"></i> <span id="dateLabel">Loading…</span>
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
     ERROR / NO-SESSION STATES
     ═══════════════════════════════════════════════════════════════ -->
<div id="todayError" class="alert alert-danger" style="display:none">
  <i class="fas fa-circle-exclamation"></i>
  <span id="todayErrorText">Something went wrong while loading your schedule.</span>
  <button type="button" class="btn btn-sm btn-outline" id="todayErrorRetry" style="margin-left:auto">
    <i class="fas fa-rotate"></i> Try Again
  </button>
</div>

<div id="noSessionAlert" class="alert alert-warning" style="display:none">
  <i class="fas fa-triangle-exclamation"></i>
  <span>There's no active academic session set right now. Please contact your school admin office.</span>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     "NOW TEACHING / NEXT CLASS / DONE" BANNER
     ═══════════════════════════════════════════════════════════════ -->
<div id="nowBanner" class="now-banner now-banner-skel">
  <div class="nb-icon"><span class="skel-line" style="width:24px;height:24px;border-radius:50%"></span></div>
  <div class="nb-text">
    <div class="skel-line" style="width:180px;height:16px;margin-bottom:8px"></div>
    <div class="skel-line" style="width:120px;height:12px"></div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     SUMMARY STRIP
     ═══════════════════════════════════════════════════════════════ -->
<div class="today-summary" id="summaryStrip">
  <div class="ts-item">
    <div class="ts-value skel-value"><span class="skel-line" style="width:28px"></span></div>
    <div class="ts-label">Total Periods</div>
  </div>
  <div class="ts-divider"></div>
  <div class="ts-item">
    <div class="ts-value skel-value"><span class="skel-line" style="width:28px"></span></div>
    <div class="ts-label">Completed</div>
  </div>
  <div class="ts-divider"></div>
  <div class="ts-item">
    <div class="ts-value skel-value"><span class="skel-line" style="width:28px"></span></div>
    <div class="ts-label">Remaining</div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     PERIOD LIST
     ═══════════════════════════════════════════════════════════════ -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-list-check"></i> Full Schedule — Today</div>
    <a href="<?= BASE_URL ?>/teacher/routine.php" class="btn btn-sm btn-outline">Full Week</a>
  </div>
  <div id="periodsBody">
    <div class="period-list" id="periodsSkeleton">
      <?php for ($i = 0; $i < 4; $i++): ?>
      <div class="period-card">
        <div class="pc-time">
          <span class="skel-line" style="width:46px;height:16px;margin-bottom:6px"></span>
          <span class="skel-line" style="width:40px;height:11px"></span>
        </div>
        <div class="pc-body">
          <span class="skel-line" style="width:140px;height:16px;margin-bottom:8px"></span>
          <span class="skel-line" style="width:100px;height:12px"></span>
        </div>
        <div class="pc-actions">
          <span class="skel-line" style="width:90px;height:36px;border-radius:8px"></span>
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
  /* ── Skeleton shimmer (shared pattern with dashboard) ─────────── */
  .skel-line {
    display: inline-block;
    height: 22px;
    border-radius: 6px;
    background: linear-gradient(90deg, var(--border-light) 25%, var(--border) 37%, var(--border-light) 63%);
    background-size: 400% 100%;
    animation: skelShimmer 1.4s ease infinite;
    vertical-align: middle;
  }
  @keyframes skelShimmer { 0% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
  #refreshIcon.spinning { animation: refreshSpin .8s linear infinite; }
  @keyframes refreshSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

  /* ── Now Teaching / Next Class / Done banner ──────────────────── */
  .now-banner {
    border-radius: var(--r-lg);
    padding: 20px 24px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 18px;
    color: #fff;
    background: linear-gradient(120deg, var(--primary) 0%, var(--accent) 100%);
    box-shadow: var(--shadow-md);
  }
  .now-banner-skel { background: var(--bg-card); border: 1px solid var(--border); color: var(--text); box-shadow: var(--shadow-card); }
  .now-banner.nb-next  { background: linear-gradient(120deg, #0284c7 0%, #0369a1 100%); }
  .now-banner.nb-done  { background: linear-gradient(120deg, #7c3aed 0%, #6d28d9 100%); }
  .now-banner.nb-empty { background: var(--bg-card); border: 1px solid var(--border); color: var(--text-muted); box-shadow: var(--shadow-card); }

  .nb-icon {
    width: 54px; height: 54px; flex-shrink: 0;
    background: rgba(255,255,255,.18);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px;
  }
  .now-banner-skel .nb-icon,
  .now-banner.nb-empty .nb-icon { background: var(--bg); color: var(--text-light); }

  .nb-text { flex: 1; min-width: 0; }
  .nb-tag {
    font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
    opacity: .85; margin-bottom: 3px; display: block;
  }
  .nb-title { font-size: 17px; font-weight: 800; letter-spacing: -.02em; line-height: 1.3; }
  .nb-sub   { font-size: 13px; font-weight: 500; opacity: .9; margin-top: 4px; }
  .nb-sub strong { font-weight: 800; }

  .nb-actions { display: flex; gap: 8px; flex-shrink: 0; }
  .nb-actions .btn { background: rgba(255,255,255,.18); border-color: rgba(255,255,255,.35); color: #fff; }
  .nb-actions .btn:hover { background: rgba(255,255,255,.3); }
  .now-banner.nb-empty .nb-actions .btn,
  .now-banner.nb-done  .nb-actions .btn { display: none; }

  @keyframes nbPulse { 0%,100% { opacity: 1; } 50% { opacity: .55; } }
  .nb-live-dot {
    display: inline-block; width: 8px; height: 8px; border-radius: 50%;
    background: #fff; margin-right: 6px; animation: nbPulse 1.6s ease infinite;
  }

  /* ── Summary strip ─────────────────────────────────────────────── */
  .today-summary {
    display: flex; align-items: center;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: var(--r-md); padding: 16px 8px;
    margin-bottom: 20px; box-shadow: var(--shadow-card);
  }
  .ts-item { flex: 1; text-align: center; padding: 0 8px; min-width: 0; }
  .ts-value { font-size: 24px; font-weight: 800; color: var(--text); line-height: 1; font-variant-numeric: tabular-nums; }
  .ts-label { font-size: 11.5px; color: var(--text-muted); font-weight: 600; margin-top: 6px; text-transform: uppercase; letter-spacing: .04em; }
  .ts-divider { width: 1px; align-self: stretch; background: var(--border); }

  /* ── Period card (bigger / more detailed than dashboard widget) ── */
  .period-list { display: flex; flex-direction: column; }
  .period-card {
    display: flex; align-items: center; gap: 16px;
    padding: 18px 20px;
    border-bottom: 1px solid var(--border-light);
    transition: background var(--t);
  }
  .period-card:last-child { border-bottom: none; }
  .period-card:hover { background: var(--primary-xlight); }

  .pc-time {
    width: 74px; flex-shrink: 0; text-align: center;
    display: flex; flex-direction: column; align-items: center;
    padding: 10px 6px; border-radius: var(--r-sm);
    background: var(--bg-elevated); border: 1px solid var(--border-light);
  }
  .pc-time-start { font-size: 13.5px; font-weight: 800; color: var(--text); font-variant-numeric: tabular-nums; line-height: 1.2; }
  .pc-time-end   { font-size: 10.5px; color: var(--text-muted); margin-top: 2px; font-weight: 600; }
  .pc-period-no  { font-size: 9.5px; color: var(--primary); font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-top: 4px; }

  .pc-body { flex: 1; min-width: 0; }
  .pc-subject { font-size: 15.5px; font-weight: 800; color: var(--text); letter-spacing: -.01em; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .pc-class { font-size: 13px; color: var(--text-muted); margin-top: 4px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
  .pc-class i { font-size: 10.5px; }
  .pc-code { font-size: 10.5px; color: var(--text-light); font-weight: 600; }

  .pc-actions { display: flex; gap: 8px; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }
  .pc-actions .btn { white-space: nowrap; }

  /* Status variants */
  .period-card.pc-current {
    background: var(--primary-xlight);
    border-left: 4px solid var(--primary);
    padding-left: 16px;
  }
  .period-card.pc-current .pc-time { background: var(--primary); border-color: var(--primary); }
  .period-card.pc-current .pc-time-start,
  .period-card.pc-current .pc-time-end { color: #fff; }
  .period-card.pc-current .pc-subject { color: var(--primary-dark); }

  .period-card.pc-done { opacity: .6; }
  .period-card.pc-done .pc-subject { color: var(--text-muted); }
  .period-card.pc-done .pc-actions .btn-primary { background: var(--text-light); border-color: var(--text-light); }

  .pc-now-pill {
    display: inline-flex; align-items: center; gap: 4px;
    background: var(--primary); color: #fff;
    font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
    padding: 2px 8px; border-radius: 20px;
  }
  .pc-now-pill i { font-size: 7px; animation: nbPulse 1.6s ease infinite; }

  .pc-done-pill {
    display: inline-flex; align-items: center; gap: 4px;
    background: var(--border); color: var(--text-muted);
    font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
    padding: 2px 8px; border-radius: 20px;
  }

  /* ── Responsive ─────────────────────────────────────────────────── */
  @media (max-width: 640px) {
    .now-banner { flex-wrap: wrap; padding: 16px 18px; }
    .nb-actions { width: 100%; }
    .nb-actions .btn { flex: 1; }

    .today-summary { padding: 14px 4px; }
    .ts-value { font-size: 20px; }
    .ts-label { font-size: 10px; }

    .period-card { flex-wrap: wrap; padding: 16px; }
    .pc-time { width: 62px; padding: 8px 4px; }
    .pc-actions { width: 100%; justify-content: stretch; margin-top: 4px; }
    .pc-actions .btn { flex: 1; }
  }

  @media (max-width: 400px) {
    .nb-title { font-size: 15px; }
    .nb-sub   { font-size: 12px; }
    .pc-subject { font-size: 14px; }
  }
</style>

<!-- CSRF token for AJAX requests -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<?php
// ── Page-specific JS — passed to the footer's $pageJs slot ────────
$pageJs = <<<'JS'
(function () {
  "use strict";

  const AJAX_URL = "today-ajax.php";
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

  // ── Bangladesh Standard Time helpers (matches the topbar clock) ──
  function bstNow() {
    const now = new Date();
    const bstOffset = 6 * 60;
    const utc = now.getTime() + now.getTimezoneOffset() * 60000;
    return new Date(utc + bstOffset * 60000);
  }
  function nowMinutes() { const b = bstNow(); return b.getHours() * 60 + b.getMinutes(); }
  function toMinutes(t) { if (!t) return null; const [h, m] = t.split(":").map(Number); return h * 60 + m; }

  function fmtTime(t) {
    if (!t) return "";
    const [h, m] = t.split(":").map(Number);
    const ampm = h >= 12 ? "PM" : "AM";
    const h12  = (h % 12) || 12;
    return String(h12).padStart(2,"0") + ":" + String(m).padStart(2,"0") + " " + ampm;
  }

  function fmtDuration(mins) {
    if (mins < 1) return "less than a minute";
    if (mins < 60) return mins + " min";
    const h = Math.floor(mins / 60), m = mins % 60;
    return h + " hr" + (m > 0 ? " " + m + " min" : "");
  }

  // ── State ──────────────────────────────────────────────────────────
  let periods       = [];
  let isFriday       = false;
  let noSessionFlag  = false;
  let hasLoadedOnce  = false;

  // ── Compute live status for every period against current BST time ──
  function withStatus() {
    const n = nowMinutes();
    return periods.map((p) => {
      const s = toMinutes(p.start_time), e = toMinutes(p.end_time);
      let status = "upcoming";
      if (s !== null && e !== null) {
        if (n > e) status = "done";
        else if (n >= s && n <= e) status = "current";
      }
      return Object.assign({}, p, { status: status, startMin: s, endMin: e });
    });
  }

  function buildAttendanceUrl(p) {
    const qs = new URLSearchParams({
      class_id: p.class_id, session_id: p.session_id,
      group_id: p.group_id !== null ? p.group_id : ""
    });
    return "attendance.php?" + qs.toString();
  }
  function buildMarksUrl(p) {
    const qs = new URLSearchParams({
      class_id: p.class_id, subject_id: p.subject_id,
      session_id: p.session_id, group_id: p.group_id !== null ? p.group_id : ""
    });
    return "marks.php?" + qs.toString();
  }

  // ── Render: "Now Teaching / Next Class / Done" banner ────────────
  function renderBanner(list) {
    const el = $("nowBanner");
    el.classList.remove("now-banner-skel", "nb-next", "nb-done", "nb-empty");

    if (isFriday) {
      el.className = "now-banner nb-empty";
      el.innerHTML =
        '<div class="nb-icon"><i class="fas fa-mug-hot"></i></div>' +
        '<div class="nb-text"><div class="nb-title">It\'s Friday — enjoy your day off!</div>' +
        '<div class="nb-sub">No classes are scheduled today.</div></div>';
      return;
    }
    if (!list.length) {
      el.className = "now-banner nb-empty";
      el.innerHTML =
        '<div class="nb-icon"><i class="fas fa-calendar-xmark"></i></div>' +
        '<div class="nb-text"><div class="nb-title">No classes scheduled for today.</div>' +
        '<div class="nb-sub">Check back once your school admin builds the routine.</div></div>';
      return;
    }

    const current = list.find((p) => p.status === "current");
    if (current) {
      const remain = fmtDuration(current.endMin - nowMinutes());
      el.className = "now-banner";
      el.innerHTML =
        '<div class="nb-icon"><i class="fas fa-chalkboard-user"></i></div>' +
        '<div class="nb-text">' +
          '<span class="nb-tag"><span class="nb-live-dot"></span>Teaching Now</span>' +
          '<div class="nb-title">' + escapeHtml(current.subject_name) + ' — ' + escapeHtml(current.class_label) + (current.group_name ? ' (' + escapeHtml(current.group_name) + ')' : '') + '</div>' +
          '<div class="nb-sub"><strong>' + remain + '</strong> left in this period</div>' +
        '</div>' +
        '<div class="nb-actions">' +
          '<a href="' + buildAttendanceUrl(current) + '" class="btn btn-sm"><i class="fas fa-calendar-check"></i> Attendance</a>' +
          '<a href="' + buildMarksUrl(current) + '" class="btn btn-sm"><i class="fas fa-pencil"></i> Marks</a>' +
        '</div>';
      return;
    }

    const next = list.find((p) => p.status === "upcoming");
    if (next) {
      const until = fmtDuration(next.startMin - nowMinutes());
      el.className = "now-banner nb-next";
      el.innerHTML =
        '<div class="nb-icon"><i class="fas fa-hourglass-half"></i></div>' +
        '<div class="nb-text">' +
          '<span class="nb-tag">Next Class</span>' +
          '<div class="nb-title">' + escapeHtml(next.subject_name) + ' — ' + escapeHtml(next.class_label) + (next.group_name ? ' (' + escapeHtml(next.group_name) + ')' : '') + '</div>' +
          '<div class="nb-sub">Starts at <strong>' + fmtTime(next.start_time) + '</strong> &middot; in ' + until + '</div>' +
        '</div>' +
        '<div class="nb-actions">' +
          '<a href="' + buildAttendanceUrl(next) + '" class="btn btn-sm"><i class="fas fa-calendar-check"></i> Attendance</a>' +
        '</div>';
      return;
    }

    // All periods are done
    el.className = "now-banner nb-done";
    el.innerHTML =
      '<div class="nb-icon"><i class="fas fa-champagne-glasses"></i></div>' +
      '<div class="nb-text"><div class="nb-title">You\'re done for today!</div>' +
      '<div class="nb-sub">All ' + list.length + ' of your classes are complete.</div></div>';
  }

  // ── Render: summary strip ─────────────────────────────────────────
  function renderSummary(list) {
    const total     = list.length;
    const completed = list.filter((p) => p.status === "done").length;
    const remaining = total - completed;

    const items = $("summaryStrip").querySelectorAll(".ts-value");
    if (items[0]) { items[0].textContent = total;     items[0].classList.remove("skel-value"); }
    if (items[1]) { items[1].textContent = completed; items[1].classList.remove("skel-value"); }
    if (items[2]) { items[2].textContent = remaining; items[2].classList.remove("skel-value"); }
  }

  // ── Render: full period list ──────────────────────────────────────
  function renderPeriods(list) {
    const wrap = $("periodsBody");

    if (isFriday) {
      wrap.innerHTML = '<div class="empty-state"><i class="fas fa-mug-hot"></i><h3>Friday — No Classes</h3><p>Enjoy your day off. Classes resume Saturday.</p></div>';
      return;
    }
    if (!list.length) {
      wrap.innerHTML = '<div class="empty-state"><i class="fas fa-calendar-xmark"></i><h3>Nothing scheduled today</h3><p>Your school admin hasn\'t added any classes for today yet.</p></div>';
      return;
    }

    let html = '<div class="period-list">';
    list.forEach((p) => {
      const statusCls = p.status === "current" ? " pc-current" : (p.status === "done" ? " pc-done" : "");
      html += '<div class="period-card' + statusCls + '">';

      html += '<div class="pc-time">';
      html += '<div class="pc-time-start">' + escapeHtml(fmtTime(p.start_time)) + '</div>';
      html += '<div class="pc-time-end">' + escapeHtml(fmtTime(p.end_time)) + '</div>';
      html += '<div class="pc-period-no">P' + p.period_number + '</div>';
      html += '</div>';

      html += '<div class="pc-body">';
      html += '<div class="pc-subject">' + escapeHtml(p.subject_name || "—");
      if (p.status === "current") html += ' <span class="pc-now-pill"><i class="fas fa-circle"></i>Now</span>';
      if (p.status === "done")    html += ' <span class="pc-done-pill"><i class="fas fa-check"></i>Done</span>';
      html += '</div>';
      html += '<div class="pc-class"><i class="fas fa-users"></i> ' + escapeHtml(p.class_label || "") + (p.group_name ? ' (' + escapeHtml(p.group_name) + ')' : '') + (p.subject_code ? ' <span class="pc-code">&middot; Code ' + escapeHtml(p.subject_code) + '</span>' : '') + '</div>';
      html += '</div>';

      html += '<div class="pc-actions">';
      html += '<a href="' + buildAttendanceUrl(p) + '" class="btn btn-sm btn-outline"><i class="fas fa-calendar-check"></i> Attendance</a>';
      html += '<a href="' + buildMarksUrl(p) + '" class="btn btn-sm btn-primary"><i class="fas fa-pencil"></i> Marks</a>';
      html += '</div>';

      html += '</div>';
    });
    html += '</div>';
    wrap.innerHTML = html;
  }

  // ── Tick: recompute status + re-render (no server call) ──────────
  function tick(scrollToCurrent) {
    if (!hasLoadedOnce) return;
    const list = withStatus();
    renderBanner(list);
    renderSummary(list);
    renderPeriods(list);

    if (scrollToCurrent) {
      const currentCard = document.querySelector(".period-card.pc-current");
      if (currentCard) {
        setTimeout(() => currentCard.scrollIntoView({ behavior: "smooth", block: "center" }), 150);
      }
    }
  }

  // ── Load from server ───────────────────────────────────────────────
  let isLoading = false;

  async function loadToday(isManualRefresh) {
    if (isLoading) return;
    isLoading = true;

    const refreshIcon = $("refreshIcon");
    if (refreshIcon) refreshIcon.classList.add("spinning");
    $("todayError").style.display = "none";

    const data = await post("fetch_today", {});

    if (refreshIcon) refreshIcon.classList.remove("spinning");
    isLoading = false;

    if (!data) {
      $("todayErrorText").textContent = "Could not reach the server. Please check your connection.";
      $("todayError").style.display = "flex";
      return;
    }
    if (!data.ok) {
      $("todayErrorText").textContent = data.msg || "Something went wrong while loading your schedule.";
      $("todayError").style.display = "flex";
      return;
    }

    $("noSessionAlert").style.display = data.no_session ? "flex" : "none";
    $("dateLabel").textContent = data.date_label || "";

    periods      = data.periods || [];
    isFriday     = !!data.is_friday;
    noSessionFlag = !!data.no_session;
    hasLoadedOnce = true;

    tick(!isManualRefresh); // auto-scroll to current class only on first load

    if (isManualRefresh && window.showToast) {
      window.showToast("success", "Refreshed", "Your schedule is up to date.");
    }
  }

  // ── Wire up buttons ────────────────────────────────────────────────
  const refreshBtn = $("refreshBtn");
  if (refreshBtn) refreshBtn.addEventListener("click", () => loadToday(true));

  const retryBtn = $("todayErrorRetry");
  if (retryBtn) retryBtn.addEventListener("click", () => loadToday(false));

  // ── Initial load ──────────────────────────────────────────────────
  loadToday(false);

  // ── Live tick every 20s (status/countdown only, no server call) ──
  setInterval(() => tick(false), 20000);

  // ── Full silent refetch every 5 min (picks up admin routine edits) ──
  setInterval(() => loadToday(false), 300000);

})();
JS;

require_once __DIR__ . '/includes/teacher-footer.php';
?>
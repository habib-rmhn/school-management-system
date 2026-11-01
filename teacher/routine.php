<?php
/**
 * EMS Teacher — My Routine  v1.0
 * ─────────────────────────────────────────────────────────────────
 * The teacher's own combined weekly timetable across every class and
 * subject they teach — the full-week reference view. (For "what am I
 * doing right now", see today.php instead — that page owns the live
 * Now/Next banner; this page is a calm, scannable week-at-a-glance.)
 *
 * Desktop/tablet : a clean period-rows × day-columns grid.
 * Mobile         : day tabs + one day's classes as big cards — a
 *                  6-column table squeezed onto a phone helps no one.
 *
 * All data loads via AJAX (routine-ajax.php) — no page reload.
 */
require_once __DIR__ . '/../includes/teacher-session.php';

$pageTitle  = 'My Routine';
$activeNav  = 'routine';
$breadcrumb = ['My Routine'];

require_once __DIR__ . '/includes/teacher-sidebar.php';
?>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="page-header-top">
    <div>
      <div class="page-title"><i class="fas fa-calendar-week" style="color:var(--primary);margin-right:8px"></i>My Routine</div>
      <div class="page-sub" id="routineSub">
        <i class="fas fa-circle-info"></i> Loading your weekly schedule…
      </div>
    </div>
    <div class="page-actions">
      <a href="<?= BASE_URL ?>/teacher/today.php" class="btn btn-outline">
        <i class="fas fa-sun"></i> Today's Classes
      </a>
      <button type="button" class="btn btn-outline" id="refreshBtn">
        <i class="fas fa-rotate" id="refreshIcon"></i> Refresh
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     ERROR / NO-SESSION STATES
     ═══════════════════════════════════════════════════════════════ -->
<div id="routineError" class="alert alert-danger" style="display:none">
  <i class="fas fa-circle-exclamation"></i>
  <span id="routineErrorText">Something went wrong while loading your routine.</span>
  <button type="button" class="btn btn-sm btn-outline" id="routineErrorRetry" style="margin-left:auto">
    <i class="fas fa-rotate"></i> Try Again
  </button>
</div>

<div id="noSessionAlert" class="alert alert-warning" style="display:none">
  <i class="fas fa-triangle-exclamation"></i>
  <span>There's no active academic session set right now. Please contact your school admin office.</span>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     SUMMARY STRIP
     ═══════════════════════════════════════════════════════════════ -->
<div class="today-summary" id="summaryStrip">
  <div class="ts-item">
    <div class="ts-value skel-value"><span class="skel-line" style="width:28px"></span></div>
    <div class="ts-label">Periods / Week</div>
  </div>
  <div class="ts-divider"></div>
  <div class="ts-item">
    <div class="ts-value skel-value"><span class="skel-line" style="width:28px"></span></div>
    <div class="ts-label">Classes You Teach</div>
  </div>
  <div class="ts-divider"></div>
  <div class="ts-item">
    <div class="ts-value skel-value"><span class="skel-line" style="width:28px"></span></div>
    <div class="ts-label">Subjects</div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     LOADING SKELETON  (shown until first successful fetch)
     ═══════════════════════════════════════════════════════════════ -->
<div id="trSkeleton" class="card">
  <div class="empty-state">
    <i class="fas fa-spinner fa-spin"></i>
    <p>Loading your class routine…</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     EMPTY STATE (no slots at all)
     ═══════════════════════════════════════════════════════════════ -->
<div id="trEmpty" class="card" style="display:none">
  <div class="empty-state">
    <i class="fas fa-calendar-xmark"></i>
    <h3>No routine has been added yet</h3>
    <p>Your school admin hasn't scheduled any classes for you this session. Check back later.</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MAIN ROUTINE VIEW (hidden until data loads)
     ═══════════════════════════════════════════════════════════════ -->
<div id="trContent" style="display:none">

  <!-- ── Mobile: Day tabs ───────────────────────────────────────── -->
  <div class="tr-day-tabs" id="trDayTabs" role="tablist" aria-label="Choose a day"></div>

  <!-- ── Mobile: selected day's classes as cards ───────────────── -->
  <div class="tr-day-cards" id="trDayCards"></div>

  <!-- ── Desktop: full weekly grid ──────────────────────────────── -->
  <div class="card tr-grid-card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-table-cells"></i> Full Week View</div>
      <span class="badge badge-green" id="trWeekBadge"></span>
    </div>
    <div class="table-wrap">
      <table class="ems-table tr-grid-table" id="trGridTable">
        <thead><tr id="trGridHead"></tr></thead>
        <tbody id="trGridBody"></tbody>
      </table>
    </div>
  </div>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE-SPECIFIC STYLES
     ═══════════════════════════════════════════════════════════════ -->
<style>
  /* ── Skeleton shimmer (shared pattern across teacher pages) ────── */
  .skel-line {
    display: inline-block; height: 22px; border-radius: 6px;
    background: linear-gradient(90deg, var(--border-light) 25%, var(--border) 37%, var(--border-light) 63%);
    background-size: 400% 100%;
    animation: skelShimmer 1.4s ease infinite;
    vertical-align: middle;
  }
  @keyframes skelShimmer { 0% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
  #refreshIcon.spinning { animation: refreshSpin .8s linear infinite; }
  @keyframes refreshSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

  /* ── Summary strip (matches today.php) ─────────────────────────── */
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

  /* ── Day tabs (mobile-first, horizontally scrollable) ──────────── */
  .tr-day-tabs {
    display: flex; gap: 8px; overflow-x: auto; -webkit-overflow-scrolling: touch;
    padding: 4px 2px 14px; scrollbar-width: none;
  }
  .tr-day-tabs::-webkit-scrollbar { display: none; }

  .tr-day-tab {
    flex-shrink: 0; min-width: 64px; padding: 10px 14px;
    border-radius: var(--r-sm); background: var(--bg-card);
    border: 1.5px solid var(--border); text-align: center;
    cursor: pointer; transition: all var(--t); user-select: none;
  }
  .tr-day-tab .dt-name  { font-size: 12px; font-weight: 700; color: var(--text-muted); }
  .tr-day-tab .dt-count { font-size: 10.5px; color: var(--text-light); margin-top: 2px; font-weight: 600; }
  .tr-day-tab.is-today { border-color: var(--primary); }
  .tr-day-tab.is-today .dt-name { color: var(--primary); }
  .tr-day-tab.active { background: var(--primary); border-color: var(--primary); }
  .tr-day-tab.active .dt-name,
  .tr-day-tab.active .dt-count { color: #fff; }

  /* ── Day cards (mobile period list) ────────────────────────────── */
  .tr-day-cards { display: none; margin-bottom: 22px; }

  .tr-period-card {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: var(--r-md); padding: 16px; margin-bottom: 10px;
    display: flex; align-items: center; gap: 14px;
    box-shadow: var(--shadow-card); position: relative; overflow: hidden;
  }
  .tr-period-card.is-now { border-color: var(--primary); background: var(--primary-xlight); }
  .tr-period-card.is-now::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--primary); }

  .trp-period {
    width: 46px; height: 46px; border-radius: var(--r-sm);
    background: var(--primary-xlight); color: var(--primary);
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    flex-shrink: 0; font-weight: 800;
  }
  .trp-period .pn { font-size: 16px; line-height: 1; }
  .trp-period .pl { font-size: 8.5px; text-transform: uppercase; letter-spacing: .04em; margin-top: 1px; }

  .trp-body { flex: 1; min-width: 0; }
  .trp-subject { font-size: 15px; font-weight: 800; color: var(--text); }
  .trp-class   { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; display: flex; align-items: center; gap: 5px; flex-wrap: wrap; }
  .trp-class i { font-size: 10.5px; }
  .trp-time { font-size: 12px; font-weight: 700; color: var(--text-sub); white-space: nowrap; text-align: right; flex-shrink: 0; }
  .trp-time .now-pill {
    display: inline-block; margin-top: 4px; font-size: 9.5px;
    background: var(--green-bg); color: var(--green);
    padding: 2px 7px; border-radius: 20px; font-weight: 700;
  }

  .tr-empty-day { text-align: center; padding: 36px 16px; color: var(--text-muted); }
  .tr-empty-day i { font-size: 32px; color: var(--text-light); display: block; margin-bottom: 10px; }
  .tr-empty-day p { font-size: 13.5px; }

  /* ── Desktop weekly grid ────────────────────────────────────────── */
  .tr-grid-card { display: none; }

  .tr-grid-table th,
  .tr-grid-table td { text-align: center; }
  .tr-grid-table th:first-child,
  .tr-grid-table td:first-child { text-align: left; white-space: nowrap; }

  .tr-grid-table .tr-today-col { background: var(--primary-xlight) !important; }
  thead .tr-today-col { background: var(--primary-light) !important; color: var(--primary-dark) !important; }

  .trg-cell {
    display: inline-flex; flex-direction: column; align-items: center; gap: 2px;
    padding: 6px 4px; border-radius: 8px; min-width: 100px;
  }
  .trg-cell .trg-subject { font-size: 12.5px; font-weight: 700; color: var(--text-sub); }
  .trg-cell .trg-class   { font-size: 10.5px; color: var(--text-muted); }
  .trg-cell.trg-empty .trg-subject { color: var(--text-light); font-weight: 500; font-style: italic; }
  .trg-cell.trg-now { background: var(--primary-light); }
  .trg-cell.trg-now .trg-subject { color: var(--primary-dark); }
  .trg-period-cell { font-weight: 700; color: var(--text); }
  .trg-period-time { font-size: 10.5px; color: var(--text-muted); font-weight: 500; display: block; margin-top: 1px; }

  /* ── Responsive switch: cards on mobile, grid on desktop ─────────── */
  @media (max-width: 900px) {
    .tr-day-cards { display: block; }
    .tr-grid-card { display: none !important; }
  }
  @media (min-width: 901px) {
    .tr-day-tabs  { display: none; }
    .tr-day-cards { display: none !important; }
    .tr-grid-card { display: block; }
  }

  @media (max-width: 480px) {
    .today-summary { padding: 14px 4px; }
    .ts-value { font-size: 20px; }
    .ts-label { font-size: 10px; }
    .page-title { font-size: 18px; }
  }
</style>

<!-- CSRF token for AJAX requests -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<?php
// ── Page-specific JS — passed to the footer's $pageJs slot ────────
$pageJs = <<<'JS'
(function () {
  "use strict";

  const AJAX_URL = "routine-ajax.php";
  function getToken() { return document.getElementById("csrfToken")?.value || ""; }
  let csrfToken = getToken();

  const $ = (id) => document.getElementById(id);
  const escapeHtml = (str) => String(str ?? "").replace(/[&<>"']/g, (c) => ({
    "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;"
  }[c]));

  const dayNames = [
    { num: 0, short: "Sat", long: "Saturday"  },
    { num: 1, short: "Sun", long: "Sunday"    },
    { num: 2, short: "Mon", long: "Monday"    },
    { num: 3, short: "Tue", long: "Tuesday"   },
    { num: 4, short: "Wed", long: "Wednesday" },
    { num: 5, short: "Thu", long: "Thursday"  },
  ];

  // Bangladesh week: Sat=0 ... Thu=5, Friday is the weekend (no class)
  function bstNow() {
    const now = new Date();
    const bstOffset = 6 * 60;
    const utc = now.getTime() + now.getTimezoneOffset() * 60000;
    return new Date(utc + bstOffset * 60000);
  }
  const jsDow    = bstNow().getDay();               // JS: 0=Sun...6=Sat
  const bdToday  = jsDow === 0 ? 1 : (jsDow === 6 ? 0 : jsDow);
  const isFriday = jsDow === 5;

  let currentSlots       = [];
  let currentPeriodTimes = [];
  let selectedDay        = isFriday ? 0 : bdToday;

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

  function toMinutes(t) { if (!t) return null; const [h, m] = t.split(":").map(Number); return h * 60 + m; }
  function nowMinutes() { const b = bstNow(); return b.getHours() * 60 + b.getMinutes(); }

  function isCurrentSlot(dayNum, start, end) {
    if (isFriday || dayNum !== bdToday) return false;
    const s = toMinutes(start), e = toMinutes(end), n = nowMinutes();
    return s !== null && e !== null && n >= s && n <= e;
  }

  function showOnly(id) {
    ["trSkeleton", "trEmpty", "trContent"].forEach((elId) => {
      const el = $(elId);
      if (el) el.style.display = (elId === id) ? "" : "none";
    });
  }

  // ── Render: summary strip ─────────────────────────────────────────
  function renderSummary(summary) {
    const items = $("summaryStrip").querySelectorAll(".ts-value");
    if (items[0]) { items[0].textContent = summary.total_slots;       items[0].classList.remove("skel-value"); }
    if (items[1]) { items[1].textContent = summary.distinct_classes;  items[1].classList.remove("skel-value"); }
    if (items[2]) { items[2].textContent = summary.distinct_subjects; items[2].classList.remove("skel-value"); }
  }

  // ── Render: mobile day tabs ────────────────────────────────────────
  function renderDayTabs() {
    const wrap = $("trDayTabs");
    wrap.innerHTML = "";
    dayNames.forEach((d) => {
      const count = currentSlots.filter((s) => s.day_of_week === d.num).length;
      const btn = document.createElement("div");
      btn.className = "tr-day-tab" + (d.num === selectedDay ? " active" : "") + (d.num === bdToday && !isFriday ? " is-today" : "");
      btn.setAttribute("role", "tab");
      btn.setAttribute("tabindex", "0");
      btn.innerHTML = '<div class="dt-name">' + d.short + '</div><div class="dt-count">' + count + (count === 1 ? " class" : " classes") + '</div>';
      btn.addEventListener("click", () => { selectedDay = d.num; renderDayTabs(); renderDayCards(); });
      wrap.appendChild(btn);
    });
  }

  // ── Render: mobile day cards for the selected day ─────────────────
  function renderDayCards() {
    const wrap = $("trDayCards");
    const daySlots = currentSlots
      .filter((s) => s.day_of_week === selectedDay)
      .sort((a, b) => a.period_number - b.period_number);

    if (isFriday && selectedDay === 0) {
      // Friday itself isn't a valid day in our 0-5 scheme, this branch is unreachable
      // but kept defensive in case selectedDay logic changes later.
    }

    if (daySlots.length === 0) {
      wrap.innerHTML = '<div class="tr-empty-day"><i class="fas fa-mug-hot"></i><p>No classes scheduled on this day.</p></div>';
      return;
    }

    let html = "";
    daySlots.forEach((s) => {
      const now = isCurrentSlot(s.day_of_week, s.start_time, s.end_time);
      html += '<div class="tr-period-card' + (now ? " is-now" : "") + '">';
      html += '<div class="trp-period"><span class="pn">' + s.period_number + '</span><span class="pl">Period</span></div>';
      html += '<div class="trp-body">';
      html += '<div class="trp-subject">' + escapeHtml(s.subject_name || "—") + '</div>';
      html += '<div class="trp-class"><i class="fas fa-users"></i> ' + escapeHtml(s.class_label || "") + (s.group_name ? ' (' + escapeHtml(s.group_name) + ')' : '') + '</div>';
      html += '</div>';
      html += '<div class="trp-time">' + escapeHtml(fmtTime(s.start_time)) + '<br>' + escapeHtml(fmtTime(s.end_time));
      if (now) html += '<div class="now-pill">Now</div>';
      html += '</div>';
      html += '</div>';
    });
    wrap.innerHTML = html;
  }

  // ── Render: desktop weekly grid ────────────────────────────────────
  function renderGrid() {
    const head = $("trGridHead");
    const body = $("trGridBody");

    let headHtml = '<th>Period</th>';
    dayNames.forEach((d) => {
      const todayCls = (d.num === bdToday && !isFriday) ? " tr-today-col" : "";
      headHtml += '<th class="' + todayCls.trim() + '">' + d.long + (d.num === bdToday && !isFriday ? ' <span style="font-weight:400;font-size:10px">(Today)</span>' : '') + '</th>';
    });
    head.innerHTML = headHtml;

    if (currentPeriodTimes.length === 0) {
      body.innerHTML = '<tr><td colspan="7" style="padding:32px;color:var(--text-muted)">No period times found.</td></tr>';
      return;
    }

    let bodyHtml = "";
    currentPeriodTimes.forEach((pt) => {
      bodyHtml += '<tr>';
      bodyHtml += '<td class="trg-period-cell">Period ' + pt.period_number + '<span class="trg-period-time">' + escapeHtml(fmtTime(pt.start_time)) + ' - ' + escapeHtml(fmtTime(pt.end_time)) + '</span></td>';

      dayNames.forEach((d) => {
        const slot = currentSlots.find((s) => s.period_number === pt.period_number && s.day_of_week === d.num);
        const todayCls = (d.num === bdToday && !isFriday) ? " tr-today-col" : "";
        if (slot) {
          const now = isCurrentSlot(slot.day_of_week, slot.start_time, slot.end_time);
          bodyHtml += '<td class="' + todayCls.trim() + '">';
          bodyHtml += '<div class="trg-cell' + (now ? " trg-now" : "") + '">';
          bodyHtml += '<div class="trg-subject">' + escapeHtml(slot.subject_name || "—") + (now ? ' <i class="fas fa-circle" style="font-size:6px;color:var(--primary)"></i>' : '') + '</div>';
          bodyHtml += '<div class="trg-class">' + escapeHtml(slot.class_label || "") + (slot.group_name ? ' (' + escapeHtml(slot.group_name) + ')' : '') + '</div>';
          bodyHtml += '</div></td>';
        } else {
          bodyHtml += '<td class="' + todayCls.trim() + '"><div class="trg-cell trg-empty"><div class="trg-subject">Free</div></div></td>';
        }
      });

      bodyHtml += '</tr>';
    });
    body.innerHTML = bodyHtml;
  }

  // ── Load from server ───────────────────────────────────────────────
  let isLoading = false;

  async function loadRoutine(isManualRefresh) {
    if (isLoading) return;
    isLoading = true;

    const refreshIcon = $("refreshIcon");
    if (refreshIcon) refreshIcon.classList.add("spinning");
    $("routineError").style.display = "none";

    const data = await post("fetch_routine", {});

    if (refreshIcon) refreshIcon.classList.remove("spinning");
    isLoading = false;

    if (!data) {
      showOnly("trEmpty");
      $("routineErrorText").textContent = "Could not reach the server. Please check your connection.";
      $("routineError").style.display = "flex";
      return;
    }
    if (!data.ok) {
      showOnly("trEmpty");
      $("routineErrorText").textContent = data.msg || "Something went wrong while loading your routine.";
      $("routineError").style.display = "flex";
      return;
    }

    $("noSessionAlert").style.display = data.no_session ? "flex" : "none";

    currentSlots       = data.slots || [];
    currentPeriodTimes = data.period_times || [];

    renderSummary(data.summary || { total_slots: 0, distinct_classes: 0, distinct_subjects: 0 });

    const subLabel = data.session_year
      ? (isFriday ? "It's Friday — no classes today. " : "") + "Session " + data.session_year
      : "No active session";
    $("routineSub").innerHTML = '<i class="fas fa-circle-info"></i> ' + escapeHtml(subLabel);

    if (currentSlots.length === 0) {
      showOnly("trEmpty");
    } else {
      showOnly("trContent");
      $("trWeekBadge").textContent = data.summary.total_slots + " periods this week";
      renderDayTabs();
      renderDayCards();
      renderGrid();
    }

    if (isManualRefresh && window.showToast) {
      window.showToast("success", "Refreshed", "Your routine is up to date.");
    }
  }

  // ── Wire up buttons ────────────────────────────────────────────────
  const refreshBtn = $("refreshBtn");
  if (refreshBtn) refreshBtn.addEventListener("click", () => loadRoutine(true));

  const retryBtn = $("routineErrorRetry");
  if (retryBtn) retryBtn.addEventListener("click", () => loadRoutine(false));

  // ── Initial load ──────────────────────────────────────────────────
  loadRoutine(false);

  // ── Re-render every minute so the "Now" highlight stays accurate
  //     (no server call — just re-evaluates against cached data) ────
  setInterval(() => {
    if (currentSlots.length > 0) { renderDayCards(); renderGrid(); }
  }, 60000);

})();
JS;

require_once __DIR__ . '/includes/teacher-footer.php';
?>
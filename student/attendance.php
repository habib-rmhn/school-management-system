<?php
/**
 * EMS Student — My Attendance  v1.0
 * ─────────────────────────────────────────────────────────────────
 * Shows the logged-in student's own attendance as a simple monthly
 * calendar. Data is loaded via AJAX (attendance-ajax.php) — no page
 * reload when changing months or class sessions.
 *
 * Design notes for a rural Class 6–10 audience:
 *   - A calendar is the most universally understood way to show
 *     "which days was I here" — no table headers to parse, no
 *     jargon. Green day = present, red = absent, amber = late,
 *     grey = no school record for that day (weekend/holiday/future).
 *   - Big month navigation arrows + a "This Month" button so a
 *     student never gets lost browsing past months.
 *   - Two simple stat cards up top: this month's % and overall %
 *     for the whole class session — answers the question a student
 *     actually has ("am I doing okay?") without making them count.
 *   - If a student has multiple enrollments (promoted classes), a
 *     dropdown lets them check a past class's attendance too.
 */
require_once __DIR__ . '/../includes/student-session.php';

$pageTitle = 'My Attendance';
$activeNav = 'attendance';

require_once __DIR__ . '/includes/student-sidebar.php';
?>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="at-header">
  <div>
    <div class="at-title"><i class="fas fa-calendar-check"></i> My Attendance</div>
    <div class="at-sub" id="atSubLabel">Loading your attendance…</div>
  </div>
  <div class="at-session-wrap" id="atSessionWrap" style="display:none">
    <label class="at-session-label" for="atSessionSelect">Class / Session</label>
    <select id="atSessionSelect" class="filter-select"></select>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     LOADING / ERROR / EMPTY STATES
     ═══════════════════════════════════════════════════════════════ -->
<div id="atLoading" class="card">
  <div class="empty-state">
    <i class="fas fa-spinner fa-spin"></i>
    <p>Loading your attendance…</p>
  </div>
</div>

<div id="atError" class="alert alert-danger" style="display:none"></div>

<div id="atNoEnrollment" class="alert alert-warning" style="display:none">
  <i class="fas fa-triangle-exclamation"></i>
  <span>You don't have any class enrollment on record yet. Please contact your school admin office.</span>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MAIN ATTENDANCE VIEW (hidden until data loads)
     ═══════════════════════════════════════════════════════════════ -->
<div id="atContent" style="display:none">

  <!-- ── Summary stat cards ─────────────────────────────────────── -->
  <div class="stats-grid at-stats-grid">
    <div class="stat-card" id="atMonthStatCard">
      <div class="stat-icon si-blue"><i class="fas fa-calendar-day"></i></div>
      <div class="stat-text">
        <div class="stat-value" id="atMonthPct">—</div>
        <div class="stat-label" id="atMonthLabel">This month</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-green"><i class="fas fa-check"></i></div>
      <div class="stat-text">
        <div class="stat-value" id="atMonthPresent">—</div>
        <div class="stat-label">Present this month</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-red"><i class="fas fa-xmark"></i></div>
      <div class="stat-text">
        <div class="stat-value" id="atMonthAbsent">—</div>
        <div class="stat-label">Absent this month</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-purple"><i class="fas fa-star"></i></div>
      <div class="stat-text">
        <div class="stat-value" id="atOverallPct">—</div>
        <div class="stat-label">Overall (whole session)</div>
      </div>
    </div>
  </div>

  <!-- ── Calendar card ───────────────────────────────────────────── -->
  <div class="card">
    <div class="card-header at-cal-header">
      <div class="card-title"><i class="fas fa-calendar"></i> <span id="atClassBadgeText">Calendar</span></div>
      <div class="at-month-nav">
        <button type="button" class="at-nav-btn" id="atPrevMonth" aria-label="Previous month">
          <i class="fas fa-chevron-left"></i>
        </button>
        <div class="at-month-label" id="atMonthYearLabel">—</div>
        <button type="button" class="at-nav-btn" id="atNextMonth" aria-label="Next month">
          <i class="fas fa-chevron-right"></i>
        </button>
        <button type="button" class="btn btn-sm btn-outline at-today-btn" id="atTodayBtn">Today</button>
      </div>
    </div>

    <div class="card-body">
      <!-- Weekday header (Bangladesh week: Sat → Thu, Friday off) -->
      <div class="at-cal-weekdays">
        <div>Sat</div><div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div class="at-fri">Fri</div>
      </div>
      <div class="at-cal-grid" id="atCalGrid"></div>

      <!-- Legend -->
      <div class="at-legend">
        <div class="at-legend-item"><span class="at-dot at-dot-present"></span> Present</div>
        <div class="at-legend-item"><span class="at-dot at-dot-late"></span> Late</div>
        <div class="at-legend-item"><span class="at-dot at-dot-absent"></span> Absent</div>
        <div class="at-legend-item"><span class="at-dot at-dot-today"></span> Today</div>
        <div class="at-legend-item"><span class="at-dot at-dot-none"></span> No record / Friday</div>
      </div>
    </div>
  </div>

  <!-- ── Tap-to-see-detail panel (mobile-friendly, replaces tooltip) ── -->
  <div class="card" id="atDetailCard" style="display:none">
    <div class="card-body" id="atDetailBody"></div>
  </div>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE-SPECIFIC STYLES
     ═══════════════════════════════════════════════════════════════ -->
<style>
  /* ── Page header ─────────────────────────────────────────────── */
  .at-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 20px;
  }
  .at-title {
    font-size: 20px;
    font-weight: 800;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -.02em;
  }
  .at-title i { color: var(--primary); font-size: 19px; }
  .at-sub {
    font-size: 13.5px;
    color: var(--text-muted);
    margin-top: 5px;
    font-weight: 500;
  }
  .at-session-wrap { display: flex; flex-direction: column; gap: 5px; min-width: 220px; }
  .at-session-label {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .04em;
  }

  .at-stats-grid { margin-bottom: 20px; }

  /* ── Calendar header / month nav ─────────────────────────────── */
  .at-cal-header { flex-wrap: wrap; gap: 12px; }
  .at-month-nav {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .at-nav-btn {
    width: 38px; height: 38px;
    border-radius: var(--r-sm);
    border: 1.5px solid var(--border);
    background: var(--bg-card);
    color: var(--text-muted);
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    cursor: pointer;
    transition: all var(--t);
    flex-shrink: 0;
  }
  .at-nav-btn:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-xlight); }
  .at-month-label {
    font-size: 14.5px;
    font-weight: 800;
    color: var(--text);
    min-width: 140px;
    text-align: center;
  }
  .at-today-btn { margin-left: 6px; }

  /* ── Weekday header row ──────────────────────────────────────── */
  .at-cal-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 6px;
    margin-bottom: 8px;
  }
  .at-cal-weekdays > div {
    text-align: center;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .04em;
    padding-bottom: 4px;
  }
  .at-cal-weekdays .at-fri { color: var(--text-light); }

  /* ── Calendar grid ────────────────────────────────────────────── */
  .at-cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 6px;
  }

  .at-day-cell {
    aspect-ratio: 1 / 1;
    border-radius: var(--r-sm);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    color: var(--text-sub);
    background: var(--bg-elevated);
    border: 1.5px solid transparent;
    cursor: default;
    position: relative;
    transition: transform var(--t);
    min-height: 38px;
  }
  .at-day-cell.is-blank { background: transparent; }

  .at-day-cell.has-present { background: var(--green-bg); color: var(--green-dark, #047857); }
  .at-day-cell.has-absent  { background: var(--red-bg);   color: #991b1b; }
  .at-day-cell.has-late    { background: var(--amber-bg); color: #92400e; }

  .at-day-cell.is-friday   { background: #f4f6fb; color: var(--text-light); }
  .at-day-cell.is-future   { background: transparent; color: var(--text-light); border-style: dashed; border-color: var(--border); }
  .at-day-cell.is-today    { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(37,99,235,.15) inset; }

  .at-day-cell.is-clickable { cursor: pointer; }
  .at-day-cell.is-clickable:hover { transform: scale(1.06); z-index: 2; }

  .at-day-num { font-size: 13px; line-height: 1; }
  .at-day-icon { font-size: 9px; margin-top: 2px; }

  /* ── Legend ───────────────────────────────────────────────────── */
  .at-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 18px;
    padding-top: 16px;
    border-top: 1px solid var(--border-light);
  }
  .at-legend-item {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-muted);
  }
  .at-dot { width: 12px; height: 12px; border-radius: 4px; flex-shrink: 0; display: inline-block; }
  .at-dot-present { background: var(--green); }
  .at-dot-late    { background: var(--amber); }
  .at-dot-absent  { background: var(--red); }
  .at-dot-today   { background: #fff; border: 2px solid var(--primary); }
  .at-dot-none    { background: var(--bg-elevated); border: 1px solid var(--border); }

  /* ── Detail panel (tap a day to see it, esp. useful on mobile) ── */
  .at-detail-row {
    display: flex;
    align-items: center;
    gap: 14px;
  }
  .at-detail-icon {
    width: 48px; height: 48px;
    border-radius: var(--r);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
  }
  .at-detail-text { flex: 1; min-width: 0; }
  .at-detail-date { font-size: 14.5px; font-weight: 800; color: var(--text); }
  .at-detail-status { font-size: 13px; font-weight: 600; margin-top: 2px; }
  .at-detail-close {
    width: 34px; height: 34px;
    border-radius: 50%;
    border: none;
    background: var(--bg);
    color: var(--text-muted);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
    transition: background var(--t);
  }
  .at-detail-close:hover { background: var(--border); }

  /* ── Responsive ───────────────────────────────────────────────── */
  @media (max-width: 900px) {
    .at-stats-grid { grid-template-columns: repeat(2, 1fr); }
  }

  @media (max-width: 600px) {
    .at-cal-header { flex-direction: column; align-items: stretch; }
    .at-month-nav { justify-content: center; }
    .at-day-cell { font-size: 11.5px; min-height: 32px; }
    .at-day-icon { font-size: 8px; }
    .at-legend { gap: 12px; justify-content: center; }
    .at-legend-item { font-size: 11.5px; }
  }

  @media (max-width: 480px) {
    .at-header { flex-direction: column; align-items: stretch; }
    .at-session-wrap { min-width: 0; width: 100%; }
    .at-title { font-size: 18px; }
    .at-cal-weekdays > div { font-size: 10px; }
    .at-month-label { font-size: 13px; min-width: 0; }
    .at-today-btn { font-size: 11.5px; height: 32px; padding: 0 10px; }
  }
</style>

<!-- CSRF token for AJAX requests (matches admin panel convention) -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<?php
// ── Page-specific JS — passed to the footer's $pageJs slot ────────
$pageJs = <<<'JS'
(function () {
  "use strict";

  const AJAX_URL = "attendance-ajax.php";
  function getToken() { return document.getElementById("csrfToken")?.value || ""; }
  let csrfToken = getToken();

  const $ = (id) => document.getElementById(id);
  const escapeHtml = (str) => String(str ?? "").replace(/[&<>"']/g, (c) => ({
    "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;"
  }[c]));

  const MONTH_NAMES = ["January","February","March","April","May","June",
                        "July","August","September","October","November","December"];

  const todayStr = new Date().toISOString().slice(0, 10); // YYYY-MM-DD (client local)

  let enrollmentsCache = [];
  let currentEnrollmentId = null;
  let viewYear  = new Date().getFullYear();
  let viewMonth = new Date().getMonth() + 1; // 1-12
  let enrollmentAdmittedAt = null;

  async function post(action, extra) {
    const body = new URLSearchParams({ action, csrf_token: csrfToken, ...extra });
    const res  = await fetch(AJAX_URL, { method: "POST", body, credentials: "same-origin" });
    const data = await res.json().catch(() => null);
    if (data && data.csrf) {
      csrfToken = data.csrf;
      const tokenInput = document.getElementById("csrfToken");
      if (tokenInput) tokenInput.value = data.csrf;
    }
    return data;
  }

  function showOnly(id) {
    ["atLoading","atError","atNoEnrollment","atContent"].forEach(elId => {
      const el = $(elId);
      if (el) el.style.display = (elId === id) ? "" : "none";
    });
  }

  function showError(msg) {
    const el = $("atError");
    el.textContent = msg;
    showOnly("atError");
  }

  // ── Step 1: load this student's enrollment history for the picker ──
  async function loadSessions() {
    showOnly("atLoading");
    const data = await post("fetch_sessions", {});
    if (!data) { showError("Could not reach the server. Please check your connection."); return; }
    if (!data.ok) { showError(data.msg || "Something went wrong."); return; }

    enrollmentsCache = data.enrollments || [];
    if (enrollmentsCache.length === 0) { showOnly("atNoEnrollment"); return; }

    const wrap   = $("atSessionWrap");
    const select = $("atSessionSelect");
    select.innerHTML = "";

    enrollmentsCache.forEach((e) => {
      const opt = document.createElement("option");
      opt.value = e.enrollment_id;
      let label = e.class_label + " · " + e.session_year;
      if (e.group_name) label += " (" + e.group_name + ")";
      if (e.status === "active") label += " — Current";
      opt.textContent = label;
      select.appendChild(opt);
    });

    if (enrollmentsCache.length > 1) {
      wrap.style.display = "flex";
    }

    select.addEventListener("change", () => {
      currentEnrollmentId = select.value;
      // Jump to the current real month/year whenever switching sessions
      const now = new Date();
      viewYear  = now.getFullYear();
      viewMonth = now.getMonth() + 1;
      hideDetail();
      loadMonth();
    });

    const activeOne = enrollmentsCache.find(e => e.status === "active");
    const defaultEnrollment = activeOne || enrollmentsCache[0];
    currentEnrollmentId = defaultEnrollment.enrollment_id;
    select.value = currentEnrollmentId;

    loadMonth();
  }

  // ── Step 2: load one month's attendance + summary ──────────────────
  async function loadMonth() {
    showOnly("atLoading");
    const data = await post("fetch_month", {
      enrollment_id: currentEnrollmentId,
      year: viewYear,
      month: viewMonth,
    });
    if (!data) { showError("Could not reach the server. Please check your connection."); return; }
    if (!data.ok) { showError(data.msg || "Something went wrong."); return; }

    enrollmentAdmittedAt = data.admitted_at ? data.admitted_at.slice(0,10) : null;

    $("atSubLabel").textContent =
      data.class_label + " · Session " + data.session_year + (data.group_name ? " · " + data.group_name : "");
    $("atClassBadgeText").textContent = data.class_label + " " + data.session_year + " Calendar";

    renderSummary(data.month_summary, data.overall_summary);
    renderCalendar(data.days || {});

    showOnly("atContent");
  }

  // ── Summary cards ────────────────────────────────────────────────
  function renderSummary(monthSum, overallSum) {
    $("atMonthLabel").textContent = MONTH_NAMES[viewMonth - 1] + " attendance";
    $("atMonthPct").textContent = monthSum.pct !== null ? monthSum.pct + "%" : "—";
    $("atMonthPresent").textContent = monthSum.present;
    $("atMonthAbsent").textContent = monthSum.absent;
    $("atOverallPct").textContent = overallSum.pct !== null ? overallSum.pct + "%" : "—";

    // Colour the month % card based on how the student is doing
    const card = $("atMonthStatCard");
    const icon = card.querySelector(".stat-icon");
    icon.className = "stat-icon " + (
      monthSum.pct === null ? "si-blue" :
      monthSum.pct >= 90 ? "si-green" :
      monthSum.pct >= 75 ? "si-amber" : "si-red"
    );
  }

  // ── Calendar rendering ────────────────────────────────────────────
  // Bangladesh week starts Saturday. JS Date.getDay(): 0=Sun...6=Sat.
  // We need column index 0=Sat, 1=Sun, 2=Mon, 3=Tue, 4=Wed, 5=Thu, 6=Fri.
  function jsDowToColumn(jsDow) {
    return (jsDow + 1) % 7; // Sun(0)->1, Mon(1)->2, ... Sat(6)->0
  }

  function renderCalendar(daysMap) {
    $("atMonthYearLabel").textContent = MONTH_NAMES[viewMonth - 1] + " " + viewYear;

    const grid = $("atCalGrid");
    grid.innerHTML = "";

    const firstOfMonth = new Date(viewYear, viewMonth - 1, 1);
    const daysInMonth   = new Date(viewYear, viewMonth, 0).getDate();
    const leadingBlanks = jsDowToColumn(firstOfMonth.getDay());

    for (let i = 0; i < leadingBlanks; i++) {
      const blank = document.createElement("div");
      blank.className = "at-day-cell is-blank";
      grid.appendChild(blank);
    }

    for (let day = 1; day <= daysInMonth; day++) {
      const dateObj = new Date(viewYear, viewMonth - 1, day);
      const dateStr = viewYear + "-" + String(viewMonth).padStart(2,"0") + "-" + String(day).padStart(2,"0");
      const col     = jsDowToColumn(dateObj.getDay());
      const isFriday = col === 6;
      const isToday   = dateStr === todayStr;
      const isFuture  = dateStr > todayStr;
      const status    = daysMap[dateStr]; // 'present' | 'absent' | 'late' | undefined

      const cell = document.createElement("div");
      let cls = "at-day-cell";
      let iconHtml = "";

      if (status === "present") { cls += " has-present"; iconHtml = '<i class="fas fa-check at-day-icon"></i>'; }
      else if (status === "absent") { cls += " has-absent"; iconHtml = '<i class="fas fa-xmark at-day-icon"></i>'; }
      else if (status === "late") { cls += " has-late"; iconHtml = '<i class="fas fa-clock at-day-icon"></i>'; }
      else if (isFriday) { cls += " is-friday"; }
      else if (isFuture) { cls += " is-future"; }

      if (isToday) cls += " is-today";
      if (status) cls += " is-clickable";

      cell.className = cls;
      cell.innerHTML = '<span class="at-day-num">' + day + '</span>' + iconHtml;

      if (status) {
        cell.addEventListener("click", () => showDetail(dateStr, status));
        cell.setAttribute("tabindex", "0");
        cell.setAttribute("role", "button");
        cell.setAttribute("aria-label", dateStr + ": " + status);
      }

      grid.appendChild(cell);
    }
  }

  // ── Detail panel — tap a day to see a friendly readout ───────────
  function showDetail(dateStr, status) {
    const card = $("atDetailCard");
    const body = $("atDetailBody");

    const dateObj = new Date(dateStr + "T00:00:00");
    const niceDate = dateObj.toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "long", year: "numeric" });

    const cfg = {
      present: { icon: "fa-check",  bg: "si-green", color: "var(--green)",  label: "You were present" },
      absent:  { icon: "fa-xmark",  bg: "si-red",   color: "var(--red)",    label: "You were absent" },
      late:    { icon: "fa-clock",  bg: "si-amber", color: "var(--amber)",  label: "You came late" },
    }[status] || { icon: "fa-question", bg: "si-blue", color: "var(--primary)", label: "No record" };

    body.innerHTML =
      '<div class="at-detail-row">' +
        '<div class="at-detail-icon ' + cfg.bg + '"><i class="fas ' + cfg.icon + '"></i></div>' +
        '<div class="at-detail-text">' +
          '<div class="at-detail-date">' + escapeHtml(niceDate) + '</div>' +
          '<div class="at-detail-status" style="color:' + cfg.color + '">' + cfg.label + '</div>' +
        '</div>' +
        '<button type="button" class="at-detail-close" id="atDetailCloseBtn" aria-label="Close"><i class="fas fa-xmark"></i></button>' +
      '</div>';

    card.style.display = "";
    card.scrollIntoView({ behavior: "smooth", block: "nearest" });

    document.getElementById("atDetailCloseBtn").addEventListener("click", hideDetail);
  }

  function hideDetail() {
    const card = $("atDetailCard");
    if (card) card.style.display = "none";
  }

  // ── Month navigation ──────────────────────────────────────────────
  function goPrevMonth() {
    viewMonth--;
    if (viewMonth < 1) { viewMonth = 12; viewYear--; }
    hideDetail();
    loadMonth();
  }
  function goNextMonth() {
    viewMonth++;
    if (viewMonth > 12) { viewMonth = 1; viewYear++; }
    hideDetail();
    loadMonth();
  }
  function goToday() {
    const now = new Date();
    viewYear  = now.getFullYear();
    viewMonth = now.getMonth() + 1;
    hideDetail();
    loadMonth();
  }

  $("atPrevMonth").addEventListener("click", goPrevMonth);
  $("atNextMonth").addEventListener("click", goNextMonth);
  $("atTodayBtn").addEventListener("click", goToday);

  loadSessions();
})();
JS;

require_once __DIR__ . '/includes/student-footer.php';
?>
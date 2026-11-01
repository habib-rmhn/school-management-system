<?php
/**
 * EMS Student — Class Routine  v1.0
 * ─────────────────────────────────────────────────────────────────
 * Shows the logged-in student's own weekly class schedule.
 * Data is loaded via AJAX (routine-ajax.php) — no page reload.
 *
 * Design notes for a rural Class 6–10 audience:
 *   - Desktop/tablet: a clean weekly grid (period rows × day cols)
 *   - Mobile: one day at a time as a big card list — a wide table
 *     squeezed onto a phone is unreadable for anyone, let alone a
 *     12-year-old. Day tabs let them swipe between days easily.
 *   - "Today" is always highlighted automatically so a student can
 *     find what's next at a glance without thinking.
 *   - If a student has been enrolled in more than one session
 *     (promoted classes), a simple dropdown lets them check a past
 *     class's routine too — defaults to their current active one.
 */
require_once __DIR__ . '/../includes/student-session.php';

$pageTitle = 'Class Routine';
$activeNav = 'routine';

require_once __DIR__ . '/includes/student-sidebar.php';
?>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="rt-header">
  <div>
    <div class="rt-title"><i class="fas fa-calendar-week"></i> My Class Routine</div>
    <div class="rt-sub" id="rtSubLabel">Loading your schedule…</div>
  </div>
  <div class="rt-session-wrap" id="rtSessionWrap" style="display:none">
    <label class="rt-session-label" for="rtSessionSelect">Class / Session</label>
    <select id="rtSessionSelect" class="filter-select"></select>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     LOADING / ERROR / EMPTY STATES
     ═══════════════════════════════════════════════════════════════ -->
<div id="rtLoading" class="card">
  <div class="empty-state">
    <i class="fas fa-spinner fa-spin"></i>
    <p>Loading your class routine…</p>
  </div>
</div>

<div id="rtError" class="alert alert-danger" style="display:none"></div>

<div id="rtNoEnrollment" class="alert alert-warning" style="display:none">
  <i class="fas fa-triangle-exclamation"></i>
  <span>You don't have any class enrollment on record yet. Please contact your school admin office.</span>
</div>

<div id="rtEmpty" class="card" style="display:none">
  <div class="empty-state">
    <i class="fas fa-calendar-xmark"></i>
    <p>Your class routine hasn't been added by the school yet. Please check back later.</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MAIN ROUTINE VIEW (hidden until data loads)
     ═══════════════════════════════════════════════════════════════ -->
<div id="rtContent" style="display:none">

  <!-- ── Today banner — quick glance ───────────────────────────── -->
  <div class="rt-today-banner" id="rtTodayBanner"></div>

  <!-- ── Mobile: Day tabs ───────────────────────────────────────── -->
  <div class="rt-day-tabs" id="rtDayTabs" role="tablist" aria-label="Choose a day"></div>

  <!-- ── Mobile: selected day's classes as cards ───────────────── -->
  <div class="rt-day-cards" id="rtDayCards"></div>

  <!-- ── Desktop: full weekly grid ──────────────────────────────── -->
  <div class="card rt-grid-card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-table-cells"></i> Full Week View</div>
      <span class="badge badge-blue" id="rtClassBadge"></span>
    </div>
    <div class="table-wrap">
      <table class="ems-table rt-grid-table" id="rtGridTable">
        <thead><tr id="rtGridHead"></tr></thead>
        <tbody id="rtGridBody"></tbody>
      </table>
    </div>
  </div>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE-SPECIFIC STYLES
     ═══════════════════════════════════════════════════════════════ -->
<style>
  /* ── Page header ─────────────────────────────────────────────── */
  .rt-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 20px;
  }
  .rt-title {
    font-size: 20px;
    font-weight: 800;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -.02em;
  }
  .rt-title i { color: var(--primary); font-size: 19px; }
  .rt-sub {
    font-size: 13.5px;
    color: var(--text-muted);
    margin-top: 5px;
    font-weight: 500;
  }
  .rt-session-wrap { display: flex; flex-direction: column; gap: 5px; min-width: 220px; }
  .rt-session-label {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .04em;
  }

  /* ── Today banner ────────────────────────────────────────────── */
  .rt-today-banner {
    background: linear-gradient(120deg, var(--primary) 0%, var(--accent) 100%);
    color: #fff;
    border-radius: var(--r-md);
    padding: 16px 20px;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    font-weight: 700;
    font-size: 14px;
  }
  .rt-today-banner .rtb-icon {
    width: 40px; height: 40px;
    background: rgba(255,255,255,.18);
    border-radius: var(--r-sm);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
  }
  .rt-today-banner .rtb-text { font-weight: 500; font-size: 13.5px; }
  .rt-today-banner .rtb-text strong { font-weight: 800; }

  /* ── Day tabs (mobile-first, also visible on desktop above cards) ── */
  .rt-day-tabs {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    padding: 4px 2px 14px;
    scrollbar-width: none;
  }
  .rt-day-tabs::-webkit-scrollbar { display: none; }

  .rt-day-tab {
    flex-shrink: 0;
    min-width: 64px;
    padding: 10px 14px;
    border-radius: var(--r-sm);
    background: var(--bg-card);
    border: 1.5px solid var(--border);
    text-align: center;
    cursor: pointer;
    transition: all var(--t);
    user-select: none;
  }
  .rt-day-tab .dt-name { font-size: 12px; font-weight: 700; color: var(--text-muted); }
  .rt-day-tab .dt-count { font-size: 10.5px; color: var(--text-light); margin-top: 2px; font-weight: 600; }
  .rt-day-tab.is-today { border-color: var(--primary); }
  .rt-day-tab.is-today .dt-name { color: var(--primary); }
  .rt-day-tab.active { background: var(--primary); border-color: var(--primary); }
  .rt-day-tab.active .dt-name,
  .rt-day-tab.active .dt-count { color: #fff; }
  .rt-day-tab.is-off { opacity: .55; }

  /* ── Day cards (mobile period list) ──────────────────────────── */
  .rt-day-cards { display: none; margin-bottom: 22px; }

  .rt-period-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--r-md);
    padding: 16px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: var(--shadow-card);
    position: relative;
    overflow: hidden;
  }
  .rt-period-card.is-now { border-color: var(--primary); background: var(--primary-xlight); }
  .rt-period-card.is-now::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 4px;
    background: var(--primary);
  }

  .rtp-period {
    width: 46px; height: 46px;
    border-radius: var(--r-sm);
    background: var(--primary-xlight);
    color: var(--primary);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-weight: 800;
  }
  .rtp-period .pn { font-size: 16px; line-height: 1; }
  .rtp-period .pl { font-size: 8.5px; text-transform: uppercase; letter-spacing: .04em; margin-top: 1px; }

  .rtp-body { flex: 1; min-width: 0; }
  .rtp-subject { font-size: 15px; font-weight: 800; color: var(--text); }
  .rtp-subject.is-free { color: var(--text-light); font-weight: 600; font-style: italic; }
  .rtp-teacher { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; display: flex; align-items: center; gap: 5px; }
  .rtp-teacher i { font-size: 10.5px; }
  .rtp-time { font-size: 12px; font-weight: 700; color: var(--text-sub); white-space: nowrap; text-align: right; flex-shrink: 0; }
  .rtp-time .now-pill {
    display: inline-block;
    margin-top: 4px;
    font-size: 9.5px;
    background: var(--green-bg);
    color: var(--green);
    padding: 2px 7px;
    border-radius: 20px;
    font-weight: 700;
  }

  .rt-empty-day {
    text-align: center;
    padding: 36px 16px;
    color: var(--text-muted);
  }
  .rt-empty-day i { font-size: 32px; color: var(--text-light); display: block; margin-bottom: 10px; }
  .rt-empty-day p { font-size: 13.5px; }

  /* ── Desktop weekly grid ─────────────────────────────────────── */
  .rt-grid-card { display: none; }

  .rt-grid-table th,
  .rt-grid-table td { text-align: center; }
  .rt-grid-table th:first-child,
  .rt-grid-table td:first-child { text-align: left; white-space: nowrap; }

  .rt-grid-table .rt-today-col { background: var(--primary-xlight) !important; }
  thead .rt-today-col { background: var(--primary-light) !important; color: var(--primary) !important; }

  .rtg-cell {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    padding: 6px 4px;
    border-radius: 8px;
    min-width: 92px;
  }
  .rtg-cell .rtg-subject { font-size: 12.5px; font-weight: 700; color: var(--text-sub); }
  .rtg-cell .rtg-teacher { font-size: 10.5px; color: var(--text-muted); }
  .rtg-cell.rtg-empty .rtg-subject { color: var(--text-light); font-weight: 500; font-style: italic; }
  .rtg-period-cell { font-weight: 700; color: var(--text); }
  .rtg-period-time { font-size: 10.5px; color: var(--text-muted); font-weight: 500; display: block; margin-top: 1px; }

  /* ── Responsive switch: cards on mobile, grid on desktop ─────── */
  @media (max-width: 900px) {
    .rt-day-cards { display: block; }
    .rt-grid-card { display: none !important; }
  }
  @media (min-width: 901px) {
    .rt-day-tabs   { display: none; }
    .rt-day-cards  { display: none !important; }
    .rt-grid-card  { display: block; }
  }

  @media (max-width: 480px) {
    .rt-header { flex-direction: column; align-items: stretch; }
    .rt-session-wrap { min-width: 0; width: 100%; }
    .rt-title { font-size: 18px; }
    .rt-today-banner { padding: 14px 16px; }
  }
</style>

<!-- CSRF token for AJAX requests (matches admin panel convention) -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<?php
// ── Page-specific JS — passed to the footer's $pageJs slot ────────
$pageJs = <<<'JS'
(function () {
  "use strict";

  const AJAX_URL = "routine-ajax.php";
  function getToken() { return document.getElementById("csrfToken")?.value || ""; }
  let csrfToken  = getToken();
  let dayNames   = [
    { num: 0, short: "Sat", long: "Saturday" },
    { num: 1, short: "Sun", long: "Sunday"   },
    { num: 2, short: "Mon", long: "Monday"   },
    { num: 3, short: "Tue", long: "Tuesday"  },
    { num: 4, short: "Wed", long: "Wednesday"},
    { num: 5, short: "Thu", long: "Thursday" },
  ];

  // Bangladesh week: Sat=0 ... Thu=5, Friday is the weekend (no class)
  const jsDow     = new Date().getDay();          // JS: 0=Sun...6=Sat
  const bdToday   = jsDow === 0 ? 1 : (jsDow === 6 ? 0 : jsDow);
  const isFriday  = jsDow === 5;

  let currentSlots       = [];
  let currentPeriodTimes = [];
  let selectedDay        = isFriday ? 0 : bdToday;
  let enrollmentsCache    = [];

  const $  = (id) => document.getElementById(id);
  const escapeHtml = (str) => String(str ?? "").replace(/[&<>"']/g, (c) => ({
    "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;"
  }[c]));

  function fmtTime(t) {
    if (!t) return "";
    const [h, m] = t.split(":").map(Number);
    const ampm = h >= 12 ? "PM" : "AM";
    const h12  = (h % 12) || 12;
    return String(h12).padStart(2,"0") + ":" + String(m).padStart(2,"0") + " " + ampm;
  }

  function nowWithinPeriod(start, end) {
    if (!start || !end) return false;
    const now = new Date();
    const cur = now.getHours()*60 + now.getMinutes();
    const [sh, sm] = start.split(":").map(Number);
    const [eh, em] = end.split(":").map(Number);
    return cur >= (sh*60+sm) && cur <= (eh*60+em);
  }

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
    ["rtLoading","rtError","rtNoEnrollment","rtEmpty","rtContent"].forEach(elId => {
      const el = $(elId);
      if (el) el.style.display = (elId === id) ? "" : "none";
    });
  }

  // ── Step 1: load this student's enrollment history for the picker ──
  async function loadSessions() {
    showOnly("rtLoading");
    const data = await post("fetch_sessions", {});
    if (!data) { showError("Could not reach the server. Please check your connection."); return; }
    if (!data.ok) { showError(data.msg || "Something went wrong."); return; }

    enrollmentsCache = data.enrollments || [];
    if (enrollmentsCache.length === 0) { showOnly("rtNoEnrollment"); return; }

    const wrap   = $("rtSessionWrap");
    const select = $("rtSessionSelect");
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

    select.addEventListener("change", () => loadRoutine(select.value));

    // Default to the active enrollment, else the most recent one
    const activeOne = enrollmentsCache.find(e => e.status === "active");
    const defaultId  = activeOne ? activeOne.enrollment_id : enrollmentsCache[0].enrollment_id;
    select.value = defaultId;
    loadRoutine(defaultId);
  }

  function showError(msg) {
    const el = $("rtError");
    el.textContent = msg;
    showOnly("rtError");
  }

  // ── Step 2: load the actual weekly routine for one enrollment ──────
  async function loadRoutine(enrollmentId) {
    showOnly("rtLoading");
    const data = await post("fetch_routine", { enrollment_id: enrollmentId });
    if (!data) { showError("Could not reach the server. Please check your connection."); return; }
    if (!data.ok) { showError(data.msg || "Something went wrong."); return; }

    currentSlots       = data.slots || [];
    currentPeriodTimes = data.period_times || [];

    $("rtSubLabel").textContent =
      data.class_label + " · Session " + data.session_year + (data.group_name ? " · " + data.group_name : "");
    $("rtClassBadge").textContent = data.class_label + " " + data.session_year;

    if (currentPeriodTimes.length === 0) {
      showOnly("rtEmpty");
      return;
    }

    showOnly("rtContent");
    renderTodayBanner();
    renderDayTabs();
    renderDayCards(selectedDay);
    renderWeekGrid();
  }

  // ── Today banner ─────────────────────────────────────────────────
  function renderTodayBanner() {
    const el = $("rtTodayBanner");
    if (isFriday) {
      el.innerHTML =
        '<div class="rtb-icon"><i class="fas fa-mug-hot"></i></div>' +
        '<div class="rtb-text">It\'s <strong>Friday</strong> — enjoy your day off! No classes today.</div>';
      return;
    }
    const todaySlots = currentSlots.filter(s => Number(s.day_of_week) === bdToday);
    const dayLong = dayNames.find(d => d.num === bdToday)?.long || "Today";
    el.innerHTML =
      '<div class="rtb-icon"><i class="fas fa-sun"></i></div>' +
      '<div class="rtb-text">Today is <strong>' + dayLong + '</strong> — you have ' +
      '<strong>' + todaySlots.length + ' class' + (todaySlots.length === 1 ? "" : "es") + '</strong> scheduled.</div>';
  }

  // ── Day tabs (mobile) ───────────────────────────────────────────
  function renderDayTabs() {
    const el = $("rtDayTabs");
    el.innerHTML = "";
    dayNames.forEach(d => {
      const count = currentSlots.filter(s => Number(s.day_of_week) === d.num).length;
      const tab = document.createElement("div");
      tab.className = "rt-day-tab" +
        (d.num === selectedDay ? " active" : "") +
        (d.num === bdToday && !isFriday ? " is-today" : "");
      tab.setAttribute("role", "tab");
      tab.setAttribute("tabindex", "0");
      tab.innerHTML =
        '<div class="dt-name">' + d.short + '</div>' +
        '<div class="dt-count">' + count + ' class' + (count === 1 ? "" : "es") + '</div>';
      tab.addEventListener("click", () => {
        selectedDay = d.num;
        renderDayTabs();
        renderDayCards(selectedDay);
      });
      el.appendChild(tab);
    });
  }

  // ── Day cards (mobile) ──────────────────────────────────────────
  function renderDayCards(dayNum) {
    const el = $("rtDayCards");
    const daySlots = currentSlots
      .filter(s => Number(s.day_of_week) === dayNum)
      .sort((a,b) => a.period_number - b.period_number);

    if (daySlots.length === 0) {
      el.innerHTML =
        '<div class="rt-empty-day"><i class="fas fa-mug-hot"></i><p>No classes on this day.</p></div>';
      return;
    }

    el.innerHTML = daySlots.map(s => {
      const isNow   = (dayNum === bdToday && !isFriday) && nowWithinPeriod(s.start_time, s.end_time);
      const hasSub  = !!s.subject_name;
      const subject = hasSub ? escapeHtml(s.subject_name) : "Free Period";
      const teacher = s.teacher_name ? escapeHtml(s.teacher_name) : "";
      return (
        '<div class="rt-period-card' + (isNow ? " is-now" : "") + '">' +
          '<div class="rtp-period"><span class="pn">' + s.period_number + '</span><span class="pl">Period</span></div>' +
          '<div class="rtp-body">' +
            '<div class="rtp-subject' + (hasSub ? "" : " is-free") + '">' + subject + '</div>' +
            (teacher ? '<div class="rtp-teacher"><i class="fas fa-chalkboard-teacher"></i>' + teacher + '</div>' : "") +
          '</div>' +
          '<div class="rtp-time">' + fmtTime(s.start_time) + '<br>' + fmtTime(s.end_time) +
            (isNow ? '<br><span class="now-pill">Now</span>' : "") +
          '</div>' +
        '</div>'
      );
    }).join("");
  }

  // ── Full weekly grid (desktop) ───────────────────────────────────
  function renderWeekGrid() {
    const headRow = $("rtGridHead");
    headRow.innerHTML = "<th>Period</th>" + dayNames.map(d =>
      '<th class="' + (d.num === bdToday && !isFriday ? "rt-today-col" : "") + '">' + d.short + '</th>'
    ).join("");

    const body = $("rtGridBody");
    body.innerHTML = currentPeriodTimes.map(pt => {
      let row = '<tr><td class="rtg-period-cell">P' + pt.period_number +
                '<span class="rtg-period-time">' + fmtTime(pt.start_time) + ' - ' + fmtTime(pt.end_time) + '</span></td>';
      dayNames.forEach(d => {
        const slot = currentSlots.find(s =>
          Number(s.day_of_week) === d.num && Number(s.period_number) === pt.period_number
        );
        const isToday = d.num === bdToday && !isFriday;
        if (slot && slot.subject_name) {
          row += '<td class="' + (isToday ? "rt-today-col" : "") + '">' +
                   '<div class="rtg-cell">' +
                     '<div class="rtg-subject">' + escapeHtml(slot.subject_name) + '</div>' +
                     (slot.teacher_name ? '<div class="rtg-teacher">' + escapeHtml(slot.teacher_name) + '</div>' : "") +
                   '</div>' +
                 '</td>';
        } else {
          row += '<td class="' + (isToday ? "rt-today-col" : "") + '">' +
                   '<div class="rtg-cell rtg-empty"><div class="rtg-subject">—</div></div>' +
                 '</td>';
        }
      });
      row += "</tr>";
      return row;
    }).join("");
  }

  loadSessions();
})();
JS;

require_once __DIR__ . '/includes/student-footer.php';
?>
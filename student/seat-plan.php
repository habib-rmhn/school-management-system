<?php
/**
 * EMS Student — Seat Plan  v1.0
 * ─────────────────────────────────────────────────────────────────
 * Shows the logged-in student's exam seating — which room, which
 * seat, for every subject of an exam. Data loads via AJAX
 * (seat-plan-ajax.php) — no page reload when switching sessions or
 * opening an exam's seat detail.
 *
 * Flow:
 *   1. Student picks a class/session (only shown if more than one).
 *   2. Exam cards appear, each showing how many subjects already
 *      have a seat assigned ("3 of 5 subjects ready").
 *   3. Tapping a card shows, per subject: date, time, room number,
 *      a big seat number, and a simple visual grid so they can see
 *      roughly where in the room their seat sits — without ever
 *      showing other students' names (privacy).
 *
 * Design notes for a rural Class 6–10 audience:
 *   - The seat NUMBER is shown huge and impossible to miss — this
 *     is the one fact a nervous student needs fastest on exam day.
 *   - The grid is a simple visual aid (numbered boxes), not a real
 *     map of the room — explained plainly so it's never confused
 *     for furniture placement.
 *   - If a seat isn't assigned yet, that's stated honestly instead
 *     of showing a blank or broken-looking row.
 */
require_once __DIR__ . '/../includes/student-session.php';

$pageTitle = 'Seat Plan';
$activeNav = 'seat-plan';

require_once __DIR__ . '/includes/student-sidebar.php';
?>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="sp-header">
  <div>
    <div class="sp-title"><i class="fas fa-chair"></i> Seat Plan</div>
    <div class="sp-sub" id="spSubLabel">Loading your exams…</div>
  </div>
  <div class="sp-session-wrap" id="spSessionWrap" style="display:none">
    <label class="sp-session-label" for="spSessionSelect">Class / Session</label>
    <select id="spSessionSelect" class="filter-select"></select>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     LOADING / ERROR / EMPTY STATES
     ═══════════════════════════════════════════════════════════════ -->
<div id="spLoading" class="card">
  <div class="empty-state">
    <i class="fas fa-spinner fa-spin"></i>
    <p>Loading your exams…</p>
  </div>
</div>

<div id="spError" class="alert alert-danger" style="display:none"></div>

<div id="spNoEnrollment" class="alert alert-warning" style="display:none">
  <i class="fas fa-triangle-exclamation"></i>
  <span>You don't have any class enrollment on record yet. Please contact your school admin office.</span>
</div>

<div id="spEmpty" class="card" style="display:none">
  <div class="empty-state">
    <i class="fas fa-chair"></i>
    <p>No exams have been scheduled for your class yet. Please check back later.</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     EXAM LIST VIEW (cards)
     ═══════════════════════════════════════════════════════════════ -->
<div id="spExamListView" style="display:none">
  <div class="sp-exam-grid" id="spExamGrid"></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     SEAT DETAIL VIEW (one exam's subjects)
     ═══════════════════════════════════════════════════════════════ -->
<div id="spDetailView" style="display:none">

  <button type="button" class="btn btn-outline sp-back-btn" id="spBackBtn">
    <i class="fas fa-arrow-left"></i> Back to all exams
  </button>

  <div id="spSubjectList"></div>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE-SPECIFIC STYLES
     ═══════════════════════════════════════════════════════════════ -->
<style>
  /* ── Page header ─────────────────────────────────────────────── */
  .sp-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 20px;
  }
  .sp-title {
    font-size: 20px;
    font-weight: 800;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -.02em;
  }
  .sp-title i { color: var(--primary); font-size: 19px; }
  .sp-sub {
    font-size: 13.5px;
    color: var(--text-muted);
    margin-top: 5px;
    font-weight: 500;
  }
  .sp-session-wrap { display: flex; flex-direction: column; gap: 5px; min-width: 220px; }
  .sp-session-label {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .04em;
  }

  /* ── Exam cards grid ─────────────────────────────────────────── */
  .sp-exam-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
  }

  .sp-exam-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border);
    border-left: 5px solid var(--border);
    border-radius: var(--r-md);
    padding: 18px;
    transition: border-color var(--t), box-shadow var(--t), transform var(--t);
    box-shadow: var(--shadow-card);
  }
  .sp-exam-card.is-ready    { border-left-color: var(--green); }
  .sp-exam-card.is-locked   { border-left-color: var(--red); }
  .sp-exam-card.is-waiting  { border-left-color: var(--amber); }
  .sp-exam-card.is-past     { border-left-color: var(--text-light); opacity: .75; }
  .sp-exam-card.is-clickable { cursor: pointer; }
  .sp-exam-card.is-clickable:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }

  .spc-name { font-size: 15.5px; font-weight: 800; color: var(--text); line-height: 1.3; }
  .spc-date { font-size: 12px; color: var(--text-muted); margin-top: 4px; display: flex; align-items: center; gap: 5px; }

  .spc-progress { margin-top: 12px; }
  .spc-progress-bar { height: 6px; background: var(--bg-elevated); border-radius: 4px; overflow: hidden; margin-top: 6px; }
  .spc-progress-fill { height: 100%; border-radius: 4px; background: var(--green); }
  .spc-progress-text { font-size: 11.5px; color: var(--text-muted); font-weight: 600; }

  .spc-status-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--border-light);
    font-size: 12.5px;
    font-weight: 700;
  }
  .spc-status-row i { font-size: 13px; }
  .spc-status-row.txt-green  { color: var(--green); }
  .spc-status-row.txt-red    { color: var(--red); }
  .spc-status-row.txt-amber  { color: var(--amber); }
  .spc-status-row.txt-muted  { color: var(--text-muted); }

  .spc-cta {
    margin-top: 10px;
    font-size: 12.5px;
    font-weight: 700;
    color: var(--primary);
    display: flex;
    align-items: center;
    gap: 5px;
  }

  /* ── Back button ───────────────────────────────────────────────── */
  .sp-back-btn { margin-bottom: 18px; }

  /* ── Subject seat card ────────────────────────────────────────── */
  .sp-subject-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--r-md);
    box-shadow: var(--shadow-card);
    margin-bottom: 18px;
    overflow: hidden;
  }

  .ssc-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-light);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
  }
  .ssc-subject { font-size: 15px; font-weight: 800; color: var(--text); }
  .s-ssc-meta { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; }

  .ssc-body { padding: 20px; }

  .ssc-no-seat {
    display: flex;
    align-items: center;
    gap: 14px;
    background: var(--amber-bg);
    border: 1px solid var(--amber-border);
    border-radius: var(--r);
    padding: 14px 16px;
    color: #78350f;
    font-size: 13.5px;
    font-weight: 600;
  }
  .ssc-no-seat i { font-size: 20px; flex-shrink: 0; }

  /* ── Big seat readout ─────────────────────────────────────────── */
  .ssc-seat-row {
    display: flex;
    align-items: center;
    gap: 22px;
    margin-bottom: 20px;
    flex-wrap: wrap;
  }

  .ssc-room-badge {
    text-align: center;
    background: var(--primary-xlight);
    border: 2px solid var(--primary-light);
    border-radius: var(--r);
    padding: 14px 22px;
    min-width: 120px;
  }
  .ssc-room-badge .rb-label { font-size: 10.5px; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: .05em; }
  .ssc-room-badge .rb-value { font-size: 24px; font-weight: 800; color: var(--primary); margin-top: 2px; letter-spacing: -.02em; }
  .ssc-room-badge .rb-desc  { font-size: 11px; color: var(--text-muted); margin-top: 3px; }

  .ssc-seat-badge {
    text-align: center;
    background: linear-gradient(135deg, var(--green) 0%, #10b981 100%);
    border-radius: var(--r);
    padding: 14px 26px;
    min-width: 120px;
    color: #fff;
  }
  .ssc-seat-badge .sb-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; opacity: .9; }
  .ssc-seat-badge .sb-value { font-size: 30px; font-weight: 800; margin-top: 2px; letter-spacing: -.02em; }

  .ssc-time-info { display: flex; flex-direction: column; gap: 6px; font-size: 13px; color: var(--text-sub); }
  .ssc-time-info div { display: flex; align-items: center; gap: 7px; }
  .ssc-time-info i { color: var(--text-muted); width: 16px; text-align: center; }

  .ssc-fill-note { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

  /* ── Seat-finder grid ─────────────────────────────────────────── */
  .ssc-grid-title {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 7px;
  }

  .ssc-grid {
    display: grid;
    gap: 6px;
    margin-bottom: 8px;
  }

  .ssc-grid-seat {
    aspect-ratio: 1 / 1;
    min-height: 30px;
    border-radius: 6px;
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 700;
    color: var(--text-light);
  }
  .ssc-grid-seat.is-mine {
    background: var(--green);
    color: #fff;
    border-color: var(--green);
    font-size: 11px;
    transform: scale(1.08);
    box-shadow: 0 2px 8px rgba(5,150,105,.4);
  }

  .ssc-grid-legend {
    display: flex;
    gap: 16px;
    margin-top: 12px;
    flex-wrap: wrap;
  }
  .ssc-grid-legend-item { display: flex; align-items: center; gap: 7px; font-size: 12px; color: var(--text-muted); font-weight: 600; }
  .ssc-legend-dot { width: 14px; height: 14px; border-radius: 4px; flex-shrink: 0; }
  .ssc-legend-dot.is-mine { background: var(--green); }
  .ssc-legend-dot.is-other { background: var(--bg-elevated); border: 1px solid var(--border); }

  /* ── Responsive ───────────────────────────────────────────────── */
  @media (max-width: 1100px) {
    .sp-exam-grid { grid-template-columns: repeat(2, 1fr); }
  }
  @media (max-width: 700px) {
    .sp-exam-grid { grid-template-columns: 1fr; }
  }

  @media (max-width: 600px) {
    .ssc-seat-row { gap: 14px; }
    .ssc-room-badge, .ssc-seat-badge { min-width: 0; flex: 1; padding: 12px 14px; }
    .ssc-room-badge .rb-value { font-size: 20px; }
    .ssc-seat-badge .sb-value { font-size: 24px; }
  }

  @media (max-width: 480px) {
    .sp-header { flex-direction: column; align-items: stretch; }
    .sp-session-wrap { min-width: 0; width: 100%; }
    .sp-title { font-size: 18px; }
    .ssc-header { padding: 14px 16px; }
    .ssc-body { padding: 16px; }
    .ssc-seat-row { flex-direction: column; align-items: stretch; }
    .ssc-room-badge, .ssc-seat-badge { width: 100%; }
  }
</style>

<!-- CSRF token for AJAX requests (matches admin panel convention) -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<?php
// ── Page-specific JS — passed to the footer's $pageJs slot ────────
$pageJs = <<<'JS'
(function () {
  "use strict";

  const AJAX_URL = "seat-plan-ajax.php";
  function getToken() { return document.getElementById("csrfToken")?.value || ""; }
  let csrfToken = getToken();

  const $ = (id) => document.getElementById(id);
  const escapeHtml = (str) => String(str ?? "").replace(/[&<>"']/g, (c) => ({
    "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;"
  }[c]));

  let enrollmentsCache  = [];
  let currentEnrollment = null;

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
    ["spLoading","spError","spNoEnrollment","spEmpty","spExamListView","spDetailView"].forEach(elId => {
      const el = $(elId);
      if (el) el.style.display = (elId === id) ? "" : "none";
    });
  }

  function showError(msg) {
    const el = $("spError");
    el.textContent = msg;
    showOnly("spError");
  }

  function fmtDate(d) {
    if (!d) return "—";
    return new Date(d + "T00:00:00").toLocaleDateString("en-GB", { day:"numeric", month:"short", year:"numeric" });
  }

  function fmtTime(t) {
    if (!t) return "—";
    const [h, m] = t.split(":").map(Number);
    const ampm = h >= 12 ? "PM" : "AM";
    const h12  = (h % 12) || 12;
    return String(h12).padStart(2,"0") + ":" + String(m).padStart(2,"0") + " " + ampm;
  }

  // ── Step 1: load this student's enrollment history for the picker ──
  async function loadSessions() {
    showOnly("spLoading");
    const data = await post("fetch_sessions", {});
    if (!data) { showError("Could not reach the server. Please check your connection."); return; }
    if (!data.ok) { showError(data.msg || "Something went wrong."); return; }

    enrollmentsCache = data.enrollments || [];
    if (enrollmentsCache.length === 0) { showOnly("spNoEnrollment"); return; }

    const wrap   = $("spSessionWrap");
    const select = $("spSessionSelect");
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
      currentEnrollment = select.value;
      loadExamList(currentEnrollment);
    });

    const activeOne = enrollmentsCache.find(e => e.status === "active");
    currentEnrollment = (activeOne || enrollmentsCache[0]).enrollment_id;
    select.value = currentEnrollment;

    loadExamList(currentEnrollment);
  }

  // ── Step 2: load exam cards with seat-readiness status ─────────────
  async function loadExamList(enrollmentId) {
    showOnly("spLoading");
    const data = await post("fetch_exams", { enrollment_id: enrollmentId });
    if (!data) { showError("Could not reach the server. Please check your connection."); return; }
    if (!data.ok) { showError(data.msg || "Something went wrong."); return; }

    $("spSubLabel").textContent =
      data.class_label + " · Session " + data.session_year + (data.group_name ? " · " + data.group_name : "");

    if (!data.exams || data.exams.length === 0) {
      showOnly("spEmpty");
      return;
    }

    renderExamGrid(data.exams);
    showOnly("spExamListView");
  }

  function renderExamGrid(exams) {
    const grid = $("spExamGrid");
    grid.innerHTML = exams.map(ex => {
      let cardCls = "sp-exam-card";
      let statusHtml = "";
      let ctaHtml = "";
      let clickable = false;

      if (ex.is_excluded) {
        cardCls += " is-locked";
        statusHtml = '<div class="spc-status-row txt-red"><i class="fas fa-ban"></i> Not registered for this exam</div>';
      } else if (ex.is_past) {
        cardCls += " is-past";
        statusHtml = '<div class="spc-status-row txt-muted"><i class="fas fa-clock-rotate-left"></i> This exam has ended</div>';
      } else if (!ex.is_ready) {
        cardCls += " is-waiting";
        statusHtml = '<div class="spc-status-row txt-amber"><i class="fas fa-hourglass-half"></i> Seat plan not ready yet</div>';
      } else {
        cardCls += " is-ready is-clickable";
        clickable = true;
        statusHtml = '<div class="spc-status-row txt-green"><i class="fas fa-circle-check"></i> Seat plan available</div>';
        ctaHtml = '<div class="spc-cta">View My Seats <i class="fas fa-arrow-right"></i></div>';
      }

      const dateRange = ex.first_date
        ? fmtDate(ex.first_date) + (ex.last_date && ex.last_date !== ex.first_date ? " – " + fmtDate(ex.last_date) : "")
        : "Dates not set";

      const pct = ex.subject_count > 0 ? Math.round((ex.seated_count / ex.subject_count) * 100) : 0;
      const progressHtml = (!ex.is_excluded && !ex.is_past)
        ? '<div class="spc-progress">' +
            '<div class="spc-progress-text">' + ex.seated_count + ' of ' + ex.subject_count + ' subjects have a seat assigned</div>' +
            '<div class="spc-progress-bar"><div class="spc-progress-fill" style="width:' + pct + '%"></div></div>' +
          '</div>'
        : '';

      return (
        '<div class="' + cardCls + '" data-exam-id="' + ex.exam_id + '" data-clickable="' + clickable + '">' +
          '<div class="spc-name">' + escapeHtml(ex.exam_name) + '</div>' +
          '<div class="spc-date"><i class="fas fa-calendar"></i> ' + dateRange + '</div>' +
          progressHtml +
          statusHtml +
          ctaHtml +
        '</div>'
      );
    }).join("");

    grid.querySelectorAll('.sp-exam-card[data-clickable="true"]').forEach(card => {
      card.addEventListener("click", () => loadSeatPlan(card.dataset.examId));
    });
  }

  // ── Step 3: load + render this student's seats for the exam ────────
  async function loadSeatPlan(examId) {
    showOnly("spLoading");
    const data = await post("fetch_seat_plan", { enrollment_id: currentEnrollment, exam_id: examId });
    if (!data) { showError("Could not reach the server. Please check your connection."); return; }
    if (!data.ok) { showError(data.msg || "Something went wrong."); return; }

    renderSubjectList(data.subjects, data.exam.exam_name);
    showOnly("spDetailView");
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  // Build a simple visual grid of seat boxes, sized to capacity,
  // with the student's own seat highlighted. Purely a finder aid —
  // not a literal furniture map. We pick a column count that keeps
  // boxes roughly square-ish on common screen widths.
  function buildSeatGrid(capacity, mySeat) {
    if (!capacity || capacity < 1) return "";
    const cols = capacity <= 24 ? 6 : capacity <= 48 ? 8 : 10;
    let boxes = "";
    for (let i = 1; i <= capacity; i++) {
      const isMine = String(i) === String(mySeat);
      boxes += '<div class="ssc-grid-seat' + (isMine ? " is-mine" : "") + '">' + (isMine ? i : i) + '</div>';
    }
    return (
      '<div class="ssc-grid-title"><i class="fas fa-border-all"></i> Seat Finder (approximate layout)</div>' +
      '<div class="ssc-grid" style="grid-template-columns:repeat(' + cols + ',1fr)">' + boxes + '</div>' +
      '<div class="ssc-grid-legend">' +
        '<div class="ssc-grid-legend-item"><span class="ssc-legend-dot is-mine"></span> Your seat</div>' +
        '<div class="ssc-grid-legend-item"><span class="ssc-legend-dot is-other"></span> Other seats</div>' +
      '</div>'
    );
  }

  function renderSubjectList(subjects, examName) {
    const wrap = $("spSubjectList");
    wrap.innerHTML = subjects.map(s => {
      const hasSeat = !!(s.room_number && s.seat_number);

      let bodyHtml;
      if (hasSeat) {
        const fillNote = (s.filled_count !== null && s.capacity)
          ? '<div class="ssc-fill-note"><i class="fas fa-users"></i> ' + s.filled_count + ' of ' + s.capacity + ' seats filled in this room</div>'
          : '';
        bodyHtml =
          '<div class="ssc-seat-row">' +
            '<div class="ssc-room-badge">' +
              '<div class="rb-label">Room</div>' +
              '<div class="rb-value">' + escapeHtml(s.room_number) + '</div>' +
              (s.description ? '<div class="rb-desc">' + escapeHtml(s.description) + '</div>' : '') +
            '</div>' +
            '<div class="ssc-seat-badge">' +
              '<div class="sb-label">Your Seat</div>' +
              '<div class="sb-value">' + escapeHtml(s.seat_number) + '</div>' +
            '</div>' +
            '<div class="ssc-time-info">' +
              '<div><i class="fas fa-calendar"></i> ' + fmtDate(s.exam_date) + '</div>' +
              '<div><i class="fas fa-clock"></i> ' + fmtTime(s.start_time) + '</div>' +
            '</div>' +
          '</div>' +
          fillNote +
          buildSeatGrid(s.capacity, s.seat_number);
      } else {
        bodyHtml =
          '<div class="ssc-no-seat">' +
            '<i class="fas fa-hourglass-half"></i>' +
            '<span>Your seat for this subject has not been assigned yet. Please check again closer to the exam date.</span>' +
          '</div>';
      }

      return (
        '<div class="sp-subject-card">' +
          '<div class="ssc-header">' +
            '<div>' +
              '<div class="ssc-subject">' + escapeHtml(s.subject_name) + (s.subject_code ? ' <span style="color:var(--text-muted);font-weight:500;font-size:12px">(' + escapeHtml(s.subject_code) + ')</span>' : '') + '</div>' +
              '<div class="s-ssc-meta">' + escapeHtml(examName) + '</div>' +
            '</div>' +
          '</div>' +
          '<div class="ssc-body">' + bodyHtml + '</div>' +
        '</div>'
      );
    }).join("");
  }

  $("spBackBtn").addEventListener("click", () => showOnly("spExamListView"));

  loadSessions();
})();
JS;

require_once __DIR__ . '/includes/student-footer.php';
?>
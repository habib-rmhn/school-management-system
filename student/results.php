<?php
/**
 * EMS Student — My Results  v1.0
 * ─────────────────────────────────────────────────────────────────
 * Shows the logged-in student's own exam results — class-wise and
 * exam-wise. Data loads via AJAX (results-ajax.php) — no page
 * reload when switching sessions or opening an exam's mark sheet.
 *
 * Flow:
 *   1. Student picks a class/session (only shown if they have more
 *      than one — e.g. after being promoted).
 *   2. A list of published exam "cards" appears — each shows the
 *      exam name, GPA, pass/fail badge, and rank at a glance.
 *   3. Tapping a card opens the full per-subject mark sheet inline
 *      (MCQ / CQ / Total / Grade / Status), with a clear GPA summary
 *      at the top — no jargon, plain language pass/fail labelling.
 *
 * Design notes for a rural Class 6–10 audience:
 *   - Exam cards use color (green border = pass) so a student
 *     instantly knows how they did before reading any numbers.
 *   - The mark sheet table becomes a stacked subject-card list on
 *     small phones — a dense table with 6 columns is unreadable
 *     squeezed onto a 360px screen.
 *   - GPA is explained inline ("GPA 4.20 — Grade A") so the number
 *     always carries its meaning next to it, never alone.
 */
require_once __DIR__ . '/../includes/student-session.php';

$pageTitle = 'My Results';
$activeNav = 'results';

require_once __DIR__ . '/includes/student-sidebar.php';
?>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="rs-header">
  <div>
    <div class="rs-title"><i class="fas fa-chart-simple"></i> My Results</div>
    <div class="rs-sub" id="rsSubLabel">Loading your results…</div>
  </div>
  <div class="rs-session-wrap" id="rsSessionWrap" style="display:none">
    <label class="rs-session-label" for="rsSessionSelect">Class / Session</label>
    <select id="rsSessionSelect" class="filter-select"></select>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     LOADING / ERROR / EMPTY STATES
     ═══════════════════════════════════════════════════════════════ -->
<div id="rsLoading" class="card">
  <div class="empty-state">
    <i class="fas fa-spinner fa-spin"></i>
    <p>Loading your results…</p>
  </div>
</div>

<div id="rsError" class="alert alert-danger" style="display:none"></div>

<div id="rsNoEnrollment" class="alert alert-warning" style="display:none">
  <i class="fas fa-triangle-exclamation"></i>
  <span>You don't have any class enrollment on record yet. Please contact your school admin office.</span>
</div>

<div id="rsEmpty" class="card" style="display:none">
  <div class="empty-state">
    <i class="fas fa-chart-simple"></i>
    <p>No results have been published for this class yet. Please check back later.</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     EXAM LIST VIEW (cards)
     ═══════════════════════════════════════════════════════════════ -->
<div id="rsExamListView" style="display:none">
  <div class="rs-exam-grid" id="rsExamGrid"></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MARK SHEET VIEW (one exam's detail)
     ═══════════════════════════════════════════════════════════════ -->
<div id="rsSheetView" style="display:none">

  <button type="button" class="btn btn-outline rs-back-btn" id="rsBackBtn">
    <i class="fas fa-arrow-left"></i> Back to all exams
  </button>

  <!-- GPA summary banner -->
  <div class="rs-gpa-banner" id="rsGpaBanner"></div>

  <!-- Per-subject mark sheet -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-list-check"></i> Subject-wise Marks</div>
    </div>

    <!-- Desktop table -->
    <div class="table-wrap rs-table-wrap">
      <table class="ems-table rs-table" id="rsMarkTable">
        <thead>
          <tr>
            <th>Subject</th>
            <th>MCQ</th>
            <th>CQ</th>
            <th>Total</th>
            <th>Grade</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="rsMarkTableBody"></tbody>
      </table>
    </div>

    <!-- Mobile subject cards -->
    <div class="rs-subject-cards" id="rsSubjectCards"></div>
  </div>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE-SPECIFIC STYLES
     ═══════════════════════════════════════════════════════════════ -->
<style>
  /* ── Page header ─────────────────────────────────────────────── */
  .rs-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 20px;
  }
  .rs-title {
    font-size: 20px;
    font-weight: 800;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -.02em;
  }
  .rs-title i { color: var(--primary); font-size: 19px; }
  .rs-sub {
    font-size: 13.5px;
    color: var(--text-muted);
    margin-top: 5px;
    font-weight: 500;
  }
  .rs-session-wrap { display: flex; flex-direction: column; gap: 5px; min-width: 220px; }
  .rs-session-label {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .04em;
  }

  /* ── Exam cards grid ─────────────────────────────────────────── */
  .rs-exam-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
  }

  .rs-exam-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border);
    border-left: 5px solid var(--border);
    border-radius: var(--r-md);
    padding: 18px;
    cursor: pointer;
    transition: border-color var(--t), box-shadow var(--t), transform var(--t);
    box-shadow: var(--shadow-card);
  }
  .rs-exam-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
  .rs-exam-card.is-pass { border-left-color: var(--green); }
  .rs-exam-card.is-fail { border-left-color: var(--red); }
  .rs-exam-card.is-pending { border-left-color: var(--text-light); }

  .rsc-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
  .rsc-name { font-size: 15.5px; font-weight: 800; color: var(--text); line-height: 1.3; }
  .rsc-date { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; }

  .rsc-gpa-row { display: flex; align-items: baseline; gap: 8px; margin-bottom: 10px; }
  .rsc-gpa-value { font-size: 26px; font-weight: 800; color: var(--text); letter-spacing: -.02em; }
  .rsc-gpa-label { font-size: 12px; color: var(--text-muted); font-weight: 600; }

  .rsc-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding-top: 12px; border-top: 1px solid var(--border-light); }
  .rsc-rank { font-size: 12px; color: var(--text-muted); font-weight: 600; display: flex; align-items: center; gap: 5px; }
  .rsc-rank i { color: var(--amber); }
  .rsc-view { font-size: 12.5px; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 4px; }

  /* ── Back button ──────────────────────────────────────────────── */
  .rs-back-btn { margin-bottom: 18px; }

  /* ── GPA banner on sheet view ─────────────────────────────────── */
  .rs-gpa-banner {
    border-radius: var(--r-lg);
    padding: 24px 26px;
    margin-bottom: 20px;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 22px;
    position: relative;
    overflow: hidden;
  }
  .rs-gpa-banner::before {
    content: '';
    position: absolute;
    top: -40px; right: -30px;
    width: 160px; height: 160px;
    border-radius: 50%;
    background: rgba(255,255,255,.08);
  }
  .rs-gpa-banner.is-pass { background: linear-gradient(120deg, var(--green) 0%, #10b981 100%); }
  .rs-gpa-banner.is-fail { background: linear-gradient(120deg, #dc2626 0%, #ef4444 100%); }

  .rgb-gpa-circle {
    width: 84px; height: 84px;
    border-radius: 50%;
    background: rgba(255,255,255,.18);
    border: 3px solid rgba(255,255,255,.35);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    position: relative; z-index: 1;
  }
  .rgb-gpa-circle .gpa-num { font-size: 22px; font-weight: 800; line-height: 1; }
  .rgb-gpa-circle .gpa-lbl { font-size: 9px; text-transform: uppercase; letter-spacing: .05em; opacity: .85; margin-top: 2px; }

  .rgb-text { position: relative; z-index: 1; min-width: 0; }
  .rgb-exam-name { font-size: 17px; font-weight: 800; }
  .rgb-meta { font-size: 13px; opacity: .9; margin-top: 5px; }
  .rgb-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 10px;
    background: rgba(255,255,255,.2);
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12.5px;
    font-weight: 700;
  }

  /* ── Mark sheet — desktop table ───────────────────────────────── */
  .rs-table th, .rs-table td { text-align: center; }
  .rs-table th:first-child, .rs-table td:first-child { text-align: left; }
  .rs-subj-name { font-weight: 700; color: var(--text); }
  .rs-subj-code { font-size: 11px; color: var(--text-muted); margin-top: 1px; }
  .rs-total-val { font-weight: 800; font-size: 14.5px; }
  .rs-grade-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    padding: 4px 8px;
    border-radius: var(--r-sm);
    font-weight: 800;
    font-size: 13px;
  }
  .rs-na { color: var(--text-light); font-style: italic; font-size: 12.5px; }

  /* ── Mobile subject cards (hidden on desktop) ─────────────────── */
  .rs-subject-cards { display: none; }

  .rs-subj-card {
    border: 1px solid var(--border);
    border-radius: var(--r);
    padding: 14px 16px;
    margin: 0 16px 12px;
  }
  .rs-subj-card:last-child { margin-bottom: 16px; }
  .rs-subj-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 10px; }

  .rs-subj-card-marks { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 10px; }
  .rs-mini-stat { background: var(--bg-elevated); border-radius: var(--r-sm); padding: 8px; text-align: center; }
  .rs-mini-stat .ms-label { font-size: 9.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; font-weight: 700; }
  .rs-mini-stat .ms-value { font-size: 14px; font-weight: 800; color: var(--text); margin-top: 2px; }

  /* Grade colour classes (shared) */
  .grade-Aplus, .grade-A   { background: var(--green-bg); color: var(--green); }
  .grade-Aminus, .grade-B  { background: var(--sky-bg);   color: var(--sky); }
  .grade-C                  { background: var(--amber-bg); color: var(--amber); }
  .grade-D                  { background: var(--amber-bg); color: #92400e; }
  .grade-F                  { background: var(--red-bg);   color: var(--red); }

  .status-pass-text { color: var(--green); font-weight: 700; }
  .status-fail-text { color: var(--red);   font-weight: 700; }

  /* ── 4th subject row — visually distinct, bonus-only rule ────────── */
  .rs-table tr.is-fourth-sub td:first-child { border-left: 3px solid var(--amber); }
  .rs-fourth-badge {
    display: inline-block; font-size: 10px; font-weight: 700;
    background: var(--amber-bg); color: var(--amber);
    padding: 1px 7px; border-radius: 999px;
    vertical-align: middle; margin-left: 5px; white-space: nowrap;
  }
  /* Muted status text for 4th subject — an F does not fail you */
  .status-fourth-fail {
    color: var(--text-muted); font-weight: 600; font-size: 12px; font-style: italic;
  }
  /* Inline note shown below GPA banner for class 9-10 exams */
  .rs-gpa-note {
    font-size: 12px; color: var(--text-muted); margin-top: 8px;
    display: flex; align-items: flex-start; gap: 6px; line-height: 1.5;
  }
  .rs-gpa-note i { flex-shrink: 0; margin-top: 2px; color: var(--amber); }
  /* Mobile 4th-subject card distinction */
  .rs-subj-card.is-fourth-sub { border-left: 3px solid var(--amber); }

  /* ── Responsive ───────────────────────────────────────────────── */
  @media (max-width: 1100px) {
    .rs-exam-grid { grid-template-columns: repeat(2, 1fr); }
  }

  @media (max-width: 900px) {
    .rs-table-wrap { display: none; }
    .rs-subject-cards { display: block; padding-top: 14px; }
  }

  @media (max-width: 700px) {
    .rs-exam-grid { grid-template-columns: 1fr; }
  }

  @media (max-width: 600px) {
    .rs-gpa-banner { flex-direction: column; align-items: flex-start; gap: 14px; padding: 20px; }
    .rgb-gpa-circle { width: 72px; height: 72px; }
    .rgb-gpa-circle .gpa-num { font-size: 19px; }
  }

  @media (max-width: 480px) {
    .rs-header { flex-direction: column; align-items: stretch; }
    .rs-session-wrap { min-width: 0; width: 100%; }
    .rs-title { font-size: 18px; }
    .rsc-gpa-value { font-size: 22px; }
    .rs-subj-card { margin: 0 12px 10px; padding: 12px; }
    .rs-subj-card-marks { gap: 6px; }
  }
</style>

<!-- CSRF token for AJAX requests (matches admin panel convention) -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<?php
// ── Page-specific JS — passed to the footer's $pageJs slot ────────
$pageJs = <<<'JS'
(function () {
  "use strict";

  const AJAX_URL = "results-ajax.php";
  function getToken() { return document.getElementById("csrfToken")?.value || ""; }
  let csrfToken = getToken();

  const $ = (id) => document.getElementById(id);
  const escapeHtml = (str) => String(str ?? "").replace(/[&<>"']/g, (c) => ({
    "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;"
  }[c]));

  let enrollmentsCache  = [];
  let currentEnrollment = null;
  let currentExamsData  = null;

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
    ["rsLoading","rsError","rsNoEnrollment","rsEmpty","rsExamListView","rsSheetView"].forEach(elId => {
      const el = $(elId);
      if (el) el.style.display = (elId === id) ? "" : "none";
    });
  }

  function showError(msg) {
    const el = $("rsError");
    el.textContent = msg;
    showOnly("rsError");
  }

  function ordinal(n) {
    n = Number(n);
    if (!n) return "";
    const s = ["th","st","nd","rd"], v = n % 100;
    return n + (s[(v-20)%10] || s[v] || s[0]);
  }

  function gradeClass(grade) {
    if (!grade) return "";
    return "grade-" + grade.replace("+","plus").replace("-","minus");
  }

  // ── Step 1: load this student's enrollment history for the picker ──
  async function loadSessions() {
    showOnly("rsLoading");
    const data = await post("fetch_sessions", {});
    if (!data) { showError("Could not reach the server. Please check your connection."); return; }
    if (!data.ok) { showError(data.msg || "Something went wrong."); return; }

    enrollmentsCache = data.enrollments || [];
    if (enrollmentsCache.length === 0) { showOnly("rsNoEnrollment"); return; }

    const wrap   = $("rsSessionWrap");
    const select = $("rsSessionSelect");
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

  // ── Step 2: load published exam cards for one enrollment ──────────
  async function loadExamList(enrollmentId) {
    showOnly("rsLoading");
    const data = await post("fetch_exams", { enrollment_id: enrollmentId });
    if (!data) { showError("Could not reach the server. Please check your connection."); return; }
    if (!data.ok) { showError(data.msg || "Something went wrong."); return; }

    currentExamsData = data;

    $("rsSubLabel").textContent =
      data.class_label + " · Session " + data.session_year + (data.group_name ? " · " + data.group_name : "");

    if (!data.exams || data.exams.length === 0) {
      showOnly("rsEmpty");
      return;
    }

    renderExamGrid(data.exams, data.class_size);
    showOnly("rsExamListView");
  }

  function renderExamGrid(exams, classSize) {
    const grid = $("rsExamGrid");
    grid.innerHTML = exams.map(ex => {
      const hasGpa = ex.gpa !== null && ex.gpa !== undefined;
      const status = ex.overall_status; // 'pass' | 'fail' | null
      const cardCls = status === "pass" ? "is-pass" : status === "fail" ? "is-fail" : "is-pending";

      let gpaBlock;
      if (hasGpa) {
        gpaBlock =
          '<div class="rsc-gpa-row">' +
            '<span class="rsc-gpa-value">' + Number(ex.gpa).toFixed(2) + '</span>' +
            '<span class="rsc-gpa-label">GPA</span>' +
          '</div>';
      } else {
        gpaBlock = '<div class="rsc-gpa-row"><span class="rs-na">Result not entered for you</span></div>';
      }

      const rankBlock = (hasGpa && ex.position)
        ? '<div class="rsc-rank"><i class="fas fa-trophy"></i> ' + ordinal(ex.position) + ' of ' + classSize + '</div>'
        : '<div class="rsc-rank"></div>';

      const dateStr = ex.published_at
        ? new Date(ex.published_at.replace(" ","T")).toLocaleDateString("en-GB", { day:"numeric", month:"short", year:"numeric" })
        : "";

      const badge = status === "pass"
        ? '<span class="badge badge-green"><i class="fas fa-check"></i> Passed</span>'
        : status === "fail"
          ? '<span class="badge badge-red"><i class="fas fa-xmark"></i> Failed</span>'
          : "";

      return (
        '<div class="rs-exam-card ' + cardCls + '" data-exam-id="' + ex.exam_id + '">' +
          '<div class="rsc-top">' +
            '<div>' +
              '<div class="rsc-name">' + escapeHtml(ex.exam_name) + '</div>' +
              (dateStr ? '<div class="rsc-date"><i class="fas fa-calendar"></i> Published ' + dateStr + '</div>' : '') +
            '</div>' +
            badge +
          '</div>' +
          gpaBlock +
          '<div class="rsc-foot">' +
            rankBlock +
            '<div class="rsc-view">View Marks <i class="fas fa-arrow-right"></i></div>' +
          '</div>' +
        '</div>'
      );
    }).join("");

    grid.querySelectorAll(".rs-exam-card").forEach(card => {
      card.addEventListener("click", () => loadResultSheet(card.dataset.examId));
    });
  }

  // ── Step 3: load full mark sheet for one exam ─────────────────────
  async function loadResultSheet(examId) {
    showOnly("rsLoading");
    const data = await post("fetch_result", { enrollment_id: currentEnrollment, exam_id: examId });
    if (!data) { showError("Could not reach the server. Please check your connection."); return; }
    if (!data.ok) { showError(data.msg || "Something went wrong."); return; }

    renderGpaBanner(data.exam, data.summary, data.class_size, data.subjects);
    renderMarkTable(data.subjects);
    renderSubjectCards(data.subjects);

    showOnly("rsSheetView");
  }

  function renderGpaBanner(exam, summary, classSize, subjects) {
    const banner = $("rsGpaBanner");
    const hasResult = !!summary;
    const status = summary ? summary.overall_status : null;

    banner.className = "rs-gpa-banner " + (status === "fail" ? "is-fail" : "is-pass");

    const gpaText = hasResult ? Number(summary.gpa).toFixed(2) : "—";
    const statusPill = hasResult
      ? '<div class="rgb-status-pill"><i class="fas ' + (status === "pass" ? "fa-check" : "fa-xmark") + '"></i> ' +
        (status === "pass" ? "You Passed" : "You Did Not Pass") + '</div>'
      : '<div class="rgb-status-pill"><i class="fas fa-circle-info"></i> Result not entered for you yet</div>';

    const rankText = (hasResult && summary.position)
      ? " · Rank " + ordinal(summary.position) + " of " + classSize
      : "";

    banner.innerHTML =
      '<div class="rgb-gpa-circle"><span class="gpa-num">' + gpaText + '</span><span class="gpa-lbl">GPA</span></div>' +
      '<div class="rgb-text">' +
        '<div class="rgb-exam-name">' + escapeHtml(exam.exam_name) + '</div>' +
        '<div class="rgb-meta">' + escapeHtml(exam.class_label) + ' · Session ' + escapeHtml(String(exam.session_year)) +
          (exam.group_name ? " · " + escapeHtml(exam.group_name) : "") + rankText +
        '</div>' +
        statusPill +
      '</div>';

    // Show GPA formula note if the student has a 4th subject in this result.
    // Placed below the banner (not inside it) so it's readable on any background.
    const hasFourth = subjects && subjects.some(s => s.is_fourth_subject);
    let noteEl = $("rsGpaNote");
    if (!noteEl) {
      noteEl = document.createElement("div");
      noteEl.id = "rsGpaNote";
      banner.parentNode.insertBefore(noteEl, banner.nextSibling);
    }
    if (hasFourth) {
      noteEl.innerHTML =
        '<div class="rs-gpa-note">' +
          '<i class="fas fa-circle-info"></i>' +
          '<span>Your GPA includes a <strong>4th subject bonus</strong>: marks above GP 2.00 on your 4th subject ' +
          'are added as a bonus to your total — but the 4th subject itself is not counted in your GPA average, ' +
          'and an F on your 4th subject does <strong>not</strong> fail you.</span>' +
        '</div>';
    } else {
      noteEl.innerHTML = '';
    }
  }

  function fmtMark(v) {
    if (v === null || v === undefined) return '<span class="rs-na">—</span>';
    return Number(v).toFixed(v % 1 === 0 ? 0 : 2);
  }

  function renderMarkTable(subjects) {
    const body = $("rsMarkTableBody");
    body.innerHTML = subjects.map(s => {
      const hasMark   = s.total_marks !== null && s.total_marks !== undefined;
      const gCls      = gradeClass(s.grade);
      const isFourth  = !!s.is_fourth_subject;

      // 4th subject badge on the subject name cell
      const fourthBadge = isFourth
        ? '<span class="rs-fourth-badge"><i class="fas fa-4"></i> 4th Subject</span>'
        : '';

      // Status cell: for 4th subject, an F is shown as "No bonus" (muted) not as a red "Fail"
      // since it never causes the student to fail the exam.
      let statusCell;
      if (!hasMark) {
        statusCell = '<span class="rs-na">Not entered</span>';
      } else if (isFourth) {
        statusCell = s.subject_status === 'pass'
          ? '<span class="status-pass-text"><i class="fas fa-check"></i> Bonus applies</span>'
          : '<span class="status-fourth-fail"><i class="fas fa-minus"></i> No bonus</span>';
      } else {
        statusCell = '<span class="' + (s.subject_status === 'pass' ? 'status-pass-text' : 'status-fail-text') + '">' +
          (s.subject_status === 'pass' ? 'Pass' : 'Fail') + '</span>';
      }

      return (
        '<tr' + (isFourth ? ' class="is-fourth-sub"' : '') + '>' +
          '<td><div class="rs-subj-name">' + escapeHtml(s.subject_name) + fourthBadge + '</div>' +
            (s.subject_code ? '<div class="rs-subj-code">' + escapeHtml(s.subject_code) + '</div>' : '') + '</td>' +
          '<td>' + (s.has_mcq ? fmtMark(s.mcq_marks) + ' <span class="rs-na">/' + (s.mcq_full ?? "—") + '</span>' : '<span class="rs-na">N/A</span>') + '</td>' +
          '<td>' + (s.has_cq ? fmtMark(s.cq_marks) + ' <span class="rs-na">/' + (s.cq_full ?? "—") + '</span>' : '<span class="rs-na">N/A</span>') + '</td>' +
          '<td><span class="rs-total-val">' + fmtMark(s.total_marks) + '</span></td>' +
          '<td>' + (hasMark ? '<span class="rs-grade-pill ' + gCls + '">' + escapeHtml(s.grade || "—") + '</span>' : '<span class="rs-na">—</span>') + '</td>' +
          '<td>' + statusCell + '</td>' +
        '</tr>'
      );
    }).join("");
  }

  function renderSubjectCards(subjects) {
    const wrap = $("rsSubjectCards");
    wrap.innerHTML = subjects.map(s => {
      const hasMark  = s.total_marks !== null && s.total_marks !== undefined;
      const gCls     = gradeClass(s.grade);
      const isFourth = !!s.is_fourth_subject;

      const fourthBadge = isFourth
        ? '<span class="rs-fourth-badge"><i class="fas fa-4"></i> 4th Subject</span>'
        : '';

      let statusLine;
      if (hasMark) {
        if (isFourth) {
          statusLine = '<div style="margin-top:10px;text-align:center;font-size:12.5px" class="' +
            (s.subject_status === 'pass' ? 'status-pass-text' : 'status-fourth-fail') + '">' +
            '<i class="fas ' + (s.subject_status === 'pass' ? 'fa-check' : 'fa-minus') + '"></i> ' +
            (s.subject_status === 'pass' ? 'Bonus applies to your GPA' : 'No bonus (GP not above 2.00)') +
            '</div>';
        } else {
          statusLine = '<div style="margin-top:10px;text-align:center;font-size:12.5px" class="' +
            (s.subject_status === 'pass' ? 'status-pass-text' : 'status-fail-text') + '">' +
            '<i class="fas ' + (s.subject_status === 'pass' ? 'fa-check' : 'fa-xmark') + '"></i> ' +
            (s.subject_status === 'pass' ? 'Passed this subject' : 'Did not pass this subject') +
            '</div>';
        }
      } else {
        statusLine = '';
      }

      return (
        '<div class="rs-subj-card' + (isFourth ? ' is-fourth-sub' : '') + '">' +
          '<div class="rs-subj-card-top">' +
            '<div>' +
              '<div class="rs-subj-name">' + escapeHtml(s.subject_name) + fourthBadge + '</div>' +
              (s.subject_code ? '<div class="rs-subj-code">' + escapeHtml(s.subject_code) + '</div>' : '') +
            '</div>' +
            (hasMark
              ? '<span class="rs-grade-pill ' + gCls + '">' + escapeHtml(s.grade || "—") + '</span>'
              : '<span class="rs-na">Not entered</span>') +
          '</div>' +
          '<div class="rs-subj-card-marks">' +
            '<div class="rs-mini-stat"><div class="ms-label">MCQ</div><div class="ms-value">' +
              (s.has_mcq ? fmtMark(s.mcq_marks) : '<span class="rs-na">N/A</span>') + '</div></div>' +
            '<div class="rs-mini-stat"><div class="ms-label">CQ</div><div class="ms-value">' +
              (s.has_cq ? fmtMark(s.cq_marks) : '<span class="rs-na">N/A</span>') + '</div></div>' +
            '<div class="rs-mini-stat"><div class="ms-label">Total</div><div class="ms-value">' + fmtMark(s.total_marks) + '</div></div>' +
          '</div>' +
          statusLine +
        '</div>'
      );
    }).join("");
  }

  $("rsBackBtn").addEventListener("click", () => {
    showOnly("rsExamListView");
  });

  loadSessions();
})();
JS;

require_once __DIR__ . '/includes/student-footer.php';
?>
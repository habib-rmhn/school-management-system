<?php
/**
 * EMS Teacher — Upload Marks  v1.0
 * ─────────────────────────────────────────────────────────────────
 * Enter MCQ/CQ marks for any subject the teacher teaches, in any
 * exam that's still open (draft) for editing. Grades and pass/fail
 * are shown live as the teacher types (instant feedback), but the
 * numbers that actually get saved are always recalculated on the
 * server — the client's preview is just a preview.
 *
 * Once admin publishes an exam, it disappears from the selector here
 * and can no longer be edited (server enforces this even if the page
 * was already open when publish happened).
 *
 * All data loads via AJAX (marks-ajax.php) — no page reload.
 */
require_once __DIR__ . '/../includes/teacher-session.php';

$pageTitle  = 'Upload Marks';
$activeNav  = 'marks';
$breadcrumb = ['Upload Marks'];

// Pre-selection hints from today.php's "Marks" button (class_id + subject_id).
$presetClassId   = isset($_GET['class_id'])   ? (int)$_GET['class_id']   : null;
$presetSubjectId = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : null;

require_once __DIR__ . '/includes/teacher-sidebar.php';
?>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="page-header-top">
    <div>
      <div class="page-title"><i class="fas fa-pencil" style="color:var(--primary);margin-right:8px"></i>Upload Marks</div>
      <div class="page-sub"><i class="fas fa-circle-info"></i> Enter marks for exams that are still open for editing</div>
    </div>
    <div class="page-actions">
      <button type="button" class="btn btn-outline" id="refreshBtn">
        <i class="fas fa-rotate" id="refreshIcon"></i> Refresh
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     ERROR / NO-SESSION / NO-ASSIGNMENTS STATES
     ═══════════════════════════════════════════════════════════════ -->
<div id="marksError" class="alert alert-danger" style="display:none">
  <i class="fas fa-circle-exclamation"></i>
  <span id="marksErrorText">Something went wrong.</span>
  <button type="button" class="btn btn-sm btn-outline" id="marksErrorRetry" style="margin-left:auto">
    <i class="fas fa-rotate"></i> Try Again
  </button>
</div>

<div id="noSessionAlert" class="alert alert-warning" style="display:none">
  <i class="fas fa-triangle-exclamation"></i>
  <span>There's no active academic session set right now. Please contact your school admin office.</span>
</div>

<div id="noAssignmentsAlert" class="alert alert-info" style="display:none">
  <i class="fas fa-circle-check"></i>
  <span>You have no exams open for marks entry right now. Once your admin creates one for a subject you teach, it will appear here.</span>
</div>

<div id="lockedAlert" class="alert alert-warning" style="display:none">
  <i class="fas fa-lock"></i>
  <span id="lockedAlertText">This exam has been published and is now locked for editing.</span>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     EXAM + SUBJECT SELECTOR
     ═══════════════════════════════════════════════════════════════ -->
<div class="card" id="selectorCard">
  <div class="card-body">
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label" for="assignSelect">Exam &amp; Subject</label>
      <select id="assignSelect" class="form-control" disabled>
        <option value="">Loading your exams…</option>
      </select>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MARKS SCHEME INFO CARD
     ═══════════════════════════════════════════════════════════════ -->
<div class="card" id="schemeCard" style="display:none">
  <div class="card-body">
    <div class="scheme-row" id="schemeRow"></div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     PROMPT (before any assignment is chosen)
     ═══════════════════════════════════════════════════════════════ -->
<div id="marksPrompt" class="card" style="display:none">
  <div class="empty-state">
    <i class="fas fa-hand-pointer"></i>
    <h3>Choose an exam &amp; subject to begin</h3>
    <p>Pick one from the dropdown above, and the student list will load automatically.</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     LOADING SKELETON
     ═══════════════════════════════════════════════════════════════ -->
<div id="marksSkeleton" class="card" style="display:none">
  <div class="empty-state">
    <i class="fas fa-spinner fa-spin"></i>
    <p>Loading student list…</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     EMPTY ROSTER
     ═══════════════════════════════════════════════════════════════ -->
<div id="marksEmptyRoster" class="card" style="display:none">
  <div class="empty-state">
    <i class="fas fa-user-slash"></i>
    <h3>No students in this class</h3>
    <p>There are no active students enrolled for this exam's class.</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     PROGRESS BAR + SAVE (top)
     ═══════════════════════════════════════════════════════════════ -->
<div class="card" id="progressBarWrap" style="display:none">
  <div class="mk-progressbar">
    <div class="mk-progress-info">
      <div class="progress-bar"><div class="progress-fill" id="progressFill" style="width:0%"></div></div>
      <div class="mk-progress-text"><b id="progressCount">0</b> of <span id="progressTotal">0</span> students fully entered</div>
    </div>
    <button type="button" class="btn btn-primary" id="saveBtnTop">
      <i class="fas fa-floppy-disk"></i> Save Marks
    </button>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MARKS ENTRY TABLE
     ═══════════════════════════════════════════════════════════════ -->
<div class="card" id="rosterCard" style="display:none">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-users"></i> <span id="rosterTitle">Student List</span></div>
    <span class="badge badge-green" id="rosterCountBadge"></span>
  </div>
  <div class="table-wrap">
    <table class="ems-table mk-table" id="marksTable">
      <thead><tr id="marksTableHead"></tr></thead>
      <tbody id="marksTableBody"></tbody>
    </table>
  </div>
  <div class="card-footer mk-savebar">
    <div class="mk-progress-text"><b id="progressCountBottom">0</b> of <span id="progressTotalBottom">0</span> students fully entered</div>
    <button type="button" class="btn btn-primary btn-lg" id="saveBtnBottom">
      <i class="fas fa-floppy-disk"></i> Save Marks
    </button>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE-SPECIFIC STYLES
     ═══════════════════════════════════════════════════════════════ -->
<style>
  #refreshIcon.spinning { animation: refreshSpin .8s linear infinite; }
  @keyframes refreshSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

  /* ── Marks scheme summary card ─────────────────────────────────── */
  .scheme-row { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
  .scheme-chip {
    display: flex; flex-direction: column; gap: 2px;
    padding: 8px 16px; border-radius: var(--r-sm);
    background: var(--bg-elevated); border: 1px solid var(--border-light);
    min-width: 100px;
  }
  .scheme-chip .sc-label { font-size: 10.5px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
  .scheme-chip .sc-value { font-size: 15px; color: var(--text); font-weight: 800; margin-top: 2px; }
  .scheme-chip-primary { background: var(--primary-light); border-color: var(--primary-border, var(--primary)); }
  .scheme-chip-primary .sc-label { color: var(--primary); }
  .scheme-chip-primary .sc-value { color: var(--primary); }
  .scheme-chip-amber { background: var(--amber-bg); border-color: var(--amber); }
  .scheme-chip-amber .sc-label { color: var(--amber); }
  .scheme-chip-amber .sc-value { color: var(--amber); }

  /* Subject-role note below scheme chips */
  .scheme-note {
    flex-basis: 100%; display: flex; align-items: flex-start; gap: 8px;
    padding: 10px 14px; border-radius: var(--r-sm); font-size: 12.5px; line-height: 1.5;
    margin-top: 4px;
  }
  .scheme-note i { flex-shrink: 0; margin-top: 2px; }
  .scheme-note-primary { background: var(--primary-light); color: var(--primary); border: 1px solid var(--primary-border, var(--primary)); }
  .scheme-note-amber   { background: var(--amber-bg);      color: var(--amber-dark, var(--amber)); border: 1px solid var(--amber); }
  .scheme-exam-title { font-size: 15px; font-weight: 800; color: var(--text); flex-basis: 100%; }
  .scheme-exam-sub { font-size: 12.5px; color: var(--text-muted); flex-basis: 100%; margin-top: -12px; }

  /* ── Progress bar ───────────────────────────────────────────────── */
  .mk-progressbar { display: flex; align-items: center; gap: 20px; padding: 18px 20px; flex-wrap: wrap; }
  .mk-progress-info { flex: 1; min-width: 200px; }
  .mk-progress-text { font-size: 13px; color: var(--text-muted); margin-top: 8px; font-weight: 600; }
  .mk-progress-text b { color: var(--text); font-size: 15px; }
  .mk-savebar { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }

  /* ── Marks entry table ──────────────────────────────────────────── */
  .mk-table th, .mk-table td { white-space: nowrap; }
  .mk-student-cell { display: flex; align-items: center; gap: 10px; white-space: normal; min-width: 160px; }
  .mk-avatar {
    width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 12.5px; font-weight: 700; color: #fff;
  }
  .av-c0 { background: #16a34a; } .av-c1 { background: #0284c7; }
  .av-c2 { background: #d97706; } .av-c3 { background: #7c3aed; }
  .av-c4 { background: #dc2626; } .av-c5 { background: #0f766e; }
  .av-c6 { background: #db2777; } .av-c7 { background: #4f46e5; }
  .mk-student-name { font-size: 13.5px; font-weight: 700; color: var(--text); }
  .mk-student-meta { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }

  .mk-input {
    width: 78px; height: 40px; padding: 0 10px;
    border: 1.5px solid var(--border); border-radius: var(--r-sm);
    font-family: inherit; font-size: 14px; font-weight: 600; color: var(--text);
    text-align: center; outline: none; transition: border-color var(--t), box-shadow var(--t);
    -moz-appearance: textfield;
  }
  .mk-input::-webkit-outer-spin-button,
  .mk-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
  .mk-input:focus { border-color: var(--border-focus); box-shadow: 0 0 0 3px rgba(22,163,74,.12); }
  .mk-input.mk-invalid { border-color: var(--red); background: var(--red-bg); }
  .mk-input-hint { font-size: 10px; color: var(--text-light); margin-top: 3px; text-align: center; }

  .mk-total-cell { font-size: 14px; font-weight: 800; color: var(--text); }
  .mk-total-cell .mk-total-scale { font-size: 11px; color: var(--text-muted); font-weight: 500; }

  .mk-grade-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 38px; height: 26px; padding: 0 8px;
    border-radius: 20px; font-size: 12px; font-weight: 800;
    background: var(--border-light); color: var(--text-light);
  }
  .mk-grade-badge.g-pass { background: var(--green-bg); color: var(--green-dark); }
  .mk-grade-badge.g-fail { background: var(--red-bg);   color: var(--red); }

  .mk-status-pill {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 20px;
  }
  .mk-status-pill.st-pass { background: var(--green-bg); color: var(--green-dark); }
  .mk-status-pill.st-fail { background: var(--red-bg);   color: var(--red); }
  .mk-status-pill.st-pending { background: var(--border-light); color: var(--text-light); }

  /* ── Responsive: convert table to cards on narrow screens ─────────── */
  @media (max-width: 900px) {
    .mk-table thead { display: none; }
    .mk-table, .mk-table tbody, .mk-table tr, .mk-table td { display: block; width: 100%; }
    .mk-table tr {
      border: 1px solid var(--border-light); border-radius: var(--r-md);
      margin-bottom: 12px; padding: 14px; background: var(--bg-card);
    }
    .mk-table td { border: none; padding: 6px 0; white-space: normal; }
    .mk-table td[data-label]::before {
      content: attr(data-label); display: block;
      font-size: 10.5px; font-weight: 700; color: var(--text-muted);
      text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px;
    }
    .mk-student-cell { margin-bottom: 4px; }
    .mk-inputs-row { display: flex; gap: 10px; }
    .mk-input { width: 100%; }
    .mk-result-row { display: flex; align-items: center; gap: 12px; margin-top: 6px; }
  }

  @media (min-width: 901px) {
    .mk-inputs-row { display: flex; gap: 8px; justify-content: center; }
    .mk-result-row { display: flex; align-items: center; gap: 8px; justify-content: center; }
  }

  @media (max-width: 480px) {
    .scheme-row { gap: 12px; }
    .scheme-chip { padding: 7px 12px; min-width: 84px; }
    .mk-progressbar { padding: 14px; }
    .mk-savebar .btn { width: 100%; }
  }
</style>

<!-- CSRF token for AJAX requests -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<?php
$presetClassIdJson   = json_encode($presetClassId);
$presetSubjectIdJson = json_encode($presetSubjectId);

$pageJs = <<<JS
(function () {
  "use strict";

  const AJAX_URL = "marks-ajax.php";
  function getToken() { return document.getElementById("csrfToken")?.value || ""; }
  let csrfToken = getToken();

  const \$ = (id) => document.getElementById(id);
  const escapeHtml = (str) => String(str ?? "").replace(/[&<>"']/g, (c) => ({
    "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;"
  }[c]));

  const PRESET_CLASS_ID   = {$presetClassIdJson};
  const PRESET_SUBJECT_ID = {$presetSubjectIdJson};

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
      const tokenInput = \$("csrfToken");
      if (tokenInput) tokenInput.value = data.csrf;
    }
    return data;
  }

  function initials(name) {
    const parts = String(name || "").trim().split(/\\s+/);
    if (parts.length === 0 || !parts[0]) return "?";
    return parts.length === 1 ? parts[0].charAt(0).toUpperCase()
                               : (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  }
  function avatarColorClass(name) {
    const s = String(name || "");
    let sum = 0;
    for (let i = 0; i < s.length; i++) sum += s.charCodeAt(i);
    return "av-c" + (sum % 8);
  }

  let assignmentsCache = [];
  let currentConfig     = null;
  let currentStudents   = [];

  function showCard(id, show) {
    const el = \$(id);
    if (el) el.style.display = show ? "" : "none";
  }

  function resetPanels() {
    showCard("schemeCard", false);
    showCard("marksPrompt", false);
    showCard("marksSkeleton", false);
    showCard("marksEmptyRoster", false);
    showCard("progressBarWrap", false);
    showCard("rosterCard", false);
    \$("lockedAlert").style.display = "none";
  }

  // ── Grading logic — mirrors the server exactly, for live preview ──
  function calcGrade(pct) {
    if (pct >= 80) return ["A+", 5.00];
    if (pct >= 70) return ["A",  4.00];
    if (pct >= 60) return ["A-", 3.50];
    if (pct >= 50) return ["B",  3.00];
    if (pct >= 40) return ["C",  2.00];
    if (pct >= 33) return ["D",  1.00];
    return ["F", 0.00];
  }

  function computePreview(student) {
    const c = currentConfig;
    const mcqRaw = student.mcq_raw, cqRaw = student.cq_raw;

    if (c.has_mcq && (mcqRaw === null || mcqRaw === "" || isNaN(mcqRaw))) return null;
    if (c.has_cq  && (cqRaw  === null || cqRaw  === "" || isNaN(cqRaw)))  return null;

    const mcqScale = c.mcq_convert_to !== null ? c.mcq_convert_to : c.mcq_full_marks;
    const cqScale  = c.cq_convert_to  !== null ? c.cq_convert_to  : c.cq_full_marks;

    const mcqConv = (c.has_mcq && c.mcq_full_marks) ? (parseFloat(mcqRaw) / c.mcq_full_marks) * mcqScale : 0;
    const cqConv  = (c.has_cq  && c.cq_full_marks)  ? (parseFloat(cqRaw)  / c.cq_full_marks)  * cqScale  : 0;

    const totalScale = (c.has_mcq ? mcqScale : 0) + (c.has_cq ? cqScale : 0);
    const total = (c.has_mcq ? mcqConv : 0) + (c.has_cq ? cqConv : 0);
    const pct = totalScale > 0 ? (total / totalScale) * 100 : 0;

    const mcqPassPct = (c.has_mcq && mcqScale) ? (mcqConv / mcqScale) * 100 : null;
    const cqPassPct  = (c.has_cq  && cqScale)  ? (cqConv  / cqScale)  * 100 : null;

    const mcqPass = !c.has_mcq || mcqPassPct >= c.pass_percentage;
    const cqPass  = !c.has_cq  || cqPassPct  >= c.pass_percentage;
    const combinedPass = pct >= c.pass_percentage;
    const overallPass  = mcqPass && cqPass && combinedPass;

    const [grade, gp] = calcGrade(pct);
    return { total: total, totalScale: totalScale, grade: grade, gp: gp, pass: overallPass };
  }

  function isRowValid(student) {
    const c = currentConfig;
    if (c.has_mcq) {
      if (student.mcq_raw === null || student.mcq_raw === "" || isNaN(student.mcq_raw)) return false;
      if (parseFloat(student.mcq_raw) < 0 || parseFloat(student.mcq_raw) > c.mcq_full_marks) return false;
    }
    if (c.has_cq) {
      if (student.cq_raw === null || student.cq_raw === "" || isNaN(student.cq_raw)) return false;
      if (parseFloat(student.cq_raw) < 0 || parseFloat(student.cq_raw) > c.cq_full_marks) return false;
    }
    return true;
  }

  // ── Load the teacher's exam/subject assignments ──────────────────
  async function loadAssignments() {
    const data = await post("fetch_assignments", {});
    if (!data) {
      \$("marksErrorText").textContent = "Could not reach the server. Please check your connection.";
      \$("marksError").style.display = "flex";
      return;
    }
    if (!data.ok) {
      \$("marksErrorText").textContent = data.msg || "Something went wrong.";
      \$("marksError").style.display = "flex";
      return;
    }

    \$("noSessionAlert").style.display = data.no_session ? "flex" : "none";
    assignmentsCache = data.assignments || [];

    const select = \$("assignSelect");
    select.innerHTML = "";
    select.disabled = false;

    if (assignmentsCache.length === 0) {
      select.innerHTML = '<option value="">No exams open right now</option>';
      select.disabled = true;
      \$("noAssignmentsAlert").style.display = "flex";
      resetPanels();
      return;
    }

    select.appendChild(new Option("Select an exam & subject…", ""));
    assignmentsCache.forEach((a, idx) => {
      const label = a.exam_name + " — " + a.class_label + (a.group_name ? " (" + a.group_name + ")" : "") +
                    " — " + a.subject_name + "  [" + a.entered_count + "/" + a.total_students + " entered]";
      select.appendChild(new Option(label, idx));
    });

    if (PRESET_CLASS_ID !== null && PRESET_SUBJECT_ID !== null) {
      const match = assignmentsCache.findIndex((a) => a.class_id === PRESET_CLASS_ID && a.subject_id === PRESET_SUBJECT_ID);
      if (match !== -1) {
        select.value = String(match);
        loadMarks();
        return;
      }
    }

    resetPanels();
    showCard("marksPrompt", true);
  }

  // ── Load roster + config for the selected exam/subject ────────────
  async function loadMarks() {
    const select = \$("assignSelect");
    const idx = select.value;
    if (idx === "") { resetPanels(); showCard("marksPrompt", true); return; }

    const a = assignmentsCache[parseInt(idx, 10)];
    if (!a) return;

    resetPanels();
    showCard("marksSkeleton", true);

    const data = await post("fetch_marks", { exam_subject_id: a.exam_subject_id });
    showCard("marksSkeleton", false);

    if (!data) {
      \$("marksErrorText").textContent = "Could not reach the server. Please check your connection.";
      \$("marksError").style.display = "flex";
      return;
    }
    if (!data.ok) {
      if ((data.msg || "").toLowerCase().includes("published")) {
        \$("lockedAlertText").textContent = data.msg;
        \$("lockedAlert").style.display = "flex";
        loadAssignments(); // refresh list so the now-locked exam disappears
        return;
      }
      \$("marksErrorText").textContent = data.msg || "Something went wrong.";
      \$("marksError").style.display = "flex";
      return;
    }

    currentConfig   = data.config;
    currentStudents = (data.students || []).map((s) => ({ ...s, mcq_raw: s.mcq_raw, cq_raw: s.cq_raw }));

    renderScheme();

    if (currentStudents.length === 0) {
      showCard("marksEmptyRoster", true);
      return;
    }

    \$("rosterTitle").textContent = currentConfig.exam_name + " — " + currentConfig.subject_name;
    \$("rosterCountBadge").textContent = currentStudents.length + " student" + (currentStudents.length === 1 ? "" : "s");

    showCard("progressBarWrap", true);
    showCard("rosterCard", true);

    renderTable();
  }

  // ── Render the marks scheme summary chips ─────────────────────────
  function renderScheme() {
    const c = currentConfig;
    let html = '<div class="scheme-exam-title">' + escapeHtml(c.exam_name) + '</div>';
    html += '<div class="scheme-exam-sub">' + escapeHtml(c.class_label) + (c.group_name ? ' (' + escapeHtml(c.group_name) + ')' : '') + ' &middot; ' + escapeHtml(c.subject_name) + (c.subject_code ? ' &middot; Code ' + escapeHtml(c.subject_code) : '') + '</div>';

    if (c.is_fourth_subject) {
      html += '<div class="scheme-chip scheme-chip-amber"><span class="sc-label">Subject Type</span><span class="sc-value">4th Subject</span></div>';
    } else if (c.is_choice) {
      html += '<div class="scheme-chip scheme-chip-primary"><span class="sc-label">Subject Type</span><span class="sc-value">Main Choice</span></div>';
    }

    if (c.has_mcq) {
      html += '<div class="scheme-chip"><span class="sc-label">MCQ</span><span class="sc-value">out of ' + c.mcq_full_marks + (c.mcq_convert_to ? ' &rarr; ' + c.mcq_convert_to : '') + '</span></div>';
    }
    if (c.has_cq) {
      html += '<div class="scheme-chip"><span class="sc-label">CQ</span><span class="sc-value">out of ' + c.cq_full_marks + (c.cq_convert_to ? ' &rarr; ' + c.cq_convert_to : '') + '</span></div>';
    }
    html += '<div class="scheme-chip"><span class="sc-label">Pass Mark</span><span class="sc-value">' + c.pass_percentage + '%</span></div>';
    if (c.exam_date) {
      html += '<div class="scheme-chip"><span class="sc-label">Exam Date</span><span class="sc-value">' + escapeHtml(c.exam_date) + '</span></div>';
    }

    // For 4th subject: show a clear note so the teacher understands the GPA rule.
    if (c.is_fourth_subject) {
      html += '<div class="scheme-note scheme-note-amber">' +
        '<i class="fas fa-circle-info"></i>' +
        '<span>This is a <strong>4th (Optional) Subject</strong>. A student\'s grade here only adds a bonus to their GPA ' +
        '(GP above 2.00 is added as a bonus). An <strong>F grade does not fail</strong> the student overall — ' +
        'enter marks normally and the system will apply the bonus correctly.</span></div>';
    } else if (c.is_choice) {
      html += '<div class="scheme-note scheme-note-primary">' +
        '<i class="fas fa-star"></i>' +
        '<span>This is a <strong>Main Choice Subject</strong>. It is treated as a compulsory subject for these students — ' +
        'an F here will fail the student in this exam.</span></div>';
    }

    \$("schemeRow").innerHTML = html;
    showCard("schemeCard", true);
  }

  // ── Render the marks entry table ──────────────────────────────────
  function renderTable() {
    const c = currentConfig;
    const head = \$("marksTableHead");
    let headHtml = '<th>Student</th>';
    if (c.has_mcq) headHtml += '<th>MCQ</th>';
    if (c.has_cq)  headHtml += '<th>CQ</th>';
    headHtml += '<th>Total</th><th>Grade</th><th>Status</th>';
    head.innerHTML = headHtml;

    const body = \$("marksTableBody");
    let html = "";
    currentStudents.forEach((s, i) => {
      html += '<tr data-idx="' + i + '">';
      html += '<td data-label="Student"><div class="mk-student-cell"><div class="mk-avatar ' + avatarColorClass(s.full_name) + '">' + initials(s.full_name) + '</div><div><div class="mk-student-name">' + escapeHtml(s.full_name) + '</div><div class="mk-student-meta">ID ' + escapeHtml(s.student_id) + (s.roll_number ? ' &middot; Roll ' + s.roll_number : '') + '</div></div></div></td>';

      html += '<td data-label="Marks"><div class="mk-inputs-row">';
      if (c.has_mcq) {
        html += '<div><input type="number" class="mk-input mk-mcq-input" min="0" max="' + c.mcq_full_marks + '" step="0.5" placeholder="/ ' + c.mcq_full_marks + '" value="' + (s.mcq_raw !== null ? s.mcq_raw : "") + '" data-idx="' + i + '"><div class="mk-input-hint">MCQ / ' + c.mcq_full_marks + '</div></div>';
      }
      if (c.has_cq) {
        html += '<div><input type="number" class="mk-input mk-cq-input" min="0" max="' + c.cq_full_marks + '" step="0.5" placeholder="/ ' + c.cq_full_marks + '" value="' + (s.cq_raw !== null ? s.cq_raw : "") + '" data-idx="' + i + '"><div class="mk-input-hint">CQ / ' + c.cq_full_marks + '</div></div>';
      }
      html += '</div></td>';

      html += '<td data-label="Total"><div class="mk-result-row"><span class="mk-total-value" data-idx="' + i + '">—</span></div></td>';
      html += '<td data-label="Grade"><span class="mk-grade-badge" data-idx="' + i + '">—</span></td>';
      html += '<td data-label="Status"><span class="mk-status-pill st-pending" data-idx="' + i + '">Incomplete</span></td>';
      html += '</tr>';
    });
    body.innerHTML = html;

    body.querySelectorAll(".mk-mcq-input").forEach((inp) => {
      inp.addEventListener("input", () => {
        const i = parseInt(inp.dataset.idx, 10);
        currentStudents[i].mcq_raw = inp.value === "" ? null : inp.value;
        refreshRow(i);
      });
    });
    body.querySelectorAll(".mk-cq-input").forEach((inp) => {
      inp.addEventListener("input", () => {
        const i = parseInt(inp.dataset.idx, 10);
        currentStudents[i].cq_raw = inp.value === "" ? null : inp.value;
        refreshRow(i);
      });
    });

    currentStudents.forEach((s, i) => refreshRow(i));
  }

  // ── Recompute + repaint one row's live preview ────────────────────
  function refreshRow(i) {
    const s = currentStudents[i];
    const totalEl  = document.querySelector('.mk-total-value[data-idx="' + i + '"]');
    const gradeEl  = document.querySelector('.mk-grade-badge[data-idx="' + i + '"]');
    const statusEl = document.querySelector('.mk-status-pill[data-idx="' + i + '"]');
    const mcqInput = document.querySelector('.mk-mcq-input[data-idx="' + i + '"]');
    const cqInput  = document.querySelector('.mk-cq-input[data-idx="' + i + '"]');

    const valid = isRowValid(s);
    if (mcqInput) mcqInput.classList.toggle("mk-invalid", s.mcq_raw !== null && s.mcq_raw !== "" && !valid && currentConfig.has_mcq && (parseFloat(s.mcq_raw) < 0 || parseFloat(s.mcq_raw) > currentConfig.mcq_full_marks));
    if (cqInput)  cqInput.classList.toggle("mk-invalid",  s.cq_raw  !== null && s.cq_raw  !== "" && !valid && currentConfig.has_cq  && (parseFloat(s.cq_raw)  < 0 || parseFloat(s.cq_raw)  > currentConfig.cq_full_marks));

    const preview = computePreview(s);
    if (!preview) {
      if (totalEl)  totalEl.innerHTML  = "—";
      if (gradeEl)  { gradeEl.textContent = "—"; gradeEl.className = "mk-grade-badge"; }
      if (statusEl) { statusEl.textContent = "Incomplete"; statusEl.className = "mk-status-pill st-pending"; }
      return;
    }

    if (totalEl)  totalEl.innerHTML  = '<span class="mk-total-cell">' + preview.total.toFixed(1) + ' <span class="mk-total-scale">/ ' + preview.totalScale + '</span></span>';
    if (gradeEl)  { gradeEl.textContent = preview.grade; gradeEl.className = "mk-grade-badge " + (preview.pass ? "g-pass" : "g-fail"); }
    if (statusEl) { statusEl.textContent = preview.pass ? "Pass" : "Fail"; statusEl.className = "mk-status-pill " + (preview.pass ? "st-pass" : "st-fail"); }

    updateProgress();
  }

  // ── Progress bar (how many rows are fully & validly entered) ──────
  function updateProgress() {
    const total = currentStudents.length;
    const done  = currentStudents.filter((s) => isRowValid(s) && computePreview(s)).length;
    const pct   = total > 0 ? Math.round((done / total) * 100) : 0;

    ["progressCount", "progressCountBottom"].forEach((id) => { const el = \$(id); if (el) el.textContent = done; });
    ["progressTotal", "progressTotalBottom"].forEach((id) => { const el = \$(id); if (el) el.textContent = total; });
    const fill = \$("progressFill");
    if (fill) fill.style.width = pct + "%";
  }

  // ── Save ────────────────────────────────────────────────────────────
  let isSaving = false;

  async function saveMarks() {
    if (isSaving || currentStudents.length === 0) return;
    isSaving = true;

    [\$("saveBtnTop"), \$("saveBtnBottom")].forEach((b) => {
      if (b) { b.disabled = true; b.dataset.origHtml = b.innerHTML; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…'; }
    });

    const records = currentStudents.map((s) => ({
      enrollment_id: s.enrollment_id,
      mcq_raw: s.mcq_raw === null || s.mcq_raw === "" ? null : s.mcq_raw,
      cq_raw:  s.cq_raw  === null || s.cq_raw  === "" ? null : s.cq_raw,
    }));

    const select = \$("assignSelect");
    const a = assignmentsCache[parseInt(select.value, 10)];

    const data = await post("save_marks", {
      exam_subject_id: a.exam_subject_id,
      records: JSON.stringify(records),
    });

    [\$("saveBtnTop"), \$("saveBtnBottom")].forEach((b) => {
      if (b) { b.disabled = false; b.innerHTML = b.dataset.origHtml; }
    });
    isSaving = false;

    if (!data) {
      if (window.showToast) window.showToast("danger", "Save Failed", "Could not reach the server. Please try again.");
      return;
    }
    if (!data.ok) {
      if (window.showToast) window.showToast("danger", "Save Failed", data.msg || "Something went wrong.");
      return;
    }
    if (!data.saved) {
      if (window.showToast) window.showToast("warning", "Nothing Saved", data.msg || "No rows were complete enough to save yet.");
      return;
    }

    let msg = data.total_saved + " student" + (data.total_saved === 1 ? "" : "s") + " saved for " + data.subject_name + ".";
    if (data.skipped_incomplete > 0) msg += " " + data.skipped_incomplete + " left incomplete.";
    if (data.skipped_invalid > 0)    msg += " " + data.skipped_invalid + " had invalid marks.";
    if (window.showToast) window.showToast("success", "Marks Saved", msg);

    // Refresh assignment list (progress counts) and reload this roster
    loadAssignments().then(() => {
      const idx = assignmentsCache.findIndex((x) => x.exam_subject_id === a.exam_subject_id);
      if (idx !== -1) { \$("assignSelect").value = String(idx); loadMarks(); }
    });
  }

  \$("saveBtnTop").addEventListener("click", saveMarks);
  \$("saveBtnBottom").addEventListener("click", saveMarks);

  // ── Wire up selector + refresh ─────────────────────────────────────
  \$("assignSelect").addEventListener("change", loadMarks);

  \$("refreshBtn").addEventListener("click", async () => {
    const icon = \$("refreshIcon");
    if (icon) icon.classList.add("spinning");
    const keepIdx = \$("assignSelect").value;
    await loadAssignments();
    if (keepIdx !== "" && assignmentsCache[parseInt(keepIdx, 10)]) {
      \$("assignSelect").value = keepIdx;
      await loadMarks();
    }
    if (icon) icon.classList.remove("spinning");
    if (window.showToast) window.showToast("success", "Refreshed", "Exam list is up to date.");
  });

  \$("marksErrorRetry").addEventListener("click", () => {
    \$("marksError").style.display = "none";
    loadAssignments();
  });

  // ── Initial load ──────────────────────────────────────────────────────
  loadAssignments();

})();
JS;

require_once __DIR__ . '/includes/teacher-footer.php';
?>
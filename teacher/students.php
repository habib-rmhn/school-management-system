<?php
/**
 * EMS Teacher — Student List  v1.0
 * ─────────────────────────────────────────────────────────────────
 * Read-only view of student info for any class the teacher teaches.
 * Per spec, teachers can VIEW student info but never edit it here —
 * editing student records is Admin-only. Tapping a student opens a
 * detail panel with their full profile (contact info, parents,
 * address) without leaving the list.
 *
 * All data loads via AJAX (students-ajax.php) — no page reload.
 */
require_once __DIR__ . '/../includes/teacher-session.php';

$pageTitle  = 'Student List';
$activeNav  = 'students';
$breadcrumb = ['Student List'];

require_once __DIR__ . '/includes/teacher-sidebar.php';
?>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="page-header-top">
    <div>
      <div class="page-title"><i class="fas fa-users" style="color:var(--primary);margin-right:8px"></i>Student List</div>
      <div class="page-sub"><i class="fas fa-circle-info"></i> View student information for your classes</div>
    </div>
    <div class="page-actions">
      <button type="button" class="btn btn-outline" id="refreshBtn">
        <i class="fas fa-rotate" id="refreshIcon"></i> Refresh
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     ERROR / NO-SESSION / NO-CLASSES STATES
     ═══════════════════════════════════════════════════════════════ -->
<div id="stError" class="alert alert-danger" style="display:none">
  <i class="fas fa-circle-exclamation"></i>
  <span id="stErrorText">Something went wrong.</span>
  <button type="button" class="btn btn-sm btn-outline" id="stErrorRetry" style="margin-left:auto">
    <i class="fas fa-rotate"></i> Try Again
  </button>
</div>

<div id="noSessionAlert" class="alert alert-warning" style="display:none">
  <i class="fas fa-triangle-exclamation"></i>
  <span>There's no active academic session set right now. Please contact your school admin office.</span>
</div>

<div id="noClassesAlert" class="alert alert-warning" style="display:none">
  <i class="fas fa-triangle-exclamation"></i>
  <span>You haven't been assigned to any class yet. Please contact your school admin office.</span>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     SUMMARY STRIP
     ═══════════════════════════════════════════════════════════════ -->
<div class="st-summary" id="summaryStrip">
  <div class="ts-item">
    <div class="ts-value skel-value"><span class="skel-line" style="width:28px"></span></div>
    <div class="ts-label">Classes Assigned</div>
  </div>
  <div class="ts-divider"></div>
  <div class="ts-item">
    <div class="ts-value skel-value"><span class="skel-line" style="width:28px"></span></div>
    <div class="ts-label">Total Students</div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     CLASS SELECTOR + SEARCH
     ═══════════════════════════════════════════════════════════════ -->
<div class="card" id="selectorCard">
  <div class="card-body">
    <div class="st-toolbar">
      <div class="form-group st-tb-field">
        <label class="form-label" for="classSelect">Class</label>
        <select id="classSelect" class="form-control" disabled>
          <option value="">Loading your classes…</option>
        </select>
      </div>
      <div class="form-group st-tb-field">
        <label class="form-label" for="searchInput">Search</label>
        <div class="st-search-wrap">
          <i class="fas fa-magnifying-glass"></i>
          <input type="text" id="searchInput" class="form-control" placeholder="Search by name or student ID…" disabled>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     PROMPT (before any class is chosen)
     ═══════════════════════════════════════════════════════════════ -->
<div id="stPrompt" class="card" style="display:none">
  <div class="empty-state">
    <i class="fas fa-hand-pointer"></i>
    <h3>Choose a class to begin</h3>
    <p>Pick a class above, and your student list will load automatically.</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     LOADING SKELETON
     ═══════════════════════════════════════════════════════════════ -->
<div id="stSkeleton" class="card" style="display:none">
  <div class="empty-state">
    <i class="fas fa-spinner fa-spin"></i>
    <p>Loading student list…</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     EMPTY STATES
     ═══════════════════════════════════════════════════════════════ -->
<div id="stEmptyRoster" class="card" style="display:none">
  <div class="empty-state">
    <i class="fas fa-user-slash"></i>
    <h3>No students in this class</h3>
    <p>There are no active students enrolled in this class for the current session.</p>
  </div>
</div>

<div id="stNoResults" class="card" style="display:none">
  <div class="empty-state">
    <i class="fas fa-magnifying-glass"></i>
    <h3>No matches found</h3>
    <p>Try a different name or student ID.</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     STUDENT LIST
     ═══════════════════════════════════════════════════════════════ -->
<div class="card" id="rosterCard" style="display:none">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-users"></i> <span id="rosterTitle">Student List</span></div>
    <span class="badge badge-green" id="rosterCountBadge"></span>
  </div>
  <div class="table-wrap">
    <table class="ems-table st-table" id="studentTable">
      <thead>
        <tr>
          <th>Student</th>
          <th>Roll</th>
          <th>Father's Name</th>
          <th>Phone</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="studentTableBody"></tbody>
    </table>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     STUDENT DETAIL MODAL  (built dynamically by JS, container here)
     ═══════════════════════════════════════════════════════════════ -->
<div id="studentModalRoot"></div>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE-SPECIFIC STYLES
     ═══════════════════════════════════════════════════════════════ -->
<style>
  .skel-line {
    display: inline-block; height: 22px; border-radius: 6px;
    background: linear-gradient(90deg, var(--border-light) 25%, var(--border) 37%, var(--border-light) 63%);
    background-size: 400% 100%; animation: skelShimmer 1.4s ease infinite; vertical-align: middle;
  }
  @keyframes skelShimmer { 0% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
  #refreshIcon.spinning { animation: refreshSpin .8s linear infinite; }
  @keyframes refreshSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

  /* ── Summary strip ─────────────────────────────────────────────── */
  .st-summary {
    display: flex; align-items: center;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: var(--r-md); padding: 16px 8px;
    margin-bottom: 20px; box-shadow: var(--shadow-card);
  }
  .ts-item { flex: 1; text-align: center; padding: 0 8px; min-width: 0; }
  .ts-value { font-size: 24px; font-weight: 800; color: var(--text); line-height: 1; font-variant-numeric: tabular-nums; }
  .ts-label { font-size: 11.5px; color: var(--text-muted); font-weight: 600; margin-top: 6px; text-transform: uppercase; letter-spacing: .04em; }
  .ts-divider { width: 1px; align-self: stretch; background: var(--border); }

  /* ── Toolbar ────────────────────────────────────────────────────── */
  .st-toolbar { display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap; }
  .st-tb-field { flex: 1; min-width: 220px; margin-bottom: 0; }
  .st-search-wrap { position: relative; }
  .st-search-wrap i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-light); font-size: 14px; }
  .st-search-wrap input { padding-left: 38px; }

  /* ── Student table row ─────────────────────────────────────────── */
  .st-student-cell { display: flex; align-items: center; gap: 12px; min-width: 190px; }
  .st-avatar-photo, .st-avatar-initials {
    width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700; color: #fff;
    object-fit: cover; border: 2px solid var(--border-light);
  }
  .av-c0 { background: #16a34a; } .av-c1 { background: #0284c7; }
  .av-c2 { background: #d97706; } .av-c3 { background: #7c3aed; }
  .av-c4 { background: #dc2626; } .av-c5 { background: #0f766e; }
  .av-c6 { background: #db2777; } .av-c7 { background: #4f46e5; }
  .st-name { font-size: 13.5px; font-weight: 700; color: var(--text); }
  .st-id   { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }

  .st-table td { vertical-align: middle; }
  .st-table .st-muted-cell { color: var(--text-muted); font-size: 13px; }
  .st-view-btn { white-space: nowrap; }

  /* ── Responsive: table → cards on mobile ───────────────────────── */
  @media (max-width: 800px) {
    .st-table thead { display: none; }
    .st-table, .st-table tbody, .st-table tr, .st-table td { display: block; width: 100%; }
    .st-table tr {
      border: 1px solid var(--border-light); border-radius: var(--r-md);
      margin-bottom: 10px; padding: 14px; background: var(--bg-card);
      cursor: pointer; transition: background var(--t);
    }
    .st-table tr:hover { background: var(--primary-xlight); }
    .st-table td { border: none; padding: 3px 0; white-space: normal; }
    .st-table td:nth-child(2), .st-table td:nth-child(3), .st-table td:nth-child(4) { display: none; } /* roll/father/phone folded into card body via JS-injected mini-meta */
    .st-table td:last-child { display: block; margin-top: 8px; }
    .st-mobile-meta { font-size: 12px; color: var(--text-muted); margin-top: 6px; display: flex; flex-wrap: wrap; gap: 4px 12px; }
    .st-mobile-meta span i { margin-right: 4px; color: var(--text-light); }
  }
  @media (min-width: 801px) {
    .st-mobile-meta { display: none; }
    .st-table tr { cursor: default; }
  }

  @media (max-width: 480px) {
    .st-summary { padding: 14px 4px; }
    .ts-value { font-size: 20px; }
    .ts-label { font-size: 10px; }
    .st-toolbar { gap: 12px; }
    .st-tb-field { min-width: 0; flex: 1 1 100%; }
  }

  /* ── Student detail modal ──────────────────────────────────────── */
  .st-modal-bd {
    position: fixed; inset: 0; background: rgba(8,16,10,.6); backdrop-filter: blur(4px);
    z-index: 11000; display: flex; align-items: center; justify-content: center; padding: 20px;
    animation: stmIn .2s ease both;
  }
  @keyframes stmIn { from{opacity:0} to{opacity:1} }
  .st-modal-bd.st-closing { animation: stmOut .18s ease forwards; }
  @keyframes stmOut { from{opacity:1} to{opacity:0} }

  .st-modal {
    background: var(--bg-card); border-radius: var(--r-xl); border: 1px solid var(--border);
    box-shadow: 0 28px 80px rgba(8,16,10,.28);
    width: 100%; max-width: 460px; max-height: 88vh; overflow-y: auto;
    animation: stModIn .26s cubic-bezier(.22,.68,0,1.15) both;
  }
  @keyframes stModIn { from{opacity:0;transform:scale(.92) translateY(14px)} to{opacity:1;transform:none} }
  .st-modal-bd.st-closing .st-modal { animation: stModOut .16s ease forwards; }
  @keyframes stModOut { from{opacity:1;transform:none} to{opacity:0;transform:scale(.96) translateY(8px)} }

  .st-modal-header {
    background: linear-gradient(120deg, var(--primary) 0%, var(--accent) 100%);
    padding: 28px 24px 20px; text-align: center; position: relative;
  }
  .st-modal-close {
    position: absolute; top: 14px; right: 14px;
    width: 32px; height: 32px; border-radius: 50%;
    background: rgba(255,255,255,.18); border: none; color: #fff;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 14px; transition: background var(--t);
  }
  .st-modal-close:hover { background: rgba(255,255,255,.3); }

  .st-modal-photo, .st-modal-initials {
    width: 84px; height: 84px; border-radius: 50%; margin: 0 auto 12px;
    border: 3px solid rgba(255,255,255,.5); object-fit: cover;
    display: flex; align-items: center; justify-content: center;
    font-size: 30px; font-weight: 800; color: #fff;
  }
  .st-modal-name { font-size: 18px; font-weight: 800; color: #fff; letter-spacing: -.01em; }
  .st-modal-id   { font-size: 13px; color: rgba(255,255,255,.85); margin-top: 4px; font-weight: 600; }

  .st-modal-body { padding: 20px 24px 24px; }
  .st-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .st-info-item { min-width: 0; }
  .st-info-item.full { grid-column: 1 / -1; }
  .st-info-label { font-size: 10.5px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
  .st-info-value { font-size: 14px; color: var(--text); font-weight: 600; word-break: break-word; }
  .st-info-value.muted-val { color: var(--text-light); font-weight: 500; font-style: italic; }

  .st-modal-divider { height: 1px; background: var(--border); margin: 18px 0; }
  .st-modal-section-title { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 12px; }

  @media (max-width: 480px) {
    .st-modal { max-width: 100%; margin: 0 4px; }
    .st-info-grid { grid-template-columns: 1fr; gap: 12px; }
  }
</style>

<?php
// Reuse the same CSRF token input pattern established across the portal.
?>
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<?php
$pageJs = <<<'JS'
(function () {
  "use strict";

  const AJAX_URL = "students-ajax.php";
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

  function initials(name) {
    const parts = String(name || "").trim().split(/\s+/);
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

  let classesCache   = [];
  let currentStudents = [];
  let filteredStudents = [];

  function showCard(id, show) {
    const el = $(id);
    if (el) el.style.display = show ? "" : "none";
  }

  function resetPanels() {
    showCard("stPrompt", false);
    showCard("stSkeleton", false);
    showCard("stEmptyRoster", false);
    showCard("stNoResults", false);
    showCard("rosterCard", false);
  }

  // ── Load the teacher's class list ──────────────────────────────────
  async function loadClasses() {
    const data = await post("fetch_classes", {});
    if (!data) {
      $("stErrorText").textContent = "Could not reach the server. Please check your connection.";
      $("stError").style.display = "flex";
      return;
    }
    if (!data.ok) {
      $("stErrorText").textContent = data.msg || "Something went wrong.";
      $("stError").style.display = "flex";
      return;
    }

    $("noSessionAlert").style.display = data.no_session ? "flex" : "none";
    classesCache = data.classes || [];

    if (data.summary) {
      const items = $("summaryStrip").querySelectorAll(".ts-value");
      if (items[0]) { items[0].textContent = data.summary.total_classes;  items[0].classList.remove("skel-value"); }
      if (items[1]) { items[1].textContent = data.summary.total_students; items[1].classList.remove("skel-value"); }
    }

    const select = $("classSelect");
    select.innerHTML = "";

    if (classesCache.length === 0) {
      select.innerHTML = '<option value="">No classes assigned</option>';
      select.disabled = true;
      $("searchInput").disabled = true;
      $("noClassesAlert").style.display = "flex";
      resetPanels();
      return;
    }

    select.disabled = false;
    select.appendChild(new Option("Select a class…", ""));
    classesCache.forEach((c, idx) => {
      const label = c.class_label + (c.group_name ? " (" + c.group_name + ")" : "") + " — " + c.student_count + " students";
      select.appendChild(new Option(label, idx));
    });

    resetPanels();
    showCard("stPrompt", true);
  }

  // ── Load roster for the selected class ─────────────────────────────
  async function loadStudents() {
    const select = $("classSelect");
    const idx = select.value;
    if (idx === "") { resetPanels(); showCard("stPrompt", true); $("searchInput").disabled = true; return; }

    const cls = classesCache[parseInt(idx, 10)];
    if (!cls) return;

    resetPanels();
    showCard("stSkeleton", true);
    $("searchInput").value = "";
    $("searchInput").disabled = true;

    const data = await post("fetch_students", {
      class_id: cls.class_id,
      group_id: cls.group_id === null ? "" : cls.group_id,
    });

    showCard("stSkeleton", false);

    if (!data) {
      $("stErrorText").textContent = "Could not reach the server. Please check your connection.";
      $("stError").style.display = "flex";
      return;
    }
    if (!data.ok) {
      $("stErrorText").textContent = data.msg || "Something went wrong.";
      $("stError").style.display = "flex";
      return;
    }

    currentStudents  = data.students || [];
    filteredStudents = currentStudents;

    const label = (data.class_label || cls.class_label) + (data.group_name || cls.group_name ? " (" + (data.group_name || cls.group_name) + ")" : "");
    $("rosterTitle").textContent = label;

    if (currentStudents.length === 0) {
      showCard("stEmptyRoster", true);
      return;
    }

    $("searchInput").disabled = false;
    showCard("rosterCard", true);
    renderTable();
  }

  // ── Render the student table ────────────────────────────────────────
  function renderTable() {
    const body = $("studentTableBody");
    $("rosterCountBadge").textContent = filteredStudents.length + " of " + currentStudents.length + " student" + (currentStudents.length === 1 ? "" : "s");

    if (filteredStudents.length === 0) {
      body.innerHTML = "";
      showCard("rosterCard", true);
      showCard("stNoResults", true);
      return;
    }
    showCard("stNoResults", false);

    let html = "";
    filteredStudents.forEach((s) => {
      const avatar = s.photo_url
        ? '<img src="' + escapeHtml(s.photo_url) + '" class="st-avatar-photo" alt="" onerror="this.outerHTML=\'<div class=&quot;st-avatar-initials ' + avatarColorClass(s.full_name) + '&quot;>' + initials(s.full_name) + '</div>\'">'
        : '<div class="st-avatar-initials ' + avatarColorClass(s.full_name) + '">' + initials(s.full_name) + '</div>';

      html += '<tr data-enrollment-id="' + s.enrollment_id + '">';
      html += '<td><div class="st-student-cell">' + avatar + '<div><div class="st-name">' + escapeHtml(s.full_name) + '</div><div class="st-id">ID ' + escapeHtml(s.student_id) + '</div>';
      html += '<div class="st-mobile-meta">' +
                (s.roll_number ? '<span><i class="fas fa-hashtag"></i>Roll ' + s.roll_number + '</span>' : '') +
                (s.father_name ? '<span><i class="fas fa-user"></i>' + escapeHtml(s.father_name) + '</span>' : '') +
                (s.phone ? '<span><i class="fas fa-phone"></i>' + escapeHtml(s.phone) + '</span>' : '') +
              '</div>';
      html += '</div></div></td>';
      html += '<td class="st-muted-cell">' + (s.roll_number || '—') + '</td>';
      html += '<td class="st-muted-cell">' + escapeHtml(s.father_name || '—') + '</td>';
      html += '<td class="st-muted-cell">' + escapeHtml(s.phone || '—') + '</td>';
      html += '<td><button type="button" class="btn btn-sm btn-outline st-view-btn" data-view="' + s.enrollment_id + '"><i class="fas fa-eye"></i> View</button></td>';
      html += '</tr>';
    });
    body.innerHTML = html;

    // Whole row is tappable on mobile (card mode); button always works too.
    body.querySelectorAll("tr").forEach((row) => {
      row.addEventListener("click", (e) => {
        if (window.innerWidth > 800 && !e.target.closest(".st-view-btn")) return;
        const eid = parseInt(row.dataset.enrollmentId, 10);
        openStudentModal(eid);
      });
    });
    body.querySelectorAll(".st-view-btn").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        openStudentModal(parseInt(btn.dataset.view, 10));
      });
    });
  }

  // ── Live search filter (client-side, instant) ──────────────────────
  $("searchInput").addEventListener("input", () => {
    const q = $("searchInput").value.trim().toLowerCase();
    filteredStudents = !q ? currentStudents : currentStudents.filter((s) =>
      (s.full_name || "").toLowerCase().includes(q) || (s.student_id || "").toLowerCase().includes(q)
    );
    renderTable();
  });

  // ── Student detail modal ────────────────────────────────────────────
  function openStudentModal(enrollmentId) {
    const s = currentStudents.find((x) => x.enrollment_id === enrollmentId);
    if (!s) return;

    const root = $("studentModalRoot");
    const avatar = s.photo_url
      ? '<img src="' + escapeHtml(s.photo_url) + '" class="st-modal-photo" alt="">'
      : '<div class="st-modal-initials ' + avatarColorClass(s.full_name) + '">' + initials(s.full_name) + '</div>';

    function field(value) {
      return value ? escapeHtml(value) : '<span class="muted-val">Not provided</span>';
    }

    root.innerHTML =
      '<div class="st-modal-bd" id="stModalBd" role="dialog" aria-modal="true" aria-labelledby="stModalName">' +
        '<div class="st-modal">' +
          '<div class="st-modal-header">' +
            '<button type="button" class="st-modal-close" id="stModalClose" aria-label="Close"><i class="fas fa-xmark"></i></button>' +
            avatar +
            '<div class="st-modal-name" id="stModalName">' + escapeHtml(s.full_name) + '</div>' +
            '<div class="st-modal-id">ID ' + escapeHtml(s.student_id) + (s.roll_number ? ' &middot; Roll ' + s.roll_number : '') + '</div>' +
          '</div>' +
          '<div class="st-modal-body">' +
            '<div class="st-modal-section-title">Personal Info</div>' +
            '<div class="st-info-grid">' +
              '<div class="st-info-item"><div class="st-info-label">Date of Birth</div><div class="st-info-value' + (s.dob ? '' : ' muted-val') + '">' + field(s.dob) + (s.age !== null && s.dob ? ' (' + s.age + ' yrs)' : '') + '</div></div>' +
              '<div class="st-info-item"><div class="st-info-label">Student ID</div><div class="st-info-value">' + escapeHtml(s.student_id) + '</div></div>' +
            '</div>' +
            '<div class="st-modal-divider"></div>' +
            '<div class="st-modal-section-title">Contact</div>' +
            '<div class="st-info-grid">' +
              '<div class="st-info-item"><div class="st-info-label">Phone</div><div class="st-info-value' + (s.phone ? '' : ' muted-val') + '">' + field(s.phone) + '</div></div>' +
              '<div class="st-info-item"><div class="st-info-label">Alt. Phone</div><div class="st-info-value' + (s.phone2 ? '' : ' muted-val') + '">' + field(s.phone2) + '</div></div>' +
              '<div class="st-info-item full"><div class="st-info-label">Email</div><div class="st-info-value' + (s.email ? '' : ' muted-val') + '">' + field(s.email) + '</div></div>' +
              '<div class="st-info-item full"><div class="st-info-label">Address</div><div class="st-info-value' + (s.address ? '' : ' muted-val') + '">' + field(s.address) + '</div></div>' +
            '</div>' +
            '<div class="st-modal-divider"></div>' +
            '<div class="st-modal-section-title">Parents / Guardian</div>' +
            '<div class="st-info-grid">' +
              '<div class="st-info-item"><div class="st-info-label">Father\'s Name</div><div class="st-info-value' + (s.father_name ? '' : ' muted-val') + '">' + field(s.father_name) + '</div></div>' +
              '<div class="st-info-item"><div class="st-info-label">Mother\'s Name</div><div class="st-info-value' + (s.mother_name ? '' : ' muted-val') + '">' + field(s.mother_name) + '</div></div>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>';

    const bd = $("stModalBd");
    function closeModal() {
      bd.classList.add("st-closing");
      setTimeout(() => { root.innerHTML = ""; }, 200);
    }
    $("stModalClose").addEventListener("click", closeModal);
    bd.addEventListener("click", (e) => { if (e.target === bd) closeModal(); });
    document.addEventListener("keydown", function escHandler(e) {
      if (e.key === "Escape") { closeModal(); document.removeEventListener("keydown", escHandler); }
    });
  }

  // ── Wire up selector + refresh ─────────────────────────────────────
  $("classSelect").addEventListener("change", loadStudents);

  $("refreshBtn").addEventListener("click", async () => {
    const icon = $("refreshIcon");
    if (icon) icon.classList.add("spinning");
    const keepIdx = $("classSelect").value;
    await loadClasses();
    if (keepIdx !== "" && classesCache[parseInt(keepIdx, 10)]) {
      $("classSelect").value = keepIdx;
      await loadStudents();
    }
    if (icon) icon.classList.remove("spinning");
    if (window.showToast) window.showToast("success", "Refreshed", "Student list is up to date.");
  });

  $("stErrorRetry").addEventListener("click", () => {
    $("stError").style.display = "none";
    loadClasses();
  });

  // ── Initial load ──────────────────────────────────────────────────────
  loadClasses();

})();
JS;

require_once __DIR__ . '/includes/teacher-footer.php';
?>
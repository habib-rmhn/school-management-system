<?php
/**
 * EMS Teacher — Attendance  v1.0
 * ─────────────────────────────────────────────────────────────────
 * Mark or update attendance for any class the teacher is assigned
 * to, on any date. Built for speed: every student defaults to
 * "Present" the moment the roster loads (or their existing record,
 * if that date was already marked) — the teacher only has to tap
 * the few who are Absent or Late, not fill in every row by hand.
 *
 * All data loads via AJAX (attendance-ajax.php) — no page reload.
 * Arriving from today.php's "Attendance" button pre-selects the
 * right class via ?class_id=&group_id= query parameters.
 */
require_once __DIR__ . '/../includes/teacher-session.php';

$pageTitle  = 'Attendance';
$activeNav  = 'attendance';
$breadcrumb = ['Attendance'];

// Pre-selection hints from today.php / dashboard.php quick links.
$presetClassId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : null;
$presetGroupRaw = $_GET['group_id'] ?? '';
$presetGroupId = ($presetGroupRaw === '' || $presetGroupRaw === 'null') ? null : (int)$presetGroupRaw;

$todayIso = date('Y-m-d');

require_once __DIR__ . '/includes/teacher-sidebar.php';
?>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="page-header-top">
    <div>
      <div class="page-title"><i class="fas fa-calendar-check" style="color:var(--primary);margin-right:8px"></i>Attendance</div>
      <div class="page-sub"><i class="fas fa-circle-info"></i> Mark or update attendance for any of your classes</div>
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
<div id="attError" class="alert alert-danger" style="display:none">
  <i class="fas fa-circle-exclamation"></i>
  <span id="attErrorText">Something went wrong.</span>
  <button type="button" class="btn btn-sm btn-outline" id="attErrorRetry" style="margin-left:auto">
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
     CLASS + DATE SELECTOR
     ═══════════════════════════════════════════════════════════════ -->
<div class="card" id="selectorCard">
  <div class="card-body">
    <div class="att-toolbar">
      <div class="form-group att-tb-field">
        <label class="form-label" for="classSelect">Class</label>
        <select id="classSelect" class="form-control" disabled>
          <option value="">Loading your classes…</option>
        </select>
      </div>
      <div class="form-group att-tb-field att-tb-date">
        <label class="form-label" for="dateInput">Date</label>
        <input type="date" id="dateInput" class="form-control" value="<?= h($todayIso) ?>" max="<?= h($todayIso) ?>">
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     ALREADY MARKED BANNER (info) — shown when a record exists for
     the selected class+date
     ═══════════════════════════════════════════════════════════════ -->
<div id="alreadyMarkedAlert" class="alert alert-info" style="display:none">
  <i class="fas fa-clock-rotate-left"></i>
  <span id="alreadyMarkedText"></span>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     PROMPT (before any class is chosen)
     ═══════════════════════════════════════════════════════════════ -->
<div id="attPrompt" class="card" style="display:none">
  <div class="empty-state">
    <i class="fas fa-hand-pointer"></i>
    <h3>Choose a class to begin</h3>
    <p>Pick a class and a date above, and your student list will load automatically.</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     LOADING SKELETON
     ═══════════════════════════════════════════════════════════════ -->
<div id="attSkeleton" class="card" style="display:none">
  <div class="empty-state">
    <i class="fas fa-spinner fa-spin"></i>
    <p>Loading student list…</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     EMPTY ROSTER
     ═══════════════════════════════════════════════════════════════ -->
<div id="attEmptyRoster" class="card" style="display:none">
  <div class="empty-state">
    <i class="fas fa-user-slash"></i>
    <h3>No students in this class</h3>
    <p>There are no active students enrolled in this class for the current session.</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     BULK ACTIONS + LIVE COUNTS
     ═══════════════════════════════════════════════════════════════ -->
<div class="card att-bulkbar-card" id="bulkBarWrap" style="display:none">
  <div class="att-bulkbar">
    <div class="att-bulk-actions">
      <button type="button" class="btn btn-sm btn-outline" id="markAllPresentBtn">
        <i class="fas fa-check"></i> All Present
      </button>
      <button type="button" class="btn btn-sm btn-outline" id="markAllAbsentBtn">
        <i class="fas fa-xmark"></i> All Absent
      </button>
    </div>
    <div class="att-counts" id="attCounts">
      <span class="att-count-pill acp-present"><i class="fas fa-check"></i> <b id="cntPresent">0</b> Present</span>
      <span class="att-count-pill acp-absent"><i class="fas fa-xmark"></i> <b id="cntAbsent">0</b> Absent</span>
      <span class="att-count-pill acp-late"><i class="fas fa-clock"></i> <b id="cntLate">0</b> Late</span>
    </div>
    <button type="button" class="btn btn-primary" id="saveBtnTop">
      <i class="fas fa-floppy-disk"></i> Save Attendance
    </button>
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
  <div id="rosterBody"></div>
  <div class="card-footer att-savebar">
    <div class="att-counts" id="attCountsBottom">
      <span class="att-count-pill acp-present"><i class="fas fa-check"></i> <b id="cntPresentBottom">0</b> Present</span>
      <span class="att-count-pill acp-absent"><i class="fas fa-xmark"></i> <b id="cntAbsentBottom">0</b> Absent</span>
      <span class="att-count-pill acp-late"><i class="fas fa-clock"></i> <b id="cntLateBottom">0</b> Late</span>
    </div>
    <button type="button" class="btn btn-primary btn-lg" id="saveBtnBottom">
      <i class="fas fa-floppy-disk"></i> Save Attendance
    </button>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE-SPECIFIC STYLES
     ═══════════════════════════════════════════════════════════════ -->
<style>
  #refreshIcon.spinning { animation: refreshSpin .8s linear infinite; }
  @keyframes refreshSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

  /* ── Toolbar: class + date selectors ───────────────────────────── */
  .att-toolbar { display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap; }
  .att-tb-field { flex: 1; min-width: 200px; margin-bottom: 0; }
  .att-tb-date  { flex: 0 0 200px; }

  /* ── Bulk actions bar ───────────────────────────────────────────── */
  .att-bulkbar-card { margin-bottom: 16px; }
  .att-bulkbar {
    display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
    padding: 16px 20px;
  }
  .att-bulk-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .att-counts { display: flex; gap: 10px; flex-wrap: wrap; margin-left: auto; }

  .att-count-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px; border-radius: 20px;
    font-size: 12.5px; font-weight: 700; white-space: nowrap;
  }
  .att-count-pill i { font-size: 10px; }
  .acp-present { background: var(--green-bg); color: var(--green-dark); }
  .acp-absent  { background: var(--red-bg);   color: var(--red); }
  .acp-late    { background: var(--amber-bg); color: var(--amber); }

  /* ── Student roster rows ────────────────────────────────────────── */
  .att-row {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 20px; border-bottom: 1px solid var(--border-light);
    transition: background var(--t);
  }
  .att-row:last-child { border-bottom: none; }
  .att-row:hover { background: var(--primary-xlight); }

  .att-avatar-photo, .att-avatar-initials {
    width: 42px; height: 42px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; font-weight: 700; color: #fff;
    object-fit: cover; border: 2px solid var(--border-light);
  }
  .av-c0 { background: #16a34a; } .av-c1 { background: #0284c7; }
  .av-c2 { background: #d97706; } .av-c3 { background: #7c3aed; }
  .av-c4 { background: #dc2626; } .av-c5 { background: #0f766e; }
  .av-c6 { background: #db2777; } .av-c7 { background: #4f46e5; }

  .att-info { flex: 1; min-width: 0; }
  .att-name { font-size: 14px; font-weight: 700; color: var(--text); }
  .att-meta { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

  /* ── 3-way segmented status control ────────────────────────────── */
  .att-seg { display: flex; gap: 6px; flex-shrink: 0; }
  .att-seg-btn {
    display: flex; align-items: center; justify-content: center; gap: 5px;
    height: 38px; min-width: 44px; padding: 0 12px;
    border-radius: var(--r-sm); border: 1.5px solid var(--border);
    background: #fff; color: var(--text-muted);
    font-size: 12.5px; font-weight: 700; cursor: pointer;
    transition: all var(--t); white-space: nowrap;
  }
  .att-seg-btn i { font-size: 12px; }
  .att-seg-btn:hover { border-color: var(--text-light); }

  .att-seg-btn.active.st-present { background: var(--green); border-color: var(--green); color: #fff; }
  .att-seg-btn.active.st-absent  { background: var(--red);   border-color: var(--red);   color: #fff; }
  .att-seg-btn.active.st-late    { background: var(--amber); border-color: var(--amber); color: #fff; }

  /* ── Bottom save bar (inside card footer) ──────────────────────── */
  .att-savebar { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }

  /* ── Responsive ─────────────────────────────────────────────────── */
  @media (max-width: 640px) {
    .att-toolbar { gap: 12px; }
    .att-tb-field, .att-tb-date { flex: 1 1 100%; min-width: 0; }

    .att-bulkbar { flex-direction: column; align-items: stretch; }
    .att-bulk-actions { justify-content: stretch; }
    .att-bulk-actions .btn { flex: 1; }
    .att-counts { margin-left: 0; justify-content: center; }
    .att-bulkbar > .btn { width: 100%; }

    .att-row { padding: 12px 14px; gap: 10px; }
    .att-avatar-photo, .att-avatar-initials { width: 36px; height: 36px; font-size: 13px; }
    .att-name { font-size: 13px; }
    .att-meta { font-size: 11px; }

    .att-seg-btn { min-width: 0; padding: 0 8px; height: 36px; font-size: 11px; }
    .att-seg-btn span.seg-label { display: none; }

    .att-savebar { flex-direction: column; align-items: stretch; }
    .att-savebar .att-counts { justify-content: center; margin-left: 0; }
    .att-savebar .btn { width: 100%; }
  }

  @media (max-width: 400px) {
    .att-count-pill { font-size: 11px; padding: 5px 9px; }
  }
</style>

<!-- CSRF token for AJAX requests -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<?php
// ── Page-specific JS — passed to the footer's $pageJs slot ────────
$presetClassIdJson  = json_encode($presetClassId);
$presetGroupIdJson  = json_encode($presetGroupId);

$pageJs = <<<JS
(function () {
  "use strict";

  const AJAX_URL = "attendance-ajax.php";
  function getToken() { return document.getElementById("csrfToken")?.value || ""; }
  let csrfToken = getToken();

  const \$ = (id) => document.getElementById(id);
  const escapeHtml = (str) => String(str ?? "").replace(/[&<>"']/g, (c) => ({
    "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;"
  }[c]));

  const PRESET_CLASS_ID = {$presetClassIdJson};
  const PRESET_GROUP_ID = {$presetGroupIdJson};

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

  let currentStudents = [];   // [{enrollment_id, student_id, full_name, roll_number, photo_url, status}]
  let classesCache    = [];

  function showCard(id, show) {
    const el = \$(id);
    if (el) el.style.display = show ? "" : "none";
  }

  function resetPanels() {
    showCard("attPrompt", false);
    showCard("attSkeleton", false);
    showCard("attEmptyRoster", false);
    showCard("bulkBarWrap", false);
    showCard("rosterCard", false);
    \$("alreadyMarkedAlert").style.display = "none";
  }

  // ── Load the teacher's class list into the dropdown ────────────────
  async function loadClasses() {
    const data = await post("fetch_classes", {});
    if (!data) {
      \$("attErrorText").textContent = "Could not reach the server. Please check your connection.";
      \$("attError").style.display = "flex";
      return;
    }
    if (!data.ok) {
      \$("attErrorText").textContent = data.msg || "Something went wrong.";
      \$("attError").style.display = "flex";
      return;
    }

    \$("noSessionAlert").style.display = data.no_session ? "flex" : "none";
    classesCache = data.classes || [];

    const select = \$("classSelect");
    select.innerHTML = "";
    select.disabled = false;

    if (classesCache.length === 0) {
      select.innerHTML = '<option value="">No classes assigned</option>';
      select.disabled = true;
      \$("noClassesAlert").style.display = "flex";
      resetPanels();
      return;
    }

    select.appendChild(new Option("Select a class…", ""));
    classesCache.forEach((c, idx) => {
      const label = c.class_label + (c.group_name ? " (" + c.group_name + ")" : "");
      const opt = new Option(label, idx);
      select.appendChild(opt);
    });

    // Try to honour ?class_id=&group_id= from Today's Classes / Dashboard
    if (PRESET_CLASS_ID !== null) {
      const match = classesCache.findIndex((c) =>
        c.class_id === PRESET_CLASS_ID &&
        (PRESET_GROUP_ID === null ? c.group_id === null : c.group_id === PRESET_GROUP_ID)
      );
      if (match !== -1) {
        select.value = String(match);
        loadStudents();
        return;
      }
    }

    resetPanels();
    showCard("attPrompt", true);
  }

  // ── Load roster for the selected class + date ───────────────────────
  async function loadStudents() {
    const select = \$("classSelect");
    const idx = select.value;
    if (idx === "") { resetPanels(); showCard("attPrompt", true); return; }

    const cls = classesCache[parseInt(idx, 10)];
    if (!cls) return;

    resetPanels();
    showCard("attSkeleton", true);

    const dateVal = \$("dateInput").value;
    const data = await post("fetch_students", {
      class_id: cls.class_id,
      group_id: cls.group_id === null ? "" : cls.group_id,
      att_date: dateVal,
    });

    showCard("attSkeleton", false);

    if (!data) {
      \$("attErrorText").textContent = "Could not reach the server. Please check your connection.";
      \$("attError").style.display = "flex";
      return;
    }
    if (!data.ok) {
      \$("attErrorText").textContent = data.msg || "Something went wrong.";
      \$("attError").style.display = "flex";
      return;
    }

    currentStudents = data.students || [];

    const label = (data.class_label || cls.class_label) + (data.group_name || cls.group_name ? " (" + (data.group_name || cls.group_name) + ")" : "");
    \$("rosterTitle").textContent = label;

    if (data.already_marked && data.marked_info) {
      \$("alreadyMarkedText").innerHTML =
        "Attendance for this date was already recorded &mdash; last updated by <strong>" +
        escapeHtml(data.marked_info.by) + "</strong> at <strong>" + escapeHtml(data.marked_info.at) +
        "</strong>. Saving again will update it.";
      \$("alreadyMarkedAlert").style.display = "flex";
    }

    if (currentStudents.length === 0) {
      showCard("attEmptyRoster", true);
      return;
    }

    showCard("bulkBarWrap", true);
    showCard("rosterCard", true);
    \$("rosterCountBadge").textContent = currentStudents.length + " student" + (currentStudents.length === 1 ? "" : "s");

    renderRoster();
  }

  // ── Render the student roster rows ──────────────────────────────────
  function renderRoster() {
    const wrap = \$("rosterBody");
    let html = "";
    currentStudents.forEach((s) => {
      const avatar = s.photo_url
        ? '<img src="' + escapeHtml(s.photo_url) + '" class="att-avatar-photo" alt="" onerror="this.outerHTML=\\'<div class=&quot;att-avatar-initials ' + avatarColorClass(s.full_name) + '&quot;>' + initials(s.full_name) + '</div>\\'">'
        : '<div class="att-avatar-initials ' + avatarColorClass(s.full_name) + '">' + initials(s.full_name) + '</div>';

      html += '<div class="att-row" data-enrollment-id="' + s.enrollment_id + '">';
      html += avatar;
      html += '<div class="att-info">';
      html += '<div class="att-name">' + escapeHtml(s.full_name) + '</div>';
      html += '<div class="att-meta">ID ' + escapeHtml(s.student_id) + (s.roll_number ? ' &middot; Roll ' + s.roll_number : '') + '</div>';
      html += '</div>';
      html += '<div class="att-seg" data-enrollment-id="' + s.enrollment_id + '">';
      html += '<div class="att-seg-btn st-present' + (s.status === "present" ? " active" : "") + '" data-status="present"><i class="fas fa-check"></i><span class="seg-label">Present</span></div>';
      html += '<div class="att-seg-btn st-absent' + (s.status === "absent" ? " active" : "") + '" data-status="absent"><i class="fas fa-xmark"></i><span class="seg-label">Absent</span></div>';
      html += '<div class="att-seg-btn st-late' + (s.status === "late" ? " active" : "") + '" data-status="late"><i class="fas fa-clock"></i><span class="seg-label">Late</span></div>';
      html += '</div>';
      html += '</div>';
    });
    wrap.innerHTML = html;

    wrap.querySelectorAll(".att-seg").forEach((seg) => {
      seg.querySelectorAll(".att-seg-btn").forEach((btn) => {
        btn.addEventListener("click", () => {
          const eid = parseInt(seg.dataset.enrollmentId, 10);
          const status = btn.dataset.status;
          const student = currentStudents.find((s) => s.enrollment_id === eid);
          if (student) student.status = status;
          seg.querySelectorAll(".att-seg-btn").forEach((b) => b.classList.toggle("active", b === btn));
          updateCounts();
        });
      });
    });

    updateCounts();
  }

  // ── Live P/A/L counters ──────────────────────────────────────────────
  function updateCounts() {
    const counts = { present: 0, absent: 0, late: 0 };
    currentStudents.forEach((s) => { if (counts[s.status] !== undefined) counts[s.status]++; });
    ["cntPresent", "cntAbsent", "cntLate"].forEach((id, i) => {
      const key = ["present", "absent", "late"][i];
      const el = \$(id); if (el) el.textContent = counts[key];
      const elB = \$(id + "Bottom"); if (elB) elB.textContent = counts[key];
    });
  }

  // ── Bulk actions ──────────────────────────────────────────────────────
  function markAll(status) {
    currentStudents.forEach((s) => { s.status = status; });
    renderRoster();
  }
  \$("markAllPresentBtn").addEventListener("click", () => markAll("present"));
  \$("markAllAbsentBtn").addEventListener("click", () => markAll("absent"));

  // ── Save ────────────────────────────────────────────────────────────
  let isSaving = false;

  async function saveAttendance() {
    if (isSaving || currentStudents.length === 0) return;
    isSaving = true;

    const select = \$("classSelect");
    const cls = classesCache[parseInt(select.value, 10)];
    if (!cls) { isSaving = false; return; }

    [\$("saveBtnTop"), \$("saveBtnBottom")].forEach((b) => {
      if (b) { b.disabled = true; b.dataset.origHtml = b.innerHTML; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…'; }
    });

    const records = currentStudents.map((s) => ({ enrollment_id: s.enrollment_id, status: s.status }));

    const data = await post("save_attendance", {
      class_id: cls.class_id,
      group_id: cls.group_id === null ? "" : cls.group_id,
      att_date: \$("dateInput").value,
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

    const msg = data.counts.present + " present, " + data.counts.absent + " absent, " + data.counts.late + " late — " + data.class_label + ".";
    if (window.showToast) window.showToast("success", "Attendance Saved", msg);

    // Refresh the "already marked" banner with the fresh save info
    \$("alreadyMarkedText").innerHTML =
      "Attendance for this date was already recorded &mdash; last updated by <strong>You</strong> at <strong>" + escapeHtml(data.marked_info.at) + "</strong>. Saving again will update it.";
    \$("alreadyMarkedAlert").style.display = "flex";
  }

  \$("saveBtnTop").addEventListener("click", saveAttendance);
  \$("saveBtnBottom").addEventListener("click", saveAttendance);

  // ── Wire up class/date change + refresh ──────────────────────────────
  \$("classSelect").addEventListener("change", loadStudents);
  \$("dateInput").addEventListener("change", loadStudents);

  \$("refreshBtn").addEventListener("click", async () => {
    const icon = \$("refreshIcon");
    if (icon) icon.classList.add("spinning");
    await loadClasses();
    if (\$("classSelect").value !== "") await loadStudents();
    if (icon) icon.classList.remove("spinning");
    if (window.showToast) window.showToast("success", "Refreshed", "Class list is up to date.");
  });

  \$("attErrorRetry").addEventListener("click", () => {
    \$("attError").style.display = "none";
    loadClasses();
  });

  // ── Initial load ──────────────────────────────────────────────────────
  loadClasses();

})();
JS;

require_once __DIR__ . '/includes/teacher-footer.php';
?>
<?php
/**
 * EMS Admin — Assign Teacher to Subjects
 * ─────────────────────────────────────────────────────────────────
 * How it works:
 *  1. Admin picks Session + Class (+ Group if class 9-10)
 *  2. All subjects for that class load as cards — each has a
 *     teacher dropdown showing the currently assigned teacher
 *  3. Admin picks/changes teachers for each subject
 *  4. "Save All" button saves everything in one AJAX call
 *  5. Summary tab shows all assignments across all classes
 *
 * Assignments are stored in class_routine (placeholder rows when
 * no timetable exists yet). The routine builder can later add
 * real time slots — teacher assignments carry over automatically.
 */
require_once __DIR__ . '/../includes/admin-session.php';

$pageTitle  = 'Assign Teachers';
$activeNav  = 'assign-teacher';
$breadcrumb = ['People', ['label' => 'Teachers', 'url' => BASE_URL . '/admin/teachers.php'], 'Assign Teachers'];

function safeRows(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); } catch (Throwable $e) { return []; }
}

$sessions = safeRows("SELECT session_id, session_year, label, is_current FROM sessions ORDER BY session_year DESC");
$classes  = safeRows("SELECT class_id, class_num, label FROM classes ORDER BY class_num");
$groups   = safeRows("SELECT group_id, group_name FROM groups ORDER BY group_id");

$currentSession = null;
foreach ($sessions as $s) { if ($s['is_current']) { $currentSession = $s; break; } }

require_once __DIR__ . '/includes/admin-sidebar.php';
?>

<style>
/* ═══════════════════════════════════════════════════════════════
   ASSIGN TEACHER PAGE
   ═══════════════════════════════════════════════════════════════ */

/* ── Page tabs ──────────────────────────────────────────────────── */
.page-tabs {
  display: flex; gap: 0;
  border-bottom: 2px solid var(--border);
  margin-bottom: 24px;
}
.page-tab {
  padding: 10px 22px;
  font-size: 13.5px; font-weight: 600;
  color: var(--text-muted); cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  transition: color .15s, border-color .15s;
  display: flex; align-items: center; gap: 8px;
  user-select: none; background: none; border-top: none;
  border-left: none; border-right: none; font-family: inherit;
}
.page-tab:hover  { color: var(--text); }
.page-tab.active { color: var(--primary); border-bottom-color: var(--primary); }
.page-tab .tab-badge {
  font-size: 10.5px; font-weight: 700; padding: 1px 7px;
  border-radius: 10px; background: var(--bg); color: var(--text-muted);
}
.page-tab.active .tab-badge { background: var(--primary-xlight); color: var(--primary); }

/* ── Selector panel ─────────────────────────────────────────────── */
.selector-panel {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  padding: 20px 24px;
  margin-bottom: 22px;
  display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap;
}
.sel-group { display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 150px; }
.sel-group label { font-size: 11.5px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; }
.sel-ctrl {
  height: 40px; border: 1.5px solid var(--border); border-radius: var(--r-sm);
  font-family: inherit; font-size: 13.5px; color: var(--text);
  background: var(--bg-card); padding: 0 12px; outline: none; width: 100%;
  transition: border-color .15s, box-shadow .15s;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 12px center; padding-right: 32px;
}
.sel-ctrl:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.sel-ctrl:disabled { background: var(--bg-elevated); color: var(--text-muted); cursor: not-allowed; }

/* ── Context bar ────────────────────────────────────────────────── */
.context-bar {
  display: flex; align-items: center; justify-content: space-between;
  gap: 14px; margin-bottom: 18px; flex-wrap: wrap;
}
.context-info {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.context-label {
  font-size: 15px; font-weight: 700; color: var(--text);
  display: flex; align-items: center; gap: 8px;
}
.ctx-badge {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 12px; font-weight: 600; padding: 4px 10px;
  border-radius: 20px;
}
.context-actions { display: flex; align-items: center; gap: 8px; }

/* ── Progress indicator ─────────────────────────────────────────── */
.assign-progress {
  display: flex; align-items: center; gap: 12px;
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--r-md); padding: 14px 20px; margin-bottom: 18px;
  flex-wrap: wrap; gap: 16px;
}
.prog-item {
  display: flex; flex-direction: column; align-items: center;
  gap: 3px; min-width: 70px;
}
.prog-val { font-size: 22px; font-weight: 800; color: var(--text); letter-spacing: -.02em; }
.prog-lbl { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; }
.prog-bar-wrap {
  flex: 1; min-width: 120px;
}
.prog-bar-track { height: 8px; background: var(--border); border-radius: 4px; overflow: hidden; }
.prog-bar-fill  { height: 100%; border-radius: 4px; background: var(--green); transition: width .4s ease; }
.prog-pct { font-size: 12.5px; font-weight: 700; color: var(--text-muted); margin-top: 4px; }
.prog-divider { width: 1px; background: var(--border); align-self: stretch; min-height: 40px; }

/* ── Subject cards grid ─────────────────────────────────────────── */
.subjects-assign-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 14px;
}

.subj-assign-card {
  background: var(--bg-card);
  border: 1.5px solid var(--border);
  border-radius: var(--r-md);
  overflow: hidden;
  transition: border-color .15s, box-shadow .15s;
  position: relative;
}

.subj-assign-card:hover { box-shadow: 0 2px 12px rgba(37,99,235,.08); }

.subj-assign-card.assigned {
  border-color: var(--green-border);
}

.subj-assign-card.assigned::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0;
  height: 3px; background: var(--green);
}

.subj-assign-card.unassigned::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0;
  height: 3px; background: var(--border);
}

.sac-head {
  padding: 14px 16px 10px;
  display: flex; align-items: flex-start; gap: 10px;
}

.sac-icon {
  width: 38px; height: 38px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; flex-shrink: 0;
}

.sac-info { flex: 1; min-width: 0; }
.sac-name { font-size: 14px; font-weight: 700; color: var(--text); line-height: 1.3; }
.sac-code { font-size: 11px; font-weight: 700; font-family: monospace; color: var(--text-muted); margin-top: 2px; }
.sac-group-tag {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 10.5px; font-weight: 700; padding: 2px 7px;
  border-radius: 4px; margin-top: 4px;
}
.sgt-all      { background: var(--primary-xlight); color: var(--primary); }
.sgt-Science  { background: #ecfdf5; color: #065f46; }
.sgt-Arts     { background: var(--amber-bg); color: #78350f; }
.sgt-Commerce { background: var(--purple-bg); color: #5b21b6; }

.sac-body { padding: 0 16px 16px; }

.sac-label {
  font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: .06em; color: var(--text-muted); margin-bottom: 6px;
  display: flex; align-items: center; justify-content: space-between;
}

/* Teacher dropdown inside card */
.teacher-select {
  width: 100%; height: 38px;
  border: 1.5px solid var(--border); border-radius: var(--r-sm);
  font-family: inherit; font-size: 13px; color: var(--text);
  background: var(--bg-card); padding: 0 32px 0 10px; outline: none;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 10px center;
  transition: border-color .15s, box-shadow .15s;
}
.teacher-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.teacher-select.has-teacher { border-color: var(--green-border); background-color: #fafffe; }

/* Current assignment pill */
.current-assign-pill {
  display: flex; align-items: center; gap: 8px;
  background: var(--green-bg); border: 1px solid var(--green-border);
  border-radius: var(--r-sm); padding: 7px 10px; margin-bottom: 8px;
}
.cap-avatar {
  width: 26px; height: 26px; border-radius: 50%; object-fit: cover;
  background: var(--green-border); flex-shrink: 0;
  display: block;
}
.cap-name   { font-size: 13px; font-weight: 600; color: var(--green-dark); flex: 1; }
.cap-desig  { font-size: 11px; color: var(--green); }
.cap-remove {
  width: 22px; height: 22px; border-radius: 50%;
  background: none; border: 1px solid var(--green-border);
  color: var(--green); font-size: 10px; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; transition: all .13s;
}
.cap-remove:hover { background: var(--red-bg); border-color: var(--red); color: var(--red); }

/* ── Empty/loading state ─────────────────────────────────────────── */
.assign-placeholder {
  text-align: center; padding: 56px 24px; color: var(--text-muted);
}
.assign-placeholder i { font-size: 52px; color: var(--text-light); display: block; margin: 0 auto 12px; }
.assign-placeholder h3 { font-size: 18px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.assign-placeholder p  { font-size: 13.5px; line-height: 1.6; max-width: 420px; margin: 0 auto; }

/* ── Summary tab ─────────────────────────────────────────────────── */
.summary-class-block { margin-bottom: 24px; }
.summary-class-hd {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 16px; background: var(--bg-elevated);
  border: 1px solid var(--border); border-radius: var(--r-sm);
  margin-bottom: 8px; font-size: 14px; font-weight: 700; color: var(--text);
}
.summary-class-badge {
  width: 30px; height: 30px; border-radius: 7px;
  background: var(--primary); color: #fff;
  font-size: 13px; font-weight: 800;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.summary-row {
  display: flex; align-items: center; gap: 12px;
  background: var(--bg-card); border: 1px solid var(--border-light);
  border-radius: var(--r-sm); padding: 10px 14px; margin-bottom: 5px;
  flex-wrap: wrap;
}
.sr-subject { font-size: 13.5px; font-weight: 600; color: var(--text); flex: 1; min-width: 120px; }
.sr-teacher { font-size: 13px; color: var(--text-muted); display: flex; align-items: center; gap: 6px; }
.sr-teacher strong { color: var(--text); }
.sr-code { font-size: 11px; font-family: monospace; color: var(--text-light); }

/* ── Save bar ────────────────────────────────────────────────────── */
.save-bar {
  position: sticky; bottom: 0;
  background: var(--bg-card); border-top: 1px solid var(--border);
  padding: 14px; margin-top: 20px; border-radius: 10px;
  display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
  z-index: 100;
  box-shadow: 0 -4px 20px rgba(16,24,40,.08);
}
.save-bar-inner {
  display: flex; align-items: center; gap: 14px; width: 100%; flex-wrap: wrap;
}
.save-note { font-size: 12.5px; color: var(--text-muted); display: flex; align-items: center; gap: 6px; }

/* Unsaved indicator */
.unsaved-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--amber); display: inline-block; margin-right: 4px;
  animation: pulse 1.5s infinite;
}
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }

/* Spinner */
.spin { animation: spin .7s linear infinite; display: inline-block; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Responsive ─────────────────────────────────────────────────── */
@media (max-width: 900px) {
  .subjects-assign-grid { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }
}
@media (max-width: 640px) {
  .selector-panel { flex-direction: column; }
  .sel-group { min-width: 0; }
  .subjects-assign-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
  .assign-progress { gap: 10px; }
  .prog-divider { display: none; }
  .page-tab { padding: 9px 14px; font-size: 12.5px; }
}
@media (max-width: 440px) {
  .subjects-assign-grid { grid-template-columns: 1fr; }
  .page-tabs { overflow-x: auto; -webkit-overflow-scrolling: touch; }
}
</style>

<!-- ══ Page header ════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="page-header-top">
    <div>
      <div class="page-title">Assign Teachers</div>
      <div class="page-sub">
        <i class="fas fa-link"></i>
        Assign a teacher to each subject per class &amp; session
      </div>
    </div>
    <div class="page-actions" id="headerActions" style="display:none">
      <button class="btn btn-primary" onclick="saveAll()" id="headerSaveBtn">
        <i class="fas fa-check"></i> Save All Assignments
      </button>
    </div>
  </div>
</div>

<!-- ══ Page tabs ══════════════════════════════════════════════════ -->
<div class="page-tabs">
  <button class="page-tab active" onclick="switchTab('assign')" id="tabAssign">
    <i class="fas fa-link"></i> Assign
  </button>
  <button class="page-tab" onclick="switchTab('summary')" id="tabSummary">
    <i class="fas fa-table-list"></i> Summary
    <span class="tab-badge" id="summaryBadge">—</span>
  </button>
</div>

<!-- ══ TAB: Assign ════════════════════════════════════════════════ -->
<div id="tabPanelAssign">

  <!-- Selector panel -->
  <div class="selector-panel">

    <div class="sel-group">
      <label><i class="fas fa-calendar-alt"></i> Session</label>
      <select class="sel-ctrl" id="selSession" onchange="triggerAutoLoad()">
        <option value="">— Select Session —</option>
        <?php foreach ($sessions as $s): ?>
          <option value="<?= $s['session_id'] ?>"
                  data-year="<?= $s['session_year'] ?>"
            <?= $s['is_current'] ? 'selected' : '' ?>>
            <?= h($s['label']) ?><?= $s['is_current'] ? ' (Current)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="sel-group">
      <label><i class="fas fa-school"></i> Class</label>
      <select class="sel-ctrl" id="selClass" onchange="onClassChange()">
        <option value="">— Select Class —</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?= $c['class_id'] ?>" data-num="<?= $c['class_num'] ?>">
            <?= h($c['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="sel-group" id="groupSelWrap" style="display:none">
      <label><i class="fas fa-object-group"></i> Group</label>
      <select class="sel-ctrl" id="selGroup" onchange="triggerAutoLoad()">
        <option value="">All Groups</option>
        <?php foreach ($groups as $g): ?>
          <option value="<?= $g['group_id'] ?>"><?= h($g['group_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

  </div>

  <!-- Content area -->
  <div id="assignContent">
    <div class="assign-placeholder">
      <i class="fas fa-arrow-up-from-bracket" style="font-size:44px"></i>
      <h3>Select Session &amp; Class</h3>
      <p>Choose a session and class above to see all subjects and assign teachers to each one.</p>
    </div>
  </div>

</div>

<!-- ══ TAB: Summary ═══════════════════════════════════════════════ -->
<div id="tabPanelSummary" style="display:none">
  <div class="selector-panel" style="margin-bottom:22px">
    <div class="sel-group">
      <label><i class="fas fa-calendar-alt"></i> Session</label>
      <select class="sel-ctrl" id="sumSession" onchange="loadSummary()">
        <option value="">— Select Session —</option>
        <?php foreach ($sessions as $s): ?>
          <option value="<?= $s['session_id'] ?>"
            <?= $s['is_current'] ? 'selected' : '' ?>>
            <?= h($s['label']) ?><?= $s['is_current'] ? ' (Current)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div id="summaryContent">
    <div class="assign-placeholder">
      <i class="fas fa-table-list"></i>
      <h3>Summary View</h3>
      <p>Select a session to see all teacher assignments across all classes.</p>
    </div>
  </div>
</div>

<!-- CSRF -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<script>
/* ═══════════════════════════════════════════════════════════════
   ASSIGN TEACHER — Full AJAX
   ═══════════════════════════════════════════════════════════════ */
const AJAX_URL = '<?= BASE_URL ?>/admin/assign-teacher-ajax.php';
const BASE_JS  = '<?= BASE_URL ?>';
const AVT_TEACH_M = '<?= BASE_URL ?>/assets/images/teacher-avatar-m.jpg';
const AVT_TEACH_F = '<?= BASE_URL ?>/assets/images/teacher-avatar-f.jpg';
const PHOTO_BASE  = '<?= BASE_URL ?>/uploads/teacher/photos/';

function avatarFor(gender) {
  return gender === 'female' ? AVT_TEACH_F : AVT_TEACH_M;
}
function teacherPhotoUrl(t) {
  return t && t.photo_path ? BASE_JS + '/' + t.photo_path : avatarFor(t ? t.gender : '');
}

// ── State ────────────────────────────────────────────────────────
let allTeachers   = [];   // fetched once
let currentSubjects   = [];
let currentAssignments = {};  // subject_id → {teacher_uid, teacher_name, designation}
let pendingChanges    = {};   // subject_id → teacher_uid (unsaved)
let hasUnsaved        = false;
let currentContext    = { sessionId: 0, classId: 0, groupId: 0 };

// ── CSRF ────────────────────────────────────────────────────────
function getToken() { return document.getElementById('csrfToken').value; }
function setToken(t){ document.getElementById('csrfToken').value = t; }

// ── AJAX ────────────────────────────────────────────────────────
async function ajax(data) {
  data.csrf_token = getToken();
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => { if (v !== null && v !== undefined) fd.append(k, v); });
  const res  = await fetch(AJAX_URL, { method: 'POST', body: fd });
  const json = await res.json();
  if (json.csrf) setToken(json.csrf);
  return json;
}

function esc(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ── Tab switching ────────────────────────────────────────────────
function switchTab(tab) {
  document.getElementById('tabPanelAssign').style.display   = tab === 'assign'  ? '' : 'none';
  document.getElementById('tabPanelSummary').style.display  = tab === 'summary' ? '' : 'none';
  document.getElementById('tabAssign').classList.toggle('active',  tab === 'assign');
  document.getElementById('tabSummary').classList.toggle('active', tab === 'summary');
  if (tab === 'summary') loadSummary();
}

// ── Class change → show/hide group selector, then auto-load ─────────
function onClassChange() {
  const sel = document.getElementById('selClass');
  const num = parseInt(sel.options[sel.selectedIndex]?.dataset.num || '0');
  const gw  = document.getElementById('groupSelWrap');
  if (num >= 9) { gw.style.display = ''; }
  else          { gw.style.display = 'none'; document.getElementById('selGroup').value = ''; }
  triggerAutoLoad();
}

let _autoLoadTimer = null;
function triggerAutoLoad() {
  clearTimeout(_autoLoadTimer);
  const sid = document.getElementById('selSession').value;
  const cid = document.getElementById('selClass').value;
  if (!sid || !cid) return;
  // Small debounce so group change doesn't double-fire
  _autoLoadTimer = setTimeout(() => loadAssignments(), 80);
}

// ── Load teachers once ───────────────────────────────────────────
async function ensureTeachers() {
  if (allTeachers.length > 0) return;
  const res = await ajax({ action: 'fetch_teachers' });
  if (res.ok) allTeachers = res.teachers || [];
}

// ── Load assignments ─────────────────────────────────────────────
async function loadAssignments() {
  const sessionId = document.getElementById('selSession').value;
  const classId   = document.getElementById('selClass').value;
  const groupId   = document.getElementById('selGroup')?.value || '';

  if (!sessionId || !classId) return; // silently ignore if incomplete

  // Check unsaved changes only when user explicitly changes selectors
  if (hasUnsaved) {
    const confirmed = await confirmLeave();
    if (!confirmed) return;
  }

  currentContext = {
    sessionId: parseInt(sessionId),
    classId:   parseInt(classId),
    groupId:   parseInt(groupId) || 0
  };
  pendingChanges = {};
  hasUnsaved     = false;

  document.getElementById('assignContent').innerHTML =
    '<div class="assign-placeholder"><i class="fas fa-spinner spin"></i><h3 style="margin-top:12px;font-weight:500">Loading subjects…</h3></div>';
  document.getElementById('headerActions').style.display = 'none';

  await ensureTeachers();

  const res = await ajax({
    action:     'fetch_assignments',
    session_id: sessionId,
    class_id:   classId,
    group_id:   groupId,
  });

  if (!res.ok) { showToast('danger', 'Error', res.msg); return; }

  currentSubjects    = res.subjects    || [];
  currentAssignments = res.assignments || {};

  if (res.warning) {
    document.getElementById('assignContent').innerHTML =
      '<div class="alert alert-warning" style="margin-bottom:0">' +
        '<i class="fas fa-triangle-exclamation"></i>' +
        '<div>' + esc(res.warning) + ' <a href="' + BASE_JS + '/admin/subjects.php" style="font-weight:600;color:var(--amber)">Add subjects →</a></div>' +
      '</div>';
    return;
  }

  renderAssignCards(res);
  document.getElementById('headerActions').style.display = '';
}

// ── Render subject cards ─────────────────────────────────────────
function renderAssignCards(res) {
  const subs     = res.subjects    || [];
  const assigns  = res.assignments || {};
  const clsLabel = res.class_label || '';
  const stuCount = res.student_count || 0;

  const assigned   = Object.keys(assigns).length;
  const total      = subs.length;
  const pct        = total > 0 ? Math.round(assigned / total * 100) : 0;

  const sessLabel = document.getElementById('selSession').options[document.getElementById('selSession').selectedIndex]?.text || '';

  let html =
    // Context bar
    '<div class="context-bar">' +
      '<div class="context-info">' +
        '<div class="context-label">' +
          '<i class="fas fa-school" style="color:var(--primary)"></i>' +
          esc(clsLabel) +
        '</div>' +
        '<span class="ctx-badge" style="background:var(--primary-xlight);color:var(--primary)">' +
          '<i class="fas fa-calendar-alt"></i>' + esc(sessLabel.replace(' (Current)','')) +
        '</span>' +
        '<span class="ctx-badge" style="background:var(--bg);color:var(--text-muted);border:1px solid var(--border)">' +
          '<i class="fas fa-users"></i>' + stuCount + ' students' +
        '</span>' +
      '</div>' +
    '</div>' +

    // Progress bar
    '<div class="assign-progress">' +
      '<div class="prog-item">' +
        '<div class="prog-val" id="progAssigned">' + assigned + '</div>' +
        '<div class="prog-lbl">Assigned</div>' +
      '</div>' +
      '<div class="prog-divider"></div>' +
      '<div class="prog-item">' +
        '<div class="prog-val">' + total + '</div>' +
        '<div class="prog-lbl">Total Subjects</div>' +
      '</div>' +
      '<div class="prog-divider"></div>' +
      '<div class="prog-item">' +
        '<div class="prog-val" style="color:var(--red)" id="progUnassigned">' + (total - assigned) + '</div>' +
        '<div class="prog-lbl">Unassigned</div>' +
      '</div>' +
      '<div class="prog-divider"></div>' +
      '<div class="prog-bar-wrap">' +
        '<div class="prog-bar-track">' +
          '<div class="prog-bar-fill" id="progBar" style="width:' + pct + '%"></div>' +
        '</div>' +
        '<div class="prog-pct" id="progPct">' + pct + '% complete</div>' +
      '</div>' +
    '</div>';

  // Subject cards
  const classNum = parseInt(res.class_num || 0);
  html += '<div class="subjects-assign-grid" id="subjectGrid">';
  subs.forEach(s => {
    const sid      = s.subject_id;
    const assigned = assigns[sid];
    const gname    = s.group_name;
    const gClass   = gname ? ('sgt-' + gname) : 'sgt-all';
    const gLabel   = gname || 'All Groups';
    const showGroupTag = classNum === 9 || classNum === 10;

    html +=
      '<div class="subj-assign-card ' + (assigned ? 'assigned' : 'unassigned') + '" id="sac-' + sid + '">' +
        '<div class="sac-head">' +
          '<div class="sac-icon ' + (assigned ? 'si-green' : 'si-gray') + '">' +
            '<i class="fas fa-book"></i>' +
          '</div>' +
          '<div class="sac-info">' +
            '<div class="sac-name">' + esc(s.subject_name) + '</div>' +
            (s.subject_code ? '<div class="sac-code">' + esc(s.subject_code) + '</div>' : '') +
            (showGroupTag
              ? '<div class="sac-group-tag ' + gClass + '">' +
                  '<i class="fas ' + (gname ? 'fa-tag' : 'fa-users') + '"></i>' +
                  esc(gLabel) +
                '</div>'
              : '') +
          '</div>' +
        '</div>' +
        '<div class="sac-body">' +
          '<div class="sac-label">' +
            'Assigned Teacher' +
            (assigned
              ? ' <span class="badge badge-green" style="font-size:9.5px"><i class="fas fa-check"></i> Set</span>'
              : ' <span class="badge badge-gray" style="font-size:9.5px">None</span>') +
          '</div>' +
          // Current assignment pill (if any)
          (assigned
            ? '<div class="current-assign-pill" id="capill-' + sid + '">' +
                '<img class="cap-avatar" src="' + teacherPhotoUrl(assigned) + '" alt="" ' +
                     'onerror="this.src=avatarFor(\'' + (assigned.gender||'') + '\')">' +
                '<div style="flex:1;min-width:0">' +
                  '<div class="cap-name">' + esc(assigned.teacher_name) + '</div>' +
                  (assigned.designation ? '<div class="cap-desig">' + esc(assigned.designation) + '</div>' : '') +
                '</div>' +
                '<button class="cap-remove" title="Remove assignment" ' +
                  'onclick=\'removeAssignment(' + sid + ')\'><i class="fas fa-xmark"></i></button>' +
              '</div>'
            : ''
          ) +
          // Teacher dropdown
          '<select class="teacher-select ' + (assigned ? 'has-teacher' : '') + '" ' +
                  'id="ts-' + sid + '" ' +
                  'data-sid="' + sid + '" ' +
                  'onchange="onTeacherChange(' + sid + ',this)">' +
            '<option value="">— Select Teacher —</option>' +
            allTeachers.map(t =>
              '<option value="' + esc(t.sys_uid) + '"' +
              (assigned && t.sys_uid === assigned.teacher_uid ? ' selected' : '') + '>' +
              esc(t.full_name) + (t.designation ? ' (' + esc(t.designation) + ')' : '') +
              '</option>'
            ).join('') +
          '</select>' +
        '</div>' +
      '</div>';
  });
  html += '</div>'; // /subjects-assign-grid

  // Save bar
  html +=
    '<div class="save-bar" id="saveBar">' +
      '<div class="save-bar-inner">' +
        '<span class="save-note">' +
          '<i class="fas fa-circle-info"></i> Changes are saved only when you click Save' +
        '</span>' +
        '<div style="display:flex;align-items:center;gap:14px;margin-left:auto;flex-wrap:wrap">' +
          '<div id="unsavedNote" class="save-note" style="display:none">' +
            '<span class="unsaved-dot"></span>' +
            'You have unsaved changes' +
          '</div>' +
          '<button class="btn btn-outline" onclick="loadAssignments()">' +
            '<i class="fas fa-rotate-right"></i> Reset' +
          '</button>' +
          '<button class="btn btn-primary btn-lg" onclick="saveAll()" id="saveAllBtn">' +
            '<i class="fas fa-check"></i> Save All Assignments' +
          '</button>' +
        '</div>' +
      '</div>' +
    '</div>';

  document.getElementById('assignContent').innerHTML = html;
}

// ── Teacher dropdown changed ─────────────────────────────────────
function onTeacherChange(sid, sel) {
  const val     = sel.value;
  const card    = document.getElementById('sac-' + sid);
  const capill  = document.getElementById('capill-' + sid);

  // Track pending change
  pendingChanges[sid] = val;
  hasUnsaved = true;
  document.getElementById('unsavedNote').style.display = '';

  // Update card visual
  if (val) {
    sel.classList.add('has-teacher');
    card.classList.replace('unassigned', 'assigned');
    // Update the "Set" badge
    updateCardBadge(sid, true);
  } else {
    sel.classList.remove('has-teacher');
    card.classList.replace('assigned', 'unassigned');
    updateCardBadge(sid, false);
  }

  // Update progress
  updateProgress();
}

function updateCardBadge(sid, isAssigned) {
  const card = document.getElementById('sac-' + sid);
  if (!card) return;
  const labelEl = card.querySelector('.sac-label');
  if (!labelEl) return;
  const txt = labelEl.childNodes[0];
  if (txt) txt.textContent = 'Assigned Teacher ';
  const badge = labelEl.querySelector('.badge');
  if (badge) {
    badge.className = 'badge ' + (isAssigned ? 'badge-green' : 'badge-gray');
    badge.style.fontSize = '9.5px';
    badge.innerHTML = isAssigned
      ? '<i class="fas fa-check"></i> Set'
      : 'None';
  }
}

function updateProgress() {
  // Count current assignments including pending
  const merged = { ...currentAssignments };
  Object.entries(pendingChanges).forEach(([sid, uid]) => {
    if (uid) merged[sid] = { teacher_uid: uid };
    else delete merged[sid];
  });

  const assigned   = Object.values(merged).filter(v => v.teacher_uid).length;
  const total      = currentSubjects.length;
  const unassigned = total - assigned;
  const pct        = total > 0 ? Math.round(assigned / total * 100) : 0;

  const pa = document.getElementById('progAssigned');
  const pu = document.getElementById('progUnassigned');
  const pb = document.getElementById('progBar');
  const pp = document.getElementById('progPct');
  if (pa) pa.textContent = assigned;
  if (pu) pu.textContent = unassigned;
  if (pb) pb.style.width = pct + '%';
  if (pp) pp.textContent = pct + '% complete';
}

// ── Remove single assignment ─────────────────────────────────────
async function removeAssignment(sid) {
  window.openModal({
    icon: 'fa-user-xmark', iconClass: 'icon-warning',
    title: 'Remove Assignment?',
    body: 'The teacher will be unassigned from this subject.<br>You can reassign anytime.',
    confirmLabel: 'Yes, Remove', confirmClass: 'btn-danger',
    onConfirm: async function() {
      const res = await ajax({
        action:     'remove_assignment',
        session_id: currentContext.sessionId,
        class_id:   currentContext.classId,
        subject_id: sid,
      });
      if (!res.ok) { showToast('danger','Error', res.msg); return; }

      // Update state
      delete currentAssignments[sid];
      delete pendingChanges[sid];

      // Update UI
      const card   = document.getElementById('sac-' + sid);
      const sel    = document.getElementById('ts-' + sid);
      const capill = document.getElementById('capill-' + sid);
      if (card)   { card.classList.replace('assigned','unassigned'); }
      if (sel)    { sel.value = ''; sel.classList.remove('has-teacher'); }
      if (capill) { capill.remove(); }
      updateCardBadge(sid, false);
      updateProgress();

      showToast('success','Removed', res.msg);
    }
  });
}

// ── Save all ─────────────────────────────────────────────────────
async function saveAll() {
  if (!currentContext.sessionId || !currentContext.classId) {
    showToast('warning','Required','Please load a class first.');
    return;
  }

  // Collect ALL current values from dropdowns
  const allValues = {};
  currentSubjects.forEach(s => {
    const sel = document.getElementById('ts-' + s.subject_id);
    if (sel) allValues[s.subject_id] = sel.value || '';
  });

  const btn = document.getElementById('saveAllBtn');
  const hBtn = document.getElementById('headerSaveBtn');
  if (btn) { btn.innerHTML = '<i class="fas fa-spinner spin"></i> Saving…'; btn.disabled = true; }
  if (hBtn) { hBtn.disabled = true; }

  const res = await ajax({
    action:      'save_assignments',
    session_id:  currentContext.sessionId,
    class_id:    currentContext.classId,
    group_id:    currentContext.groupId || '',
    assignments: JSON.stringify(allValues),
  });

  if (btn)  { btn.innerHTML = '<i class="fas fa-check"></i> Save All Assignments'; btn.disabled = false; }
  if (hBtn) { hBtn.disabled = false; }

  if (!res.ok) { showToast('danger','Error', res.msg); return; }

  // Update state — reload assignments from server
  hasUnsaved     = false;
  pendingChanges = {};
  const unsavedNote = document.getElementById('unsavedNote');
  if (unsavedNote) unsavedNote.style.display = 'none';

  showToast('success','Saved!', res.msg);

  // Reload to reflect saved state
  await loadAssignments();
}

// ── Confirm leave with unsaved changes ───────────────────────────
function confirmLeave() {
  return new Promise(resolve => {
    window.openModal({
      icon: 'fa-triangle-exclamation', iconClass: 'icon-warning',
      title: 'Unsaved Changes',
      body: 'You have unsaved assignment changes. Loading a new class will discard them.',
      confirmLabel: 'Discard & Continue', confirmClass: 'btn-danger',
      cancelLabel: 'Stay & Save',
      onConfirm: () => resolve(true),
      onCancel:  () => resolve(false),
    });
  });
}

// ── Summary tab ──────────────────────────────────────────────────
async function loadSummary() {
  const sessionId = document.getElementById('sumSession').value;
  if (!sessionId) return;

  document.getElementById('summaryContent').innerHTML =
    '<div class="assign-placeholder" style="padding:40px"><i class="fas fa-spinner spin"></i>' +
    '<h3 style="margin-top:12px;font-weight:500">Loading…</h3></div>';

  const res = await ajax({ action: 'fetch_summary', session_id: sessionId });
  if (!res.ok) { showToast('danger','Error',res.msg); return; }

  const rows = res.summary || [];
  const badge = document.getElementById('summaryBadge');
  if (badge) badge.textContent = rows.length;

  if (rows.length === 0) {
    document.getElementById('summaryContent').innerHTML =
      '<div class="assign-placeholder">' +
        '<i class="fas fa-circle-info"></i>' +
        '<h3>No assignments yet</h3>' +
        '<p>Switch to the <strong>Assign</strong> tab to start assigning teachers to subjects.</p>' +
      '</div>';
    return;
  }

  // Group by class
  const byClass = {};
  rows.forEach(r => {
    const key = r.class_num;
    if (!byClass[key]) byClass[key] = { label: r.class_label, num: r.class_num, rows: [] };
    byClass[key].rows.push(r);
  });

  let html = '';
  Object.values(byClass).sort((a,b) => a.num-b.num).forEach(cls => {
    html +=
      '<div class="summary-class-block">' +
        '<div class="summary-class-hd">' +
          '<div class="summary-class-badge">' + cls.num + '</div>' +
          esc(cls.label) +
          '<span class="badge badge-blue" style="margin-left:auto">' + cls.rows.length + ' subject' + (cls.rows.length!==1?'s':'') + '</span>' +
        '</div>';

    cls.rows.forEach(r => {
      html +=
        '<div class="summary-row">' +
          '<div class="sr-subject">' +
            esc(r.subject_name) +
            (r.subject_code ? ' <span class="sr-code">' + esc(r.subject_code) + '</span>' : '') +
          '</div>' +
          (r.group_name
            ? '<span class="badge badge-gray" style="font-size:10.5px">' + esc(r.group_name) + '</span>'
            : '') +
          '<div class="sr-teacher">' +
            '<i class="fas fa-chalkboard-teacher" style="font-size:11px;color:var(--text-light)"></i>' +
            '<div>' +
              '<strong>' + esc(r.teacher_name) + '</strong>' +
              (r.designation ? ' <span style="font-size:11.5px;color:var(--text-muted)">· ' + esc(r.designation) + '</span>' : '') +
            '</div>' +
          '</div>' +
        '</div>';
    });

    html += '</div>';
  });

  document.getElementById('summaryContent').innerHTML = html;
}

// ── Init ─────────────────────────────────────────────────────────
// Auto-load if current session is pre-selected and a class is already chosen
(function init() {
  // Load summary badge on init
  const sumSess = document.getElementById('sumSession').value;
  if (sumSess) {
    ajax({ action:'fetch_summary', session_id:sumSess }).then(res => {
      if (res.ok) {
        const badge = document.getElementById('summaryBadge');
        if (badge) badge.textContent = (res.summary||[]).length;
      }
    });
  }
})();

// Warn on page leave with unsaved changes
window.addEventListener('beforeunload', function(e) {
  if (hasUnsaved) {
    e.preventDefault();
    e.returnValue = '';
  }
});
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
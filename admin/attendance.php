<?php
/**
 * EMS Admin — Attendance Module
 * Mark / Update attendance by class+date, view per-student reports
 */
require_once __DIR__ . '/../includes/admin-session.php';

$pageTitle  = 'Attendance';
$activeNav  = 'attendance';
$breadcrumb = ['Academics', 'Attendance'];

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
   ATTENDANCE PAGE
   ═══════════════════════════════════════════════════════════════ */

/* ── Page tabs ──────────────────────────────────────────────────── */
.att-tabs {
  display: flex; gap: 0;
  border-bottom: 2px solid var(--border);
  margin-bottom: 24px;
  overflow: visible;
}
.att-tab {
  padding: 10px 22px; font-size: 13.5px; font-weight: 600;
  color: var(--text-muted); cursor: pointer;
  border-bottom: 2px solid transparent; margin-bottom: -2px;
  transition: color .15s, border-color .15s; white-space: nowrap;
  display: flex; align-items: center; gap: 8px;
  background: none; border-top: none; border-left: none; border-right: none;
  font-family: inherit; user-select: none;
}
.att-tab:hover  { color: var(--text); }
.att-tab.active { color: var(--primary); border-bottom-color: var(--primary); }

/* ── Selector panel ─────────────────────────────────────────────── */
.att-selector {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--r-md); padding: 20px 22px;
  margin-bottom: 20px;
  display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;
}
.att-sel-group { display: flex; flex-direction: column; gap: 5px; flex: 1 1 0; min-width: 140px; }
.att-sel-group label { font-size: 11.5px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; }
.att-ctrl {
  height: 40px; border: 1.5px solid var(--border); border-radius: var(--r-sm);
  font-family: inherit; font-size: 13.5px; color: var(--text);
  background: var(--bg-card); padding: 0 12px; outline: none; width: 100%;
  transition: border-color .15s, box-shadow .15s;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 12px center; padding-right: 32px;
}
.att-ctrl:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.att-ctrl.date-ctrl {
  background-image: none; padding-right: 12px;
}
.att-ctrl:disabled { background: var(--bg-elevated); color: var(--text-muted); cursor: not-allowed; }

/* ── Already marked banner ──────────────────────────────────────── */
.marked-banner {
  display: flex; align-items: center; gap: 14px;
  padding: 12px 18px; border-radius: var(--r-sm); margin-bottom: 16px;
  flex-wrap: wrap;
}
.marked-banner.marked   { background: var(--sky-bg);   border: 1px solid var(--sky-border); }
.marked-banner.unmarked { background: var(--amber-bg);  border: 1px solid var(--amber-border); }
.mb-icon { font-size: 20px; flex-shrink: 0; }
.marked-banner.marked .mb-icon   { color: var(--sky); }
.marked-banner.unmarked .mb-icon { color: var(--amber); }
.mb-text { flex: 1; font-size: 13.5px; }
.mb-stats { display: flex; gap: 12px; flex-wrap: wrap; }
.mb-stat { display: flex; align-items: center; gap: 5px; font-size: 13px; font-weight: 700; }

/* ── Quick actions bar ──────────────────────────────────────────── */
.att-quickbar {
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 16px; flex-wrap: wrap;
}
.att-quickbar-left  { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; flex: 1; }
.att-quickbar-right { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
.att-search-wrap { position: relative; }
.att-search-wrap input {
  width: 200px; height: 36px; padding: 0 12px 0 34px;
  border: 1.5px solid var(--border); border-radius: var(--r-sm);
  font-family: inherit; font-size: 13px; color: var(--text); background: var(--bg-card);
  outline: none; transition: border-color .15s;
}
.att-search-wrap input:focus { border-color: var(--primary); }
.att-search-wrap i { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); font-size: 12px; color: var(--text-muted); }

/* ── Attendance student table (same structure as students.php) ───── */
.att-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 20px; }
.att-table {
  width: 100%; border-collapse: collapse; min-width: 640px;
}
.att-table th {
  padding: 10px 14px; text-align: left;
  font-size: 11px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: var(--text-muted);
  background: var(--bg-elevated); border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
.att-table td {
  padding: 11px 14px; border-bottom: 1px solid var(--border-light);
  vertical-align: middle; font-size: 13.5px;
}
.att-table tbody tr { transition: background .12s; }
.att-table tbody tr:hover { background: #f8faff; }
.att-table tbody tr:last-child td { border-bottom: none; }

/* Status accent — left border on the row, same idea as the old ::before */
.att-card.status-none    { border-left: 4px solid var(--border); }
.att-card.status-present { border-left: 4px solid var(--green); }
.att-card.status-absent  { border-left: 4px solid var(--red); }
.att-card.status-late    { border-left: 4px solid var(--amber); }

/* Photo */
.att-photo {
  width: 54px; height: 54px; border-radius: 50%;
  object-fit: cover; object-position: center top; display: block;
  border: 2px solid var(--border); background: var(--bg-elevated);
}
.att-card.status-present .att-photo { border-color: var(--green-border); }
.att-card.status-absent  .att-photo { border-color: var(--red-border); }
.att-card.status-late    .att-photo { border-color: var(--amber-border); }

/* Name + roll sub-text (mirrors tbl-name / tbl-sid in students.php) */
.att-student-name { font-weight: 700; color: var(--text); font-size: 14px; display: block; }
.att-roll-num     { font-size: 14px; color: var(--text-muted); margin-top: 2px; display: block; }
.att-student-id   { font-size: 16px; color: var(--text-muted); font-family: monospace; letter-spacing: .04em; }

/* P / A / L action buttons — sit in their own <td> */
.att-btn-group {
  display: flex; gap: 4px; align-items: center;
}
.att-btn {
  width: 52px; height: 48px; border-radius: var(--r-sm);
  border: 1.5px solid var(--border); background: var(--bg-card);
  font-family: inherit; cursor: pointer;
  transition: all .13s;
  display: flex; align-items: center; justify-content: center;
  flex-direction: column; gap: 1px; line-height: 1;
  color: var(--text-muted);
}
.att-btn .btn-letter { font-size: 13px; font-weight: 800; line-height: 1; }
.att-btn .btn-word   { font-size: 8px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; opacity: .7; }

/* Active (selected) states */
.att-btn.btn-p.sel { background: var(--green);  border-color: var(--green);  color: #fff; }
.att-btn.btn-a.sel { background: var(--red);    border-color: var(--red);    color: #fff; }
.att-btn.btn-l.sel { background: var(--amber);  border-color: var(--amber);  color: #fff; }
.att-btn.btn-p.sel .btn-word,
.att-btn.btn-a.sel .btn-word,
.att-btn.btn-l.sel .btn-word { opacity: 1; }

/* Hover — unselected */
.att-btn.btn-p:not(.sel):hover { border-color: var(--green);  color: var(--green);  background: var(--green-bg); }
.att-btn.btn-a:not(.sel):hover { border-color: var(--red);    color: var(--red);    background: var(--red-bg); }
.att-btn.btn-l:not(.sel):hover { border-color: var(--amber);  color: #b45309;        background: var(--amber-bg); }
/* Hover — selected (darken) */
.att-btn.btn-p.sel:hover { background: var(--green-dark); border-color: var(--green-dark); }
.att-btn.btn-a.sel:hover { background: #b91c1c;           border-color: #b91c1c; }
.att-btn.btn-l.sel:hover { background: #b45309;           border-color: #b45309; }

/* ── Table view (report) ────────────────────────────────────────── */
.rpt-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.rpt-table {
  width: 100%; border-collapse: collapse; min-width: 620px;
}
.rpt-table th {
  padding: 10px 14px; text-align: left; font-size: 11px;
  font-weight: 700; text-transform: uppercase; letter-spacing: .07em;
  color: var(--text-muted); background: var(--bg-elevated);
  border-bottom: 1px solid var(--border); white-space: nowrap;
}
.rpt-table td {
  padding: 11px 14px; border-bottom: 1px solid var(--border-light);
  vertical-align: middle; font-size: 13.5px;
}
.rpt-table tbody tr:hover { background: #f8faff; }
.rpt-table tbody tr:last-child td { border-bottom: none; }

.rpt-photo { width: 54px; height: 54px; border-radius: 50%; object-fit: cover; object-position: center top; border: 2px solid var(--border); display: block; background: var(--bg); }
.rpt-name  { font-weight: 700; color: var(--text); }
.rpt-id    { font-size: 14px; font-family: monospace; color: var(--text-muted); }

/* Attendance percentage bar */
.pct-bar-wrap { display: flex; align-items: center; gap: 8px; min-width: 120px; }
.pct-bar-track { flex: 1; height: 7px; background: var(--border); border-radius: 4px; overflow: hidden; }
.pct-bar-fill  { height: 100%; border-radius: 4px; transition: width .4s ease; }
.pct-val { font-size: 13px; font-weight: 700; width: 42px; text-align: right; flex-shrink: 0; }

/* Report stat cards */
.rpt-stats {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px;
  margin-bottom: 20px;
}
.rpt-stat {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--r-md); padding: 16px 18px;
  display: flex; align-items: center; gap: 12px;
}
.rpt-stat-icon { width: 40px; height: 40px; border-radius: var(--r-sm); display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
.rpt-stat-val  { font-size: 24px; font-weight: 800; letter-spacing: -.03em; color: var(--text); line-height: 1; }
.rpt-stat-lbl  { font-size: 12px; font-weight: 500; color: var(--text-muted); margin-top: 3px; }

/* ── Calendar dots ──────────────────────────────────────────────── */
.cal-wrap {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--r-md); padding: 10px 16px; margin-bottom: 16px;
}
.cal-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 6px;
}
.cal-title { font-size: 16px; font-weight: 700; color: var(--text); }
.cal-nav   { display: flex; gap: 5px; }
.cal-nav-btn {
  width: 24px; height: 24px; border-radius: var(--r-sm);
  border: 1.5px solid var(--border); background: var(--bg-card);
  color: var(--text-muted); cursor: pointer; font-size: 10px;
  display: flex; align-items: center; justify-content: center; transition: all .13s;
}
.cal-nav-btn:hover { border-color: var(--primary); color: var(--primary); }

.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
.cal-day-name { text-align: center; font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; padding: 2px 0; line-height: 1.4; }
.cal-day {
  height: 44px; border-radius: 4px; display: flex; flex-direction: column;
  align-items: center; justify-content: center; cursor: pointer;
  font-size: 14px; font-weight: 600; color: var(--text-muted);
  transition: all .13s; position: relative; gap: 1px; line-height: 1;
}
.cal-day.empty { cursor: default; }
.cal-day.today { background: var(--primary-xlight); color: var(--primary); }
.cal-day.marked-day {
  background: var(--green-bg); color: var(--green-dark); font-weight: 700;
}
.cal-day.marked-day:hover { background: var(--green); color: #fff; }
.cal-day.selected { background: var(--primary); color: #fff !important; }
.cal-day.friday   { color: var(--red-border); }
.cal-dot { width: 3px; height: 3px; border-radius: 50%; background: var(--green); flex-shrink: 0; }
.cal-day.selected .cal-dot { background: rgba(255,255,255,.7); }
.cal-day.future { opacity: .4; cursor: not-allowed; }
.cal-day:not(.empty):not(.marked-day):not(.today):not(.selected):not(.future):hover { background: var(--bg); }

/* ── Sticky save bar ────────────────────────────────────────────── */
.att-save-bar {
  position: sticky; bottom: 0;
  background: var(--bg-card); border-top: 1px solid var(--border);
  padding: 14px 14px; margin-top: 4px; border-radius: var(--r-md);
  display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
  z-index: 100; box-shadow: 0 -4px 20px rgba(16,24,40,.08);
}
.att-save-note { font-size: 12.5px; color: var(--text-muted); display: flex; align-items: center; gap: 6px; }

/* ── Empty / loading ────────────────────────────────────────────── */
.att-placeholder { text-align: center; padding: 56px 24px; color: var(--text-muted); }
.att-placeholder i { font-size: 52px; color: var(--text-light); display: block; margin: 0 auto 12px; }
.att-placeholder h3 { font-size: 17px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.att-placeholder p  { font-size: 13.5px; line-height: 1.6; max-width: 380px; margin: 0 auto; }

/* ── Spinner */
.spin { animation: spin .7s linear infinite; display: inline-block; }
@keyframes spin { to { transform: rotate(360deg); } }

/* NOTE: a page-scoped `html { scrollbar-gutter: stable; }` rule used to
   live here. It targeted the document root, so it didn't just stabilize
   this page's own tables — it also nudged the fixed sidebar/topbar
   (which are shared across every admin page) a scrollbar's-width to the
   left, but only while viewing THIS page. Removed so this page matches
   every other admin page's topbar position exactly. */

/* ── Responsive ─────────────────────────────────────────────────── */
@media (max-width: 1100px) {
  .rpt-stats { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
  .att-btn .btn-word { display: none; }
  .att-btn { width: 40px; height: 36px; }
}
@media (max-width: 680px) {
  .att-selector { flex-direction: column; align-items: stretch; }
  .att-sel-group { min-width: 0; width: 100%; flex: 1 1 100%; }
  .rpt-stats { grid-template-columns: repeat(2, 1fr); gap: 10px; }
  .att-quickbar { flex-direction: column; align-items: flex-start; }
  .att-quickbar-left, .att-quickbar-right { width: 100%; justify-content: flex-start; }
  .att-search-wrap input { width: 100%; }
  .att-photo { width: 54px; height: 54px; }
}
@media (max-width: 420px) {
  .rpt-stats { grid-template-columns: 1fr 1fr; }
  .att-student-name { font-size: 13px; }
}
</style>

<!-- ══ Page header ════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="page-header-top">
    <div>
      <div class="page-title">Attendance</div>
      <div class="page-sub">
        <i class="fas fa-calendar-check"></i>
        Mark, update and view student attendance
      </div>
    </div>
    <div class="page-actions" id="headerSaveWrap" style="display:none">
      <button class="btn btn-primary" onclick="saveAttendance()" id="headerSaveBtn">
        <i class="fas fa-check"></i> Save Attendance
      </button>
    </div>
  </div>
</div>

<!-- ══ Page tabs ══════════════════════════════════════════════════ -->
<div class="att-tabs">
  <button class="att-tab active" id="tabMark"   onclick="switchTab('mark')">
    <i class="fas fa-pen-to-square"></i> Mark / Update
  </button>
  <button class="att-tab"        id="tabReport" onclick="switchTab('report')">
    <i class="fas fa-chart-bar"></i> Report
  </button>
</div>

<!-- ══════════════════════════════════════════════════════════════
     TAB: MARK / UPDATE
     ══════════════════════════════════════════════════════════════ -->
<div id="panelMark">

  <!-- Selector row -->
  <div class="att-selector">
    <div class="att-sel-group">
      <label><i class="fas fa-calendar-alt"></i> Session</label>
      <select class="att-ctrl" id="mSession" onchange="onMarkSelectorChange()">
        <option value="">— Session —</option>
        <?php foreach ($sessions as $s): ?>
          <option value="<?= $s['session_id'] ?>" data-year="<?= $s['session_year'] ?>"
            <?= $s['is_current'] ? 'selected' : '' ?>>
            <?= h($s['label']) ?><?= $s['is_current'] ? ' (Current)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="att-sel-group">
      <label><i class="fas fa-school"></i> Class</label>
      <select class="att-ctrl" id="mClass" onchange="onMarkClassChange(); onMarkSelectorChange()">
        <option value="">— Class —</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?= $c['class_id'] ?>" data-num="<?= $c['class_num'] ?>">
            <?= h($c['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="att-sel-group" id="mGroupWrap" style="display:none">
      <label><i class="fas fa-object-group"></i> Group</label>
      <select class="att-ctrl" id="mGroup" onchange="onMarkSelectorChange()">
        <option value="">All Groups</option>
        <?php foreach ($groups as $g): ?>
          <option value="<?= $g['group_id'] ?>"><?= h($g['group_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="att-sel-group">
      <label><i class="fas fa-calendar-day"></i> Date</label>
      <input type="date" class="att-ctrl date-ctrl" id="mDate"
             value="<?= date('Y-m-d') ?>"
             max="<?= date('Y-m-d') ?>"
             onchange="onMarkSelectorChange()">
    </div>

  </div>

  <!-- Calendar strip (shown after class+session selected) -->
  <div id="calendarWrap" style="display:none">
    <div class="cal-wrap">
      <div class="cal-header">
        <div class="cal-title" id="calTitle">—</div>
        <div class="cal-nav">
          <button class="cal-nav-btn" onclick="calNav(-1)" title="Previous month"><i class="fas fa-chevron-left"></i></button>
          <button class="cal-nav-btn" onclick="calNav(1)"  title="Next month"><i class="fas fa-chevron-right"></i></button>
        </div>
      </div>
      <div class="cal-grid" id="calGrid"></div>
    </div>
  </div>

  <!-- Attendance content -->
  <div id="markContent">
    <div class="att-placeholder">
      <i class="fas fa-user-clock"></i>
      <h3>Select Class &amp; Date</h3>
      <p>Choose a session, class and date above to start marking attendance.</p>
    </div>
  </div>

</div><!-- /panelMark -->

<!-- ══════════════════════════════════════════════════════════════
     TAB: REPORT
     ══════════════════════════════════════════════════════════════ -->
<div id="panelReport" style="display:none">

  <div class="att-selector">
    <div class="att-sel-group">
      <label><i class="fas fa-calendar-alt"></i> Session</label>
      <select class="att-ctrl" id="rSession" onchange="onReportChange()">
        <option value="">— Session —</option>
        <?php foreach ($sessions as $s): ?>
          <option value="<?= $s['session_id'] ?>"
            <?= $s['is_current'] ? 'selected' : '' ?>>
            <?= h($s['label']) ?><?= $s['is_current'] ? ' (Current)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="att-sel-group">
      <label><i class="fas fa-school"></i> Class</label>
      <select class="att-ctrl" id="rClass" onchange="onReportClassChange(); onReportChange()">
        <option value="">— Class —</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?= $c['class_id'] ?>" data-num="<?= $c['class_num'] ?>">
            <?= h($c['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="att-sel-group" id="rGroupWrap" style="display:none">
      <label><i class="fas fa-object-group"></i> Group</label>
      <select class="att-ctrl" id="rGroup" onchange="onReportChange()">
        <option value="">All Groups</option>
        <?php foreach ($groups as $g): ?>
          <option value="<?= $g['group_id'] ?>"><?= h($g['group_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

  </div>

  <div id="reportContent">
    <div class="att-placeholder">
      <i class="fas fa-chart-bar"></i>
      <h3>Select Session &amp; Class</h3>
      <p>Choose a session and class to view the full attendance report for all students.</p>
    </div>
  </div>

</div><!-- /panelReport -->

<!-- CSRF -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<script>
/* ═══════════════════════════════════════════════════════════════
   ATTENDANCE — Full AJAX
   ═══════════════════════════════════════════════════════════════ */
const AJAX_URL  = '<?= BASE_URL ?>/admin/attendance-ajax.php';
const BASE_JS   = '<?= BASE_URL ?>';
const AVT_M     = '<?= BASE_URL ?>/assets/images/student-avatar-m.jpg';
const AVT_F     = '<?= BASE_URL ?>/assets/images/student-avatar-f.jpg';

let currentStudents = [];
let markedDates     = {}; // date → {total, present_count, absent_count}
let calYear         = new Date().getFullYear();
let calMonth        = new Date().getMonth(); // 0-indexed
let calContext      = { sessionId: 0, classId: 0 };
let hasUnsaved      = false;
let activeTab       = 'mark';

// ── CSRF ────────────────────────────────────────────────────────
function getToken() { return document.getElementById('csrfToken').value; }
function setToken(t){ document.getElementById('csrfToken').value = t; }

// ── AJAX ────────────────────────────────────────────────────────
async function ajax(data) {
  data.csrf_token = getToken();
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => {
    if (v !== null && v !== undefined) fd.append(k, v);
  });
  const res  = await fetch(AJAX_URL, { method:'POST', body:fd });
  const json = await res.json();
  if (json.csrf) setToken(json.csrf);
  return json;
}

function esc(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function ph(s) { return (!s || String(s).trim() === '') ? '—' : esc(s); }

// ── Tab switching ────────────────────────────────────────────────
function switchTab(tab) {
  activeTab = tab;
  document.getElementById('panelMark').style.display   = tab === 'mark'   ? '' : 'none';
  document.getElementById('panelReport').style.display = tab === 'report' ? '' : 'none';
  document.getElementById('tabMark').classList.toggle('active',   tab === 'mark');
  document.getElementById('tabReport').classList.toggle('active', tab === 'report');
  document.getElementById('headerSaveWrap').style.display = (tab === 'mark' && currentStudents.length > 0) ? '' : 'none';
}

function onMarkClassChange() {
  const sel = document.getElementById('mClass');
  const num = parseInt(sel.options[sel.selectedIndex]?.dataset.num || '0');
  document.getElementById('mGroupWrap').style.display = num >= 9 ? '' : 'none';
  if (num < 9) document.getElementById('mGroup').value = '';
}

let markLoadTimer = null;
function onMarkSelectorChange() {
  const s = document.getElementById('mSession').value;
  const c = document.getElementById('mClass').value;
  const d = document.getElementById('mDate').value;
  if (s && c && d) {
    clearTimeout(markLoadTimer);
    markLoadTimer = setTimeout(loadAttendance, 100);
  }
}

// ── Report tab — class change ────────────────────────────────────
function onReportClassChange() {
  const sel = document.getElementById('rClass');
  const num = parseInt(sel.options[sel.selectedIndex]?.dataset.num || '0');
  document.getElementById('rGroupWrap').style.display = num >= 9 ? '' : 'none';
  if (num < 9) document.getElementById('rGroup').value = '';
}

let reportLoadTimer = null;
function onReportChange() {
  const s = document.getElementById('rSession').value;
  const c = document.getElementById('rClass').value;
  if (s && c) {
    clearTimeout(reportLoadTimer);
    reportLoadTimer = setTimeout(loadReport, 100);
  }
}

// ── LOAD ATTENDANCE ──────────────────────────────────────────────
async function loadAttendance() {
  const sessionId = document.getElementById('mSession').value;
  const classId   = document.getElementById('mClass').value;
  const groupId   = document.getElementById('mGroup')?.value || '';
  const date      = document.getElementById('mDate').value;

  if (!sessionId || !classId || !date) {
    showToast('warning','Required','Please select session, class and date.');
    return;
  }

  document.getElementById('markContent').innerHTML =
    '<div class="att-placeholder"><i class="fas fa-spinner spin" style="font-size:36px"></i>' +
    '<h3 style="margin-top:12px;font-weight:500">Loading students…</h3></div>';
  document.getElementById('headerSaveWrap').style.display = 'none';
  hasUnsaved = false;

  const res = await ajax({
    action:     'fetch_students',
    session_id: sessionId,
    class_id:   classId,
    group_id:   groupId,
    att_date:   date,
  });

  if (!res.ok) { showToast('danger','Error',res.msg); return; }
  if (res.students.length === 0) {
    document.getElementById('markContent').innerHTML =
      '<div class="att-placeholder"><i class="fas fa-user-slash"></i>' +
      '<h3>No students found</h3>' +
      '<p>No active students enrolled in this class for the selected session.' +
      ' Check <a href="' + BASE_JS + '/admin/students.php" style="color:var(--primary);font-weight:600">Students</a> page.</p></div>';
    return;
  }

  currentStudents = res.students;

  // Update calendar context
  calContext = { sessionId: parseInt(sessionId), classId: parseInt(classId) };
  document.getElementById('calendarWrap').style.display = '';
  loadCalendarMonth(calYear, calMonth);

  renderMarkGrid(res, date);
  document.getElementById('headerSaveWrap').style.display = '';
}

// ── Render mark grid ─────────────────────────────────────────────
function renderMarkGrid(res, date) {
  const students      = res.students;
  const alreadyMarked = res.already_marked;
  const isFriday      = res.is_holiday;
  const dayName       = res.day_name;

  // Format display date
  const dObj = new Date(date + 'T00:00:00');
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const dateDisp = dObj.getDate() + ' ' + months[dObj.getMonth()] + ' ' + dObj.getFullYear();

  let html = '';

  // Friday warning
  if (isFriday) {
    html += '<div class="alert alert-warning" style="margin-bottom:14px">' +
      '<i class="fas fa-triangle-exclamation" style="margin-top:4px"></i>' +
      '<div><strong>Friday is a weekly holiday.</strong> You can still mark attendance if school was open on this day.</div>' +
    '</div>';
  }

  // Already marked / not yet banner
  html += '<div class="marked-banner ' + (alreadyMarked ? 'marked' : 'unmarked') + '" style="margin-bottom:14px">' +
    '<i class="fas ' + (alreadyMarked ? 'fa-circle-check' : 'fa-clock') + ' mb-icon"></i>' +
    '<div class="mb-text">' +
      '<strong>' + dayName + ', ' + esc(dateDisp) + ' — ' + esc(res.class_label) + '</strong>' +
      (alreadyMarked
        ? ' · Attendance already marked. You can update it below.'
        : ' · Attendance not marked yet for this date.') +
    '</div>' +
    (alreadyMarked
      ? '<div class="mb-stats">' +
          '<span class="mb-stat" style="color:var(--green)"><i class="fas fa-circle-check"></i>' + res.present_count + ' Present</span>' +
          '<span class="mb-stat" style="color:var(--red)"><i class="fas fa-circle-xmark"></i>' + res.absent_count + ' Absent</span>' +
          '<span class="mb-stat" style="color:var(--amber)"><i class="fas fa-clock"></i>' + res.late_count + ' Late</span>' +
        '</div>'
      : '') +
  '</div>';

  // Quick actions bar — search & counter on the LEFT, All P/A/L on the RIGHT
  html +=
    '<div class="att-quickbar">' +
      '<div class="att-quickbar-left">' +
        '<div class="att-search-wrap">' +
          '<i class="fas fa-magnifying-glass"></i>' +
          '<input type="text" id="attSearch" placeholder="Search student…" oninput="filterCards()" autocomplete="off">' +
        '</div>' +
        '<span id="attCounter" style="font-size:13px;font-weight:600;color:var(--text-muted);white-space:nowrap">' +
          students.length + ' students' +
        '</span>' +
      '</div>' +
      '<div class="att-quickbar-right">' +
        '<button class="btn btn-sm btn-success" onclick="markAll(\'present\')">' +
          '<i class="fas fa-circle-check"></i> All Present' +
        '</button>' +
        '<button class="btn btn-sm btn-danger"  onclick="markAll(\'absent\')">' +
          '<i class="fas fa-circle-xmark"></i> All Absent' +
        '</button>' +
        '<button class="btn btn-sm btn-ghost"   onclick="markAll(\'late\')" style="color:var(--amber);border-color:var(--amber-border)">' +
          '<i class="fas fa-clock"></i> All Late' +
        '</button>' +
      '</div>' +
    '</div>';

  // Student list — proper <table> matching students.php structure
  html += '<div class="card p-0 mb-0"><div class="att-table-wrap"><table class="att-table" id="attGrid">';
  html +=
    '<thead><tr>' +
      '<th style="width:90px"></th>' +
      '<th>Name</th>' +
      '<th>Roll</th>' +
      '<th>Student ID</th>' +
      '<th style="width:140px">Action</th>' +
    '</tr></thead><tbody>';

  students.forEach(s => {
    const eid       = s.enrollment_id;
    const rawStatus = s.attendance_id ? (s.att_status || 'present') : 'none';
    const photo     = s.photo_path ? BASE_JS + '/' + s.photo_path : (s.gender === 'female' ? AVT_F : AVT_M);
    const roll      = s.roll_number ? esc(s.roll_number) : '—';

    html +=
      '<tr class="att-card status-' + rawStatus + '" id="ac-' + eid + '" data-name="' + esc(s.full_name).toLowerCase() + '">' +
        '<td><img class="att-photo" src="' + photo + '" alt="" onerror="this.src=\'' + (s.gender==='female'?AVT_F:AVT_M) + '\'"></td>' +
        '<td>' +
          '<span class="att-student-name">' + esc(s.full_name) + '</span>' +
          '<span class="att-roll-num" style="display:none"></span>' + // hidden — roll has its own column
        '</td>' +
        '<td><span style="font-size:13px;color:var(--text-muted)">' + roll + '</span></td>' +
        '<td><span class="att-student-id">' + esc(s.student_id) + '</span></td>' +
        '<td>' +
          '<div class="att-btn-group">' +
            '<button class="att-btn btn-p' + (rawStatus==='present'?' sel':'') + '" ' +
              'onclick="setStatus(' + eid + ',\'present\')" title="Present">' +
              '<span class="btn-letter">P</span>' +
              '<span class="btn-word">Present</span>' +
            '</button>' +
            '<button class="att-btn btn-a' + (rawStatus==='absent'?' sel':'') + '" ' +
              'onclick="setStatus(' + eid + ',\'absent\')" title="Absent">' +
              '<span class="btn-letter">A</span>' +
              '<span class="btn-word">Absent</span>' +
            '</button>' +
            '<button class="att-btn btn-l' + (rawStatus==='late'?' sel':'') + '" ' +
              'onclick="setStatus(' + eid + ',\'late\')" title="Late">' +
              '<span class="btn-letter">L</span>' +
              '<span class="btn-word">Late</span>' +
            '</button>' +
          '</div>' +
        '</td>' +
      '</tr>';
  });

  html += '</tbody></table></div></div>';

  // Save bar
  html +=
    '<div class="att-save-bar">' +
      '<span class="att-save-note">' +
        '<i class="fas fa-shield-halved"></i> Saves securely for ' + esc(dateDisp) +
      '</span>' +
      '<div id="saveCounter" class="att-save-note" style="margin-left:auto">' +
        '<i class="fas fa-circle-info"></i> ' +
        '<span id="saveCountTxt">All set as Present by default</span>' +
      '</div>' +
      '<button class="btn btn-primary btn-lg" onclick="saveAttendance()" id="saveBtn">' +
        '<i class="fas fa-check"></i> ' + (alreadyMarked ? 'Update Attendance' : 'Save Attendance') +
      '</button>' +
    '</div>';

  document.getElementById('markContent').innerHTML = html;
  updateSaveCounter();
}

// ── Set single student status ────────────────────────────────────
function setStatus(eid, status) {
  const card = document.getElementById('ac-' + eid);
  if (!card) return;

  card.className = 'att-card status-' + status;
  card.querySelectorAll('.att-btn').forEach(btn => btn.classList.remove('sel'));
  const map = { present: 'btn-p', absent: 'btn-a', late: 'btn-l' };
  card.querySelector('.' + map[status])?.classList.add('sel');

  hasUnsaved = true;
  updateSaveCounter();
}

// ── Mark all ─────────────────────────────────────────────────────
function markAll(status) {
  currentStudents.forEach(s => setStatus(s.enrollment_id, status));
}

// ── Update save counter ──────────────────────────────────────────
function updateSaveCounter() {
  let p=0, a=0, l=0, n=0;
  currentStudents.forEach(s => {
    const card = document.getElementById('ac-' + s.enrollment_id);
    if (!card) return;
    if (card.classList.contains('status-present'))     p++;
    else if (card.classList.contains('status-absent')) a++;
    else if (card.classList.contains('status-late'))   l++;
    else n++;
  });
  const el = document.getElementById('saveCountTxt');
  if (el) {
    let txt = 'Present: ' + p + '  ·  Absent: ' + a + '  ·  Late: ' + l;
    if (n > 0) txt += '  ·  <span style="color:var(--amber);font-weight:700">' + n + ' not marked</span>';
    el.innerHTML = txt;
  }
}

// ── Filter cards by search ───────────────────────────────────────
function filterCards() {
  const q = (document.getElementById('attSearch')?.value || '').toLowerCase().trim();
  let shown = 0;
  document.querySelectorAll('#attGrid .att-card').forEach(row => {
    const name = row.dataset.name || '';
    const vis  = !q || name.includes(q);
    row.style.display = vis ? '' : 'none';
    if (vis) shown++;
  });
  const c = document.getElementById('attCounter');
  if (c) c.textContent = shown + ' student' + (shown!==1?'s':'');
}

// ── SAVE ATTENDANCE ──────────────────────────────────────────────
async function saveAttendance() {
  const sessionId = document.getElementById('mSession').value;
  const classId   = document.getElementById('mClass').value;
  const date      = document.getElementById('mDate').value;

  if (!sessionId || !classId || !date || currentStudents.length === 0) {
    showToast('warning','Not Ready','Please load students first.');
    return;
  }

  // Collect all statuses from DOM
  // Collect statuses — skip status-none (unmarked)
  const records = [];
  let unmarked = 0;
  currentStudents.forEach(s => {
    const card = document.getElementById('ac-' + s.enrollment_id);
    if (!card) return;
    if (card.classList.contains('status-none')) { unmarked++; return; }
    let status = 'present';
    if (card.classList.contains('status-absent'))    status = 'absent';
    else if (card.classList.contains('status-late')) status = 'late';
    records.push({ enrollment_id: s.enrollment_id, status });
  });

  if (records.length === 0) {
    showToast('warning','Nothing to save','Please mark at least one student before saving.');
    return;
  }
  if (unmarked > 0) {
    const go = confirm(unmarked + ' student(s) have no status selected and will be skipped. Continue saving the rest?');
    if (!go) return;
  }

  const saveBtn    = document.getElementById('saveBtn');
  const headerBtn  = document.getElementById('headerSaveBtn');
  if (saveBtn)   { saveBtn.innerHTML   = '<i class="fas fa-spinner spin"></i> Saving…'; saveBtn.disabled = true; }
  if (headerBtn) { headerBtn.disabled = true; }

  const res = await ajax({
    action:     'save_attendance',
    session_id: sessionId,
    class_id:   classId,
    att_date:   date,
    records:    JSON.stringify(records),
  });

  if (saveBtn)   { saveBtn.innerHTML = '<i class="fas fa-check"></i> Update Attendance'; saveBtn.disabled = false; }
  if (headerBtn) { headerBtn.disabled = false; }

  if (!res.ok) { showToast('danger','Error',res.msg); return; }

  hasUnsaved = false;
  showToast('success','Saved!',res.msg);

  // Refresh calendar for this month
  loadCalendarMonth(calYear, calMonth);

  // Reload to show updated banner
  await loadAttendance();
}

// ── CALENDAR ─────────────────────────────────────────────────────
const DAY_NAMES = ['Sat','Sun','Mon','Tue','Wed','Thu','Fri'];
const MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];

async function loadCalendarMonth(year, month) {
  if (!calContext.sessionId || !calContext.classId) return;

  const monthStr = year + '-' + String(month+1).padStart(2,'0');
  const res = await ajax({
    action:     'fetch_date_status',
    session_id: calContext.sessionId,
    class_id:   calContext.classId,
    month:      monthStr,
  });

  if (!res.ok) return;

  // Index by date
  markedDates = {};
  (res.dates || []).forEach(d => { markedDates[d.att_date] = d; });

  renderCalendar(year, month);
}

function renderCalendar(year, month) {
  calYear  = year;
  calMonth = month;
  document.getElementById('calTitle').textContent = MONTH_NAMES[month] + ' ' + year;

  // First day of month — Bangladesh week starts Saturday (day 6 in JS)
  const firstDay = new Date(year, month, 1);
  // JS day: 0=Sun,1=Mon,...,6=Sat. We want 0=Sat, so shift
  let startOffset = (firstDay.getDay() + 1) % 7; // 0=Sat
  const daysInMonth = new Date(year, month+1, 0).getDate();
  const today = new Date().toISOString().slice(0,10);
  const selDate = document.getElementById('mDate')?.value || '';

  let html = '';
  // Day headers
  DAY_NAMES.forEach(d => {
    html += '<div class="cal-day-name' + (d==='Fri'?' friday':'') + '">' + d + '</div>';
  });

  // Empty cells before first day
  for (let i=0; i<startOffset; i++) html += '<div class="cal-day empty"></div>';

  // Days
  for (let d=1; d<=daysInMonth; d++) {
    const dateStr = year + '-' + String(month+1).padStart(2,'0') + '-' + String(d).padStart(2,'0');
    const dayOfWeek = (new Date(year, month, d).getDay() + 1) % 7;
    const isFri     = dayOfWeek === 6;
    const isToday   = dateStr === today;
    const isSel     = dateStr === selDate;
    const isMarked  = !!markedDates[dateStr];
    const isFuture  = dateStr > today;

    let cls = 'cal-day';
    if (isFri)    cls += ' friday';
    if (isToday)  cls += ' today';
    if (isSel)    cls += ' selected';
    else if (isMarked) cls += ' marked-day';
    if (isFuture) cls += ' future';

    const md = markedDates[dateStr];
    const tip = md ? 'Present: ' + md.present_count + ' / Total: ' + md.total : (isFuture ? 'Future date' : 'Not marked');

    html += '<div class="' + cls + '" ' +
      (!isFuture ? 'onclick="pickCalDate(\'' + dateStr + '\')" ' : '') +
      'title="' + tip + '">' +
      d +
      (isMarked ? '<span class="cal-dot"></span>' : '') +
      '</div>';
  }

  document.getElementById('calGrid').innerHTML = html;
}

function calNav(dir) {
  calMonth += dir;
  if (calMonth > 11) { calMonth = 0;  calYear++; }
  if (calMonth <  0) { calMonth = 11; calYear--; }
  loadCalendarMonth(calYear, calMonth);
}

function pickCalDate(date) {
  document.getElementById('mDate').value = date;
  loadAttendance();
}

// ── REPORT TAB ────────────────────────────────────────────────────
async function loadReport() {
  const sessionId = document.getElementById('rSession').value;
  const classId   = document.getElementById('rClass').value;
  const groupId   = document.getElementById('rGroup')?.value || '';

  if (!sessionId || !classId) { showToast('warning','Required','Please select session and class.'); return; }

  document.getElementById('reportContent').innerHTML =
    '<div class="att-placeholder"><i class="fas fa-spinner spin" style="font-size:36px"></i>' +
    '<h3 style="margin-top:12px;font-weight:500">Loading report…</h3></div>';

  const res = await ajax({
    action:     'fetch_report',
    session_id: sessionId,
    class_id:   classId,
    group_id:   groupId,
  });

  if (!res.ok) { showToast('danger','Error',res.msg); return; }
  renderReport(res);
}

function renderReport(res) {
  const students  = res.students || [];
  const totalDays = res.total_days || 0;
  const lastDate  = res.last_date;
  const avgPct    = res.class_avg_pct || 0;

  if (students.length === 0 || totalDays === 0) {
    document.getElementById('reportContent').innerHTML =
      '<div class="att-placeholder"><i class="fas fa-calendar-xmark"></i>' +
      '<h3>No attendance data</h3>' +
      '<p>No attendance has been marked for this class yet. Start by marking attendance in the <strong>Mark / Update</strong> tab.</p></div>';
    return;
  }

  const lastDateFmt = lastDate ? (function() {
    const p = lastDate.split('-');
    const m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return p[2] + ' ' + m[+p[1]-1] + ' ' + p[0];
  })() : '—';

  const totalPresent = students.reduce((s,r) => s + parseInt(r.present_days||0) + parseInt(r.late_days||0), 0);
  const totalAbsent  = students.reduce((s,r) => s + parseInt(r.absent_days||0), 0);
  const totalLate    = students.reduce((s,r) => s + parseInt(r.late_days||0), 0);

  // Stat cards
  let html =
    '<div class="rpt-stats">' +
      '<div class="rpt-stat">' +
        '<div class="rpt-stat-icon si-blue"><i class="fas fa-calendar-days"></i></div>' +
        '<div><div class="rpt-stat-val">' + totalDays + '</div><div class="rpt-stat-lbl">Days Marked</div></div>' +
      '</div>' +
      '<div class="rpt-stat">' +
        '<div class="rpt-stat-icon si-green"><i class="fas fa-circle-check"></i></div>' +
        '<div><div class="rpt-stat-val">' + totalPresent + '</div><div class="rpt-stat-lbl">Total Present</div></div>' +
      '</div>' +
      '<div class="rpt-stat">' +
        '<div class="rpt-stat-icon si-red"><i class="fas fa-circle-xmark"></i></div>' +
        '<div><div class="rpt-stat-val">' + totalAbsent + '</div><div class="rpt-stat-lbl">Total Absent</div></div>' +
      '</div>' +
      '<div class="rpt-stat">' +
        '<div class="rpt-stat-icon si-amber"><i class="fas fa-chart-line"></i></div>' +
        '<div><div class="rpt-stat-val">' + avgPct + '%</div><div class="rpt-stat-lbl">Class Average</div></div>' +
      '</div>' +
    '</div>';

  // Context bar
  html +=
    '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px">' +
      '<div style="font-size:13px;color:var(--text-muted)">' +
        '<strong>' + students.length + '</strong> students &nbsp;·&nbsp; ' +
        '<strong>' + totalDays + '</strong> school days marked &nbsp;·&nbsp; ' +
        'Last marked: <strong>' + esc(lastDateFmt) + '</strong>' +
      '</div>' +
    '</div>';

  // Table
  html += '<div class="card p-0"><div class="rpt-table-wrap"><table class="rpt-table">' +
    '<thead><tr>' +
      '<th style="width:90px"></th>' +
      '<th>Student</th>' +
      '<th>ID</th>' +
      '<th style="text-align:center">Present</th>' +
      '<th style="text-align:center">Absent</th>' +
      '<th style="text-align:center">Late</th>' +
      '<th>Attendance %</th>' +
    '</tr></thead><tbody>';

  students.forEach(s => {
    const p   = parseInt(s.present_days || 0);
    const a   = parseInt(s.absent_days  || 0);
    const l   = parseInt(s.late_days    || 0);
    const tot = parseInt(s.total_marked || 0);
    const pct = tot > 0 ? Math.round((p + l) / tot * 100) : 0;
    const pctColor = pct >= 75 ? 'var(--green)' : (pct >= 50 ? 'var(--amber)' : 'var(--red)');
    const photo = s.photo_path ? BASE_JS + '/' + s.photo_path : (s.gender === 'female' ? AVT_F : AVT_M);

    html +=
      '<tr>' +
        '<td><img class="rpt-photo" src="' + photo + '" alt="" onerror="this.src=\'' + (s.gender==='female'?AVT_F:AVT_M) + '\'"></td>' +
        '<td><div class="rpt-name">' + esc(s.full_name) + '</div>' +
             (s.roll_number ? '<div style="font-size:12px;color:var(--text-muted)">Roll ' + esc(s.roll_number) + '</div>' : '') + '</td>' +
        '<td><span class="rpt-id">' + esc(s.student_id) + '</span></td>' +
        '<td style="text-align:center;font-weight:700;color:var(--green)">' + p + '</td>' +
        '<td style="text-align:center;font-weight:700;color:var(--red)">'   + a + '</td>' +
        '<td style="text-align:center;font-weight:700;color:var(--amber)">' + l + '</td>' +
        '<td>' +
          '<div class="pct-bar-wrap">' +
            '<div class="pct-bar-track">' +
              '<div class="pct-bar-fill" style="width:' + pct + '%;background:' + pctColor + '"></div>' +
            '</div>' +
            '<div class="pct-val" style="color:' + pctColor + '">' + pct + '%</div>' +
          '</div>' +
          (pct < 75 ? '<div style="font-size:10.5px;color:var(--red);margin-top:2px"><i class="fas fa-triangle-exclamation"></i> Low attendance</div>' : '') +
        '</td>' +
      '</tr>';
  });

  html += '</tbody></table></div></div>';

  document.getElementById('reportContent').innerHTML = html;
}

// ── Init ─────────────────────────────────────────────────────────
onMarkSelectorChange();
onReportChange();

// Warn on leave if unsaved
window.addEventListener('beforeunload', function(e) {
  if (hasUnsaved) { e.preventDefault(); e.returnValue = ''; }
});
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
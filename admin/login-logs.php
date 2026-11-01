<?php
/**
 * EMS Admin — Login Logs
 * Full login/auth history across all roles — students, teachers, admin,
 * and failed attempts against unknown identifiers (security visibility).
 * AJAX-driven: filter, search, paginate, drill into one account's history.
 */
require_once __DIR__ . '/../includes/admin-session.php';

$pageTitle  = 'Login Logs';
$activeNav  = 'login-logs';
$breadcrumb = ['System', 'Login Logs'];

require_once __DIR__ . '/includes/admin-sidebar.php';
?>

<style>
/* ═══════════════════════════════════════════════════════════════
   LOGIN LOGS PAGE
   ═══════════════════════════════════════════════════════════════ */

/* ── Stat cards ──────────────────────────────────────────────────── */
.ll-stats {
  display: grid; grid-template-columns: repeat(4, 1fr);
  gap: 14px; margin-bottom: 20px;
}
.ll-stat {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--r-md); padding: 16px 18px;
  display: flex; align-items: center; gap: 12px;
}
.ll-stat-icon {
  width: 42px; height: 42px; border-radius: var(--r-sm);
  display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;
}
.ll-stat-val { font-size: 21px; font-weight: 800; color: var(--text); line-height: 1.1; }
.ll-stat-lbl { font-size: 11.5px; font-weight: 600; color: var(--text-muted); margin-top: 2px; }

/* ── Filter panel ────────────────────────────────────────────────── */
.ll-filter-card {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--r-md); margin-bottom: 18px; overflow: hidden;
}
.ll-filter-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 18px; cursor: pointer; user-select: none; gap: 10px;
}
.ll-filter-head-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ll-filter-title { font-size: 13.5px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px; }
.ll-filter-badge {
  background: var(--primary); color: #fff; font-size: 11px; font-weight: 700;
  padding: 2px 8px; border-radius: 20px; display: none;
}
.ll-filter-badge.show { display: inline-flex; }
.ll-filter-chevron { font-size: 13px; color: var(--text-muted); transition: transform .2s; }
.ll-filter-head.open .ll-filter-chevron { transform: rotate(180deg); }

.ll-filter-body { display: none; padding: 4px 18px 18px; border-top: 1px solid var(--border-light); }
.ll-filter-body.open { display: block; }

.ll-filter-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-top: 14px; }
.ll-fg { display: flex; flex-direction: column; gap: 5px; }
.ll-fg label { font-size: 11.5px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; }
.ll-ctrl {
  height: 38px; border: 1.5px solid var(--border); border-radius: var(--r-sm);
  font-family: inherit; font-size: 13px; color: var(--text);
  background: var(--bg-card); padding: 0 32px 0 11px; outline: none; width: 100%;
  transition: border-color .15s, box-shadow .15s;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 11px center;
}
.ll-ctrl:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.ll-ctrl.ll-date { background-image: none; padding-right: 11px; }
.ll-search-wrap { position: relative; }
.ll-search-wrap input {
  height: 38px; padding: 0 12px 0 34px; border: 1.5px solid var(--border); border-radius: var(--r-sm);
  font-family: inherit; font-size: 13px; color: var(--text); background: var(--bg-card);
  outline: none; width: 100%; transition: border-color .15s;
}
.ll-search-wrap input:focus { border-color: var(--primary); }
.ll-search-wrap i { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); font-size: 12px; color: var(--text-muted); }

.ll-filter-actions { display: flex; gap: 8px; margin-top: 14px; align-items: center; }

/* Role quick chips */
.ll-role-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.ll-role-chip {
  height: 38px; padding: 0 14px; border-radius: var(--r-sm);
  border: 1.5px solid var(--border); background: var(--bg-card);
  font-family: inherit; font-size: 12.5px; font-weight: 600; color: var(--text-muted);
  cursor: pointer; transition: all .13s; display: flex; align-items: center; gap: 6px;
}
.ll-role-chip:hover  { border-color: var(--primary); color: var(--primary); }
.ll-role-chip.active { background: var(--primary); border-color: var(--primary); color: #fff; }

/* Active filter chips (applied summary) */
.ll-active-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
.ll-achip {
  display: inline-flex; align-items: center; gap: 6px;
  background: var(--primary-xlight); border: 1px solid var(--primary-light);
  color: var(--primary); font-size: 12px; font-weight: 600;
  padding: 4px 6px 4px 10px; border-radius: 20px;
}
.ll-achip button {
  width: 16px; height: 16px; border-radius: 50%; border: none; background: rgba(37,99,235,.15);
  color: var(--primary); font-size: 9px; display: flex; align-items: center; justify-content: center;
  cursor: pointer; flex-shrink: 0;
}
.ll-achip button:hover { background: var(--primary); color: #fff; }

/* ── Table card ──────────────────────────────────────────────────── */
.ll-table-wrap {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--r-md); overflow: hidden; margin-bottom: 18px;
}
.ll-table-head-bar {
  display: flex; align-items: center; justify-content: space-between;
  padding: 13px 18px; border-bottom: 1px solid var(--border-light); flex-wrap: wrap; gap: 10px;
}
.ll-quick-dates { display: flex; gap: 6px; flex-wrap: wrap; }
.ll-qd-btn {
  height: 30px; padding: 0 11px; border-radius: 20px; border: 1.5px solid var(--border);
  background: var(--bg-card); font-family: inherit; font-size: 11.5px; font-weight: 600;
  color: var(--text-muted); cursor: pointer; transition: all .13s;
}
.ll-qd-btn:hover, .ll-qd-btn.active { border-color: var(--primary); background: var(--primary-xlight); color: var(--primary); }

.ll-table { width: 100%; border-collapse: collapse; }
.ll-table th {
  padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted);
  background: var(--bg-elevated); border-bottom: 1px solid var(--border); white-space: nowrap;
}
.ll-table td {
  padding: 12px 16px; font-size: 13px; color: var(--text-sub);
  border-bottom: 1px solid var(--border-light); vertical-align: middle;
}
.ll-table tbody tr:hover { background: #fafbfd; }
.ll-table tbody tr:last-child td { border-bottom: none; }
.ll-table tbody tr.row-failed { background: #fff8f8; }
.ll-table tbody tr.row-failed:hover { background: #fff1f1; }

/* User cell */
.ll-user-cell { display: flex; align-items: center; gap: 10px; }
.ll-avatar {
  width: 40px; height: 40px; border-radius: 50%; object-fit: cover; object-position: center top;
  border: 1.5px solid var(--border); flex-shrink: 0; background: var(--bg-elevated);
}
.ll-avatar-fallback {
  width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; font-weight: 700; color: #fff;
}
.ll-uname { font-size: 13px; font-weight: 700; color: var(--text); }
.ll-umeta { font-size: 11px; color: var(--text-muted); margin-top: 1px; }
.ll-unknown { color: var(--red); font-style: italic; }

/* Role badge */
.ll-role-badge {
  display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 700;
  padding: 2px 8px; border-radius: 20px; white-space: nowrap;
}
.lrb-student { background: var(--sky-bg);   color: var(--sky); }
.lrb-teacher { background: var(--green-bg); color: var(--green); }
.lrb-admin   { background: var(--purple-bg);color: var(--purple); }
.lrb-unknown { background: var(--red-bg);   color: var(--red); }

/* Status pill */
.ll-status-pill {
  display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700;
  padding: 4px 10px; border-radius: 20px; white-space: nowrap;
}
.llsp-ok   { background: var(--green-bg); color: var(--green); }
.llsp-fail { background: var(--red-bg);   color: var(--red); }

/* Device cell */
.ll-device-cell { display: flex; align-items: center; gap: 8px; }
.ll-device-icon {
  width: 28px; height: 28px; border-radius: 7px; background: var(--bg-elevated);
  display: flex; align-items: center; justify-content: center; font-size: 12px; color: var(--text-muted); flex-shrink: 0;
}
.ll-device-name { font-size: 12.5px; font-weight: 600; color: var(--text-sub); }
.ll-device-sub  { font-size: 11px; color: var(--text-muted); margin-top: 1px; }

/* IP / location cell */
.ll-ip { font-family: var(--font-mono, monospace); font-size: 12px; color: var(--text-sub); }
.ll-location { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; display: flex; align-items: center; gap: 4px; }

/* Time cell */
.ll-time { font-size: 12.5px; font-weight: 600; color: var(--text-sub); white-space: nowrap; }
.ll-time-ago { font-size: 11px; color: var(--text-muted); margin-top: 1px; }

/* History button */
.ll-history-btn {
  width: 30px; height: 30px; border-radius: var(--r-sm); border: 1.5px solid var(--border);
  background: var(--bg-card); display: flex; align-items: center; justify-content: center;
  font-size: 12px; color: var(--text-muted); cursor: pointer; transition: all .13s; flex-shrink: 0;
}
.ll-history-btn:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-xlight); }

/* Empty / loading */
.ll-empty { text-align: center; padding: 56px 24px; }
.ll-empty i  { font-size: 44px; color: var(--text-light); display: block; margin: 0 auto 12px; }
.ll-empty h3 { font-size: 16px; font-weight: 700; margin-bottom: 6px; }
.ll-empty p  { font-size: 13.5px; color: var(--text-muted); max-width: 380px; margin: 0 auto; line-height: 1.6; }

.spin { animation: spin .65s linear infinite; display: inline-block; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Pagination ──────────────────────────────────────────────────── */
.ll-pagination {
  display: flex; align-items: center; justify-content: space-between;
  padding: 13px 18px; border-top: 1px solid var(--border-light); flex-wrap: wrap; gap: 10px;
}
.ll-page-info { font-size: 12.5px; color: var(--text-muted); }
.ll-page-info strong { color: var(--text); }
.ll-page-btns { display: flex; gap: 5px; }
.ll-page-btn {
  min-width: 32px; height: 32px; padding: 0 8px; border-radius: var(--r-sm);
  border: 1.5px solid var(--border); background: var(--bg-card); font-family: inherit;
  font-size: 12.5px; font-weight: 600; color: var(--text-muted); cursor: pointer; transition: all .13s;
}
.ll-page-btn:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
.ll-page-btn.active { background: var(--primary); border-color: var(--primary); color: #fff; }
.ll-page-btn:disabled { opacity: .4; cursor: not-allowed; }

/* ── Drill-in drawer ─────────────────────────────────────────────── */
.ll-drawer-overlay {
  display: none; position: fixed; inset: 0; background: rgba(12,15,26,.5);
  backdrop-filter: blur(2px); z-index: 1099;
}
.ll-drawer-overlay.open { display: block; }
.ll-drawer {
  position: fixed; top: 0; right: 0; bottom: 0; width: min(520px, 100vw);
  background: var(--bg-card); box-shadow: -10px 0 40px rgba(12,15,26,.16); z-index: 1100;
  display: flex; flex-direction: column; transform: translateX(110%);
  transition: transform .28s cubic-bezier(.25,.46,.45,.94);
}
.ll-drawer.open { transform: translateX(0); }
.lld-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  padding: 18px 22px 14px; border-bottom: 1px solid var(--border); flex-shrink: 0; gap: 12px;
}
.lld-title { font-size: 15.5px; font-weight: 800; color: var(--text); }
.lld-sub { font-size: 12.5px; color: var(--text-muted); margin-top: 4px; }
.lld-close {
  width: 32px; height: 32px; border-radius: var(--r-sm); border: 1.5px solid var(--border);
  background: var(--bg); display: flex; align-items: center; justify-content: center;
  font-size: 14px; color: var(--text-muted); cursor: pointer; transition: all .13s; flex-shrink: 0;
}
.lld-close:hover { border-color: var(--red); color: var(--red); background: var(--red-bg); }
.lld-stats { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; padding: 14px 22px; border-bottom: 1px solid var(--border-light); flex-shrink: 0; }
.lld-stat-box { background: var(--bg-elevated); border: 1px solid var(--border); border-radius: var(--r-sm); padding: 9px 10px; text-align: center; }
.lld-stat-val { font-size: 16px; font-weight: 800; }
.lld-stat-lbl { font-size: 9.5px; font-weight: 600; color: var(--text-muted); margin-top: 2px; text-transform: uppercase; letter-spacing: .03em; }
.lld-body { flex: 1; overflow-y: auto; padding: 14px 22px; }
.lld-entry {
  display: flex; align-items: flex-start; gap: 10px; padding: 10px 0;
  border-bottom: 1px solid var(--border-light); font-size: 12.5px;
}
.lld-entry:last-child { border-bottom: none; }
.lld-entry-icon { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; flex-shrink: 0; }
.lld-entry-info { flex: 1; min-width: 0; }
.lld-entry-meta { color: var(--text-muted); font-size: 11.5px; margin-top: 2px; }

/* ── Responsive ──────────────────────────────────────────────────── */
@media (max-width: 1100px) {
  .ll-filter-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 900px) {
  .ll-stats { grid-template-columns: 1fr 1fr; }
  .ll-table-wrap { overflow-x: auto; }
  .ll-table { min-width: 760px; }
}
@media (max-width: 680px) {
  .ll-filter-grid { grid-template-columns: 1fr; }
  .ll-role-chips { width: 100%; }
  .ll-drawer { width: 100%; }
  .lld-stats { grid-template-columns: 1fr 1fr 1fr; }
}
@media (max-width: 540px) {
  .ll-stats { grid-template-columns: 1fr 1fr; }
  .ll-pagination { flex-direction: column; align-items: stretch; text-align: center; }
  .ll-page-btns { justify-content: center; }
}
</style>

<!-- ════════════════════════════════════════════════════════════
     PAGE HEADER
     ════════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="page-header-top">
    <div>
      <div class="page-title">Login Logs</div>
      <div class="page-sub">
        <i class="fas fa-shield-halved"></i>
        Track every login attempt — device, location, and success/failure
      </div>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     STATS
     ════════════════════════════════════════════════════════════ -->
<div class="ll-stats" id="llStats">
  <div class="ll-stat"><div class="ll-stat-icon si-blue"><i class="fas fa-spinner spin"></i></div><div><div class="ll-stat-val">—</div><div class="ll-stat-lbl">Loading…</div></div></div>
</div>

<!-- ════════════════════════════════════════════════════════════
     FILTER PANEL
     ════════════════════════════════════════════════════════════ -->
<div class="ll-filter-card">
  <div class="ll-filter-head" id="filterHead" onclick="toggleFilterPanel()">
    <div class="ll-filter-head-left">
      <div class="ll-filter-title"><i class="fas fa-filter"></i> Filters</div>
      <span class="ll-filter-badge" id="filterCountBadge">0</span>
      <div class="ll-role-chips" onclick="event.stopPropagation()">
        <button class="ll-role-chip active" data-role="" onclick="setRoleChip(this,'')"><i class="fas fa-layer-group"></i> All</button>
        <button class="ll-role-chip" data-role="student" onclick="setRoleChip(this,'student')"><i class="fas fa-user-graduate"></i> Students</button>
        <button class="ll-role-chip" data-role="teacher" onclick="setRoleChip(this,'teacher')"><i class="fas fa-chalkboard-teacher"></i> Teachers</button>
        <button class="ll-role-chip" data-role="admin" onclick="setRoleChip(this,'admin')"><i class="fas fa-user-shield"></i> Admin</button>
        <button class="ll-role-chip" data-role="unknown" onclick="setRoleChip(this,'unknown')"><i class="fas fa-triangle-exclamation"></i> Unknown</button>
      </div>
    </div>
    <i class="fas fa-chevron-down ll-filter-chevron"></i>
  </div>

  <div class="ll-filter-body" id="filterBody">
    <div class="ll-filter-grid">
      <div class="ll-fg">
        <label><i class="fas fa-magnifying-glass"></i> Search</label>
        <div class="ll-search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="fSearch" placeholder="Name, ID, username, or IP…" oninput="debouncedFilter()">
        </div>
      </div>
      <div class="ll-fg">
        <label><i class="fas fa-circle-check"></i> Status</label>
        <select id="fStatus" class="ll-ctrl" onchange="applyFilters()">
          <option value="">All Attempts</option>
          <option value="success">Successful Only</option>
          <option value="failed">Failed Only</option>
        </select>
      </div>
      <div class="ll-fg">
        <label><i class="fas fa-display"></i> Device</label>
        <select id="fDevice" class="ll-ctrl" onchange="applyFilters()">
          <option value="">All Devices</option>
        </select>
      </div>
      <div class="ll-fg"></div>
      <div class="ll-fg">
        <label><i class="fas fa-calendar-day"></i> From Date</label>
        <input type="date" id="fDateFrom" class="ll-ctrl ll-date" onchange="applyFilters()">
      </div>
      <div class="ll-fg">
        <label><i class="fas fa-calendar-day"></i> To Date</label>
        <input type="date" id="fDateTo" class="ll-ctrl ll-date" onchange="applyFilters()">
      </div>
    </div>

    <div class="ll-filter-actions">
      <button class="btn btn-sm btn-outline" onclick="resetFilters()"><i class="fas fa-rotate-left"></i> Reset All</button>
    </div>
  </div>
</div>

<!-- Active filter chips summary -->
<div class="ll-active-chips" id="activeChips" style="display:none"></div>

<!-- ════════════════════════════════════════════════════════════
     TABLE
     ════════════════════════════════════════════════════════════ -->
<div class="ll-table-wrap">
  <div class="ll-table-head-bar">
    <div class="ll-quick-dates">
      <button class="ll-qd-btn" onclick="quickDate('today',this)"><i class="fas fa-sun"></i> Today</button>
      <button class="ll-qd-btn" onclick="quickDate('week',this)"><i class="fas fa-calendar-week"></i> Last 7 Days</button>
      <button class="ll-qd-btn" onclick="quickDate('month',this)"><i class="fas fa-calendar"></i> This Month</button>
    </div>
    <span id="resultCount" style="font-size:12.5px;color:var(--text-muted);font-weight:600"></span>
  </div>

  <div class="table-wrap">
    <table class="ll-table">
      <thead>
        <tr>
          <th>User</th>
          <th>Role</th>
          <th>Device</th>
          <th>IP / Location</th>
          <th>Time</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="logsBody">
        <tr><td colspan="7" style="text-align:center;padding:48px"><i class="fas fa-spinner spin" style="font-size:24px;color:var(--text-muted)"></i></td></tr>
      </tbody>
    </table>
  </div>

  <div class="ll-pagination" id="paginationBar"></div>
</div>

<!-- ════════════════════════════════════════════════════════════
     DRILL-IN DRAWER (per-account login history)
     ════════════════════════════════════════════════════════════ -->
<div class="ll-drawer-overlay" id="lldOverlay" onclick="closeHistoryDrawer()"></div>
<div class="ll-drawer" id="lldDrawer">
  <div class="lld-header">
    <div>
      <div class="lld-title" id="lldTitle">Login History</div>
      <div class="lld-sub" id="lldSub"></div>
    </div>
    <button class="lld-close" onclick="closeHistoryDrawer()"><i class="fas fa-xmark"></i></button>
  </div>
  <div class="lld-stats" id="lldStats"></div>
  <div class="lld-body" id="lldBody">
    <div style="text-align:center;padding:32px"><i class="fas fa-spinner spin" style="font-size:22px;color:var(--text-muted)"></i></div>
  </div>
</div>

<!-- CSRF -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<script>
/* ════════════════════════════════════════════════════════════════
   LOGIN LOGS — Full AJAX JS
   ════════════════════════════════════════════════════════════════ */
const AJAX_URL = '<?= BASE_URL ?>/admin/login-logs-ajax.php';
const BASE_JS  = '<?= BASE_URL ?>';

const AVATARS = {
  student_male:   BASE_JS + '/assets/images/student-avatar-m.jpg',
  student_female: BASE_JS + '/assets/images/student-avatar-f.jpg',
  teacher_male:   BASE_JS + '/assets/images/teacher-avatar-m.jpg',
  teacher_female: BASE_JS + '/assets/images/teacher-avatar-f.jpg',
  admin:          BASE_JS + '/assets/images/admin-avatar.jpg',
};

let currentPage     = 1;
let totalPages      = 1;
let roleFilter      = '';
let searchDebounce  = null;
let lastLogs         = [];

/* ── CSRF ── */
function getToken() { return document.getElementById('csrfToken').value; }
function setToken(t){ document.getElementById('csrfToken').value = t; }

/* ── AJAX ──
   Requests are serialized through ajaxQueue. The backend rotates the CSRF
   token on every request and invalidates the previous one immediately, so
   two requests in flight at once will always cause the second to fail with
   "Session expired." Queuing guarantees only one request is ever in flight,
   no matter how many places in the UI call ajax() around the same time. */
let ajaxQueue = Promise.resolve();
function ajax(data) {
  const run = () => ajaxInner(data);
  const result = ajaxQueue.then(run, run);
  ajaxQueue = result.catch(() => {}); // keep the chain alive even if a call fails
  return result;
}
async function ajaxInner(data) {
  data.csrf_token = getToken();
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => { if (v !== null && v !== undefined) fd.append(k, v); });
  const res  = await fetch(AJAX_URL, { method:'POST', body:fd });
  const json = await res.json();
  if (json.csrf) setToken(json.csrf);
  return json;
}

function esc(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

/* ── Date helpers ── */
function fmtDateTime(dt) {
  if (!dt) return '—';
  const d = new Date(dt.replace(' ', 'T'));
  return d.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' }) + ', ' +
         d.toLocaleTimeString('en-GB', { hour:'2-digit', minute:'2-digit' });
}
function timeAgo(dt) {
  if (!dt) return '';
  const diffMs = Date.now() - new Date(dt.replace(' ', 'T')).getTime();
  const mins = Math.floor(diffMs / 60000);
  if (mins < 1)   return 'just now';
  if (mins < 60)  return mins + 'm ago';
  const hrs = Math.floor(mins / 60);
  if (hrs < 24)   return hrs + 'h ago';
  const days = Math.floor(hrs / 24);
  if (days < 30)  return days + 'd ago';
  return Math.floor(days/30) + 'mo ago';
}

/* ── Device icon mapping ── */
function deviceIcon(type) {
  const t = (type || '').toLowerCase();
  if (t.includes('smartphone') || t.includes('mobile')) return 'fa-mobile-screen';
  if (t.includes('tablet')) return 'fa-tablet-screen-button';
  if (t.includes('desktop')) return 'fa-desktop';
  return 'fa-display';
}

/* ════════════════════════════════════════════════════════════════
   FILTER PANEL TOGGLE
   ════════════════════════════════════════════════════════════════ */
function toggleFilterPanel() {
  document.getElementById('filterHead').classList.toggle('open');
  document.getElementById('filterBody').classList.toggle('open');
}

function setRoleChip(btn, role) {
  roleFilter = role;
  document.querySelectorAll('.ll-role-chip').forEach(c => c.classList.toggle('active', c === btn));
  currentPage = 1;
  loadLogs();
}

function debouncedFilter() {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => { currentPage = 1; loadLogs(); }, 350);
}

function applyFilters() {
  currentPage = 1;
  loadLogs();
}

function resetFilters() {
  document.getElementById('fSearch').value    = '';
  document.getElementById('fStatus').value    = '';
  document.getElementById('fDevice').value    = '';
  document.getElementById('fDateFrom').value  = '';
  document.getElementById('fDateTo').value    = '';
  roleFilter = '';
  document.querySelectorAll('.ll-role-chip').forEach(c => c.classList.toggle('active', c.dataset.role === ''));
  document.querySelectorAll('.ll-qd-btn').forEach(b => b.classList.remove('active'));
  currentPage = 1;
  loadLogs();
}

/* Quick date presets */
function quickDate(range, btn) {
  document.querySelectorAll('.ll-qd-btn').forEach(b => b.classList.remove('active'));
  const today = new Date();
  const fmt = d => d.toISOString().split('T')[0];

  if (range === 'today') {
    document.getElementById('fDateFrom').value = fmt(today);
    document.getElementById('fDateTo').value   = fmt(today);
    btn.classList.add('active');
  } else if (range === 'week') {
    const past = new Date(today); past.setDate(past.getDate() - 6);
    document.getElementById('fDateFrom').value = fmt(past);
    document.getElementById('fDateTo').value   = fmt(today);
    btn.classList.add('active');
  } else if (range === 'month') {
    const first = new Date(today.getFullYear(), today.getMonth(), 1);
    document.getElementById('fDateFrom').value = fmt(first);
    document.getElementById('fDateTo').value   = fmt(today);
    btn.classList.add('active');
  }
  currentPage = 1;
  loadLogs();
}

/* ── Build active-filter chip summary ── */
function renderActiveChips() {
  const chips = [];
  const search = document.getElementById('fSearch').value.trim();
  const status = document.getElementById('fStatus').value;
  const device = document.getElementById('fDevice').value;
  const from   = document.getElementById('fDateFrom').value;
  const to     = document.getElementById('fDateTo').value;

  if (roleFilter) chips.push({ key:'role', label: 'Role: ' + roleFilter.charAt(0).toUpperCase() + roleFilter.slice(1) });
  if (search)     chips.push({ key:'search', label: 'Search: "' + search + '"' });
  if (status)     chips.push({ key:'status', label: status === 'success' ? 'Successful only' : 'Failed only' });
  if (device)     chips.push({ key:'device', label: 'Device: ' + device });
  if (from || to) chips.push({ key:'date', label: 'Date: ' + (from||'…') + ' → ' + (to||'…') });

  const wrap = document.getElementById('activeChips');
  const badge = document.getElementById('filterCountBadge');
  badge.textContent = chips.length;
  badge.classList.toggle('show', chips.length > 0);

  if (!chips.length) { wrap.style.display = 'none'; wrap.innerHTML = ''; return; }
  wrap.style.display = 'flex';
  wrap.innerHTML = chips.map(c =>
    `<span class="ll-achip">${esc(c.label)} <button onclick="removeChip('${c.key}')"><i class="fas fa-xmark"></i></button></span>`
  ).join('');
}

function removeChip(key) {
  if (key === 'role') {
    roleFilter = '';
    document.querySelectorAll('.ll-role-chip').forEach(c => c.classList.toggle('active', c.dataset.role === ''));
  }
  if (key === 'search') document.getElementById('fSearch').value = '';
  if (key === 'status') document.getElementById('fStatus').value = '';
  if (key === 'device') document.getElementById('fDevice').value = '';
  if (key === 'date') { document.getElementById('fDateFrom').value = ''; document.getElementById('fDateTo').value = ''; document.querySelectorAll('.ll-qd-btn').forEach(b=>b.classList.remove('active')); }
  currentPage = 1;
  loadLogs();
}

/* ════════════════════════════════════════════════════════════════
   LOAD LOGS
   ════════════════════════════════════════════════════════════════ */
async function loadLogs() {
  renderActiveChips();

  document.getElementById('logsBody').innerHTML =
    '<tr><td colspan="7" style="text-align:center;padding:48px"><i class="fas fa-spinner spin" style="font-size:24px;color:var(--text-muted)"></i></td></tr>';

  const payload = {
    action: 'fetch_logs',
    role: roleFilter,
    status: document.getElementById('fStatus').value,
    device: document.getElementById('fDevice').value,
    date_from: document.getElementById('fDateFrom').value,
    date_to: document.getElementById('fDateTo').value,
    search: document.getElementById('fSearch').value.trim(),
    page: currentPage,
  };

  const r = await ajax(payload);
  if (!r.ok) {
    document.getElementById('logsBody').innerHTML =
      `<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--red)"><i class="fas fa-triangle-exclamation"></i> ${esc(r.msg)}</td></tr>`;
    return;
  }

  lastLogs    = r.logs || [];
  currentPage = r.page;
  totalPages  = r.total_pages;

  renderStats(r.stats);
  renderTable(r.logs, r.total);
  renderPagination(r.page, r.total_pages, r.total, r.per_page);
}

/* ── Stat cards ── */
function renderStats(stats) {
  document.getElementById('llStats').innerHTML = `
    <div class="ll-stat">
      <div class="ll-stat-icon si-blue"><i class="fas fa-right-to-bracket"></i></div>
      <div><div class="ll-stat-val">${stats.today}</div><div class="ll-stat-lbl">Logins Today</div></div>
    </div>
    <div class="ll-stat">
      <div class="ll-stat-icon si-red"><i class="fas fa-triangle-exclamation"></i></div>
      <div><div class="ll-stat-val" style="color:${stats.failed_today>0?'var(--red)':'var(--text)'}">${stats.failed_today}</div><div class="ll-stat-lbl">Failed Today</div></div>
    </div>
    <div class="ll-stat">
      <div class="ll-stat-icon si-green"><i class="fas fa-calendar-week"></i></div>
      <div><div class="ll-stat-val">${stats.week}</div><div class="ll-stat-lbl">Last 7 Days</div></div>
    </div>
    <div class="ll-stat">
      <div class="ll-stat-icon si-purple"><i class="fas fa-globe"></i></div>
      <div><div class="ll-stat-val">${stats.unique_ips_week}</div><div class="ll-stat-lbl">Unique IPs (7d)</div></div>
    </div>`;
}

/* ── Table rows ── */
function renderTable(logs, total) {
  document.getElementById('resultCount').textContent = total + ' record' + (total !== 1 ? 's' : '');

  if (!logs.length) {
    document.getElementById('logsBody').innerHTML = `<tr><td colspan="7">
      <div class="ll-empty">
        <i class="fas fa-shield-halved"></i>
        <h3>No login records found</h3>
        <p>Try adjusting your filters, or check back after the next login.</p>
      </div>
    </td></tr>`;
    return;
  }

  document.getElementById('logsBody').innerHTML = logs.map(log => {
    const isKnown = !!log.is_known;
    const role    = log.display_role;

    // Avatar / fallback
    const avatarHtml = avatarFor(log, role, isKnown);

    const roleBadge = !isKnown
      ? '<span class="ll-role-badge lrb-unknown"><i class="fas fa-triangle-exclamation"></i> Unknown</span>'
      : role === 'student' ? '<span class="ll-role-badge lrb-student"><i class="fas fa-user-graduate"></i> Student</span>'
      : role === 'teacher' ? '<span class="ll-role-badge lrb-teacher"><i class="fas fa-chalkboard-teacher"></i> Teacher</span>'
      : '<span class="ll-role-badge lrb-admin"><i class="fas fa-user-shield"></i> Admin</span>';

    const nameLine = isKnown
      ? esc(log.display_name)
      : `<span class="ll-unknown">"${esc(log.display_name)}"</span>`;
    const metaLine = isKnown
      ? (role === 'student' ? esc(log.display_id || '') : '')
      : 'No matching account';

    const statusPill = log.success
      ? '<span class="ll-status-pill llsp-ok"><i class="fas fa-circle-check"></i> Success</span>'
      : '<span class="ll-status-pill llsp-fail"><i class="fas fa-circle-xmark"></i> Failed</span>';

    const historyBtn = isKnown
      ? `<button class="ll-history-btn" title="View full history for this account" onclick="openHistoryDrawer(${log.log_id}, ${esc(JSON.stringify(log.display_name))})"><i class="fas fa-list"></i></button>`
      : '';

    return `<tr class="${!log.success ? 'row-failed' : ''}">
      <td>
        <div class="ll-user-cell">
          ${avatarHtml}
          <div>
            <div class="ll-uname">${nameLine}</div>
            ${metaLine ? `<div class="ll-umeta">${esc(metaLine)}</div>` : ''}
          </div>
        </div>
      </td>
      <td>${roleBadge}</td>
      <td>
        <div class="ll-device-cell">
          <div class="ll-device-icon"><i class="fas ${deviceIcon(log.device_type)}"></i></div>
          <div>
            <div class="ll-device-name">${esc(log.device_type || 'Unknown')}</div>
            <div class="ll-device-sub">${esc(log.browser || '')}${log.os ? ' · ' + esc(log.os) : ''}</div>
          </div>
        </div>
      </td>
      <td>
        <div class="ll-ip">${esc(log.ip_address || '—')}</div>
        <div class="ll-location"><i class="fas fa-location-dot" style="font-size:9px"></i> ${esc(log.location || 'Unknown')}</div>
      </td>
      <td>
        <div class="ll-time">${fmtDateTime(log.login_at)}</div>
        <div class="ll-time-ago">${timeAgo(log.login_at)}</div>
      </td>
      <td>${statusPill}</td>
      <td>${historyBtn}</td>
    </tr>`;
  }).join('');
}

/* ── Avatar resolution ──
   Known users: real photo if available, else the role+gender default
   avatar image (never a plain colored letter — those images exist for
   exactly this purpose). Unknown/failed attempts: a letter from whatever
   name was actually typed, or a plain em dash if there's nothing usable
   to show (blank input, deleted account). */
function avatarFor(log, role, isKnown) {
  if (isKnown) {
    if (log.display_photo) {
      return `<img src="${BASE_JS}/${esc(log.display_photo)}" class="ll-avatar" alt=""
               onerror="this.outerHTML=roleAvatar('${role}',${JSON.stringify(log.display_gender)})">`;
    }
    return roleAvatar(role, log.display_gender);
  }
  return unknownAvatar(log.display_name);
}

function roleAvatar(role, gender) {
  let src;
  if (role === 'admin') src = AVATARS.admin;
  else if (role === 'teacher') src = gender === 'female' ? AVATARS.teacher_female : AVATARS.teacher_male;
  else src = gender === 'female' ? AVATARS.student_female : AVATARS.student_male;
  return `<img src="${src}" class="ll-avatar" alt="">`;
}

function unknownAvatar(name) {
  const trimmed  = (name || '').trim();
  // Placeholder strings like "(blank)" or "(deleted account)" carry no
  // usable identity — show an em dash. Anything else typed into the login
  // box shows its first character as a letter.
  const isPlaceholder = !trimmed || trimmed.startsWith('(');
  const initial = isPlaceholder ? '—' : trimmed.charAt(0).toUpperCase();
  return `<div class="ll-avatar-fallback" style="background:#dc2626">${initial}</div>`;
}

/* ── Pagination ── */
function renderPagination(page, totalPages, total, perPage) {
  const bar = document.getElementById('paginationBar');
  if (total === 0) { bar.innerHTML = ''; return; }

  const startItem = (page - 1) * perPage + 1;
  const endItem   = Math.min(page * perPage, total);

  let buttons = '';
  buttons += `<button class="ll-page-btn" onclick="goToPage(1)" ${page<=1?'disabled':''} title="First"><i class="fas fa-angles-left"></i></button>`;
  buttons += `<button class="ll-page-btn" onclick="goToPage(${page-1})" ${page<=1?'disabled':''} title="Previous"><i class="fas fa-angle-left"></i></button>`;

  const range = [];
  const span = 2;
  for (let p = Math.max(1, page-span); p <= Math.min(totalPages, page+span); p++) range.push(p);
  if (range[0] > 1) buttons += `<button class="ll-page-btn" onclick="goToPage(1)">1</button>` + (range[0] > 2 ? '<span style="padding:0 4px;color:var(--text-light)">…</span>' : '');
  range.forEach(p => {
    buttons += `<button class="ll-page-btn ${p===page?'active':''}" onclick="goToPage(${p})">${p}</button>`;
  });
  if (range[range.length-1] < totalPages) buttons += (range[range.length-1] < totalPages-1 ? '<span style="padding:0 4px;color:var(--text-light)">…</span>' : '') + `<button class="ll-page-btn" onclick="goToPage(${totalPages})">${totalPages}</button>`;

  buttons += `<button class="ll-page-btn" onclick="goToPage(${page+1})" ${page>=totalPages?'disabled':''} title="Next"><i class="fas fa-angle-right"></i></button>`;
  buttons += `<button class="ll-page-btn" onclick="goToPage(${totalPages})" ${page>=totalPages?'disabled':''} title="Last"><i class="fas fa-angles-right"></i></button>`;

  bar.innerHTML = `
    <div class="ll-page-info">Showing <strong>${startItem}–${endItem}</strong> of <strong>${total}</strong></div>
    <div class="ll-page-btns">${buttons}</div>`;
}

function goToPage(p) {
  if (p < 1 || p > totalPages || p === currentPage) return;
  currentPage = p;
  loadLogs();
  document.querySelector('.ll-table-wrap')?.scrollIntoView({ behavior:'smooth', block:'start' });
}

/* ════════════════════════════════════════════════════════════════
   FILTER META (device dropdown options)
   ════════════════════════════════════════════════════════════════ */
async function loadFilterMeta() {
  const r = await ajax({ action: 'fetch_filter_meta' });
  if (!r.ok) return;
  const sel = document.getElementById('fDevice');
  (r.devices || []).forEach(d => {
    const opt = document.createElement('option');
    opt.value = d; opt.textContent = d;
    sel.appendChild(opt);
  });
}

/* ════════════════════════════════════════════════════════════════
   HISTORY DRAWER (drill-in per account)
   ════════════════════════════════════════════════════════════════ */
function openHistoryDrawer(logId, displayName) {
  document.getElementById('lldOverlay').classList.add('open');
  document.getElementById('lldDrawer').classList.add('open');
  document.body.style.overflow = 'hidden';

  document.getElementById('lldTitle').textContent = displayName;
  document.getElementById('lldSub').textContent = 'Full login history for this account';
  document.getElementById('lldStats').innerHTML = '';
  document.getElementById('lldBody').innerHTML =
    '<div style="text-align:center;padding:32px"><i class="fas fa-spinner spin" style="font-size:22px;color:var(--text-muted)"></i></div>';

  loadHistoryByLog(logId);
}

async function loadHistoryByLog(logId) {
  // The browser only ever has log_id, never the raw sys_uid (it's
  // intentionally stripped from every row for privacy). The server
  // resolves log_id -> sys_uid internally and returns the full history.
  const r = await ajax({ action: 'get_history_by_log', log_id: logId });
  if (!r.ok) {
    document.getElementById('lldBody').innerHTML =
      `<div style="text-align:center;padding:32px;color:var(--red)"><i class="fas fa-triangle-exclamation"></i> ${esc(r.msg || 'Failed to load history.')}</div>`;
    return;
  }
  renderHistoryResult(r);
}

/* Since sys_uid is intentionally never sent to the browser, the History
   drawer uses the rich data already present in the row itself (role,
   display name/id) to show a clear, useful summary without an extra
   round trip that would require exposing internal identifiers. */
function renderHistoryFallback(row) {
  document.getElementById('lldStats').innerHTML = `
    <div class="lld-stat-box"><div class="lld-stat-val">${row.success ? '1' : '0'}</div><div class="lld-stat-lbl">This Entry</div></div>
    <div class="lld-stat-box"><div class="lld-stat-val">${row.display_role ? row.display_role.charAt(0).toUpperCase()+row.display_role.slice(1) : '—'}</div><div class="lld-stat-lbl">Role</div></div>
    <div class="lld-stat-box"><div class="lld-stat-val">${row.success ? '✓' : '✕'}</div><div class="lld-stat-lbl">Status</div></div>`;

  document.getElementById('lldBody').innerHTML = `
    <div class="lld-entry">
      <div class="lld-entry-icon" style="background:${row.success?'var(--green-bg)':'var(--red-bg)'};color:${row.success?'var(--green)':'var(--red)'}">
        <i class="fas ${row.success?'fa-circle-check':'fa-circle-xmark'}"></i>
      </div>
      <div class="lld-entry-info">
        <div><strong>${fmtDateTime(row.login_at)}</strong></div>
        <div class="lld-entry-meta">
          ${esc(row.device_type||'Unknown')} · ${esc(row.browser||'')} ${esc(row.os||'')}<br>
          IP: ${esc(row.ip_address||'—')} · ${esc(row.location||'Unknown')}
        </div>
      </div>
    </div>
    <div style="margin-top:14px;padding:12px;background:var(--bg-elevated);border-radius:var(--r-sm);font-size:12.5px;color:var(--text-muted);text-align:center">
      <i class="fas fa-circle-info"></i> Use the Search filter above with this account's name or ID to see all related entries in the main table.
    </div>`;
}

function renderHistoryResult(r) {
  document.getElementById('lldStats').innerHTML = `
    <div class="lld-stat-box"><div class="lld-stat-val">${r.stats.total_attempts}</div><div class="lld-stat-lbl">Total Attempts</div></div>
    <div class="lld-stat-box"><div class="lld-stat-val" style="color:${r.stats.failed_attempts>0?'var(--red)':'var(--text)'}">${r.stats.failed_attempts}</div><div class="lld-stat-lbl">Failed</div></div>
    <div class="lld-stat-box"><div class="lld-stat-val" style="font-size:12px">${r.stats.last_success ? fmtDateTime(r.stats.last_success).split(',')[0] : '—'}</div><div class="lld-stat-lbl">Last Success</div></div>`;

  if (!r.logs.length) {
    document.getElementById('lldBody').innerHTML = '<div class="ll-empty" style="padding:24px"><i class="fas fa-inbox"></i><p>No history found.</p></div>';
    return;
  }

  document.getElementById('lldBody').innerHTML = r.logs.map(log => `
    <div class="lld-entry">
      <div class="lld-entry-icon" style="background:${log.success?'var(--green-bg)':'var(--red-bg)'};color:${log.success?'var(--green)':'var(--red)'}">
        <i class="fas ${log.success?'fa-circle-check':'fa-circle-xmark'}"></i>
      </div>
      <div class="lld-entry-info">
        <div><strong>${fmtDateTime(log.login_at)}</strong></div>
        <div class="lld-entry-meta">
          ${esc(log.device_type||'Unknown')} · ${esc(log.browser||'')} ${esc(log.os||'')}<br>
          IP: ${esc(log.ip_address||'—')} · ${esc(log.location||'Unknown')}
        </div>
      </div>
    </div>`).join('');
}

function closeHistoryDrawer() {
  document.getElementById('lldOverlay').classList.remove('open');
  document.getElementById('lldDrawer').classList.remove('open');
  document.body.style.overflow = '';
}

/* ── Init ──
   IMPORTANT: these must run sequentially, not in parallel. csrf_rotate()
   issues a brand-new token on every request and the old one is invalidated
   immediately. Firing both calls at once means both read the same starting
   token, but only the first to land on the server gets accepted — the
   second arrives after rotation and fails with "Session expired." */
(async () => {
  await loadFilterMeta();
  await loadLogs();
})();
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
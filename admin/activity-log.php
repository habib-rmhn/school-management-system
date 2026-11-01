<?php
/**
 * EMS Admin — Activity Log
 * Full system activity history — students (login/logout), teachers, and
 * admin actions. Fully AJAX-driven: filter, search, paginate — no GET
 * params, no page reloads. Avatars use the actor's real photo when
 * available, falling back to role+gender-aware default avatars.
 */
require_once __DIR__ . '/../includes/admin-session.php';

$pageTitle  = 'Activity Log';
$activeNav  = 'activity-log';
$breadcrumb = ['System', 'Activity Log'];

require_once __DIR__ . '/includes/admin-sidebar.php';
?>

<style>
/* ═══════════════════════════════════════════════════════════════
   ACTIVITY LOG PAGE  (mirrors login-logs.php structure/spacing)
   ═══════════════════════════════════════════════════════════════ */

/* ── Stat cards ──────────────────────────────────────────────────── */
.al-stats {
  display: grid; grid-template-columns: repeat(5, 1fr);
  gap: 14px; margin-bottom: 20px;
}
.al-stat {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--r-md); padding: 16px 18px;
  display: flex; align-items: center; gap: 12px;
}
.al-stat-icon {
  width: 42px; height: 42px; border-radius: var(--r-sm);
  display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;
}
/* Local fallbacks in case the shared stylesheet doesn't define these —
   si-blue/si-red/si-green/si-purple already exist globally (confirmed via
   login-logs.php), these two are page-local extras for the extra cards. */
.al-stat-icon.si-sky  { background: #f0f9ff; color: #0284c7; }
.al-stat-icon.si-cyan { background: #ecfeff; color: #0891b2; }
.al-stat-val { font-size: 21px; font-weight: 800; color: var(--text); line-height: 1.1; }
.al-stat-lbl { font-size: 11.5px; font-weight: 600; color: var(--text-muted); margin-top: 2px; }

/* ── Filter panel ────────────────────────────────────────────────── */
.al-filter-card {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--r-md); margin-bottom: 18px; overflow: hidden;
}
.al-filter-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 18px; cursor: pointer; user-select: none; gap: 10px;
}
.al-filter-head-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.al-filter-title { font-size: 13.5px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px; }
.al-filter-badge {
  background: var(--primary); color: #fff; font-size: 11px; font-weight: 700;
  padding: 2px 8px; border-radius: 20px; display: none;
}
.al-filter-badge.show { display: inline-flex; }
.al-filter-chevron { font-size: 13px; color: var(--text-muted); transition: transform .2s; }
.al-filter-head.open .al-filter-chevron { transform: rotate(180deg); }

.al-filter-body { display: none; padding: 4px 18px 18px; border-top: 1px solid var(--border-light); }
.al-filter-body.open { display: block; }

.al-filter-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-top: 14px; }
.al-fg { display: flex; flex-direction: column; gap: 5px; }
.al-fg label { font-size: 11.5px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; }
.al-ctrl {
  height: 38px; border: 1.5px solid var(--border); border-radius: var(--r-sm);
  font-family: inherit; font-size: 13px; color: var(--text);
  background: var(--bg-card); padding: 0 32px 0 11px; outline: none; width: 100%;
  transition: border-color .15s, box-shadow .15s;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 11px center;
}
.al-ctrl:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.al-ctrl.al-date { background-image: none; padding-right: 11px; }
.al-search-wrap { position: relative; }
.al-search-wrap input {
  height: 38px; padding: 0 12px 0 34px; border: 1.5px solid var(--border); border-radius: var(--r-sm);
  font-family: inherit; font-size: 13px; color: var(--text); background: var(--bg-card);
  outline: none; width: 100%; transition: border-color .15s;
}
.al-search-wrap input:focus { border-color: var(--primary); }
.al-search-wrap i { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); font-size: 12px; color: var(--text-muted); }

.al-filter-actions { display: flex; gap: 8px; margin-top: 14px; align-items: center; }

/* Role quick chips */
.al-role-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.al-role-chip {
  height: 38px; padding: 0 14px; border-radius: var(--r-sm);
  border: 1.5px solid var(--border); background: var(--bg-card);
  font-family: inherit; font-size: 12.5px; font-weight: 600; color: var(--text-muted);
  cursor: pointer; transition: all .13s; display: flex; align-items: center; gap: 6px;
}
.al-role-chip:hover  { border-color: var(--primary); color: var(--primary); }
.al-role-chip.active { background: var(--primary); border-color: var(--primary); color: #fff; }

/* Active filter chips (applied summary) */
.al-active-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
.al-achip {
  display: inline-flex; align-items: center; gap: 6px;
  background: var(--primary-xlight); border: 1px solid var(--primary-light);
  color: var(--primary); font-size: 12px; font-weight: 600;
  padding: 4px 6px 4px 10px; border-radius: 20px;
}
.al-achip button {
  width: 16px; height: 16px; border-radius: 50%; border: none; background: rgba(37,99,235,.15);
  color: var(--primary); font-size: 9px; display: flex; align-items: center; justify-content: center;
  cursor: pointer; flex-shrink: 0;
}
.al-achip button:hover { background: var(--primary); color: #fff; }

/* ── Feed card ───────────────────────────────────────────────────── */
.al-feed-wrap {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--r-md); overflow: hidden; margin-bottom: 18px;
}
.al-feed-head-bar {
  display: flex; align-items: center; justify-content: space-between;
  padding: 13px 18px; border-bottom: 1px solid var(--border-light); flex-wrap: wrap; gap: 10px;
}
.al-quick-dates { display: flex; gap: 6px; flex-wrap: wrap; }
.al-qd-btn {
  height: 30px; padding: 0 11px; border-radius: 20px; border: 1.5px solid var(--border);
  background: var(--bg-card); font-family: inherit; font-size: 11.5px; font-weight: 600;
  color: var(--text-muted); cursor: pointer; transition: all .13s;
}
.al-qd-btn:hover, .al-qd-btn.active { border-color: var(--primary); background: var(--primary-xlight); color: var(--primary); }

/* Feed rows */
.al-row {
  display: flex; align-items: flex-start; gap: 14px;
  padding: 14px 18px; border-bottom: 1px solid var(--border-light); transition: background .12s;
}
.al-row:last-child { border-bottom: none; }
.al-row:hover { background: #fafbfd; }

.al-avatar {
  width: 40px; height: 40px; border-radius: 50%; object-fit: cover; object-position: center top;
  border: 1.5px solid var(--border); flex-shrink: 0; background: var(--bg-elevated);
}
.al-avatar-fallback {
  width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; font-weight: 700; color: #fff;
}

.al-body { flex: 1; min-width: 0; }
.al-main {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; flex-wrap: wrap;
}
.al-text { font-size: 13.5px; color: var(--text-sub); line-height: 1.55; flex: 1; min-width: 220px; }
.al-text .al-actor { font-weight: 700; color: var(--text); }
.al-text strong { color: var(--text); font-weight: 700; }
.al-sub { color: var(--text-muted); font-size: 12px; }
.al-mono { font-family: var(--font-mono, monospace); font-size: 12px; }

.al-time-col { text-align: right; flex-shrink: 0; }
.al-time { font-size: 12px; color: var(--text-muted); white-space: nowrap; font-variant-numeric: tabular-nums; }
.al-time-ago { font-size: 11px; color: var(--text-light); margin-top: 2px; text-align: right; }

.al-meta-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
.al-role-badge {
  display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 700;
  padding: 2px 8px; border-radius: 20px; white-space: nowrap;
}
.arb-student { background: var(--sky-bg);   color: var(--sky); }
.arb-teacher { background: var(--green-bg); color: var(--green); }
.arb-admin   { background: var(--purple-bg);color: var(--purple); }

.al-action-chip {
  display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700;
  padding: 3px 9px; border-radius: 20px; white-space: nowrap;
}

/* Mobile inline time (hidden on desktop where time column shows) */
.al-mtime { display: none; align-items: center; gap: 4px; }

/* Empty / loading */
.al-empty { text-align: center; padding: 56px 24px; }
.al-empty i  { font-size: 44px; color: var(--text-light); display: block; margin: 0 auto 12px; }
.al-empty h3 { font-size: 16px; font-weight: 700; margin-bottom: 6px; }
.al-empty p  { font-size: 13.5px; color: var(--text-muted); max-width: 380px; margin: 0 auto; line-height: 1.6; }

.spin { animation: spin .65s linear infinite; display: inline-block; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Pagination ──────────────────────────────────────────────────── */
.al-pagination {
  display: flex; align-items: center; justify-content: space-between;
  padding: 13px 18px; border-top: 1px solid var(--border-light); flex-wrap: wrap; gap: 10px;
}
.al-page-info { font-size: 12.5px; color: var(--text-muted); }
.al-page-info strong { color: var(--text); }
.al-page-btns { display: flex; gap: 5px; }
.al-page-btn {
  min-width: 32px; height: 32px; padding: 0 8px; border-radius: var(--r-sm);
  border: 1.5px solid var(--border); background: var(--bg-card); font-family: inherit;
  font-size: 12.5px; font-weight: 600; color: var(--text-muted); cursor: pointer; transition: all .13s;
}
.al-page-btn:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
.al-page-btn.active { background: var(--primary); border-color: var(--primary); color: #fff; }
.al-page-btn:disabled { opacity: .4; cursor: not-allowed; }

/* ── Responsive ──────────────────────────────────────────────────── */
@media (max-width: 1300px) {
  .al-stats { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 1100px) {
  .al-filter-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 900px) {
  .al-stats { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 680px) {
  .al-filter-grid { grid-template-columns: 1fr; }
  .al-role-chips { width: 100%; }
}
@media (max-width: 540px) {
  .al-stats { grid-template-columns: 1fr 1fr; }
  .al-pagination { flex-direction: column; align-items: stretch; text-align: center; }
  .al-page-btns { justify-content: center; }
  .al-time-col { display: none; }
  .al-mtime { display: inline-flex; }
  .al-row { gap: 10px; padding: 12px 14px; }
  .al-avatar, .al-avatar-fallback { width: 34px; height: 34px; }
}
</style>

<!-- ════════════════════════════════════════════════════════════
     PAGE HEADER
     ════════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="page-header-top">
    <div>
      <div class="page-title">Activity Log</div>
      <div class="page-sub">
        <i class="fas fa-list-check"></i>
        Every action across the system — who did what, and when
      </div>
    </div>
    <div class="page-actions">
      <a href="login-logs.php" class="btn btn-outline">
        <i class="fas fa-shield-halved"></i> Login Logs
      </a>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     STATS
     ════════════════════════════════════════════════════════════ -->
<div class="al-stats" id="alStats">
  <div class="al-stat"><div class="al-stat-icon si-blue"><i class="fas fa-spinner spin"></i></div><div><div class="al-stat-val">—</div><div class="al-stat-lbl">Loading…</div></div></div>
</div>

<!-- ════════════════════════════════════════════════════════════
     FILTER PANEL
     ════════════════════════════════════════════════════════════ -->
<div class="al-filter-card">
  <div class="al-filter-head" id="filterHead" onclick="toggleFilterPanel()">
    <div class="al-filter-head-left">
      <div class="al-filter-title"><i class="fas fa-filter"></i> Filters</div>
      <span class="al-filter-badge" id="filterCountBadge">0</span>
      <div class="al-role-chips" onclick="event.stopPropagation()">
        <button class="al-role-chip active" data-role="" onclick="setRoleChip(this,'')"><i class="fas fa-layer-group"></i> All</button>
        <button class="al-role-chip" data-role="student" onclick="setRoleChip(this,'student')"><i class="fas fa-user-graduate"></i> Students</button>
        <button class="al-role-chip" data-role="teacher" onclick="setRoleChip(this,'teacher')"><i class="fas fa-chalkboard-teacher"></i> Teachers</button>
        <button class="al-role-chip" data-role="admin" onclick="setRoleChip(this,'admin')"><i class="fas fa-user-shield"></i> Admin</button>
      </div>
    </div>
    <i class="fas fa-chevron-down al-filter-chevron"></i>
  </div>

  <div class="al-filter-body" id="filterBody">
    <div class="al-filter-grid">
      <div class="al-fg">
        <label><i class="fas fa-magnifying-glass"></i> Search</label>
        <div class="al-search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="fSearch" placeholder="Name, action, student ID…" oninput="debouncedFilter()">
        </div>
      </div>
      <div class="al-fg">
        <label><i class="fas fa-bolt"></i> Action Type</label>
        <select id="fActionType" class="al-ctrl" onchange="applyFilters()">
          <option value="">All Actions</option>
        </select>
      </div>
      <div class="al-fg">
        <label><i class="fas fa-chalkboard-teacher"></i> Specific Teacher</label>
        <select id="fTeacher" class="al-ctrl" onchange="applyFilters()">
          <option value="">All Teachers</option>
        </select>
      </div>
      <div class="al-fg">
        <label><i class="fas fa-layer-group"></i> Class</label>
        <select id="fClass" class="al-ctrl" onchange="applyFilters()">
          <option value="">All Classes</option>
        </select>
      </div>
      <div class="al-fg">
        <label><i class="fas fa-calendar-alt"></i> Session</label>
        <select id="fSession" class="al-ctrl" onchange="applyFilters()">
          <option value="">All Sessions</option>
        </select>
      </div>
      <div class="al-fg">
        <label><i class="fas fa-calendar-day"></i> From Date</label>
        <input type="date" id="fDateFrom" class="al-ctrl al-date" onchange="applyFilters()">
      </div>
      <div class="al-fg">
        <label><i class="fas fa-calendar-day"></i> To Date</label>
        <input type="date" id="fDateTo" class="al-ctrl al-date" onchange="applyFilters()">
      </div>
    </div>

    <div class="al-filter-actions">
      <button class="btn btn-sm btn-outline" onclick="resetFilters()"><i class="fas fa-rotate-left"></i> Reset All</button>
    </div>
  </div>
</div>

<!-- Active filter chips summary -->
<div class="al-active-chips" id="activeChips" style="display:none"></div>

<!-- ════════════════════════════════════════════════════════════
     ACTIVITY FEED
     ════════════════════════════════════════════════════════════ -->
<div class="al-feed-wrap">
  <div class="al-feed-head-bar">
    <div class="al-quick-dates">
      <button class="al-qd-btn" onclick="quickDate('today',this)"><i class="fas fa-sun"></i> Today</button>
      <button class="al-qd-btn" onclick="quickDate('week',this)"><i class="fas fa-calendar-week"></i> Last 7 Days</button>
      <button class="al-qd-btn" onclick="quickDate('month',this)"><i class="fas fa-calendar"></i> This Month</button>
    </div>
    <span id="resultCount" style="font-size:12.5px;color:var(--text-muted);font-weight:600"></span>
  </div>

  <div id="feedBody">
    <div style="text-align:center;padding:48px"><i class="fas fa-spinner spin" style="font-size:24px;color:var(--text-muted)"></i></div>
  </div>

  <div class="al-pagination" id="paginationBar"></div>
</div>

<!-- ── Note about students ─────────────────────────────────────── -->
<div class="alert alert-info" style="margin-top:16px">
  <i class="fas alert-info-icon fa-circle-info"></i>
  <div>
    <strong>About student activity:</strong>
    Students only appear here for account actions like logging in or out — they
    don't create or modify academic records. Attendance, marks, and other data
    changes always show under the teacher or admin who performed them.
  </div>
</div>

<!-- CSRF -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<script>
/* ════════════════════════════════════════════════════════════════
   ACTIVITY LOG — Full AJAX JS  (mirrors login-logs.php pattern)
   ════════════════════════════════════════════════════════════════ */
const AJAX_URL = '<?= BASE_URL ?>/admin/activity-log-ajax.php';
const BASE_JS  = '<?= BASE_URL ?>';

const AVATARS = {
  student_male:   BASE_JS + '/assets/images/student-avatar-m.jpg',
  student_female: BASE_JS + '/assets/images/student-avatar-f.jpg',
  teacher_male:   BASE_JS + '/assets/images/teacher-avatar-m.jpg',
  teacher_female: BASE_JS + '/assets/images/teacher-avatar-f.jpg',
  admin:          BASE_JS + '/assets/images/admin-avatar.jpg',
};

let currentPage    = 1;
let totalPages     = 1;
let roleFilter     = '';
let searchDebounce = null;

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
function fmtDateShort(dt) {
  if (!dt) return '—';
  const d = new Date(dt.replace(' ', 'T'));
  return d.toLocaleDateString('en-GB', { day:'2-digit', month:'short' }) + ', ' +
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

/* ════════════════════════════════════════════════════════════════
   FILTER PANEL
   ════════════════════════════════════════════════════════════════ */
function toggleFilterPanel() {
  document.getElementById('filterHead').classList.toggle('open');
  document.getElementById('filterBody').classList.toggle('open');
}

function setRoleChip(btn, role) {
  roleFilter = role;
  document.querySelectorAll('.al-role-chip').forEach(c => c.classList.toggle('active', c === btn));
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
  document.getElementById('fSearch').value     = '';
  document.getElementById('fActionType').value = '';
  document.getElementById('fTeacher').value    = '';
  document.getElementById('fClass').value      = '';
  document.getElementById('fSession').value    = '';
  document.getElementById('fDateFrom').value   = '';
  document.getElementById('fDateTo').value     = '';
  roleFilter = '';
  document.querySelectorAll('.al-role-chip').forEach(c => c.classList.toggle('active', c.dataset.role === ''));
  document.querySelectorAll('.al-qd-btn').forEach(b => b.classList.remove('active'));
  currentPage = 1;
  loadLogs();
}

/* Quick date presets */
function quickDate(range, btn) {
  document.querySelectorAll('.al-qd-btn').forEach(b => b.classList.remove('active'));
  const today = new Date();
  const fmt = d => d.toISOString().split('T')[0];

  if (range === 'today') {
    document.getElementById('fDateFrom').value = fmt(today);
    document.getElementById('fDateTo').value   = fmt(today);
  } else if (range === 'week') {
    const past = new Date(today); past.setDate(past.getDate() - 6);
    document.getElementById('fDateFrom').value = fmt(past);
    document.getElementById('fDateTo').value   = fmt(today);
  } else if (range === 'month') {
    const first = new Date(today.getFullYear(), today.getMonth(), 1);
    document.getElementById('fDateFrom').value = fmt(first);
    document.getElementById('fDateTo').value   = fmt(today);
  }
  btn.classList.add('active');
  currentPage = 1;
  loadLogs();
}

/* ── Active filter chip summary ── */
function renderActiveChips() {
  const chips = [];
  const search   = document.getElementById('fSearch').value.trim();
  const actionTy = document.getElementById('fActionType').value;
  const teacher  = document.getElementById('fTeacher').value;
  const cls      = document.getElementById('fClass').value;
  const session  = document.getElementById('fSession').value;
  const from     = document.getElementById('fDateFrom').value;
  const to       = document.getElementById('fDateTo').value;

  if (roleFilter) chips.push({ key:'role', label: 'Role: ' + roleFilter.charAt(0).toUpperCase() + roleFilter.slice(1) });
  if (search)     chips.push({ key:'search', label: 'Search: "' + search + '"' });
  if (actionTy)   chips.push({ key:'action_type', label: 'Action: ' + (document.querySelector(`#fActionType option[value="${actionTy}"]`)?.textContent || actionTy) });
  if (teacher)    chips.push({ key:'teacher', label: 'Teacher: ' + (document.querySelector(`#fTeacher option[value="${teacher}"]`)?.textContent || teacher) });
  if (cls)        chips.push({ key:'class', label: 'Class: ' + (document.querySelector(`#fClass option[value="${cls}"]`)?.textContent || cls) });
  if (session)    chips.push({ key:'session', label: 'Session: ' + (document.querySelector(`#fSession option[value="${session}"]`)?.textContent || session) });
  if (from || to) chips.push({ key:'date', label: 'Date: ' + (from||'…') + ' → ' + (to||'…') });

  const wrap  = document.getElementById('activeChips');
  const badge = document.getElementById('filterCountBadge');
  badge.textContent = chips.length;
  badge.classList.toggle('show', chips.length > 0);

  if (!chips.length) { wrap.style.display = 'none'; wrap.innerHTML = ''; return; }
  wrap.style.display = 'flex';
  wrap.innerHTML = chips.map(c =>
    `<span class="al-achip">${esc(c.label)} <button onclick="removeChip('${c.key}')"><i class="fas fa-xmark"></i></button></span>`
  ).join('');
}

function removeChip(key) {
  if (key === 'role') {
    roleFilter = '';
    document.querySelectorAll('.al-role-chip').forEach(c => c.classList.toggle('active', c.dataset.role === ''));
  }
  if (key === 'search')       document.getElementById('fSearch').value = '';
  if (key === 'action_type')  document.getElementById('fActionType').value = '';
  if (key === 'teacher')      document.getElementById('fTeacher').value = '';
  if (key === 'class')        document.getElementById('fClass').value = '';
  if (key === 'session')      document.getElementById('fSession').value = '';
  if (key === 'date') { document.getElementById('fDateFrom').value = ''; document.getElementById('fDateTo').value = ''; document.querySelectorAll('.al-qd-btn').forEach(b=>b.classList.remove('active')); }
  currentPage = 1;
  loadLogs();
}

/* ════════════════════════════════════════════════════════════════
   LOAD LOGS
   ════════════════════════════════════════════════════════════════ */
async function loadLogs() {
  renderActiveChips();

  document.getElementById('feedBody').innerHTML =
    '<div style="text-align:center;padding:48px"><i class="fas fa-spinner spin" style="font-size:24px;color:var(--text-muted)"></i></div>';

  const payload = {
    action: 'fetch_logs',
    role: roleFilter,
    actor: document.getElementById('fTeacher').value,
    action_type: document.getElementById('fActionType').value,
    class_id: document.getElementById('fClass').value,
    session_id: document.getElementById('fSession').value,
    date_from: document.getElementById('fDateFrom').value,
    date_to: document.getElementById('fDateTo').value,
    search: document.getElementById('fSearch').value.trim(),
    page: currentPage,
  };

  const r = await ajax(payload);
  if (!r.ok) {
    document.getElementById('feedBody').innerHTML =
      `<div style="text-align:center;padding:32px;color:var(--red)"><i class="fas fa-triangle-exclamation"></i> ${esc(r.msg)}</div>`;
    return;
  }

  currentPage = r.page;
  totalPages  = r.total_pages;

  renderStats(r.stats);
  renderFeed(r.logs, r.total);
  renderPagination(r.page, r.total_pages, r.total, r.per_page);
}

/* ── Stat cards ── */
function renderStats(stats) {
  document.getElementById('alStats').innerHTML = `
    <div class="al-stat">
      <div class="al-stat-icon si-blue"><i class="fas fa-clock-rotate-left"></i></div>
      <div><div class="al-stat-val">${stats.today}</div><div class="al-stat-lbl">Actions Today</div></div>
    </div>
    <div class="al-stat">
      <div class="al-stat-icon si-sky"><i class="fas fa-calendar-week"></i></div>
      <div><div class="al-stat-val">${stats.week}</div><div class="al-stat-lbl">Last 7 Days</div></div>
    </div>
    <div class="al-stat">
      <div class="al-stat-icon si-cyan"><i class="fas fa-user-graduate"></i></div>
      <div><div class="al-stat-val">${stats.student}</div><div class="al-stat-lbl">Student Actions</div></div>
    </div>
    <div class="al-stat">
      <div class="al-stat-icon si-green"><i class="fas fa-chalkboard-teacher"></i></div>
      <div><div class="al-stat-val">${stats.teacher}</div><div class="al-stat-lbl">Teacher Actions</div></div>
    </div>
    <div class="al-stat">
      <div class="al-stat-icon si-purple"><i class="fas fa-user-shield"></i></div>
      <div><div class="al-stat-val">${stats.admin}</div><div class="al-stat-lbl">Admin Actions</div></div>
    </div>`;
}

/* ── Avatar resolution ── */
function avatarFor(log) {
  if (log.actor_photo) {
    return `<img src="${BASE_JS}/${esc(log.actor_photo)}" class="al-avatar" alt=""
             onerror="this.outerHTML=fallbackAvatar(${JSON.stringify(log.actor_role)},${JSON.stringify(log.actor_gender)},${JSON.stringify(log.actor_name)})">`;
  }
  return fallbackAvatar(log.actor_role, log.actor_gender, log.actor_name);
}

function fallbackAvatar(role, gender, name) {
  let src;
  if (role === 'admin') src = AVATARS.admin;
  else if (role === 'teacher') src = gender === 'female' ? AVATARS.teacher_female : AVATARS.teacher_male;
  else if (role === 'student') src = gender === 'female' ? AVATARS.student_female : AVATARS.student_male;
  else return `<div class="al-avatar-fallback" style="background:#64748b">${esc((name||'?').trim().charAt(0).toUpperCase() || '?')}</div>`;
  return `<img src="${src}" class="al-avatar" alt="">`;
}

function roleBadge(role) {
  if (role === 'student') return '<span class="al-role-badge arb-student"><i class="fas fa-user-graduate"></i> Student</span>';
  if (role === 'teacher') return '<span class="al-role-badge arb-teacher"><i class="fas fa-chalkboard-teacher"></i> Teacher</span>';
  if (role === 'admin')   return '<span class="al-role-badge arb-admin"><i class="fas fa-user-shield"></i> Admin</span>';
  return '';
}

/* ── Feed rows ── */
function renderFeed(logs, total) {
  document.getElementById('resultCount').textContent = total + ' record' + (total !== 1 ? 's' : '');

  if (!logs.length) {
    document.getElementById('feedBody').innerHTML = `
      <div class="al-empty">
        <i class="fas fa-inbox"></i>
        <h3>No activity found</h3>
        <p>Try adjusting your filters, or check back after the next action is logged.</p>
      </div>`;
    return;
  }

  document.getElementById('feedBody').innerHTML = logs.map(log => `
    <div class="al-row">
      ${avatarFor(log)}
      <div class="al-body">
        <div class="al-main">
          <div class="al-text">
            <span class="al-actor">${esc(log.actor_name)}</span>
            <span style="color:var(--text-light)"> — </span>
            ${log.description}
          </div>
          <div class="al-time-col">
            <div class="al-time">${fmtDateTime(log.logged_at)}</div>
            <div class="al-time-ago">${timeAgo(log.logged_at)}</div>
          </div>
        </div>
        <div class="al-meta-row">
          ${roleBadge(log.actor_role)}
          <span class="al-action-chip" style="background:${log.icon_bg};color:${log.icon_color}">
            <i class="fas ${log.icon}"></i> ${esc(log.action_label)}
          </span>
          <span class="al-mtime al-sub"><i class="fas fa-clock"></i> ${fmtDateShort(log.logged_at)}</span>
        </div>
      </div>
    </div>`).join('');
}

/* ── Pagination ── */
function renderPagination(page, totalPages, total, perPage) {
  const bar = document.getElementById('paginationBar');
  if (total === 0) { bar.innerHTML = ''; return; }

  const startItem = (page - 1) * perPage + 1;
  const endItem   = Math.min(page * perPage, total);

  let buttons = '';
  buttons += `<button class="al-page-btn" onclick="goToPage(1)" ${page<=1?'disabled':''} title="First"><i class="fas fa-angles-left"></i></button>`;
  buttons += `<button class="al-page-btn" onclick="goToPage(${page-1})" ${page<=1?'disabled':''} title="Previous"><i class="fas fa-angle-left"></i></button>`;

  const range = [];
  const span = 2;
  for (let p = Math.max(1, page-span); p <= Math.min(totalPages, page+span); p++) range.push(p);
  if (range[0] > 1) buttons += `<button class="al-page-btn" onclick="goToPage(1)">1</button>` + (range[0] > 2 ? '<span style="padding:0 4px;color:var(--text-light)">…</span>' : '');
  range.forEach(p => {
    buttons += `<button class="al-page-btn ${p===page?'active':''}" onclick="goToPage(${p})">${p}</button>`;
  });
  if (range[range.length-1] < totalPages) buttons += (range[range.length-1] < totalPages-1 ? '<span style="padding:0 4px;color:var(--text-light)">…</span>' : '') + `<button class="al-page-btn" onclick="goToPage(${totalPages})">${totalPages}</button>`;

  buttons += `<button class="al-page-btn" onclick="goToPage(${page+1})" ${page>=totalPages?'disabled':''} title="Next"><i class="fas fa-angle-right"></i></button>`;
  buttons += `<button class="al-page-btn" onclick="goToPage(${totalPages})" ${page>=totalPages?'disabled':''} title="Last"><i class="fas fa-angles-right"></i></button>`;

  bar.innerHTML = `
    <div class="al-page-info">Showing <strong>${startItem}–${endItem}</strong> of <strong>${total}</strong></div>
    <div class="al-page-btns">${buttons}</div>`;
}

function goToPage(p) {
  if (p < 1 || p > totalPages || p === currentPage) return;
  currentPage = p;
  loadLogs();
  document.querySelector('.al-feed-wrap')?.scrollIntoView({ behavior:'smooth', block:'start' });
}

/* ════════════════════════════════════════════════════════════════
   FILTER META (action types, teachers, classes, sessions)
   ════════════════════════════════════════════════════════════════ */
async function loadFilterMeta() {
  const r = await ajax({ action: 'fetch_filter_meta' });
  if (!r.ok) return;

  const actSel = document.getElementById('fActionType');
  (r.action_types || []).forEach(a => {
    const opt = document.createElement('option');
    opt.value = a.value; opt.textContent = a.label;
    actSel.appendChild(opt);
  });

  const teacherSel = document.getElementById('fTeacher');
  (r.teachers || []).forEach(t => {
    const opt = document.createElement('option');
    opt.value = t.sys_uid; opt.textContent = t.full_name;
    teacherSel.appendChild(opt);
  });

  const classSel = document.getElementById('fClass');
  (r.classes || []).forEach(c => {
    const opt = document.createElement('option');
    opt.value = c.class_id; opt.textContent = c.label;
    classSel.appendChild(opt);
  });

  const sessionSel = document.getElementById('fSession');
  (r.sessions || []).forEach(s => {
    const opt = document.createElement('option');
    opt.value = s.session_id; opt.textContent = s.label;
    sessionSel.appendChild(opt);
  });
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
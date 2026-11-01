<?php
/**
 * EMS Admin — Marks Entry  (marks.php)
 *
 * Flow:
 *  1. Admin picks Session → Class → Exam  (filter bar)
 *  2. Subject tabs appear — one tab per subject in the exam
 *  3. Per-subject: student list with MCQ / CQ input cells
 *     Each row shows student photo, name, roll, student-ID
 *     Grade + GPA + status previewed live as admin types
 *  4. Save — AJAX, per-subject batch, auto grade/GPA computed server-side
 *  5. Summary tab shows all-student totals across every subject
 *
 * No page reloads. Full CSRF. Prepared statements. BST timestamps.
 */
require_once __DIR__ . '/../includes/admin-session.php';

$pageTitle  = 'Marks Entry';
$activeNav  = 'marks';
$breadcrumb = ['Examinations', 'Marks Entry'];

function safeRows(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); } catch (Throwable $e) { return []; }
}

$allSessions = safeRows("SELECT session_id, session_year, label, is_current FROM sessions ORDER BY session_year DESC");
$allClasses  = safeRows("SELECT class_id, class_num, label FROM classes ORDER BY class_num");

$currentSession = null;
foreach ($allSessions as $s) { if ($s['is_current']) { $currentSession = $s; break; } }

// If opened from exams.php with ?exam_id=X, preselect
$preExamId = (int)($_GET['exam_id'] ?? 0);

require_once __DIR__ . '/includes/admin-sidebar.php';
?>

<style>
/* ═══════════════════════════════════════════════════════════════
   Marks Entry — Page CSS
   ═══════════════════════════════════════════════════════════════ */

/* ── Selector bar ─────────────────────────────────────────────── */
.mk-selector {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  padding: 18px 22px;
  margin-bottom: 20px;
  display: flex;
  gap: 12px;
  align-items: flex-end;
  flex-wrap: wrap;
}
.mk-sel-group {
  display: flex; flex-direction: column; gap: 5px;
  flex: 1; min-width: 150px;
}
.mk-sel-group label {
  font-size: 11.5px; font-weight: 700;
  color: var(--text-muted);
  text-transform: uppercase; letter-spacing: .05em;
}
.mk-ctrl {
  height: 40px;
  border: 1.5px solid var(--border);
  border-radius: var(--r-sm);
  font-family: inherit; font-size: 13.5px;
  color: var(--text); background: var(--bg-card);
  padding: 0 32px 0 12px;
  outline: none; width: 100%;
  transition: border-color .15s, box-shadow .15s;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  cursor: pointer;
}
.mk-ctrl:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.mk-ctrl:disabled { background-color: var(--bg-elevated); color: var(--text-light); cursor: not-allowed; }

/* ── Exam context banner ──────────────────────────────────────── */
.exam-banner {
  background: linear-gradient(135deg, #1e3a8a 0%, #1d4ed8 60%, #2563eb 100%);
  border-radius: var(--r-md);
  padding: 16px 22px;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  color: #fff;
}
.eb-icon {
  width: 44px; height: 44px;
  background: rgba(255,255,255,.15);
  border-radius: var(--r-sm);
  display: flex; align-items: center; justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}
.eb-info { flex: 1; min-width: 0; }
.eb-name { font-size: 17px; font-weight: 700; line-height: 1.2; }
.eb-meta { font-size: 13px; color: rgba(255,255,255,.75); margin-top: 4px;
           display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.eb-meta i { font-size: 11px; }
.eb-status {
  font-size: 12px; font-weight: 700; padding: 4px 12px;
  border-radius: 20px; flex-shrink: 0;
  display: flex; align-items: center; gap: 5px;
}
.eb-draft     { background: rgba(217,119,6,.25); color: #fde68a; border: 1px solid rgba(252,211,77,.3); }
.eb-published { background: rgba(5,150,105,.25); color: #6ee7b7; border: 1px solid rgba(52,211,153,.3); }

.eb-pdf-btn {
  display: flex; align-items: center; gap: 7px;
  font-size: 13px; font-weight: 700; color: #fff;
  background: rgba(255,255,255,.15);
  border: 1px solid rgba(255,255,255,.3);
  border-radius: var(--r-sm);
  padding: 8px 14px;
  flex-shrink: 0;
  text-decoration: none;
  transition: background .15s;
}
.eb-pdf-btn:hover { background: rgba(255,255,255,.26); }
.eb-pdf-btn i { color: rgba(255,255,255,.9); }

/* ── Subject tabs ──────────────────────────────────────────────────
   Layout: [ scrollable subject rail ][ sticky Summary tab ]
   Fixed: previously .stab-item used flex-direction:column with no
   flex-shrink, which let labels wrap/overlap inside the scroll rail.
   Now each tab is a fixed-content flex row item that never shrinks
   and never wraps, so subject names can't stack on top of each other.
   The Summary tab lives outside the scroll rail entirely, so it
   never scrolls away — only the subjects scroll, and only
   horizontally (overflow-y is locked to hidden so no vertical
   scrollbar can ever appear), with a slim custom-styled track drawn
   below the rail instead of relying on the OS scrollbar.
   ─────────────────────────────────────────────────────────────── */
.subj-tabs-wrap {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r-md) var(--r-md) 0 0;
  border-bottom: none;
  display: flex;
  align-items: stretch;
}
.subj-tabs-rail-col { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; }
.subj-tabs-scroll {
  display: flex;
  overflow-x: auto;
  overflow-y: hidden;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
  border-bottom: 2px solid var(--border);
  background: var(--bg-elevated);
}
.subj-tabs-scroll::-webkit-scrollbar { display: none; height: 0; }

/* Custom horizontal scrollbar track, drawn ourselves below the rail */
.subj-tabs-scrollbar {
  height: 5px;
  background: var(--border-light);
  position: relative;
  cursor: pointer;
  flex-shrink: 0;
  display: none; /* shown via JS only when content actually overflows */
}
.subj-tabs-scrollbar-thumb {
  position: absolute;
  top: 0; left: 0;
  height: 100%;
  background: var(--text-light);
  border-radius: 4px;
  transition: background .15s;
}
.subj-tabs-scrollbar:hover .subj-tabs-scrollbar-thumb,
.subj-tabs-scrollbar-thumb.dragging { background: var(--primary); }

.stab-item {
  flex: 0 0 auto;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  padding: 11px 20px;
  border-bottom: 3px solid transparent;
  margin-bottom: -2px;
  white-space: nowrap;
  cursor: pointer;
  border-top: none; border-left: none; border-right: none;
  background: none;
  font-family: inherit;
  transition: color .13s, border-color .13s, background .13s;
  color: var(--text-muted);
  position: relative;
  min-width: 116px;
}
.stab-item:hover { color: var(--text); background: rgba(37,99,235,.05); }
.stab-item.active {
  color: var(--primary);
  border-bottom-color: var(--primary);
  background: #fff;
}

/* Summary tab — sticky outside the scroll rail, never scrolls away */
.stab-summary-wrap {
  flex: 0 0 auto;
  border-left: 1px solid var(--border);
  border-bottom: 2px solid var(--border);
  background: var(--bg-elevated);
  display: flex;
}
.stab-item.is-summary { color: var(--text-muted); }
.stab-item.is-summary.active { color: var(--accent); border-bottom-color: var(--accent); }

.stab-name { font-size: 13px; font-weight: 700; line-height: 1.25; }
.stab-code { font-size: 10.5px; font-weight: 600; color: var(--text-light); }
.stab-item.active .stab-code { color: var(--primary); opacity: .75; }

/* Tiny progress dot on tab */
.stab-progress {
  position: absolute;
  top: 8px; right: 12px;
  width: 7px; height: 7px;
  border-radius: 50%;
  background: var(--border);
  transition: background .2s;
}
.stab-progress.done    { background: var(--green); }
.stab-progress.partial { background: var(--amber); }
.stab-progress.none    { background: var(--border); }

/* ── Sheet panel ──────────────────────────────────────────────── */
.sheet-panel {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 0 0 var(--r-md) var(--r-md);
  overflow: hidden;
  margin-bottom: 24px;
}

/* ── Sheet toolbar ───────────────────────────────────────────── */
.sheet-toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 18px;
  border-bottom: 1px solid var(--border-light);
  background: var(--bg-elevated);
  flex-wrap: wrap;
}
.sheet-toolbar-left  { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; flex: 1; row-gap: 8px; }
.sheet-toolbar-right { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }

/* Search in toolbar */
.st-search { position: relative; }
.st-search input {
  height: 36px; padding: 0 10px 0 32px;
  border: 1.5px solid var(--border); border-radius: var(--r-sm);
  font-family: inherit; font-size: 13px; color: var(--text);
  background: var(--bg-card); outline: none; width: 200px;
  transition: border-color .13s;
}
.st-search input:focus { border-color: var(--primary); }
.st-search i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 12px; color: var(--text-muted); pointer-events: none; }

/* Absent-all toggle */
.absent-all-btn {
  height: 36px; padding: 0 12px;
  border: 1.5px solid var(--border); border-radius: var(--r-sm);
  background: var(--bg-card); font-family: inherit; font-size: 12.5px;
  font-weight: 600; color: var(--text-muted); cursor: pointer;
  display: inline-flex; align-items: center; gap: 6px;
  transition: all .13s;
}
.absent-all-btn:hover { border-color: var(--red); color: var(--red); background: var(--red-bg); }
.absent-all-btn.active { border-color: var(--red); color: var(--red); background: var(--red-bg); }

/* Marks stat chips in toolbar */
.marks-stat-chips { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.msc {
  font-size: 11.5px; font-weight: 600; padding: 4px 10px;
  border-radius: 20px; display: flex; align-items: center; gap: 4px;
  white-space: nowrap;
}
.msc i { font-size: 10px; }
.msc-done { background: var(--green-bg); color: var(--green); }
.msc-rem  { background: var(--amber-bg); color: var(--amber); }
.msc-fail { background: var(--red-bg);   color: var(--red); }
.msc-info { background: var(--bg); color: var(--text-muted); border: 1px solid var(--border); }

/* ── Marks table ──────────────────────────────────────────────────
   Mirrors students-table: photo + name/id stacked, generous row
   height, vertically centered header labels, no horizontal-scroll
   trap for a normal subject (MCQ + CQ) layout.
   ─────────────────────────────────────────────────────────────── */
.marks-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }

.marks-table {
  width: 100%;
  border-collapse: collapse;
  min-width: 640px;
  table-layout: fixed;
}
.marks-table th {
  padding: 12px 10px;
  text-align: left;
  font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .06em;
  color: var(--text-muted);
  background: var(--bg-elevated);
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
  vertical-align: middle;
  line-height: 1.3;
  overflow: hidden;
  text-overflow: ellipsis;
}
.marks-table th.th-center { text-align: center; }
.marks-table td {
  padding: 10px 10px;
  vertical-align: middle;
  border-bottom: 1px solid var(--border-light);
  font-size: 13.5px; color: var(--text-sub);
  overflow: hidden;
}
.marks-table tbody tr:hover { background: #fafbfd; }
.marks-table tbody tr.is-absent { background: #fff8f8; opacity: .85; }
.marks-table tbody tr.is-absent td { color: var(--text-muted); }
.marks-table tbody tr.row-hidden { display: none; }
.marks-table tbody tr:last-child td { border-bottom: none; }

/* Student cell — matches students.php tbl-photo treatment */
.stu-cell { display: flex; align-items: center; gap: 12px; }
.stu-photo {
  width: 44px; height: 44px; border-radius: 50%;
  background: var(--bg-elevated);
  border: 2px solid var(--border);
  object-fit: cover;
  object-position: center top;
  flex-shrink: 0;
  display: block;
}
.stu-name { font-weight: 700; color: var(--text); font-size: 13.5px; line-height: 1.3; }
/* Subject-role badge shown next to student name on choice-subject tabs */
.role-badge {
  display: inline-block; font-size: 10px; font-weight: 700;
  padding: 1px 6px; border-radius: 999px; line-height: 1.6;
  vertical-align: middle; margin-left: 5px; white-space: nowrap;
}
.role-badge-main   { background: var(--primary-light); color: var(--primary); }
.role-badge-fourth { background: var(--amber-bg);      color: var(--amber);   }

/* Student ID — own column, right after Student */
.stu-id-cell { font-size: 12.5px; color: var(--text-muted); font-family: monospace; letter-spacing: .03em; text-align: center; }

/* Roll cell */
.roll-cell { font-weight: 700; color: var(--text-muted); font-size: 13px; text-align: center; }

/* Mark input cell — "max N" text removed; max shown inline in the
   column header instead (Roll | Student | MCQ (/30) | CQ (/70) …) */
.mark-input-wrap { display: flex; justify-content: center; }
.mark-input {
  width: 84px; height: 40px;
  text-align: center; font-family: inherit; font-size: 15px;
  font-weight: 700; color: var(--text);
  border: 1.5px solid var(--border); border-radius: var(--r-sm);
  background: var(--bg-card); outline: none;
  transition: border-color .13s, box-shadow .13s;
  padding: 0 6px;
  -moz-appearance: textfield;
}
.mark-input::-webkit-outer-spin-button,
.mark-input::-webkit-inner-spin-button { -webkit-appearance: none; }
.mark-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.mark-input.inp-over  { border-color: var(--red); background: var(--red-bg); }
.mark-input.inp-ok    { border-color: #86efac; }
.mark-input:disabled  { background: var(--bg-elevated); color: var(--text-muted); cursor: not-allowed; border-color: var(--border); box-shadow: none; }

/* Grade cell */
.grade-cell { text-align: center; }
.grade-badge {
  display: inline-flex; align-items: center; justify-content: center;
  width: 38px; height: 38px;
  border-radius: var(--r-sm);
  font-size: 13px; font-weight: 800;
  transition: all .15s;
}
.gb-ap { background: #fef9c3; color: #713f12; }
.gb-a  { background: #d1fae5; color: #065f46; }
.gb-am { background: #dcfce7; color: #166534; }
.gb-b  { background: #dbeafe; color: #1e40af; }
.gb-c  { background: #e0e7ff; color: #3730a3; }
.gb-d  { background: #fef3c7; color: #92400e; }
.gb-f  { background: #fee2e2; color: #991b1b; }
.gb-blank { background: var(--bg-elevated); color: var(--text-light); }

/* Status cell */
.status-cell { text-align: center; }
.st-pass { color: var(--green); font-size: 13px; font-weight: 700; white-space: nowrap; }
.st-fail { color: var(--red);   font-size: 13px; font-weight: 700; white-space: nowrap; }

/* Total cell */
.total-cell {
  font-size: 14.5px; font-weight: 800;
  color: var(--text);
  text-align: center;
  font-variant-numeric: tabular-nums;
}

/* Absent toggle */
.absent-toggle { text-align: center; vertical-align: middle; }

.absent-toggle .abs-switch { display: inline-flex; vertical-align: middle; }

/* Modern toggle switch — replaces the plain checkbox for marking absence */
.abs-switch {
  position: relative;
  display: inline-flex;
  width: 42px; height: 24px;
  cursor: pointer;
  flex-shrink: 0;
}
.abs-switch input {
  position: absolute;
  opacity: 0;
  width: 100%; height: 100%;
  margin: 0;
  cursor: pointer;
}
.abs-switch-track {
  position: absolute;
  inset: 0;
  background: var(--border);
  border-radius: 999px;
  transition: background .18s ease;
}
.abs-switch-track::before {
  content: '';
  position: absolute;
  top: 3px; left: 3px;
  width: 18px; height: 18px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgba(16,24,40,.25);
  transition: transform .18s ease;
}
.abs-switch input:checked ~ .abs-switch-track { background: var(--red); }
.abs-switch input:checked ~ .abs-switch-track::before { transform: translateX(18px); }
.abs-switch input:focus-visible ~ .abs-switch-track { box-shadow: 0 0 0 3px rgba(220,38,38,.25); }
.abs-switch input:disabled ~ .abs-switch-track { opacity: .5; cursor: not-allowed; }

/* ── Save bar (sticky bottom) ─────────────────────────────────── */
.save-bar {
  position: sticky; bottom: 0; left: 0; right: 0;
  background: var(--bg-card);
  border-top: 2px solid var(--border);
  padding: 14px 20px;
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  z-index: 50;
  box-shadow: 0 -4px 24px rgba(16,24,40,.1);
}
.save-bar-info { flex: 1; font-size: 13.5px; color: var(--text-muted); min-width: 0; }
.save-bar-info strong { color: var(--text); }
.save-bar-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

/* Unsaved indicator */
.unsaved-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--amber); display: inline-block; margin-right: 4px;
  animation: pulse-dot .8s ease-in-out infinite alternate;
}
@keyframes pulse-dot { from { opacity:.4; transform:scale(.8); } to { opacity:1; transform:scale(1); } }

/* ── Summary tab panel ─────────────────────────────────────────────
   Stat cards now use 1fr columns (instead of a minmax auto-fill grid)
   so the 5 cards always stretch to fill the full panel width, with
   no leftover empty space on the right.
   ─────────────────────────────────────────────────────────────── */
.summary-panel {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 0 0 var(--r-md) var(--r-md);
  overflow: hidden;
  margin-bottom: 24px;
}

.summary-stats {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 12px;
  padding: 18px;
  border-bottom: 1px solid var(--border-light);
}
.sum-stat {
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  border-radius: var(--r-sm);
  padding: 14px 16px;
  text-align: center;
}
.sum-stat-val  { font-size: 25px; font-weight: 800; color: var(--text); line-height: 1; }
.sum-stat-lbl  { font-size: 12px; font-weight: 600; color: var(--text-muted); margin-top: 6px; }

/* Summary table */
.summary-table { width: 100%; border-collapse: collapse; min-width: 640px; table-layout: fixed; }
.summary-table th {
  padding: 12px 10px; text-align: left;
  font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
  color: var(--text-muted); background: var(--bg-elevated);
  border-bottom: 1px solid var(--border); white-space: nowrap;
  vertical-align: middle; line-height: 1.3;
  overflow: hidden; text-overflow: ellipsis;
}
.summary-table th.tc { text-align: center; }
.summary-table td {
  padding: 11px 10px; font-size: 13.5px; color: var(--text-sub);
  border-bottom: 1px solid var(--border-light); vertical-align: middle;
  overflow: hidden;
}
.summary-table tbody tr:hover { background: #fafbfd; }
.summary-table tbody tr:last-child td { border-bottom: none; }

/* Per-subject grade cell in the summary table — badge is always the
   same fixed size (inherited from .grade-badge) and sits centered
   directly under its subject column header, with the raw mark
   centered underneath it as a unit. */
.subj-mark-cell { display: flex; flex-direction: column; align-items: center; gap: 3px; }
.subj-mark-total { font-size: 10.5px; color: var(--text-muted); }

/* GPA badge */
.gpa-badge {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 52px; height: 28px; border-radius: var(--r-sm);
  font-size: 14px; font-weight: 800; padding: 0 8px;
}
.gpa-5  { background: #fef9c3; color: #713f12; }
.gpa-4  { background: #d1fae5; color: #065f46; }
.gpa-35 { background: #dcfce7; color: #166534; }
.gpa-3  { background: #dbeafe; color: #1e40af; }
.gpa-2  { background: #e0e7ff; color: #3730a3; }
.gpa-1  { background: #fef3c7; color: #92400e; }
.gpa-0  { background: #fee2e2; color: #991b1b; }

/* Rank badge */
.rank-badge {
  display: inline-flex; align-items: center; justify-content: center;
  width: 32px; height: 32px; border-radius: 50%;
  font-size: 13px; font-weight: 800;
}
.rank-1 { background: #fef9c3; color: #854d0e; }
.rank-2 { background: #e2e8f0; color: #334155; }
.rank-3 { background: #fde8d8; color: #7c2d12; }
.rank-n { background: var(--bg-elevated); color: var(--text-muted); font-size: 12px; }

/* ── Empty / Loading states ───────────────────────────────────── */
.mk-empty { text-align: center; padding: 56px 24px; }
.mk-empty .mk-empty-icon { font-size: 48px; color: var(--text-light); display: block; margin: 0 auto 12px; }
.mk-empty h3  { font-size: 16px; font-weight: 700; margin-bottom: 8px; }
.mk-empty p   { font-size: 13.5px; color: var(--text-muted); max-width: 380px; margin: 0 auto; line-height: 1.6; }

.mk-loading { text-align: center; padding: 40px; color: var(--text-muted); }
.mk-loading i { font-size: 28px; display: block; margin: 0 auto 12px; }

/* spin */
.spin { animation: spin .65s linear infinite; display: inline-block; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 900px) {
  .mk-selector { flex-direction: column; align-items: stretch; gap: 10px; }
  .mk-sel-group { min-width: auto; }
  .sheet-toolbar { flex-direction: column; align-items: flex-start; }
  .sheet-toolbar-right { width: 100%; justify-content: flex-start; }
  .st-search input { width: 100%; }
  .stab-item { min-width: 96px; padding: 10px 14px; }
  .summary-stats { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 680px) {
  .mark-input { width: 72px; font-size: 14px; }
  .marks-table td { padding: 10px 8px; }
  .marks-table th { padding: 9px 8px; }
  .stu-photo { width: 36px; height: 36px; }
  .stu-name { font-size: 12.5px; }
  .stu-id-col { display: none; }
  .save-bar { padding: 10px 14px; }
  .exam-banner { padding: 12px 16px; }
  .eb-name { font-size: 15px; }
  .summary-stats { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
  .stab-code { display: none; }
  .marks-stat-chips { display: none; }
  .stab-item { min-width: 80px; padding: 9px 12px; }
  .absent-all-btn span { display: none; }
  .summary-stats { grid-template-columns: 1fr 1fr; }
}
</style>

<!-- ════════════════════════════════════════════════════════════
     PAGE HEADER
     ════════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="page-header-top">
    <div>
      <div class="page-title">Marks Entry</div>
      <div class="page-sub">
        <i class="fas fa-pencil"></i>
        Enter student marks — grades &amp; GPA calculated automatically
      </div>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     SELECTOR BAR
     ════════════════════════════════════════════════════════════ -->
<div class="mk-selector">
  <div class="mk-sel-group">
    <label><i class="fas fa-calendar-alt"></i> Session</label>
    <select id="selSession" class="mk-ctrl" onchange="onSessionChange()">
      <option value="">— Select Session —</option>
      <?php foreach ($allSessions as $s): ?>
      <option value="<?= $s['session_id'] ?>"
              <?= ($currentSession && $s['session_id'] == $currentSession['session_id']) ? 'selected' : '' ?>>
        <?= h($s['label']) ?><?= $s['is_current'] ? ' (Current)' : '' ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="mk-sel-group">
    <label><i class="fas fa-chalkboard"></i> Class</label>
    <select id="selClass" class="mk-ctrl" onchange="onClassChange()" disabled>
      <option value="">— Select Class —</option>
      <?php foreach ($allClasses as $c): ?>
      <option value="<?= $c['class_id'] ?>"><?= h($c['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="mk-sel-group">
    <label><i class="fas fa-file-alt"></i> Exam</label>
    <select id="selExam" class="mk-ctrl" onchange="onExamChange()" disabled>
      <option value="">— Select Exam —</option>
    </select>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MAIN WORK AREA  (hidden until exam loaded)
     ════════════════════════════════════════════════════════════ -->
<div id="workArea" style="display:none">

  <!-- Exam context banner -->
  <div class="exam-banner" id="examBanner"></div>

  <!-- Subject tabs -->
  <div class="subj-tabs-wrap">
    <div class="subj-tabs-rail-col">
      <div class="subj-tabs-scroll" id="subjTabsScroll">
        <div id="subjTabs" style="display:flex"></div>
      </div>
      <div class="subj-tabs-scrollbar" id="subjScrollbar">
        <div class="subj-tabs-scrollbar-thumb" id="subjScrollbarThumb"></div>
      </div>
    </div>
    <div class="stab-summary-wrap" id="subjTabsSummary"></div>
  </div>

  <!-- Marks entry sheet (per-subject) -->
  <div class="sheet-panel" id="sheetPanel">
    <div class="mk-loading">
      <i class="fas fa-spinner spin"></i>
      <p>Loading…</p>
    </div>
  </div>

  <!-- Summary panel (hidden until summary tab) -->
  <div class="summary-panel" id="summaryPanel" style="display:none">
    <div class="mk-loading">
      <i class="fas fa-spinner spin"></i>
      <p>Loading summary…</p>
    </div>
  </div>

</div>

<!-- Initial empty state -->
<div id="emptyState">
  <div class="card">
    <div class="mk-empty">
      <i class="fas fa-pencil mk-empty-icon"></i>
      <h3>Select an Exam to Enter Marks</h3>
      <p>Choose a session, class, and exam from the dropdowns above, then click <strong>Load</strong> to open the marks sheet.</p>
    </div>
  </div>
</div>

<!-- CSRF token -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">
<!-- Pre-selected exam ID from URL -->
<input type="hidden" id="preExamId" value="<?= $preExamId ?>">

<script>
/* ================================================================
   EMS — Marks Entry JS
   ================================================================ */
const AJAX    = '<?= BASE_URL ?>/admin/marks-ajax.php';
const BASE    = '<?= BASE_URL ?>';
const AVATAR_M = BASE + '/assets/images/student-avatar-m.jpg';
const AVATAR_F = BASE + '/assets/images/student-avatar-f.jpg';

/* ── CSRF ── */
const getToken = ()  => document.getElementById('csrfToken').value;
const setToken = (t) => { if(t) document.getElementById('csrfToken').value = t; };

/* ── Ajax ── */
async function ajax(data) {
  data.csrf_token = getToken();
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => {
    if (v !== null && v !== undefined) fd.append(k, String(v));
  });
  const r = await fetch(AJAX, { method:'POST', body:fd });
  const j = await r.json();
  setToken(j.csrf);
  return j;
}

/* ── HTML escape ── */
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');

/* ── Date format ── */
const fmtDate = d => d ? new Date(d+'T00:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '—';
const fmtTime = t => { if(!t) return ''; const [h,m]=t.split(':'); const hr=parseInt(h); return (hr%12||12)+':'+m+' '+(hr>=12?'PM':'AM'); };

/* ── Loading spinner markup ── */
const mkLoading = (msg) => `<div class="mk-loading"><i class="fas fa-spinner spin"></i><p>${esc(msg)}</p></div>`;

/* ================================================================
   STATE
   ================================================================ */
let state = {
  examId:         null,
  examData:       null,        // exam meta
  subjects:       [],          // all exam_subjects
  currentSubjIdx: 0,           // which tab is open
  sheetData:      null,        // { students, marks, current_subject }
  unsaved:        false,
  saving:         false,
  tabProgress:    {},          // { subjIdx: 'done'|'partial'|'none' }
};

/* ================================================================
   SELECTOR LOGIC
   ================================================================ */
function onSessionChange() {
  const sid = document.getElementById('selSession').value;
  const cls = document.getElementById('selClass');
  const exam = document.getElementById('selExam');
  cls.disabled  = !sid;
  exam.disabled = true;
  exam.innerHTML = '<option value="">— Select Exam —</option>';
  if (!sid) cls.value = '';
  document.getElementById('workArea').style.display  = 'none';
  document.getElementById('emptyState').style.display= '';
}

function onClassChange() {
  const sid  = document.getElementById('selSession').value;
  const cid  = document.getElementById('selClass').value;
  const examSel = document.getElementById('selExam');
  examSel.disabled = true;
  examSel.innerHTML = '<option value="">Loading…</option>';

  if (!sid || !cid) {
    examSel.innerHTML = '<option value="">— Select Exam —</option>';
    return;
  }
  loadExamDropdown(sid, cid);
}

async function loadExamDropdown(sid, cid) {
  const r = await ajax({ action:'fetch_exams_for_marks', session_id:sid, class_id:cid });
  const examSel = document.getElementById('selExam');
  if (!r.ok || !r.exams?.length) {
    examSel.innerHTML = '<option value="">No exams found</option>';
    examSel.disabled  = false;
    return;
  }
  let opts = '<option value="">— Select Exam —</option>';
  r.exams.forEach(e => {
    const subjNote = e.subject_count > 0 ? ` (${e.subject_count} subjects)` : '';
    const status   = e.status === 'published' ? ' ✓' : ' ◷';
    opts += `<option value="${e.exam_id}">${esc(e.exam_name)}${subjNote}${status}</option>`;
  });
  examSel.innerHTML = opts;
  examSel.disabled  = false;

  // If pre-selected exam_id from URL
  const preId = parseInt(document.getElementById('preExamId').value);
  if (preId) {
    examSel.value = preId;
    document.getElementById('preExamId').value = 0; // consume
    onExamChange();
  }
}

function onExamChange() {
  const v = document.getElementById('selExam').value;
  if (v) loadExam();
}

/* ================================================================
   LOAD EXAM
   ================================================================ */
async function loadExam() {
  if (state.unsaved) {
    if (!confirm('You have unsaved marks. Discard and load another exam?')) return;
  }
  const examId = parseInt(document.getElementById('selExam').value);
  if (!examId) return;

  state = { examId, examData:null, subjects:[], currentSubjIdx:0,
            sheetData:null, unsaved:false, saving:false, tabProgress:{} };

  document.getElementById('emptyState').style.display = 'none';
  document.getElementById('workArea').style.display   = '';
  document.getElementById('sheetPanel').innerHTML = mkLoading('Loading exam…');
  document.getElementById('summaryPanel').style.display = 'none';

  // Load first subject sheet, then fill in accurate progress dots for
  // every subject from the DB (not just the one tab that's open)
  await loadSheet(0);
  await loadAllSubjectProgress();
}

/* ================================================================
   LOAD SHEET FOR SUBJECT INDEX
   ================================================================ */
async function loadSheet(subjIdx) {
  document.getElementById('sheetPanel').innerHTML = mkLoading('Loading students…');

  const r = await ajax({
    action:      'fetch_marks_sheet',
    exam_id:     state.examId,
    subject_idx: subjIdx,
  });

  if (!r.ok) {
    document.getElementById('sheetPanel').innerHTML =
      `<div class="mk-empty"><i class="fas fa-triangle-exclamation mk-empty-icon" style="color:var(--red)"></i><h3>Error</h3><p>${esc(r.msg)}</p></div>`;
    return;
  }

  // Store state
  state.examData       = r.exam;
  state.subjects       = r.subjects;
  state.currentSubjIdx = r.subject_idx;
  state.sheetData      = { students: r.students, marks: r.marks, subject: r.current_subject };
  state.unsaved        = false;

  // Count existing marks for tab progress
  updateTabProgress(r.subject_idx, r.students, r.marks);

  // Render everything
  renderExamBanner();
  renderSubjTabs();
  renderSheet();
}

/* ================================================================
   RENDER EXAM BANNER
   ================================================================ */
function renderExamBanner() {
  const e   = state.examData;
  const pub = e.status === 'published';

  document.getElementById('examBanner').innerHTML = `
    <div class="eb-icon"><i class="fas fa-file-alt"></i></div>
    <div class="eb-info">
      <div class="eb-name">${esc(e.exam_name)}</div>
      <div class="eb-meta">
        <span><i class="fas fa-chalkboard"></i> ${esc(e.class_label)}</span>
        ${e.group_name ? `<span>· ${esc(e.group_name)}</span>` : ''}
        <span><i class="fas fa-calendar-alt"></i> ${esc(e.session_label)}</span>
        <span><i class="fas fa-book"></i> ${state.subjects.length} subjects</span>
      </div>
    </div>
    <a class="eb-pdf-btn" href="${BASE}/admin/results-pdf.php?exam_id=${state.examId}" target="_blank" title="Download result sheet (PDF)">
      <i class="fa-solid fa-file-pdf"></i> Result PDF
    </a>
    <div class="eb-status ${pub ? 'eb-published' : 'eb-draft'}">
      <i class="fas ${pub ? 'fa-circle-check' : 'fa-clock'}"></i>
      ${pub ? 'Published' : 'Draft'}
    </div>`;
}

/* ================================================================
   RENDER SUBJECT TABS
   ================================================================ */
function renderSubjTabs() {
  let html = '';
  state.subjects.forEach((s, i) => {
    const prog  = state.tabProgress[i] || 'none';
    const actv  = i === state.currentSubjIdx ? ' active' : '';
    html += `<button class="stab-item${actv}" onclick="switchTab(${i})" id="stab-${i}">
      <span class="stab-progress ${prog}" id="stprog-${i}"></span>
      <span class="stab-name">${esc(s.subject_name)}</span>
      ${s.subject_code ? `<span class="stab-code">${esc(s.subject_code)}</span>` : ''}
    </button>`;
  });
  document.getElementById('subjTabs').innerHTML = html;

  // Summary tab — rendered into its own sticky container, outside the
  // scrollable rail, so it never scrolls away with the subjects.
  const summActv = state.currentSubjIdx === -1 ? ' active' : '';
  document.getElementById('subjTabsSummary').innerHTML =
    `<button class="stab-item is-summary${summActv}" onclick="switchTab(-1)" id="stab-summary">
      <span class="stab-name"><i class="fas fa-chart-bar" style="font-size:12px;margin-right:4px"></i>Summary</span>
    </button>`;

  initSubjScrollbar();
}

/* ── Custom horizontal scrollbar for the subject tab rail ──────────
   Replaces the OS scrollbar with a slim styled track + thumb, lets
   the mouse wheel (vertical scroll gesture) pan the rail
   horizontally, and supports click-drag on the track itself.
   Listeners are attached once only — renderSubjTabs() (which calls
   this) re-renders the tab buttons on every load, but the scroll
   container/track/thumb themselves are static, persistent DOM nodes,
   so re-attaching here would otherwise stack duplicate listeners. ── */
let _subjScrollbarReady = false;
function initSubjScrollbar() {
  const scroll = document.getElementById('subjTabsScroll');
  const bar    = document.getElementById('subjScrollbar');
  const thumb  = document.getElementById('subjScrollbarThumb');
  if (!scroll || !bar || !thumb) return;

  function sync() {
    const { scrollWidth, clientWidth, scrollLeft } = scroll;
    const overflowing = scrollWidth > clientWidth + 1;
    bar.style.display = overflowing ? 'block' : 'none';
    if (!overflowing) return;
    const trackW = bar.clientWidth;
    const thumbW = Math.max(36, (clientWidth / scrollWidth) * trackW);
    const maxThumbX = trackW - thumbW;
    const maxScroll  = scrollWidth - clientWidth;
    const thumbX = maxScroll > 0 ? (scrollLeft / maxScroll) * maxThumbX : 0;
    thumb.style.width = thumbW + 'px';
    thumb.style.transform = `translateX(${thumbX}px)`;
  }

  if (!_subjScrollbarReady) {
    _subjScrollbarReady = true;

    // Vertical mouse-wheel gesture pans the rail horizontally
    scroll.addEventListener('wheel', (e) => {
      if (Math.abs(e.deltaY) > Math.abs(e.deltaX)) {
        e.preventDefault();
        scroll.scrollLeft += e.deltaY;
      }
    }, { passive: false });

    scroll.addEventListener('scroll', sync);
    window.addEventListener('resize', sync);

    // Click-drag the custom thumb
    let dragging = false, dragStartX = 0, dragStartScroll = 0;
    thumb.addEventListener('mousedown', (e) => {
      dragging = true;
      dragStartX = e.clientX;
      dragStartScroll = scroll.scrollLeft;
      thumb.classList.add('dragging');
      e.preventDefault();
    });
    document.addEventListener('mousemove', (e) => {
      if (!dragging) return;
      const trackW = bar.clientWidth;
      const ratio  = (scroll.scrollWidth - scroll.clientWidth) / (trackW - thumb.clientWidth || 1);
      scroll.scrollLeft = dragStartScroll + (e.clientX - dragStartX) * ratio;
    });
    document.addEventListener('mouseup', () => {
      if (!dragging) return;
      dragging = false;
      thumb.classList.remove('dragging');
    });

    // Click anywhere on the track jumps the thumb there
    bar.addEventListener('click', (e) => {
      if (e.target === thumb) return;
      const rect  = bar.getBoundingClientRect();
      const ratio = (e.clientX - rect.left) / rect.width;
      scroll.scrollLeft = ratio * (scroll.scrollWidth - scroll.clientWidth);
    });
  }

  sync();
}

async function switchTab(idx) {
  if (idx === state.currentSubjIdx) return;
  if (state.unsaved) {
    if (!confirm('You have unsaved marks. Switch tab without saving?')) return;
  }
  state.unsaved = false;

  if (idx === -1) {
    // Summary tab
    state.currentSubjIdx = -1;
    document.querySelectorAll('.stab-item').forEach(b => b.classList.remove('active'));
    document.getElementById('stab-summary')?.classList.add('active');
    document.getElementById('sheetPanel').style.display   = 'none';
    document.getElementById('summaryPanel').style.display = '';
    await loadSummary();
  } else {
    document.getElementById('sheetPanel').style.display   = '';
    document.getElementById('summaryPanel').style.display = 'none';
    await loadSheet(idx);
    // Update tab active state
    document.querySelectorAll('.stab-item').forEach((b,i) => b.classList.toggle('active', i === idx));
  }
}

/* ================================================================
   RENDER MARKS SHEET
   ================================================================ */
function renderSheet() {
  const { students, marks, subject: es } = state.sheetData;
  const hasMcq = !!parseInt(es.has_mcq);
  const hasCq  = !!parseInt(es.has_cq);
  const mcqFull = parseInt(es.mcq_full_marks) || 0;
  const cqFull  = parseInt(es.cq_full_marks)  || 0;
  const mcqEff  = parseInt(es.mcq_convert_to  || es.mcq_full_marks) || 0;
  const cqEff   = parseInt(es.cq_convert_to   || es.cq_full_marks)  || 0;
  const totalEff= mcqEff + cqEff;
  const passPct = parseFloat(es.pass_percentage) || 33;

  if (students.length === 0) {
    document.getElementById('sheetPanel').innerHTML =
      `<div class="mk-empty">
         <i class="fas fa-users-slash mk-empty-icon"></i>
         <h3>No Students Enrolled</h3>
         <p>No active students found for this class and session.</p>
       </div>`;
    return;
  }

  // Count entered
  let entered = 0, failed = 0;
  Object.values(marks).forEach(m => {
    if (m.total_marks !== null && m.total_marks !== undefined) {
      entered++;
      if (m.subject_status === 'fail') failed++;
    }
  });
  const remaining = students.length - entered;

  // Subject info strip above table
  let subjectInfo = '';
  // Show subject role chip for class 9-10 choice subjects
  if (state.sheetData?.students?.some(st => st.subject_role === 'main')) {
    subjectInfo += `<span class="msc" style="background:var(--primary-light);color:var(--primary)"><i class="fas fa-star"></i> Main Subject</span>`;
  } else if (state.sheetData?.students?.some(st => st.subject_role === 'fourth')) {
    subjectInfo += `<span class="msc" style="background:var(--amber-bg);color:var(--amber)"><i class="fas fa-4"></i> 4th Subject — bonus GP only</span>`;
  }
  if (hasMcq) subjectInfo += `<span class="msc msc-done"><i class="fas fa-list-check"></i> MCQ ${mcqFull}${mcqEff !== mcqFull ? ' → '+mcqEff : ''} marks</span>`;
  if (hasCq)  subjectInfo += `<span class="msc msc-done" style="background:var(--green-bg);color:var(--green)"><i class="fas fa-pen-nib"></i> CQ ${cqFull}${cqEff !== cqFull ? ' → '+cqEff : ''} marks</span>`;
  if (totalEff > 0) subjectInfo += `<span class="msc" style="background:var(--bg-elevated);color:var(--text-muted)"><i class="fas fa-sigma"></i> Total ${totalEff}</span>`;
  subjectInfo += `<span class="msc" style="background:var(--amber-bg);color:var(--amber)"><i class="fas fa-check"></i> Pass ${passPct}%</span>`;

  // Date/time
  let schedInfo = '';
  if (es.exam_date) schedInfo = `<span><i class="fas fa-calendar-day"></i> ${fmtDate(es.exam_date)}${es.start_time ? ' · '+fmtTime(es.start_time) : ''}${es.duration_min ? ' · '+es.duration_min+' min' : ''}</span>`;

  // Table columns — explicit widths declared once via <colgroup>,
  // shared identically by header and body so they can never drift
  // apart (table-layout:fixed makes these widths authoritative).
  let colGroup   = `<col style="width:64px">` +            // Roll
                    `<col style="width:200px">` +                               // Student (flexible)
                    `<col class="stu-id-col" style="width:120px">`;            // Student ID
  let colHeaders = `<th class="th-center">Roll</th>
    <th>Student</th>
    <th class="th-center stu-id-col">Student ID</th>`;
  if (hasMcq) {
    colGroup   += `<col style="width:130px">`;
    colHeaders += `<th class="th-center">MCQ<span style="display:block;font-weight:500;font-size:10px;text-transform:none;letter-spacing:0;color:var(--text-light)">out of ${mcqFull}</span></th>`;
  }
  if (hasCq) {
    colGroup   += `<col style="width:130px">`;
    colHeaders += `<th class="th-center">CQ<span style="display:block;font-weight:500;font-size:10px;text-transform:none;letter-spacing:0;color:var(--text-light)">out of ${cqFull}</span></th>`;
  }
  colGroup   += `<col style="width:84px"><col style="width:76px"><col style="width:84px"><col style="width:100px">`;
  colHeaders += `<th class="th-center">Absent</th>
    <th class="th-center">Grade</th>
    <th class="th-center">Total</th>
    <th class="th-center">Status</th>`;

  // Table rows
  let rows = '';
  students.forEach(st => {
    const eid = st.enrollment_id;
    const m   = marks[eid];
    const isAbsent = m && m.total_marks == 0 && m.mcq_marks_raw === null && m.cq_marks_raw === null;

    const mcqVal  = m?.mcq_marks_raw  !== null && m?.mcq_marks_raw  !== undefined ? m.mcq_marks_raw  : '';
    const cqVal   = m?.cq_marks_raw   !== null && m?.cq_marks_raw   !== undefined ? m.cq_marks_raw   : '';
    const grade   = m?.grade         || '';
    const total   = m?.total_marks   !== null && m?.total_marks !== undefined ? parseFloat(m.total_marks).toFixed(1) : '';
    const status  = m?.subject_status || '';

    // Photo — gender-based avatar fallback, same as students.php
    const fallback = st.gender === 'female' ? AVATAR_F : AVATAR_M;
    const photoHtml = st.photo_path
      ? `<img class="stu-photo" src="${BASE}/${esc(st.photo_path)}" alt="" onerror="this.src='${fallback}'" loading="lazy">`
      : `<img class="stu-photo" src="${fallback}" alt="" loading="lazy">`;

    const absentChk = isAbsent ? 'checked' : '';

    let tdMcq = '';
    let tdCq  = '';
    if (hasMcq) {
      tdMcq = `<td>
        <div class="mark-input-wrap">
          <input type="number" class="mark-input" id="mcq-${eid}"
                 min="0" max="${mcqFull}" step="0.5"
                 value="${esc(String(mcqVal))}"
                 placeholder="0"
                 ${isAbsent ? 'disabled' : ''}
                 oninput="onMarkInput(${eid})"
                 onkeydown="handleArrowNav(event,${eid},'mcq')"
                 data-max="${mcqFull}" data-type="mcq" data-eid="${eid}">
        </div>
      </td>`;
    }
    if (hasCq) {
      tdCq = `<td>
        <div class="mark-input-wrap">
          <input type="number" class="mark-input" id="cq-${eid}"
                 min="0" max="${cqFull}" step="0.5"
                 value="${esc(String(cqVal))}"
                 placeholder="0"
                 ${isAbsent ? 'disabled' : ''}
                 oninput="onMarkInput(${eid})"
                 onkeydown="handleArrowNav(event,${eid},'cq')"
                 data-max="${cqFull}" data-type="cq" data-eid="${eid}">
        </div>
      </td>`;
    }

    const roleBadge = st.subject_role === 'main'
      ? '<span class="role-badge role-badge-main">Main</span>'
      : st.subject_role === 'fourth'
        ? '<span class="role-badge role-badge-fourth">4th</span>'
        : '';

    rows += `
    <tr id="row-${eid}" class="${isAbsent ? 'is-absent' : ''}">
      <td class="roll-cell">${esc(String(st.roll_number || '—'))}</td>
      <td>
        <div class="stu-cell">
          ${photoHtml}
          <div class="stu-name">${esc(st.full_name)}${roleBadge}</div>
        </div>
      </td>
      <td class="stu-id-cell stu-id-col">${esc(st.student_id)}</td>
      ${tdMcq}${tdCq}
      <td class="absent-toggle">
        <label class="abs-switch" title="Mark absent">
          <input type="checkbox" id="abs-${eid}"
                 ${absentChk}
                 onchange="onAbsentToggle(${eid})">
          <span class="abs-switch-track"></span>
        </label>
      </td>
      <td class="grade-cell" id="grade-${eid}">${grade ? gradeHTML(grade) : '<span class="grade-badge gb-blank">—</span>'}</td>
      <td class="total-cell" id="total-${eid}">${total || '—'}</td>
      <td class="status-cell" id="status-${eid}">${statusHTML(status)}</td>
    </tr>`;
  });

  const html = `
  <div class="sheet-toolbar">
    <div class="sheet-toolbar-left">
      <div class="marks-stat-chips">
        <span class="msc msc-done"><i class="fas fa-check"></i> ${entered} entered</span>
        ${remaining > 0 ? `<span class="msc msc-rem"><i class="fas fa-clock"></i> ${remaining} remaining</span>` : ''}
        ${failed   > 0 ? `<span class="msc msc-fail"><i class="fas fa-xmark"></i> ${failed} failing</span>` : ''}
      </div>
      <div class="marks-stat-chips">${subjectInfo}</div>
      ${schedInfo ? `<div class="marks-stat-chips" style="color:var(--text-muted);font-size:12.5px">${schedInfo}</div>` : ''}
    </div>
    <div class="sheet-toolbar-right">
      <div class="st-search"><i class="fas fa-search"></i>
        <input type="text" id="markSearch" placeholder="Search student…" oninput="filterRows(this.value)">
      </div>
      <button class="absent-all-btn" id="btnAbsentAll" onclick="toggleAbsentAll()" title="Toggle all absent">
        <i class="fas fa-user-xmark"></i> <span>All Absent</span>
      </button>
      <button class="btn btn-sm btn-outline" onclick="clearSubjectMarks()" title="Clear all marks for this subject">
        <i class="fas fa-eraser"></i>
      </button>
    </div>
  </div>

  <div class="marks-table-wrap">
    <table class="marks-table" id="marksTable">
      <colgroup>${colGroup}</colgroup>
      <thead><tr>${colHeaders}</tr></thead>
      <tbody id="marksBody">${rows}</tbody>
    </table>
  </div>

  <div class="save-bar" id="saveBar">
    <div class="save-bar-info" id="saveBarInfo">
      <strong>${students.length}</strong> students · <strong>${state.subjects[state.currentSubjIdx]?.subject_name || ''}</strong>
    </div>
    <div class="save-bar-actions">
      <button class="btn btn-outline btn-sm" onclick="resetUnsaved()">
        <i class="fas fa-rotate-left"></i> Discard
      </button>
      <button class="btn btn-primary" id="btnSave" onclick="saveMarks()">
        <i class="fas fa-floppy-disk"></i> Save Marks
      </button>
    </div>
  </div>`;

  document.getElementById('sheetPanel').innerHTML = html;
}

/* ── Grade HTML ── */
function gradeHTML(g) {
  const cls = {'A+':'gb-ap','A':'gb-a','A-':'gb-am','B':'gb-b','C':'gb-c','D':'gb-d','F':'gb-f'}[g] || 'gb-blank';
  return `<span class="grade-badge ${cls}">${esc(g)}</span>`;
}
function gpaClass(gp) {
  const map = {5:'gpa-5',4:'gpa-4',3.5:'gpa-35',3:'gpa-3',2:'gpa-2',1:'gpa-1',0:'gpa-0'};
  return map[parseFloat(gp)] || 'gpa-0';
}
function statusHTML(s) {
  if (s==='pass') return '<span class="st-pass"><i class="fas fa-circle-check"></i> Pass</span>';
  if (s==='fail') return '<span class="st-fail"><i class="fas fa-circle-xmark"></i> Fail</span>';
  return '<span style="color:var(--text-light)">—</span>';
}

/* ── Live preview on input ── */
function onMarkInput(eid) {
  const es    = state.sheetData?.subject;
  if (!es) return;
  const hasMcq = !!parseInt(es.has_mcq);
  const hasCq  = !!parseInt(es.has_cq);

  const mcqInp = document.getElementById('mcq-'+eid);
  const cqInp  = document.getElementById('cq-'+eid);

  const mcqRaw = hasMcq && mcqInp ? parseFloat(mcqInp.value) : null;
  const cqRaw  = hasCq  && cqInp  ? parseFloat(cqInp.value)  : null;

  // Validate over-max
  if (mcqInp && !isNaN(mcqRaw)) {
    const max = parseFloat(mcqInp.dataset.max);
    mcqInp.classList.toggle('inp-over', mcqRaw > max);
    mcqInp.classList.toggle('inp-ok',   mcqRaw <= max && mcqRaw >= 0);
  }
  if (cqInp && !isNaN(cqRaw)) {
    const max = parseFloat(cqInp.dataset.max);
    cqInp.classList.toggle('inp-over', cqRaw > max);
    cqInp.classList.toggle('inp-ok',   cqRaw <= max && cqRaw >= 0);
  }

  // Live calc
  const computed = clientCalc(es, isNaN(mcqRaw) ? null : mcqRaw, isNaN(cqRaw) ? null : cqRaw);
  document.getElementById('grade-'+eid).innerHTML  = computed ? gradeHTML(computed.grade) : '<span class="grade-badge gb-blank">—</span>';
  document.getElementById('total-'+eid).textContent = computed ? computed.total.toFixed(1) : '—';
  document.getElementById('status-'+eid).innerHTML  = computed ? statusHTML(computed.status) : '<span style="color:var(--text-light)">—</span>';

  markUnsaved();
}

/* Client-side grade calc (mirrors server) */
function clientCalc(es, mcqRaw, cqRaw) {
  const hasMcq  = !!parseInt(es.has_mcq);
  const hasCq   = !!parseInt(es.has_cq);
  const mcqEff  = parseInt(es.mcq_convert_to || es.mcq_full_marks) || 0;
  const cqEff   = parseInt(es.cq_convert_to  || es.cq_full_marks)  || 0;
  const totalEff= mcqEff + cqEff;
  const passPct = parseFloat(es.pass_percentage) || 33;

  if (mcqRaw === null && cqRaw === null) return null;

  let mcqConv = 0, cqConv = 0;
  if (hasMcq && mcqRaw !== null) {
    const mf = parseInt(es.mcq_full_marks) || 0;
    mcqConv = mf > 0 ? Math.min((mcqRaw/mf)*mcqEff, mcqEff) : mcqRaw;
  }
  if (hasCq && cqRaw !== null) {
    const cf = parseInt(es.cq_full_marks) || 0;
    cqConv = cf > 0 ? Math.min((cqRaw/cf)*cqEff, cqEff) : cqRaw;
  }

  const total = mcqConv + cqConv;
  const pct   = totalEff > 0 ? (total/totalEff)*100 : 0;

  let pass = pct >= passPct;
  if (hasMcq && mcqEff > 0 && (mcqConv/mcqEff)*100 < passPct) pass = false;
  if (hasCq  && cqEff  > 0 && (cqConv/cqEff)*100  < passPct) pass = false;

  const g = pct>=80?'A+':pct>=70?'A':pct>=60?'A-':pct>=50?'B':pct>=40?'C':pct>=33?'D':'F';
  return { grade:g, total, status: pass?'pass':'fail' };
}

/* ── Absent toggle ── */
function onAbsentToggle(eid) {
  const chk     = document.getElementById('abs-'+eid);
  const row     = document.getElementById('row-'+eid);
  const mcqInp  = document.getElementById('mcq-'+eid);
  const cqInp   = document.getElementById('cq-'+eid);
  const absent  = chk.checked;

  row.classList.toggle('is-absent', absent);
  if (mcqInp) { mcqInp.disabled = absent; if(absent) { mcqInp.value=''; mcqInp.classList.remove('inp-over','inp-ok'); } }
  if (cqInp)  { cqInp.disabled  = absent; if(absent) { cqInp.value='';  cqInp.classList.remove('inp-over','inp-ok'); } }

  if (absent) {
    document.getElementById('grade-'+eid).innerHTML  = gradeHTML('F');
    document.getElementById('total-'+eid).textContent = '0';
    document.getElementById('status-'+eid).innerHTML  = statusHTML('fail');
  } else {
    document.getElementById('grade-'+eid).innerHTML  = '<span class="grade-badge gb-blank">—</span>';
    document.getElementById('total-'+eid).textContent = '—';
    document.getElementById('status-'+eid).innerHTML  = '<span style="color:var(--text-light)">—</span>';
  }
  markUnsaved();
}

/* ── Toggle all absent ── */
function toggleAbsentAll() {
  const students = state.sheetData?.students || [];
  // Check if all currently absent
  const allAbsent = students.every(st => document.getElementById('abs-'+st.enrollment_id)?.checked);
  students.forEach(st => {
    const chk = document.getElementById('abs-'+st.enrollment_id);
    if (!chk) return;
    if (chk.checked === !allAbsent) return; // already in target state
    chk.checked = !allAbsent;
    onAbsentToggle(st.enrollment_id);
  });
  document.getElementById('btnAbsentAll')?.classList.toggle('active', !allAbsent);
  markUnsaved();
}

/* ── Search filter ── */
function filterRows(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('#marksBody tr').forEach(row => {
    const text = row.querySelector('.stu-name')?.textContent?.toLowerCase() + ' ' +
                 row.querySelector('.stu-id-cell')?.textContent?.toLowerCase();
    row.classList.toggle('row-hidden', !!q && !text.includes(q));
  });
}

/* ── Arrow-key navigation between inputs ── */
function handleArrowNav(e, eid, type) {
  const students = state.sheetData?.students || [];
  const idx = students.findIndex(s => s.enrollment_id == eid);
  if (e.key === 'ArrowDown' || e.key === 'Enter') {
    e.preventDefault();
    const next = students[idx+1];
    if (next) {
      const inp = document.getElementById(type+'-'+next.enrollment_id);
      inp?.focus(); inp?.select();
    }
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    const prev = students[idx-1];
    if (prev) {
      const inp = document.getElementById(type+'-'+prev.enrollment_id);
      inp?.focus(); inp?.select();
    }
  }
}

/* ── Unsaved state ── */
function markUnsaved() {
  if (state.unsaved) return;
  state.unsaved = true;
  const info = document.getElementById('saveBarInfo');
  if (info) {
    const dot = '<span class="unsaved-dot"></span>';
    if (!info.innerHTML.includes('unsaved-dot')) {
      info.innerHTML = dot + info.innerHTML;
    }
  }
}

function resetUnsaved() {
  state.unsaved = false;
  if (state.sheetData) renderSheet();
}

/* ================================================================
   SAVE MARKS
   ================================================================ */
async function saveMarks() {
  if (state.saving) return;
  const { students, subject: es } = state.sheetData;
  const esId = parseInt(es.exam_subject_id);
  const hasMcq = !!parseInt(es.has_mcq);
  const hasCq  = !!parseInt(es.has_cq);

  // Build marks array
  const marksData = [];
  let hasError = false;

  for (const st of students) {
    const eid    = st.enrollment_id;
    const absent = !!document.getElementById('abs-'+eid)?.checked;

    if (absent) {
      marksData.push({ enrollment_id: eid, absent: 1 });
      continue;
    }

    const mcqInp = document.getElementById('mcq-'+eid);
    const cqInp  = document.getElementById('cq-'+eid);
    const mcqVal = hasMcq && mcqInp?.value !== '' ? parseFloat(mcqInp.value) : null;
    const cqVal  = hasCq  && cqInp?.value  !== '' ? parseFloat(cqInp.value)  : null;

    // Validate
    if (mcqInp?.classList.contains('inp-over')) {
      showToast('warning','Over Maximum', `MCQ marks for ${st.full_name} exceed the maximum.`);
      mcqInp.focus(); hasError = true; break;
    }
    if (cqInp?.classList.contains('inp-over')) {
      showToast('warning','Over Maximum', `CQ marks for ${st.full_name} exceed the maximum.`);
      cqInp.focus(); hasError = true; break;
    }

    if (mcqVal === null && cqVal === null) continue; // skip blank

    marksData.push({
      enrollment_id: eid,
      mcq_raw: mcqVal,
      cq_raw:  cqVal,
    });
  }

  if (hasError) return;
  if (marksData.length === 0) {
    showToast('warning','Nothing to Save','Enter at least one mark or mark students absent.');
    return;
  }

  // Submit
  state.saving = true;
  const btn = document.getElementById('btnSave');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner spin"></i> Saving…'; }

  const r = await ajax({
    action:          'save_marks',
    exam_subject_id: esId,
    marks_data:      JSON.stringify(marksData),
  });

  state.saving = false;
  if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-floppy-disk"></i> Save Marks'; }

  if (!r.ok) { showToast('danger','Save Failed', r.msg); return; }

  // Merge returned marks into local state
  if (r.marks) {
    Object.assign(state.sheetData.marks, r.marks);
  }
  state.unsaved = false;

  // Update tab progress dot
  updateTabProgress(state.currentSubjIdx, students, state.sheetData.marks);
  renderSubjTabs();

  showToast('success','Saved!', r.msg);

  // Re-render to show updated values cleanly
  renderSheet();
}

/* ── Clear all marks for this subject ── */
function clearSubjectMarks() {
  const es    = state.sheetData?.subject;
  const name  = es?.subject_name || 'this subject';
  const count = Object.keys(state.sheetData?.marks || {}).length;
  if (count === 0) { showToast('info','Nothing to Clear','No marks entered yet.'); return; }

  window.openModal({
    icon:'fa-eraser', iconClass:'icon-danger',
    title:'Clear All Marks?', subtitle: name,
    body:`<p style="font-size:14px;color:var(--text-sub);line-height:1.6">
      This will permanently delete all <strong>${count} mark records</strong> for <strong>${esc(name)}</strong>.<br>
      Student result summaries will be recalculated. This cannot be undone.
    </p>`,
    confirmLabel:'Yes, Clear Marks', confirmClass:'btn-danger',
    onConfirm: async () => {
      const r = await ajax({ action:'clear_subject_marks', exam_subject_id: es.exam_subject_id });
      if (!r.ok) { showToast('danger','Error', r.msg); return; }
      state.sheetData.marks = {};
      updateTabProgress(state.currentSubjIdx, state.sheetData.students, {});
      renderSubjTabs();
      renderSheet();
      showToast('success','Cleared', r.msg);
    }
  });
}

/* ── Tab progress calculation ── */
function updateTabProgress(idx, students, marks) {
  const total   = students.length;
  const entered = Object.values(marks).filter(m => m.total_marks !== null && m.total_marks !== undefined).length;
  const prog    = entered === 0 ? 'none' : entered < total ? 'partial' : 'done';
  state.tabProgress[idx] = prog;
  const dot = document.getElementById('stprog-'+idx);
  if (dot) { dot.className = 'stab-progress ' + prog; }
}

/* ── Load real progress for every subject from the DB ──────────────
   Runs once per exam load. Without this, tabProgress only ever held
   data for whichever single tab happened to be open (loadSheet only
   reports on the current subject), so dots looked reset on reload
   and stayed wrong until each tab was clicked individually. This
   pulls entered/total counts for *all* subjects in one query, straight
   from student_marks — never localStorage — so every admin sees the
   same accurate dots regardless of who entered marks or when. ── */
async function loadAllSubjectProgress() {
  const r = await ajax({ action: 'fetch_subject_progress', exam_id: state.examId });
  if (!r.ok) return; // non-fatal — dots just stay as-is if this fails

  // r.entered is now { exam_subject_id: { total, entered } } — per-subject eligible counts.
  state.subjects.forEach((s, idx) => {
    const perSubj  = r.entered[s.exam_subject_id];
    const total    = perSubj?.total    ?? 0;
    const entered  = perSubj?.entered  ?? 0;
    const prog = entered === 0 ? 'none' : entered < total ? 'partial' : 'done';
    state.tabProgress[idx] = prog;
    const dot = document.getElementById('stprog-'+idx);
    if (dot) { dot.className = 'stab-progress ' + prog; }
  });
}

/* ================================================================
   SUMMARY TAB
   ================================================================ */
async function loadSummary() {
  document.getElementById('summaryPanel').innerHTML =
    '<div class="mk-loading"><i class="fas fa-spinner spin"></i><p>Loading summary…</p></div>';

  const r = await ajax({ action:'get_exam_summary', exam_id: state.examId });
  if (!r.ok) {
    document.getElementById('summaryPanel').innerHTML =
      `<div class="mk-empty"><i class="fas fa-triangle-exclamation mk-empty-icon" style="color:var(--red)"></i><h3>Error</h3><p>${esc(r.msg)}</p></div>`;
    return;
  }

  const { exam, subjects, summaries, total_students } = r;
  const withMarks  = summaries.length;
  const passCount  = summaries.filter(s => s.overall_status === 'pass').length;
  const failCount  = summaries.filter(s => s.overall_status === 'fail').length;
  const avgGPA     = withMarks > 0
    ? (summaries.reduce((a,s) => a + parseFloat(s.gpa||0), 0) / withMarks).toFixed(2)
    : '—';

  // Stats
  let statsHtml = `
    <div class="sum-stat"><div class="sum-stat-val">${total_students}</div><div class="sum-stat-lbl">Total Students</div></div>
    <div class="sum-stat"><div class="sum-stat-val">${withMarks}</div><div class="sum-stat-lbl">Marks Entered</div></div>
    <div class="sum-stat" style="border-color:var(--green-border)"><div class="sum-stat-val" style="color:var(--green)">${passCount}</div><div class="sum-stat-lbl">Passed</div></div>
    <div class="sum-stat" style="border-color:var(--red-border)"><div class="sum-stat-val" style="color:var(--red)">${failCount}</div><div class="sum-stat-lbl">Failed</div></div>
    <div class="sum-stat" style="border-color:var(--purple-border)"><div class="sum-stat-val" style="color:var(--purple)">${avgGPA}</div><div class="sum-stat-lbl">Average GPA</div></div>`;

  // Table header — one col per subject
  let thSubjs = subjects.map(s => `<th class="tc">${esc(s.subject_name)}${s.subject_code ? `<br><span style="font-weight:400;font-size:9.5px">${esc(s.subject_code)}</span>` : ''}</th>`).join('');

  // Explicit column widths — locked via colgroup since the table uses
  // table-layout:fixed. Subject columns share a sensible fixed width
  // each so badges always land centered under their header regardless
  // of how many subjects the exam has.
  let colGroup = `<col style="width:52px"><col style="width:170px">` +
                 subjects.map(() => `<col style="width:92px">`).join('') +
                 `<col style="width:64px"><col style="width:72px"><col style="width:90px">`;

  // Build subject id map
  const subjectIds = subjects.map(s => parseInt(s.exam_subject_id));

  // Rows
  let trows = '';
  summaries.forEach((s, i) => {
    // Parse subject marks from raw string
    const subjectMarks = {};
    if (s.subject_marks_raw) {
      s.subject_marks_raw.split('|').forEach(chunk => {
        const [esid, grade, total, status] = chunk.split(':');
        subjectMarks[parseInt(esid)] = { grade, total, status };
      });
    }

    const rank   = i + 1;
    const rankCls= rank===1?'rank-1':rank===2?'rank-2':rank===3?'rank-3':'rank-n';
    const gpCls  = gpaClass(s.gpa);
    const passRow= s.overall_status==='pass';

    const subjectTds = subjects.map(sub => {
      const sm = subjectMarks[parseInt(sub.exam_subject_id)];
      if (!sm || sm.grade === '—') {
          return `
          <td class="tc">
              <div class="subj-mark-cell">
                  <span style="color:var(--text-light)">—</span>
              </div>
          </td>`;
      }
      const gc = {'A+':'gb-ap','A':'gb-a','A-':'gb-am','B':'gb-b','C':'gb-c','D':'gb-d','F':'gb-f'}[sm.grade]||'gb-blank';
      return `<td class="tc">
        <div class="subj-mark-cell">
          <span class="grade-badge ${gc}">${esc(sm.grade)}</span>
          <div class="subj-mark-total">${parseFloat(sm.total||0).toFixed(1)}</div>
        </div>
      </td>`;
    }).join('');

    trows += `<tr>
      <td class="tc"><span class="rank-badge ${rankCls}">${rank}</span></td>
      <td>
        <div style="font-weight:700;font-size:13.5px">${esc(s.full_name)}</div>
        <div style="font-size:11.5px;color:var(--text-muted)">${esc(s.student_id)} · Roll ${s.roll_number||'—'}</div>
      </td>
      ${subjectTds}
      <td class="tc"><span class="gpa-badge ${gpCls}">${parseFloat(s.gpa).toFixed(2)}</span></td>
      <td class="tc"><strong>${parseFloat(s.total_marks).toFixed(1)}</strong></td>
      <td class="tc">${passRow
        ? '<span class="st-pass"><i class="fas fa-circle-check"></i> Pass</span>'
        : '<span class="st-fail"><i class="fas fa-circle-xmark"></i> Fail</span>'}</td>
    </tr>`;
  });

  if (!trows) {
    trows = `<tr><td colspan="${5 + subjects.length}" style="text-align:center;padding:32px;color:var(--text-muted)">
      No marks entered yet for this exam.</td></tr>`;
  }

  document.getElementById('summaryPanel').innerHTML = `
    <div class="summary-stats">${statsHtml}</div>
    <div class="marks-table-wrap">
      <table class="summary-table">
        <colgroup>${colGroup}</colgroup>
        <thead>
          <tr>
            <th class="tc">Rank</th>
            <th>Student</th>
            ${thSubjs}
            <th class="tc">GPA</th>
            <th class="tc">Total</th>
            <th class="tc">Result</th>
          </tr>
        </thead>
        <tbody>${trows}</tbody>
      </table>
    </div>`;
}

/* ================================================================
   INIT — auto-load if session/class/exam pre-set from URL
   ================================================================ */
(function init() {
  const preExamId = parseInt(document.getElementById('preExamId').value);
  if (!preExamId) {
    // Auto-select current session
    const sesEl = document.getElementById('selSession');
    if (sesEl.value) {
      document.getElementById('selClass').disabled = false;
    }
    return;
  }

  // We have a preExamId — need to find which session/class it belongs to
  (async () => {
    const r = await ajax({ action:'fetch_exams_for_marks', session_id:0, class_id:0 });
    if (!r.ok) return;
    const exam = (r.exams||[]).find(e => parseInt(e.exam_id) === preExamId);
    if (!exam) return;

    // Set selectors
    document.getElementById('selSession').value = exam.session_id;
    document.getElementById('selClass').disabled = false;
    document.getElementById('selClass').value = exam.class_id;
    // loadExamDropdown will detect preExamId, select it, and auto-load
    await loadExamDropdown(exam.session_id, exam.class_id);
  })();
})();
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
<?php
/**
 * EMS Admin — Exams Management  (exams.php)
 * All mutations go through exams-ajax.php via AJAX — zero page reloads.
 *
 * What this page covers:
 *  • List all exams (filter by session, class, status)
 *  • Create exam: name → class+session → pick subjects → configure
 *    each subject's MCQ/CQ marks, conversion, exam date/time
 *  • View exam detail (subjects breakdown)
 *  • Publish / unpublish an exam
 *  • Delete a draft exam (only if no marks submitted)
 */
require_once __DIR__ . '/../includes/admin-session.php';

$pageTitle  = 'Exams';
$activeNav  = 'exams';
$breadcrumb = ['Examinations', 'Exams'];

// ── Reference data (dropdowns, preloaded in PHP) ──────────────────
function safeRows(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); } catch (Throwable $e) { return []; }
}

$allSessions = safeRows("SELECT session_id, session_year, label, is_current FROM sessions ORDER BY session_year DESC");
$allClasses  = safeRows("SELECT class_id, class_num, label FROM classes ORDER BY class_num");
$allGroups   = safeRows("SELECT group_id, group_name FROM groups ORDER BY group_id");

// Default session = current
$currentSession = null;
foreach ($allSessions as $s) { if ($s['is_current']) { $currentSession = $s; break; } }
if (!$currentSession && !empty($allSessions)) $currentSession = $allSessions[0];

require_once __DIR__ . '/includes/admin-sidebar.php';
?>

<style>
/* ================================================================
   Exams Page — page-specific styles
   (base design tokens from admin.css, not repeated here)
   ================================================================ */

/* ── Filter bar (matches Publish Results page exactly) ─────────────── */
.rs-filterbar {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  padding: 16px 20px;
  display: flex;
  align-items: flex-end;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 20px;
}
.rs-fg { display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 140px; }
.rs-fg label {
  font-size: 11.5px; font-weight: 700; color: var(--text-muted);
  text-transform: uppercase; letter-spacing: .05em;
}
.rs-ctrl {
  height: 40px; padding: 0 32px 0 12px;
  border: 1.5px solid var(--border); border-radius: var(--r-sm);
  font-family: inherit; font-size: 13.5px; color: var(--text);
  background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E") no-repeat right 12px center;
  appearance: none; outline: none; width: 100%;
  transition: border-color .15s, box-shadow .15s; cursor: pointer;
}
.rs-ctrl:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }

/* Status filter tabs */
.rs-status-tabs {
  display: flex; border: 1.5px solid var(--border);
  border-radius: var(--r-sm); overflow: hidden; flex-shrink: 0; align-self: flex-end;
}
.rs-stab {
  height: 40px; padding: 0 16px; border: none; background: var(--bg-card);
  font-family: inherit; font-size: 12.5px; font-weight: 600; color: var(--text-muted);
  cursor: pointer; border-right: 1px solid var(--border);
  transition: background .13s, color .13s;
  display: flex; align-items: center; gap: 6px; white-space: nowrap;
}
.rs-stab:last-child { border-right: none; }
.rs-stab.active { background: var(--primary); color: #fff; }
.rs-stab i { font-size: 11px; }

/* filter separator */
.filter-sep {
  width: 1px;
  height: 24px;
  background: var(--border);
  flex-shrink: 0;
}
.filter-spacer { flex: 1; }

/* ── Exam cards grid ─────────────────────────────────────────────── */
.exams-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 16px;
}

.exam-card {
  background: var(--bg-card);
  border: 1.5px solid var(--border);
  border-radius: var(--r-md);
  overflow: hidden;
  transition: border-color .15s, box-shadow .15s, transform .15s;
  display: flex;
  flex-direction: column;
}
.exam-card:hover {
  border-color: #c7d2fe;
  box-shadow: 0 4px 20px rgba(99,102,241,.1);
  transform: translateY(-1px);
}
.exam-card.is-published {
  border-color: #a7f3d0;
}
.exam-card.is-published:hover {
  border-color: var(--green);
  box-shadow: 0 4px 20px rgba(5,150,105,.1);
}
.exam-card.is-ongoing {
  border-color: #bfdbfe;
}
.exam-card.is-ongoing:hover {
  border-color: var(--primary);
  box-shadow: 0 4px 20px rgba(37,99,235,.1);
}

/* Top colour strip */
.exam-card-strip {
  height: 4px;
  background: linear-gradient(90deg, var(--accent), var(--primary));
}
.exam-card.is-published .exam-card-strip {
  background: linear-gradient(90deg, var(--green), #10b981);
}
.exam-card.is-ongoing .exam-card-strip {
  background: linear-gradient(90deg, var(--primary), #60a5fa);
}

.exam-card-body { padding: 18px 18px 14px; flex: 1; }

.exam-card-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 12px;
}

.exam-name {
  font-size: 15px;
  font-weight: 700;
  color: var(--text);
  line-height: 1.3;
  flex: 1;
  min-width: 0;
}

.exam-status-badge {
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 20px;
  display: flex;
  align-items: center;
  gap: 4px;
}
.esb-draft     { background: var(--amber-bg);  color: var(--amber); }
.esb-ongoing   { background: var(--primary-xlight); color: var(--primary); }
.esb-published { background: var(--green-bg);  color: var(--green); }

/* Meta row */
.exam-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 14px;
}
.exam-meta-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  font-weight: 500;
  color: var(--text-muted);
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 3px 9px;
}
.exam-meta-chip i { font-size: 10.5px; }

/* Subjects mini list */
.exam-subjects-row {
  margin-bottom: 4px;
}
.exam-subjects-label {
  font-size: 11.5px;
  font-weight: 600;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: .06em;
  margin-bottom: 6px;
}
.exam-subj-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.esp {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  background: var(--primary-xlight);
  color: var(--primary);
  font-weight: 600;
  white-space: nowrap;
}
.esp-more {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  background: var(--bg);
  color: var(--text-muted);
  border: 1px solid var(--border);
  font-weight: 600;
}

/* Dates row */
.exam-dates {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--text-muted);
  margin-top: 10px;
}
.exam-dates i { font-size: 11px; }

/* Card footer — action buttons */
.exam-card-footer {
  padding: 10px 18px 14px;
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  border-top: 1px solid var(--border-light);
}

/* Compact action button */
.exam-btn {
  height: 30px;
  padding: 0 12px;
  border-radius: var(--r-sm);
  font-family: inherit;
  font-size: 12px;
  font-weight: 600;
  border: 1.5px solid var(--border);
  background: var(--bg-card);
  color: var(--text-muted);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: all .13s;
  text-decoration: none;
  white-space: nowrap;
}
.exam-btn:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-xlight); }
.exam-btn.eb-danger:hover { border-color: var(--red); color: var(--red); background: var(--red-bg); }
.exam-btn.eb-success:hover { border-color: var(--green); color: var(--green); background: var(--green-bg); }
.exam-btn.eb-primary { border-color: var(--primary); color: var(--primary); }
.exam-btn.eb-primary:hover { background: var(--primary-xlight); }
.exam-btn.eb-solid-green { background: var(--green); color: #fff; border-color: var(--green); }
.exam-btn.eb-solid-green:hover { background: var(--green-dark); }
.exam-btn.eb-solid-amber { background: var(--amber); color: #fff; border-color: var(--amber); }
.exam-btn i { font-size: 11px; }

/* Created by / time */
.exam-created {
  margin-left: auto;
  font-size: 11px;
  color: var(--text-light);
  white-space: nowrap;
}

/* Empty / loading state */
.exam-empty {
  grid-column: 1 / -1;
  text-align: center;
  padding: 60px 24px;
}
.exam-empty i   { font-size: 48px; color: var(--text-light); display: block; margin: 0 auto 12px; }
.exam-empty h3  { font-size: 17px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.exam-empty p   { font-size: 13.5px; color: var(--text-muted); max-width: 360px; margin: 0 auto; }

/* ── Results counter strip ───────────────────────────────────────── */
.exam-results-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
  gap: 10px;
  flex-wrap: wrap;
}
.exam-results-count {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-muted);
}
.exam-results-count strong { color: var(--text); }

/* ── WIZARD: Create Exam ─────────────────────────────────────────── */
.wizard-panel {
  background: var(--bg-card);
  border: 1.5px solid var(--border);
  border-radius: var(--r-md);
  overflow: hidden;
  margin-bottom: 28px;
  display: none; /* shown by JS */
}
.wizard-panel.open { display: block; }

/* Step indicator */
.wizard-steps {
  display: flex;
  background: var(--bg-elevated);
  border-bottom: 1px solid var(--border);
  overflow-x: auto;
  scrollbar-width: none;
}
.wizard-steps::-webkit-scrollbar { display: none; }

.wstep {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 14px 10px;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--text-light);
  border-bottom: 2.5px solid transparent;
  white-space: nowrap;
  position: relative;
  min-width: 110px;
  cursor: default;
}
.wstep-num {
  width: 22px; height: 22px;
  border-radius: 50%;
  background: var(--border);
  color: var(--text-muted);
  font-size: 11.5px;
  font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  transition: background .2s, color .2s;
}
.wstep.active {
  color: var(--primary);
  border-bottom-color: var(--primary);
}
.wstep.active .wstep-num {
  background: var(--primary);
  color: #fff;
}
.wstep.done {
  color: var(--green);
}
.wstep.done .wstep-num {
  background: var(--green);
  color: #fff;
}
/* Arrow separator */
.wstep + .wstep::before {
  content: '';
  position: absolute;
  left: 0; top: 50%;
  transform: translateY(-50%);
  width: 1px; height: 20px;
  background: var(--border);
}

/* Step body */
.wizard-body {
  padding: 24px;
}

/* Step sections */
.wstep-section { display: none; }
.wstep-section.active { display: block; }

/* Step title in body */
.wizard-section-title {
  font-size: 15px;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 18px;
  display: flex;
  align-items: center;
  gap: 9px;
}
.wizard-section-title i {
  font-size: 15px;
  color: var(--primary);
}

/* Step 1 form grid */
.wiz-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.wiz-span { grid-column: 1 / -1; }

/* Exam name preview */
.name-preview {
  margin-top: 6px;
  font-size: 12px;
  color: var(--text-muted);
  display: flex;
  align-items: center;
  gap: 5px;
}
.name-preview strong { color: var(--primary); font-size: 13px; }

/* ── Step 2: Subject picker ──────────────────────────────────────── */
.subject-picker-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 8px;
  margin-bottom: 16px;
}

.subject-pick-item {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--bg-elevated);
  border: 1.5px solid var(--border);
  border-radius: var(--r-sm);
  padding: 10px 12px;
  cursor: pointer;
  transition: border-color .13s, background .13s;
  user-select: none;
}
.subject-pick-item:hover { border-color: #c7d2fe; background: var(--primary-xlight); }
.subject-pick-item.selected {
  border-color: var(--primary);
  background: var(--primary-xlight);
}
.subject-pick-item input[type="checkbox"] {
  position: absolute;
  opacity: 0;
  width: 0; height: 0;
  pointer-events: none;
}
.subject-pick-label {
  font-size: 13px;
  font-weight: 600;
  color: var(--text);
  flex: 1;
  min-width: 0;
}
.subject-pick-code {
  font-size: 10.5px;
  color: var(--text-muted);
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 3px;
  padding: 1px 5px;
  font-family: monospace;
  flex-shrink: 0;
}

.group-subhead {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--text-muted);
  padding: 10px 0 6px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.group-subhead::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--border);
}

/* select all / none toolbar */
.pick-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.pick-toolbar-label {
  font-size: 13px;
  color: var(--text-muted);
  font-weight: 500;
}
.pick-count-badge {
  background: var(--primary);
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 20px;
}

/* Loading spinner inside picker */
.subject-loading {
  text-align: center;
  padding: 32px 16px;
  color: var(--text-muted);
}
.subject-loading i { font-size: 26px; display: block; margin-bottom: 10px; }

/* ── Step 3: Subject config rows ─────────────────────────────────── */
.subj-config-list { display: flex; flex-direction: column; gap: 12px; }

.subj-config-card {
  border: 1.5px solid var(--border);
  border-radius: var(--r);
  overflow: hidden;
  transition: border-color .15s;
}
.subj-config-card:hover { border-color: #c7d2fe; }

/* Card header */
.scc-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  background: var(--bg-elevated);
  cursor: pointer;
  user-select: none;
}
.scc-num {
  width: 26px; height: 26px;
  border-radius: 7px;
  background: var(--primary);
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.scc-name { font-size: 14px; font-weight: 700; color: var(--text); flex: 1; min-width: 0; }
.scc-code { font-size: 11px; color: var(--text-muted); background: var(--bg); border: 1px solid var(--border); border-radius: 3px; padding: 1px 6px; font-family: monospace; flex-shrink: 0; }
.scc-summary { font-size: 12px; color: var(--text-muted); flex-shrink: 0; }
.scc-chevron { font-size: 11px; color: var(--text-muted); transition: transform .2s; flex-shrink: 0; }
.scc-header.open .scc-chevron { transform: rotate(180deg); }

/* Card body (collapsible) */
.scc-body {
  padding: 16px;
  display: none;
  border-top: 1px solid var(--border);
}
.scc-body.open { display: block; }

/* MCQ / CQ toggle switches — modern switch style (not plain checkboxes) */
.part-toggle-row {
  display: flex;
  gap: 10px;
  margin-bottom: 14px;
  flex-wrap: wrap;
}
.part-toggle {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border: 1.5px solid var(--border);
  border-radius: var(--r-sm);
  cursor: pointer;
  user-select: none;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-muted);
  transition: all .13s;
  background: var(--bg);
  flex: 1 1 180px;
}
/* Hide the native checkbox visually but keep it functional/accessible.
   Selection state is conveyed purely by border/background color below — no toggle switch, no visible checkbox. */
.part-toggle input[type="checkbox"] {
  position: absolute;
  opacity: 0;
  width: 0; height: 0;
  pointer-events: none;
}
.part-toggle i.pt-icon { font-size: 12px; flex-shrink: 0; }
.part-toggle-label { flex: 1; }
.part-toggle:has(input:checked) {
  border-color: var(--primary);
  background: var(--primary-xlight);
  color: var(--primary);
}
.part-toggle-cq:has(input:checked) {
  border-color: var(--green);
  background: var(--green-bg);
  color: var(--green-dark);
}

/* Same color-only selected state for the drawer's compact CQ/MCQ picker */
.ese-part {
  position: relative;
}
.ese-part input[type="checkbox"] {
  position: absolute;
  opacity: 0;
  width: 0; height: 0;
  pointer-events: none;
}
.ese-part:has(input:checked) {
  border-color: var(--primary);
  background: var(--primary-xlight);
  color: var(--primary);
}

/* Config row inside card body */
.cfg-row {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr auto;
  gap: 10px;
  align-items: end;
  margin-bottom: 10px;
  padding: 10px 12px;
  background: var(--bg-elevated);
  border-radius: var(--r-sm);
  border: 1px solid var(--border-light);
}
.cfg-row.hidden { display: none; }
.cfg-label {
  font-size: 11.5px;
  font-weight: 600;
  color: var(--text-muted);
  margin-bottom: 4px;
  display: flex;
  align-items: center;
  gap: 4px;
  white-space: nowrap;
}
.cfg-input {
  width: 100%;
  height: 36px;
  padding: 0 10px;
  border: 1.5px solid var(--border);
  border-radius: var(--r-sm);
  font-family: inherit;
  font-size: 13px;
  color: var(--text);
  background: #fff;
  outline: none;
  transition: border-color .13s;
}
.cfg-input:focus { border-color: var(--primary); }
.cfg-input:disabled { background: var(--bg-elevated); color: var(--text-muted); cursor: not-allowed; }

/* conversion arrow */
.cfg-arrow {
  display: flex;
  align-items: flex-end;
  padding-bottom: 8px;
  color: var(--text-muted);
  font-size: 13px;
}

/* Date / time / duration row */
.cfg-datetime {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 10px;
  margin-top: 6px;
}

/* Pass % row */
.cfg-pass-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 8px;
  padding: 8px 12px;
  background: var(--amber-bg);
  border: 1px solid var(--amber-border);
  border-radius: var(--r-sm);
  font-size: 12.5px;
  color: #78350f;
  flex-wrap: wrap;
}
.cfg-pass-row label { font-weight: 600; white-space: nowrap; }
.cfg-pass-input {
  width: 70px;
  height: 30px;
  padding: 0 8px;
  border: 1.5px solid var(--amber-border);
  border-radius: var(--r-sm);
  font-family: inherit;
  font-size: 13px;
  color: var(--text);
  background: #fff;
  outline: none;
}
.cfg-pass-input:focus { border-color: var(--amber); }
.cfg-pass-note { font-size: 11.5px; color: #78350f; }

/* ── Step summary ────────────────────────────────────────────────── */
.exam-summary-box {
  background: var(--bg-elevated);
  border: 1.5px solid var(--border);
  border-radius: var(--r);
  padding: 18px 20px;
  margin-bottom: 20px;
}
.esb-title { font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 14px; }
.esb-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 8px 0;
  border-bottom: 1px solid var(--border-light);
  font-size: 13.5px;
}
.esb-row:last-child { border-bottom: none; }
.esb-row-label { color: var(--text-muted); font-weight: 600; min-width: 120px; flex-shrink: 0; }
.esb-row-value { color: var(--text); flex: 1; }

/* Wizard navigation buttons */
.wizard-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 24px;
  border-top: 1px solid var(--border);
  background: var(--bg-elevated);
  gap: 10px;
}

/* ── View detail drawer (slide from right) ───────────────────────── */
.detail-drawer {
  position: fixed;
  top: 0; right: 0; bottom: 0;
  width: 480px;
  background: var(--bg-card);
  box-shadow: -8px 0 40px rgba(16,24,40,.15);
  z-index: 1100;
  display: flex;
  flex-direction: column;
  transform: translateX(110%);
  transition: transform .28s cubic-bezier(.25,.46,.45,.94);
}
.detail-drawer.open { transform: translateX(0); }
.detail-drawer-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.35);
  z-index: 1099;
  backdrop-filter: blur(1px);
}
.detail-drawer-overlay.open { display: block; }

.dd-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  padding: 20px 22px 16px;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
.dd-title { font-size: 17px; font-weight: 700; color: var(--text); line-height: 1.3; }
.dd-sub   { font-size: 13px; color: var(--text-muted); margin-top: 4px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.dd-close {
  width: 32px; height: 32px;
  border-radius: var(--r-sm);
  border: 1.5px solid var(--border);
  background: var(--bg);
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; color: var(--text-muted);
  cursor: pointer;
  transition: all .13s;
  flex-shrink: 0;
}
.dd-close:hover { border-color: var(--red); color: var(--red); background: var(--red-bg); }

.dd-body {
  flex: 1;
  overflow-y: auto;
  padding: 20px 22px;
}

.dd-section-title {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--text-muted);
  margin-bottom: 10px;
  margin-top: 18px;
}
.dd-section-title:first-child { margin-top: 0; }

/* Subject detail row */
.dd-subj-row {
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  border-radius: var(--r-sm);
  padding: 12px 14px;
  margin-bottom: 8px;
}
.dd-subj-name { font-size: 13.5px; font-weight: 700; color: var(--text); margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
.dd-subj-name .scode { font-size: 10.5px; }

.dd-marks-row {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin-bottom: 6px;
}
.dd-mark-chip {
  font-size: 11.5px;
  font-weight: 600;
  padding: 3px 9px;
  border-radius: 5px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.dd-mark-chip i { font-size: 10px; }
.dmc-mcq { background: #eff6ff; color: #1d4ed8; }
.dmc-cq  { background: #ecfdf5; color: #065f46; }
.dmc-tot { background: var(--bg); border: 1px solid var(--border); color: var(--text-muted); }
.dmc-pass { background: var(--amber-bg); color: #78350f; }
.dmc-date { background: var(--purple-bg); color: #5b21b6; }

.dd-subj-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 8px;
}
.dd-subj-actions {
  display: flex;
  align-items: center;
  gap: 5px;
  flex-shrink: 0;
}
.dd-icon-btn {
  width: 28px;
  height: 28px;
  border-radius: var(--r-sm);
  border: 1.5px solid var(--border);
  background: var(--bg-card);
  color: var(--text-muted);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all .13s;
}
.dd-icon-btn:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-xlight); }
.dd-icon-btn.danger:hover { border-color: var(--red); color: var(--red); background: var(--red-bg); }

.exam-subject-editor {
  text-align: left;
}
.ese-note {
  font-size: 12px;
  color: var(--text-muted);
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  border-radius: var(--r-sm);
  padding: 9px 11px;
  margin-bottom: 12px;
}
.ese-subject-select {
  margin-bottom: 12px;
}
.ese-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.ese-grid .span-full { grid-column: 1 / -1; }
.ese-label {
  font-size: 11.5px;
  font-weight: 700;
  color: var(--text-muted);
  margin-bottom: 4px;
  text-transform: uppercase;
  letter-spacing: .04em;
}
.ese-input {
  width: 100%;
  height: 36px;
  border: 1.5px solid var(--border);
  border-radius: var(--r-sm);
  padding: 0 9px;
  font: inherit;
  font-size: 13px;
  outline: none;
  background: #fff;
}
.ese-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.08); }
.ese-parts {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}
.ese-part {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 8px 10px;
  border: 1.5px solid var(--border);
  border-radius: var(--r-sm);
  background: var(--bg);
  font-size: 13px;
  font-weight: 700;
  color: var(--text-muted);
  cursor: pointer;
}
.ese-part input { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
.ese-row-title {
  font-size: 12px;
  font-weight: 800;
  color: var(--text);
  margin: 8px 0 6px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.ese-row-box {
  background: var(--bg-elevated);
  border: 1px solid var(--border-light);
  border-radius: var(--r-sm);
  padding: 10px;
}
.ese-hidden { display: none !important; }

.dd-footer {
  padding: 14px 22px;
  border-top: 1px solid var(--border);
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  flex-shrink: 0;
}

/* ── Spinner util ────────────────────────────────────────────────── */
.spin { animation: spin .65s linear infinite; display: inline-block; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Responsive ──────────────────────────────────────────────────── */
@media (max-width: 900px) {
  .exams-grid { grid-template-columns: 1fr 1fr; }
  .wiz-grid   { grid-template-columns: 1fr; }
  .cfg-row    { grid-template-columns: 1fr 1fr; }
  .cfg-arrow  { display: none; }
  .cfg-datetime { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 680px) {
  .exams-grid { grid-template-columns: 1fr; }
  .subject-picker-grid { grid-template-columns: 1fr; }
  .detail-drawer { width: 100%; border-radius: 0; }
}
@media (max-width: 540px) {
  .rs-filterbar { flex-direction: column; align-items: stretch; }
  .rs-status-tabs { align-self: flex-start; }
  .cfg-row { grid-template-columns: 1fr; }
  .cfg-datetime { grid-template-columns: 1fr; }
  .wstep .wstep-label { display: none; }
  .wizard-nav .btn { font-size: 12.5px; padding: 0 12px; }
  .part-toggle-row { flex-direction: column; gap: 8px; }
}
</style>

<!-- ════════════════════════════════════════════════════════════════
     PAGE HEADER
     ════════════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="page-header-top">
    <div>
      <div class="page-title">Exams</div>
      <div class="page-sub">
        <i class="fas fa-file-alt"></i>
        Create and manage exams — set subjects, marks, schedule &amp; publish results
        <span style="font-size:4px">●</span>
        <span id="hdrStats" style="color:var(--text-muted)">Loading…</span>
      </div>
    </div>
    <div class="page-actions">
      <button class="btn btn-primary" id="btnNewExam" onclick="toggleWizard()">
        <i class="fas fa-plus"></i> Create Exam
      </button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     CREATE EXAM WIZARD (collapsible)
     ════════════════════════════════════════════════════════════════ -->
<div class="wizard-panel" id="wizardPanel">

  <!-- Step indicator -->
  <div class="wizard-steps">
    <div class="wstep active" id="ws1">
      <div class="wstep-num">1</div>
      <span class="wstep-label">Exam Details</span>
    </div>
    <div class="wstep" id="ws2">
      <div class="wstep-num">2</div>
      <span class="wstep-label">Pick Subjects</span>
    </div>
    <div class="wstep" id="ws3">
      <div class="wstep-num">3</div>
      <span class="wstep-label">Configure Marks</span>
    </div>
    <div class="wstep" id="ws4">
      <div class="wstep-num">4</div>
      <span class="wstep-label">Review &amp; Create</span>
    </div>
  </div>

  <!-- Step bodies -->
  <div class="wizard-body">

    <!-- ── Step 1: Basic Details ─── -->
    <div class="wstep-section active" id="wsBody1">
      <div class="wizard-section-title"><i class="fas fa-circle-info"></i> Exam Details</div>
      <div class="wiz-grid">

        <div class="form-group mb-0 wiz-span">
          <label class="form-label">Exam Name <span class="req">*</span></label>
          <input type="text" id="wExamName" class="form-control"
                 placeholder="e.g. Mid Term, Final Exam, Half Yearly…"
                 maxlength="60" autocomplete="off"
                 oninput="updateNamePreview()">
          <div class="name-preview" id="namePreview" style="display:none">
            Will be saved as: <strong id="namePreviewText"></strong>
          </div>
          <div class="form-hint">
            <i class="fas fa-circle-info"></i>
            The session year will be appended automatically — e.g. "Mid Term 2026"
          </div>
        </div>

        <div class="form-group mb-0">
          <label class="form-label">Session <span class="req">*</span></label>
          <select id="wSession" class="form-control" onchange="updateNamePreview()">
            <option value="">— Select Session —</option>
            <?php foreach ($allSessions as $s): ?>
            <option value="<?= $s['session_id'] ?>"
                    data-year="<?= $s['session_year'] ?>"
                    <?= ($currentSession && $s['session_id'] == $currentSession['session_id']) ? 'selected' : '' ?>>
              <?= h($s['label']) ?> <?= $s['is_current'] ? '(Current)' : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group mb-0">
          <label class="form-label">Class <span class="req">*</span></label>
          <select id="wClass" class="form-control" onchange="onWizClassChange()">
            <option value="">— Select Class —</option>
            <?php foreach ($allClasses as $c): ?>
            <option value="<?= $c['class_id'] ?>"
                    data-num="<?= $c['class_num'] ?>">
              <?= h($c['label']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group mb-0" id="wGroupWrap" style="display:none">
          <label class="form-label">Group</label>
          <select id="wGroup" class="form-control" onchange="clearSubjectPicker()">
            <option value="">— All Groups —</option>
            <?php foreach ($allGroups as $g): ?>
            <option value="<?= $g['group_id'] ?>"><?= h($g['group_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-hint"><i class="fas fa-circle-info"></i> Only relevant for Class 9 &amp; 10</div>
        </div>

      </div>
    </div><!-- /step1 -->

    <!-- ── Step 2: Subject Picker ─── -->
    <div class="wstep-section" id="wsBody2">
      <div class="wizard-section-title"><i class="fas fa-book-open"></i> Select Subjects</div>

      <div class="pick-toolbar">
        <span class="pick-toolbar-label">Choose subjects for this exam:</span>
        <button class="btn btn-sm btn-outline" type="button" onclick="selectAllSubjects(true)">
          <i class="fas fa-check-double"></i> All
        </button>
        <button class="btn btn-sm btn-outline" type="button" onclick="selectAllSubjects(false)">
          <i class="fas fa-xmark"></i> None
        </button>
        <span class="pick-count-badge" id="pickCountBadge">0</span>
        <span class="pick-toolbar-label">selected</span>
      </div>

      <div id="subjectPickerArea">
        <div class="subject-loading">
          <i class="fas fa-book-open text-muted"></i>
          <p>Select a class in Step 1 first</p>
        </div>
      </div>
    </div><!-- /step2 -->

    <!-- ── Step 3: Configure Marks ─── -->
    <div class="wstep-section" id="wsBody3">
      <div class="wizard-section-title"><i class="fas fa-sliders"></i> Configure Marks &amp; Schedule</div>
      <div class="alert alert-info mb-3" style="font-size:13px">
        <i class="fas fa-circle-info"></i>
        <div>
          <strong>Tip:</strong>
          Toggle <strong>CQ</strong> (written) and/or <strong>MCQ</strong> (multiple choice) for each subject.
          If the exam is in a different marks range (e.g. 30), enter the original marks and set <em>Convert to</em> for what it becomes (e.g. 50).
          Students must pass CQ and MCQ <strong>separately</strong> as well as combined.
        </div>
      </div>
      <div class="subj-config-list" id="subjConfigList">
        <!-- populated by JS -->
      </div>
    </div><!-- /step3 -->

    <!-- ── Step 4: Review & Confirm ─── -->
    <div class="wstep-section" id="wsBody4">
      <div class="wizard-section-title"><i class="fas fa-circle-check"></i> Review &amp; Create</div>
      <div class="exam-summary-box" id="examSummaryBox">
        <!-- populated by JS -->
      </div>
      <div class="alert alert-warning" style="font-size:13px">
        <i class="fas fa-triangle-exclamation"></i>
        <div>
          The exam will be saved as a <strong>Draft</strong> — students cannot see it yet.
          You can publish it later once marks schedule and seat plan are ready.
        </div>
      </div>
    </div><!-- /step4 -->

  </div><!-- /wizard-body -->

  <!-- Wizard navigation footer -->
  <div class="wizard-nav">
    <button class="btn btn-outline" id="btnWizBack" onclick="wizStep(-1)" style="display:none">
      <i class="fas fa-arrow-left"></i> Back
    </button>
    <div style="display:flex;align-items:center;gap:6px;margin-left:auto">
      <button class="btn btn-outline" onclick="toggleWizard()">
        <i class="fas fa-xmark"></i> Cancel
      </button>
      <button class="btn btn-primary" id="btnWizNext" onclick="wizStep(1)">
        Next <i class="fas fa-arrow-right"></i>
      </button>
    </div>
  </div>

</div><!-- /wizard-panel -->

<!-- ════════════════════════════════════════════════════════════════
     FILTER BAR
     ════════════════════════════════════════════════════════════════ -->
<div class="rs-filterbar">

  <div class="rs-fg">
    <label><i class="fas fa-calendar-alt"></i> Session</label>
    <select id="fSession" class="rs-ctrl" onchange="loadExams()">
      <option value="0">All Sessions</option>
      <?php foreach ($allSessions as $s): ?>
      <option value="<?= $s['session_id'] ?>"
              <?= ($currentSession && $s['session_id'] == $currentSession['session_id']) ? 'selected' : '' ?>>
        <?= h($s['label']) ?><?= $s['is_current'] ? ' (Current)' : '' ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="rs-fg">
    <label><i class="fas fa-chalkboard"></i> Class</label>
    <select id="fClass" class="rs-ctrl" onchange="loadExams()">
      <option value="0">All Classes</option>
      <?php foreach ($allClasses as $c): ?>
      <option value="<?= $c['class_id'] ?>"><?= h($c['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- Status filter tabs -->
  <div class="rs-status-tabs">
    <button class="rs-stab active" data-status="" onclick="setStatusFilter(this,'')">
      <i class="fas fa-layer-group"></i> All
    </button>
    <button class="rs-stab" data-status="draft" onclick="setStatusFilter(this,'draft')">
      <i class="fas fa-clock"></i> Draft
    </button>
    <button class="rs-stab" data-status="ongoing" onclick="setStatusFilter(this,'ongoing')">
      <i class="fas fa-spinner"></i> On-going
    </button>
    <button class="rs-stab" data-status="published" onclick="setStatusFilter(this,'published')">
      <i class="fas fa-circle-check"></i> Published
    </button>
  </div>

  <!-- Summary count -->
  <span id="examCountLabel" style="font-size:12.5px;font-weight:600;color:var(--text-muted);white-space:nowrap;align-self:flex-end;padding-bottom:2px"></span>
</div>

<!-- Results bar -->
<div class="exam-results-bar">
  <div class="exam-results-count" id="examResultsCount"></div>
</div>

<!-- Exam cards -->
<div class="exams-grid" id="examsGrid">
  <div class="exam-empty">
    <i class="fas fa-spinner spin"></i>
    <h3>Loading exams…</h3>
    <p></p>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     DETAIL DRAWER (exam view)
     ════════════════════════════════════════════════════════════════ -->
<div class="detail-drawer-overlay" id="ddOverlay" onclick="closeDrawer()"></div>
<div class="detail-drawer" id="detailDrawer">
  <div class="dd-header">
    <div style="flex:1;min-width:0">
      <div class="dd-title" id="ddTitle">Exam Details</div>
      <div class="dd-sub" id="ddSub"></div>
    </div>
    <button class="dd-close" onclick="closeDrawer()" title="Close"><i class="fas fa-xmark"></i></button>
  </div>
  <div class="dd-body" id="ddBody">
    <div style="text-align:center;padding:32px;color:var(--text-muted)">
      <i class="fas fa-spinner spin" style="font-size:24px"></i>
    </div>
  </div>
  <div class="dd-footer" id="ddFooter"></div>
</div>

<!-- CSRF holder -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<script>
/* ================================================================
   EMS — Exams Page JS
   ================================================================ */
const AJAX_URL  = '<?= BASE_URL ?>/admin/exams-ajax.php';
const BASE      = '<?= BASE_URL ?>';

/* ── CSRF ── */
const getToken = ()    => document.getElementById('csrfToken').value;
const setToken = (t)   => { document.getElementById('csrfToken').value = t; };

/* ── Ajax helper ── */
async function ajax(data) {
  data.csrf_token = getToken();
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => { if (v !== null && v !== undefined) fd.append(k, String(v)); });
  const r = await fetch(AJAX_URL, { method:'POST', body:fd });
  const j = await r.json();
  if (j.csrf) setToken(j.csrf);
  return j;
}

/* ── HTML escape ── */
function esc(s) {
  if (!s && s !== 0) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

/* ── Format date ── */
function fmtDate(d) {
  if (!d) return '—';
  const dt = new Date(d + 'T00:00:00');
  return dt.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
}
function fmtTime(t) {
  if (!t) return '';
  const [h,m] = t.split(':');
  const hr = parseInt(h);
  return (hr % 12 || 12) + ':' + m + ' ' + (hr >= 12 ? 'PM' : 'AM');
}

/* ================================================================
   STATE
   ================================================================ */
let exams       = [];
let statusFilter = '';
let wizardStep  = 1;
let pickedSubjects = []; // subject objects from DB for chosen class
let selSubjIds  = [];    // subject_ids selected in step 2
let subjConfigs = {};    // { subject_id: { has_cq, cq_full_marks, ... } }
let activeExamDetail = null;
let subjectEditorAvailable = {}; // prefix -> array of subject master rows (for auto-fill on pick)

/* ================================================================
   LOAD EXAMS
   ================================================================ */
async function loadExams() {
  const sid  = document.getElementById('fSession').value;
  const cid  = document.getElementById('fClass').value;
  setGrid('<div class="exam-empty"><i class="fas fa-spinner spin"></i><h3>Loading…</h3><p></p></div>');
  try {
    const r = await ajax({ action:'fetch_exams', session_id:sid, class_id:cid, status:statusFilter });
    if (!r.ok) { showToast('danger','Error',r.msg); return; }
    exams = r.exams || [];
    renderExams();
  } catch(e) {
    setGrid('<div class="exam-empty"><i class="fas fa-triangle-exclamation"></i><h3>Load failed</h3><p>Check your connection and try again.</p></div>');
  }
}

function setStatusFilter(el, status) {
  statusFilter = status;
  document.querySelectorAll('.rs-stab').forEach(b => b.classList.toggle('active', b === el));
  renderExams();
}

function renderExams() {
  let list = exams;
  if (statusFilter) list = list.filter(e => e.status === statusFilter);

  // Header stats
  const total   = exams.length;
  const draft   = exams.filter(e => e.status === 'draft').length;
  const ongoing = exams.filter(e => e.status === 'ongoing').length;
  const pub     = exams.filter(e => e.status === 'published').length;
  document.getElementById('hdrStats').innerHTML =
    `<span style="color:var(--green);font-weight:600">${pub} published</span>` +
    (ongoing > 0 ? ` <span style="font-size:4px">●</span> <span style="color:var(--primary);font-weight:600">${ongoing} on-going</span>` : '') +
    (draft > 0 ? ` <span style="font-size:4px">●</span> <span>${draft} draft</span>` : '');
  document.getElementById('examCountLabel').textContent = total + ' total';
  document.getElementById('examResultsCount').innerHTML = list.length > 0
    ? `<strong>${list.length}</strong> exam${list.length !== 1 ? 's' : ''} shown`
    : '';

  if (list.length === 0) {
    setGrid(
      '<div class="exam-empty">' +
      '<i class="fas fa-' + (exams.length === 0 ? 'file-circle-plus' : 'filter-circle-xmark') + '"></i>' +
      '<h3>' + (exams.length === 0 ? 'No exams yet' : 'No exams match filter') + '</h3>' +
      '<p>' + (exams.length === 0
        ? 'Click <strong>Create Exam</strong> to get started.'
        : 'Try changing the session, class, or status filter.') +
      '</p></div>'
    );
    return;
  }

  setGrid(list.map(examCardHTML).join(''));
}

function setGrid(html) {
  document.getElementById('examsGrid').innerHTML = html;
}

function examCardHTML(e) {
  const status  = e.status; // draft | ongoing | published
  const isDraft = status === 'draft';
  const isOngoing = status === 'ongoing';
  const isPublished = status === 'published';
  const subjCount = parseInt(e.subject_count) || 0;

  const statusIcon  = isPublished ? 'fa-circle-check' : (isOngoing ? 'fa-spinner' : 'fa-clock');
  const statusLabel = isPublished ? 'Published' : (isOngoing ? 'On-going' : 'Draft');
  const statusClass = isPublished ? 'esb-published' : (isOngoing ? 'esb-ongoing' : 'esb-draft');
  const cardClass   = isPublished ? 'is-published' : (isOngoing ? 'is-ongoing' : '');

  const dateStr = e.first_date
    ? fmtDate(e.first_date) + (e.last_date && e.last_date !== e.first_date ? ' – ' + fmtDate(e.last_date) : '')
    : '';

  const totalMarks = parseInt(e.total_marks) || 0;

  return `
  <div class="exam-card ${cardClass}" id="ec-${e.exam_id}">
    <div class="exam-card-strip"></div>
    <div class="exam-card-body">
      <div class="exam-card-header">
        <div class="exam-name">${esc(e.exam_name)}</div>
        <div class="exam-status-badge ${statusClass}">
          <i class="fas ${statusIcon}"></i>
          ${statusLabel}
        </div>
      </div>

      <div class="exam-meta">
        <div class="exam-meta-chip">
          <i class="fas fa-chalkboard"></i> ${esc(e.class_label)}
          ${e.group_name ? `<span style="color:var(--accent)">· ${esc(e.group_name)}</span>` : ''}
        </div>
        <div class="exam-meta-chip">
          <i class="fas fa-calendar-alt"></i> ${esc(e.session_label)}
        </div>
        <div class="exam-meta-chip">
          <i class="fas fa-book"></i> ${subjCount} subject${subjCount !== 1 ? 's' : ''}
        </div>
        ${totalMarks > 0 ? `<div class="exam-meta-chip"><i class="fas fa-star"></i> ${totalMarks} total marks</div>` : ''}
      </div>

      ${dateStr ? `<div class="exam-dates"><i class="fas fa-calendar-week"></i> ${dateStr}</div>` : ''}
    </div>

    <div class="exam-card-footer">
      <button class="exam-btn" onclick='viewExam(${e.exam_id})'>
        <i class="fas fa-eye"></i> View
      </button>
      ${isDraft
        ? `<button class="exam-btn" onclick='viewExam(${e.exam_id})'>
             <i class="fas fa-sliders"></i> Edit Subjects
           </button>`
        : ''
      }
      ${isDraft
        ? `<button class="exam-btn eb-primary" onclick='startOngoing(${e.exam_id},${JSON.stringify(e.exam_name)})'>
             <i class="fas fa-play"></i> Start On-going
           </button>`
        : isOngoing
        ? `<button class="exam-btn eb-success" onclick='publishExam(${e.exam_id},${JSON.stringify(e.exam_name)})'>
             <i class="fas fa-paper-plane"></i> Publish Result
           </button>
           <button class="exam-btn" onclick='revertToDraft(${e.exam_id},${JSON.stringify(e.exam_name)})' title="Back to Draft">
             <i class="fas fa-rotate-left"></i> Back to Draft
           </button>`
        : `<button class="exam-btn" onclick='unpublishExam(${e.exam_id},${JSON.stringify(e.exam_name)})'>
             <i class="fas fa-rotate-left"></i> Unpublish
           </button>`
      }
      <a class="exam-btn" href="${BASE}/admin/marks.php?exam_id=${e.exam_id}">
        <i class="fas fa-pencil"></i> Marks
      </a>
      ${isDraft
        ? `<button class="exam-btn eb-danger" onclick='deleteExam(${e.exam_id},${JSON.stringify(e.exam_name)})'>
             <i class="fas fa-trash"></i>
           </button>`
        : ''
      }
      <span class="exam-created">
        ${isPublished ? '✓ ' + fmtDate(e.published_at)
          : isOngoing ? '● ' + fmtDate(e.ongoing_at)
          : 'Created ' + fmtDate(e.created_at)}
      </span>
    </div>
  </div>`;
}

/* ================================================================
   EXAM ACTIONS
   ================================================================ */
async function startOngoing(examId, name) {
  window.openModal({
    icon: 'fa-play', iconClass: 'icon-info',
    title: 'Start On-going Phase?', subtitle: name,
    body: `<p style="font-size:14px;color:var(--text-sub);line-height:1.6">
      Moving <strong>${esc(name)}</strong> to <strong>On-going</strong> will let students download their admit card and see the seat plan, and let teachers start entering marks.
    </p>`,
    confirmLabel: 'Yes, Start', confirmClass: 'btn-primary',
    onConfirm: async () => {
      showToast('info','Starting…','',3000);
      const r = await ajax({ action:'start_ongoing', exam_id: examId });
      if (!r.ok) { showToast('danger','Error',r.msg); return; }
      exams = r.exams; renderExams();
      showToast('success','On-going!', r.msg);
    }
  });
}

async function revertToDraft(examId, name) {
  window.openModal({
    icon: 'fa-rotate-left', iconClass: 'icon-warning',
    title: 'Move Back to Draft?', subtitle: name,
    body: `<p style="font-size:14px;color:var(--text-sub);line-height:1.6">
      Students will no longer see the admit card or seat plan, and teachers won't be able to enter marks.<br>
      <strong>This cannot be done if any marks have already been submitted.</strong>
    </p>`,
    confirmLabel: 'Yes, Revert', confirmClass: 'btn-outline',
    onConfirm: async () => {
      const r = await ajax({ action:'revert_to_draft', exam_id: examId });
      if (!r.ok) { showToast('danger','Cannot Revert',r.msg); return; }
      exams = r.exams; renderExams();
      showToast('info','Moved to Draft', r.msg);
    }
  });
}

async function publishExam(examId, name) {
  window.openModal({
    icon: 'fa-paper-plane', iconClass: 'icon-success',
    title: 'Publish Result?', subtitle: name,
    body: `<p style="font-size:14px;color:var(--text-sub);line-height:1.6">
      Publishing results for <strong>${esc(name)}</strong> makes the final marks/GPA visible to students.<br><br>
      Make sure all teachers have finished entering marks first.
    </p>`,
    confirmLabel: 'Yes, Publish', confirmClass: 'btn-success',
    onConfirm: async () => {
      showToast('info','Publishing…','',3000);
      const r = await ajax({ action:'publish_exam', exam_id: examId });
      if (!r.ok) { showToast('danger','Error',r.msg); return; }
      exams = r.exams; renderExams();
      showToast('success','Published!', r.msg);
    }
  });
}

async function unpublishExam(examId, name) {
  window.openModal({
    icon: 'fa-rotate-left', iconClass: 'icon-warning',
    title: 'Unpublish Result?', subtitle: name,
    body: `<p style="font-size:14px;color:var(--text-sub);line-height:1.6">
      Students will no longer see the published result. The exam moves back to <strong>On-going</strong> (not draft) so teachers can still fix marks.<br>
      <strong>This cannot be done if marks have already been locked/published.</strong>
    </p>`,
    confirmLabel: 'Yes, Unpublish', confirmClass: 'btn-outline',
    onConfirm: async () => {
      const r = await ajax({ action:'unpublish_exam', exam_id: examId });
      if (!r.ok) { showToast('danger','Cannot Unpublish',r.msg); return; }
      exams = r.exams; renderExams();
      showToast('info','Moved to On-going', r.msg);
    }
  });
}

async function deleteExam(examId, name) {
  window.openModal({
    icon: 'fa-trash', iconClass: 'icon-danger',
    title: 'Delete Exam?', subtitle: name,
    body: `<p style="font-size:14px;color:var(--text-sub);line-height:1.6">
      This will permanently delete <strong>${esc(name)}</strong> and all its subject configurations.<br>
      <strong>This cannot be undone.</strong> (Exams with submitted marks cannot be deleted.)
    </p>`,
    confirmLabel: 'Yes, Delete', confirmClass: 'btn-danger',
    onConfirm: async () => {
      const r = await ajax({ action:'delete_exam', exam_id: examId });
      if (!r.ok) { showToast('danger','Cannot Delete',r.msg); return; }
      // animate card out
      const card = document.getElementById('ec-' + examId);
      if (card) {
        card.style.transition = 'opacity .25s, transform .25s';
        card.style.opacity = '0';
        card.style.transform = 'scale(.96)';
        setTimeout(() => {
          exams = exams.filter(e => parseInt(e.exam_id) !== examId);
          renderExams();
        }, 250);
      } else {
        exams = exams.filter(e => parseInt(e.exam_id) !== examId);
        renderExams();
      }
      showToast('success','Deleted', r.msg);
    }
  });
}

/* ── View detail drawer ── */
async function viewExam(examId) {
  openDrawer();
  document.getElementById('ddTitle').textContent = 'Loading…';
  document.getElementById('ddSub').innerHTML = '';
  document.getElementById('ddBody').innerHTML =
    '<div style="text-align:center;padding:32px;color:var(--text-muted)"><i class="fas fa-spinner spin" style="font-size:24px"></i></div>';
  document.getElementById('ddFooter').innerHTML = '';

  const r = await ajax({ action:'get_exam', exam_id: examId });
  if (!r.ok) {
    document.getElementById('ddBody').innerHTML = `<div class="empty-state"><i class="fas fa-triangle-exclamation"></i><p>${esc(r.msg)}</p></div>`;
    return;
  }

  const e = r.exam;
  const subs = r.subjects || [];
  const isDraft = e.status === 'draft';
  const isOngoing = e.status === 'ongoing';
  const published = e.status === 'published';
  const canEditSubjects = isDraft; // subjects are locked once on-going/published
  activeExamDetail = { exam: e, subjects: subs };

  document.getElementById('ddTitle').textContent = e.exam_name;
  document.getElementById('ddSub').innerHTML =
    `<span class="badge ${published ? 'badge-green' : (isOngoing ? 'badge-blue' : 'badge-amber')}">
       ${published ? '✓ Published' : (isOngoing ? '● On-going' : '◷ Draft')}
     </span>` +
    `<span>${esc(e.class_label)}</span>` +
    (e.group_name ? `<span>· ${esc(e.group_name)}</span>` : '') +
    `<span>· ${esc(e.session_label)}</span>`;

  // Build body
  let html = '';

  // Meta info
  html += '<div class="dd-section-title">Exam Info</div>';
  html += `<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:4px">
    <div class="dd-subj-row" style="padding:10px 12px">
      <div style="font-size:11px;color:var(--text-muted);font-weight:600;margin-bottom:3px">CLASS</div>
      <div style="font-size:14px;font-weight:700">${esc(e.class_label)}</div>
    </div>
    <div class="dd-subj-row" style="padding:10px 12px">
      <div style="font-size:11px;color:var(--text-muted);font-weight:600;margin-bottom:3px">SESSION</div>
      <div style="font-size:14px;font-weight:700">${esc(e.session_label)}</div>
    </div>
    <div class="dd-subj-row" style="padding:10px 12px">
      <div style="font-size:11px;color:var(--text-muted);font-weight:600;margin-bottom:3px">CREATED</div>
      <div style="font-size:13px;font-weight:600">${fmtDate(e.created_at)}</div>
    </div>
    ${isOngoing || published ? `
    <div class="dd-subj-row" style="padding:10px 12px;border:1.5px solid var(--primary)">
      <div style="font-size:11px;color:var(--primary);font-weight:600;margin-bottom:3px">ON-GOING SINCE</div>
      <div style="font-size:13px;font-weight:600;color:var(--primary)">${e.ongoing_at ? fmtDate(e.ongoing_at) : '—'}</div>
    </div>` : ''}
    ${published ? `
    <div class="dd-subj-row" style="padding:10px 12px;border:1.5px solid var(--green-border)">
      <div style="font-size:11px;color:var(--green);font-weight:600;margin-bottom:3px">PUBLISHED</div>
      <div style="font-size:13px;font-weight:600;color:var(--green)">${fmtDate(e.published_at)}</div>
    </div>` : ''}
  </div>`;

  // Subjects
  html += `<div class="dd-section-title" style="margin-top:16px">${subs.length} Subject${subs.length !== 1 ? 's' : ''}</div>`;

  if (subs.length === 0) {
    html += '<div style="text-align:center;padding:20px;color:var(--text-muted);font-size:13.5px">No subjects configured yet.</div>';
  } else {
    subs.forEach((s, i) => {
      const mcqFull    = parseInt(s.mcq_full_marks) || 0;
      const mcqConvert = parseInt(s.mcq_convert_to) || 0;
      const cqFull     = parseInt(s.cq_full_marks)  || 0;
      const cqConvert  = parseInt(s.cq_convert_to)  || 0;
      const totalFull  = (mcqConvert || mcqFull) + (cqConvert || cqFull);
      const passPct    = parseFloat(s.pass_percentage) || 33;

      html += `<div class="dd-subj-row">
        <div class="dd-subj-top">
          <div class="dd-subj-name">
            <span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:6px;background:var(--primary);color:#fff;font-size:11px;font-weight:700;flex-shrink:0">${i+1}</span>
            ${esc(s.subject_name)}
            ${s.subject_code ? `<span class="scode">${esc(s.subject_code)}</span>` : ''}
            ${s.group_name ? `<span class="badge badge-purple" style="font-size:10px">${esc(s.group_name)}</span>` : ''}
          </div>
          ${canEditSubjects ? `
            <div class="dd-subj-actions">
              <button class="dd-icon-btn" onclick="openEditExamSubject(${s.exam_subject_id})" title="Edit subject setup">
                <i class="fas fa-pen"></i>
              </button>
              <button class="dd-icon-btn danger" onclick='removeExamSubject(${s.exam_subject_id},${JSON.stringify(s.subject_name)})' title="Remove subject">
                <i class="fas fa-xmark"></i>
              </button>
            </div>` : ''}
        </div>
        <div class="dd-marks-row">
          ${parseInt(s.has_mcq) ? `
            <span class="dd-mark-chip dmc-mcq">
              <i class="fas fa-list-check"></i>
              MCQ: ${mcqFull}${mcqConvert && mcqConvert !== mcqFull ? ` → ${mcqConvert}` : ''} marks
            </span>` : ''}
          ${parseInt(s.has_cq) ? `
            <span class="dd-mark-chip dmc-cq">
              <i class="fas fa-pen-nib"></i>
              CQ: ${cqFull}${cqConvert && cqConvert !== cqFull ? ` → ${cqConvert}` : ''} marks
            </span>` : ''}
          ${totalFull > 0 ? `<span class="dd-mark-chip dmc-tot"><i class="fas fa-sigma"></i> Total: ${totalFull}</span>` : ''}
          <span class="dd-mark-chip dmc-pass"><i class="fas fa-check"></i> Pass: ${passPct}%</span>
        </div>
        ${s.exam_date ? `
        <div class="dd-marks-row">
          <span class="dd-mark-chip dmc-date"><i class="fas fa-calendar-day"></i> ${fmtDate(s.exam_date)}${s.start_time ? ' · ' + fmtTime(s.start_time) : ''}${s.duration_min ? ' · ' + s.duration_min + ' min' : ''}</span>
        </div>` : ''}
      </div>`;
    });
  }

  document.getElementById('ddBody').innerHTML = html;

  // Footer actions
  let footerHtml = '';
  if (isDraft) {
    footerHtml += `<button class="btn btn-outline" onclick='openAddExamSubject(${e.exam_id})'>
                     <i class="fas fa-plus"></i> Add Subject
                   </button>
                   <button class="btn btn-primary" onclick='closeDrawer();startOngoing(${e.exam_id},${JSON.stringify(e.exam_name)})'>
                     <i class="fas fa-play"></i> Start On-going
                   </button>`;
  } else if (isOngoing) {
    footerHtml += `<button class="btn btn-outline" onclick='closeDrawer();revertToDraft(${e.exam_id},${JSON.stringify(e.exam_name)})'>
                     <i class="fas fa-rotate-left"></i> Back to Draft
                   </button>
                   <button class="btn btn-success" onclick='closeDrawer();publishExam(${e.exam_id},${JSON.stringify(e.exam_name)})'>
                     <i class="fas fa-paper-plane"></i> Publish Result
                   </button>`;
  } else {
    footerHtml += `<button class="btn btn-outline" onclick='closeDrawer();unpublishExam(${e.exam_id},${JSON.stringify(e.exam_name)})'>
                     <i class="fas fa-rotate-left"></i> Unpublish
                   </button>`;
  }

  footerHtml += `<a class="btn btn-primary" href="${BASE}/admin/marks.php?exam_id=${e.exam_id}">
      <i class="fas fa-pencil"></i> Enter Marks
     </a>`;

  if (isDraft) {
    footerHtml += `<button class="btn btn-outline" style="color:var(--red);border-color:var(--red-border)" onclick='closeDrawer();deleteExam(${e.exam_id},${JSON.stringify(e.exam_name)})'>
                     <i class="fas fa-trash"></i>
                   </button>`;
  }

  document.getElementById('ddFooter').innerHTML = footerHtml;
}

function examSubjectById(examSubjectId) {
  return (activeExamDetail?.subjects || []).find(s => parseInt(s.exam_subject_id) === parseInt(examSubjectId));
}

function subjectEditorHTML(prefix, cfg, opts = {}) {
  cfg = cfg || {};
  const hasCq = parseInt(cfg.has_cq ?? 1) === 1;
  const hasMcq = parseInt(cfg.has_mcq ?? 0) === 1;
  const available = opts.available || [];
  const includeSelect = !!opts.includeSelect;

  return `
  <div class="exam-subject-editor">
    ${opts.note ? `<div class="ese-note">${opts.note}</div>` : ''}
    ${includeSelect ? `
      <div class="ese-subject-select">
        <div class="ese-label">Subject</div>
        <select id="${prefix}Subject" class="ese-input" onchange="onSubjectEditorPick('${prefix}', this.value)">
          <option value="">Select subject</option>
          ${available.map(s => `<option value="${s.subject_id}">${esc(s.subject_name)}${s.subject_code ? ' - ' + esc(s.subject_code) : ''}${s.group_name ? ' (' + esc(s.group_name) + ')' : ''}</option>`).join('')}
        </select>
      </div>` : ''}

    <div class="ese-parts">
      <label class="ese-part">
        <input type="checkbox" id="${prefix}HasCq" ${hasCq ? 'checked' : ''} onchange="syncSubjectEditorParts('${prefix}')">
        <i class="fas fa-pen-nib"></i> CQ
      </label>
      <label class="ese-part">
        <input type="checkbox" id="${prefix}HasMcq" ${hasMcq ? 'checked' : ''} onchange="syncSubjectEditorParts('${prefix}')">
        <i class="fas fa-list-check"></i> MCQ
      </label>
    </div>

    <div class="ese-row-box" id="${prefix}CqBox">
      <div class="ese-row-title"><i class="fas fa-pen-nib" style="color:var(--green)"></i> CQ Marks</div>
      <div class="ese-grid">
        <div>
          <div class="ese-label">Full Marks</div>
          <input type="number" class="ese-input" id="${prefix}CqFull" min="1" max="200" value="${cfg.cq_full_marks || ''}" placeholder="e.g. 70">
        </div>
        <div>
          <div class="ese-label">Convert To</div>
          <input type="number" class="ese-input" id="${prefix}CqConvert" min="1" max="200" value="${cfg.cq_convert_to || ''}" placeholder="Optional">
        </div>
      </div>
    </div>

    <div class="ese-row-box" id="${prefix}McqBox" style="margin-top:10px">
      <div class="ese-row-title"><i class="fas fa-list-check" style="color:var(--primary)"></i> MCQ Marks</div>
      <div class="ese-grid">
        <div>
          <div class="ese-label">Full Marks</div>
          <input type="number" class="ese-input" id="${prefix}McqFull" min="1" max="200" value="${cfg.mcq_full_marks || ''}" placeholder="e.g. 30">
        </div>
        <div>
          <div class="ese-label">Convert To</div>
          <input type="number" class="ese-input" id="${prefix}McqConvert" min="1" max="200" value="${cfg.mcq_convert_to || ''}" placeholder="Optional">
        </div>
      </div>
    </div>

    <div class="ese-row-box" style="margin-top:10px">
      <div class="ese-row-title"><i class="fas fa-calendar-day"></i> Schedule</div>
      <div class="ese-grid">
        <div>
          <div class="ese-label">Exam Date</div>
          <input type="date" class="ese-input" id="${prefix}ExamDate" value="${cfg.exam_date || ''}">
        </div>
        <div>
          <div class="ese-label">Start Time</div>
          <input type="time" class="ese-input" id="${prefix}StartTime" value="${(cfg.start_time || '').slice(0,5)}">
        </div>
        <div>
          <div class="ese-label">Duration</div>
          <div style="display:flex;gap:6px;align-items:center">
            <input type="number" class="ese-input" id="${prefix}DurationH" min="0" max="8" value="${cfg.duration_min ? Math.floor(cfg.duration_min/60) : ''}" placeholder="Hr" style="text-align:center">
            <input type="number" class="ese-input" id="${prefix}DurationM" min="0" max="59" value="${cfg.duration_min ? (cfg.duration_min % 60) : ''}" placeholder="Min" style="text-align:center">
          </div>
        </div>
        <div>
          <div class="ese-label">Pass %</div>
          <input type="number" class="ese-input" id="${prefix}PassPct" min="1" max="100" step="0.5" value="${cfg.pass_percentage || 33}">
        </div>
      </div>
    </div>
  </div>`;
}

/* Auto-fill CQ/MCQ toggle + marks + duration from the subject's own configured
   defaults (from the Subjects page) when a subject is picked in the "Add Subject" editor. */
function onSubjectEditorPick(prefix, subjectId) {
  const list = subjectEditorAvailable[prefix] || [];
  const s = list.find(x => parseInt(x.subject_id) === parseInt(subjectId));
  if (!s) return;

  const subWritten  = parseInt(s.written_marks)  || 0;
  const subMcq      = parseInt(s.mcq_marks)      || 0;
  const subDuration = parseInt(s.exam_duration)  || 0;

  let hasCq  = subWritten > 0;
  let hasMcq = subMcq > 0;
  if (!hasCq && !hasMcq) hasCq = true; // no marks data on the subject — fall back to CQ only

  const setVal = (id, v) => { const el = document.getElementById(prefix + id); if (el) el.value = v; };
  const setChk = (id, v) => { const el = document.getElementById(prefix + id); if (el) el.checked = v; };

  setChk('HasCq',  hasCq);
  setChk('HasMcq', hasMcq);
  setVal('CqFull',  subWritten > 0 ? subWritten : '');
  setVal('McqFull', subMcq     > 0 ? subMcq     : '');
  setVal('DurationH', subDuration > 0 ? Math.floor(subDuration / 60) : '');
  setVal('DurationM', subDuration > 0 ? (subDuration % 60) : '');

  syncSubjectEditorParts(prefix);
}

function syncSubjectEditorParts(prefix) {
  const hasCq = document.getElementById(prefix + 'HasCq')?.checked;
  const hasMcq = document.getElementById(prefix + 'HasMcq')?.checked;
  document.getElementById(prefix + 'CqBox')?.classList.toggle('ese-hidden', !hasCq);
  document.getElementById(prefix + 'McqBox')?.classList.toggle('ese-hidden', !hasMcq);
}

function readSubjectEditor(prefix, subjectId) {
  const hasCq = !!document.getElementById(prefix + 'HasCq')?.checked;
  const hasMcq = !!document.getElementById(prefix + 'HasMcq')?.checked;
  if (!hasCq && !hasMcq) {
    showToast('warning', 'Incomplete', 'Select CQ, MCQ, or both.');
    return null;
  }
  const val = id => document.getElementById(prefix + id)?.value || '';
  const durH = val('DurationH');
  const durM = val('DurationM');
  let durationMin = '';
  if (durH !== '' || durM !== '') {
    durationMin = (Number(durH || 0) * 60) + Number(durM || 0);
    if (durationMin <= 0) durationMin = '';
  }
  const cfg = {
    subject_id: subjectId,
    has_cq: hasCq ? 1 : 0,
    cq_full_marks: hasCq ? val('CqFull') : '',
    cq_convert_to: hasCq ? val('CqConvert') : '',
    has_mcq: hasMcq ? 1 : 0,
    mcq_full_marks: hasMcq ? val('McqFull') : '',
    mcq_convert_to: hasMcq ? val('McqConvert') : '',
    exam_date: val('ExamDate'),
    start_time: val('StartTime'),
    duration_min: durationMin,
    pass_percentage: val('PassPct') || 33,
  };
  if (hasCq && parseInt(cfg.cq_full_marks || '0') < 1) {
    showToast('warning', 'Incomplete', 'CQ full marks are required.');
    return null;
  }
  if (hasMcq && parseInt(cfg.mcq_full_marks || '0') < 1) {
    showToast('warning', 'Incomplete', 'MCQ full marks are required.');
    return null;
  }
  return cfg;
}

function openEditExamSubject(examSubjectId) {
  const sub = examSubjectById(examSubjectId);
  const exam = activeExamDetail?.exam;
  if (!sub || !exam) return;

  window.openModal({
    icon: 'fa-sliders',
    iconClass: 'icon-info',
    title: 'Edit Subject Setup',
    subtitle: sub.subject_name,
    body: subjectEditorHTML('editSubj', sub, {
      note: 'Update marks, MCQ/CQ parts, pass percentage, date, time, and duration for this subject.',
    }),
    confirmLabel: 'Save Changes',
    confirmClass: 'btn-primary',
    onConfirm: async () => {
      const cfg = readSubjectEditor('editSubj', sub.subject_id);
      if (!cfg) return;
      const r = await ajax({
        action: 'update_exam_subject',
        exam_id: exam.exam_id,
        exam_subject_id: examSubjectId,
        config: JSON.stringify(cfg),
      });
      if (!r.ok) { showToast('danger', 'Cannot Update', r.msg); return; }
      showToast('success', 'Updated', r.msg);
      await viewExam(exam.exam_id);
      await loadExams();
    }
  });
  setTimeout(() => syncSubjectEditorParts('editSubj'), 30);
}

async function openAddExamSubject(examId) {
  const r = await ajax({ action: 'fetch_exam_available_subjects', exam_id: examId });
  if (!r.ok) { showToast('danger', 'Error', r.msg); return; }
  const subjects = r.subjects || [];
  if (subjects.length === 0) {
    showToast('info', 'No Subjects Left', 'All matching active subjects are already selected for this exam.');
    return;
  }
  subjectEditorAvailable['addSubj'] = subjects;

  window.openModal({
    icon: 'fa-plus',
    iconClass: 'icon-success',
    title: 'Add Subject',
    subtitle: 'Only unselected subjects for this class/group are shown.',
    body: subjectEditorHTML('addSubj', { has_cq: 1, pass_percentage: 33 }, {
      includeSelect: true,
      available: subjects,
      note: 'Choose an unselected subject, then configure marks and schedule.',
    }),
    confirmLabel: 'Add Subject',
    confirmClass: 'btn-success',
    onConfirm: async () => {
      const sid = parseInt(document.getElementById('addSubjSubject')?.value || '0');
      if (!sid) { showToast('warning', 'Required', 'Please select a subject.'); return; }
      const cfg = readSubjectEditor('addSubj', sid);
      if (!cfg) return;
      const res = await ajax({
        action: 'add_exam_subject',
        exam_id: examId,
        config: JSON.stringify(cfg),
      });
      if (!res.ok) { showToast('danger', 'Cannot Add', res.msg); return; }
      showToast('success', 'Added', res.msg);
      await viewExam(examId);
      await loadExams();
    }
  });
  setTimeout(() => syncSubjectEditorParts('addSubj'), 30);
}

function removeExamSubject(examSubjectId, subjectName) {
  const exam = activeExamDetail?.exam;
  if (!exam) return;
  window.openModal({
    icon: 'fa-xmark',
    iconClass: 'icon-danger',
    title: 'Remove Subject?',
    subtitle: subjectName,
    body: `<p style="font-size:14px;color:var(--text-sub);line-height:1.6">
      This removes <strong>${esc(subjectName)}</strong> from this exam. It cannot be removed if marks were already submitted.
    </p>`,
    confirmLabel: 'Remove',
    confirmClass: 'btn-danger',
    onConfirm: async () => {
      const r = await ajax({ action: 'remove_exam_subject', exam_id: exam.exam_id, exam_subject_id: examSubjectId });
      if (!r.ok) { showToast('danger', 'Cannot Remove', r.msg); return; }
      showToast('success', 'Removed', r.msg);
      await viewExam(exam.exam_id);
      await loadExams();
    }
  });
}

function openDrawer() {
  document.getElementById('detailDrawer').classList.add('open');
  document.getElementById('ddOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeDrawer() {
  document.getElementById('detailDrawer').classList.remove('open');
  document.getElementById('ddOverlay').classList.remove('open');
  document.body.style.overflow = '';
}

/* ================================================================
   WIZARD
   ================================================================ */
function toggleWizard() {
  const panel = document.getElementById('wizardPanel');
  const isOpen = panel.classList.contains('open');
  if (isOpen) {
    panel.classList.remove('open');
    document.getElementById('btnNewExam').innerHTML = '<i class="fas fa-plus"></i> Create Exam';
    resetWizard();
  } else {
    panel.classList.add('open');
    document.getElementById('btnNewExam').innerHTML = '<i class="fas fa-xmark"></i> Cancel';
    panel.scrollIntoView({ behavior:'smooth', block:'start' });
    // Pre-fill session from current filter
    const fSes = document.getElementById('fSession').value;
    if (fSes && fSes !== '0') document.getElementById('wSession').value = fSes;
    updateNamePreview();
  }
}

function resetWizard() {
  wizardStep = 1;
  pickedSubjects = [];
  selSubjIds = [];
  subjConfigs = {};
  document.getElementById('wExamName').value = '';
  document.getElementById('wClass').value = '';
  document.getElementById('wGroup').value = '';
  document.getElementById('wGroupWrap').style.display = 'none';
  document.getElementById('namePreview').style.display = 'none';
  document.getElementById('subjectPickerArea').innerHTML =
    '<div class="subject-loading"><i class="fas fa-book-open text-muted"></i><p>Select a class in Step 1 first</p></div>';
  document.getElementById('subjConfigList').innerHTML = '';
  updateWizardUI();
}

function updateNamePreview() {
  const name = document.getElementById('wExamName').value.trim();
  const selEl = document.getElementById('wSession');
  const year = selEl.options[selEl.selectedIndex]?.dataset?.year || '';
  const preview = document.getElementById('namePreview');
  const previewText = document.getElementById('namePreviewText');
  if (name && year) {
    const finalName = name.includes(year) ? name : name + ' ' + year;
    previewText.textContent = '"' + finalName + '"';
    preview.style.display = 'flex';
  } else {
    preview.style.display = 'none';
  }
}

function onWizClassChange() {
  const sel  = document.getElementById('wClass');
  const num  = parseInt(sel.options[sel.selectedIndex]?.dataset?.num || '0');
  const grpW = document.getElementById('wGroupWrap');
  grpW.style.display = num >= 9 ? '' : 'none';
  if (num < 9) document.getElementById('wGroup').value = '';
  clearSubjectPicker();
}

function clearSubjectPicker() {
  pickedSubjects = [];
  selSubjIds = [];
  document.getElementById('subjectPickerArea').innerHTML =
    '<div class="subject-loading"><i class="fas fa-spinner spin" style="font-size:22px"></i><p>Loading subjects…</p></div>';
}

async function loadSubjectPicker() {
  const classId = document.getElementById('wClass').value;
  const groupId = document.getElementById('wGroup').value;
  if (!classId) {
    document.getElementById('subjectPickerArea').innerHTML =
      '<div class="subject-loading"><i class="fas fa-book-open text-muted"></i><p>Please select a class first.</p></div>';
    return;
  }
  const r = await ajax({ action:'fetch_subjects', class_id:classId, group_id:groupId });
  if (!r.ok) {
    document.getElementById('subjectPickerArea').innerHTML =
      `<div class="subject-loading"><i class="fas fa-triangle-exclamation" style="color:var(--red)"></i><p>${esc(r.msg)}</p></div>`;
    return;
  }
  pickedSubjects = r.subjects || [];

  if (pickedSubjects.length === 0) {
    document.getElementById('subjectPickerArea').innerHTML =
      '<div class="subject-loading"><i class="fas fa-book-open text-muted"></i><p>No active subjects found for this class.</p>' +
      `<a href="${BASE}/admin/subjects.php" class="btn btn-sm btn-outline" style="margin-top:10px">Go to Subjects →</a></div>`;
    return;
  }

  renderSubjectPicker();
}

function renderSubjectPicker() {
  const classNum = parseInt(document.getElementById('wClass').options[document.getElementById('wClass').selectedIndex]?.dataset?.num || '0');
  const hasGroups = classNum >= 9;

  let html = '';
  const groups = {};

  pickedSubjects.forEach(s => {
    const gk = s.group_id ? s.group_name : '_common';
    if (!groups[gk]) groups[gk] = [];
    groups[gk].push(s);
  });

  if (hasGroups) {
    if (groups['_common']) {
      html += '<div class="group-subhead"><i class="fas fa-users"></i> Common — All Groups</div>';
      html += '<div class="subject-picker-grid">';
      groups['_common'].forEach(s => { html += subjectPickItemHTML(s); });
      html += '</div>';
    }
    Object.entries(groups).forEach(([gk, subs]) => {
      if (gk === '_common') return;
      html += `<div class="group-subhead"><i class="fas fa-tag"></i> ${esc(gk)}</div>`;
      html += '<div class="subject-picker-grid">';
      subs.forEach(s => { html += subjectPickItemHTML(s); });
      html += '</div>';
    });
  } else {
    html += '<div class="subject-picker-grid">';
    pickedSubjects.forEach(s => { html += subjectPickItemHTML(s); });
    html += '</div>';
  }

  document.getElementById('subjectPickerArea').innerHTML = html;
  updatePickCount();
}

function subjectPickItemHTML(s) {
  const checked = selSubjIds.includes(parseInt(s.subject_id));
  return `<label class="subject-pick-item${checked ? ' selected' : ''}" onclick="togglePickSubject(${s.subject_id}, this)">
    <input type="checkbox" ${checked ? 'checked' : ''} onclick="event.stopPropagation()">
    <span class="subject-pick-label">${esc(s.subject_name)}</span>
    ${s.subject_code ? `<span class="subject-pick-code">${esc(s.subject_code)}</span>` : ''}
  </label>`;
}

function togglePickSubject(sid, el) {
  sid = parseInt(sid);
  const idx = selSubjIds.indexOf(sid);
  if (idx > -1) {
    selSubjIds.splice(idx, 1);
    el.classList.remove('selected');
    el.querySelector('input').checked = false;
  } else {
    selSubjIds.push(sid);
    el.classList.add('selected');
    el.querySelector('input').checked = true;
  }
  updatePickCount();
}

function selectAllSubjects(all) {
  document.querySelectorAll('.subject-pick-item').forEach(el => {
    const sid = parseInt(el.querySelector('input')?.value || '0');
    el.classList.toggle('selected', all);
    const inp = el.querySelector('input');
    if (inp) inp.checked = all;
  });
  selSubjIds = all ? pickedSubjects.map(s => parseInt(s.subject_id)) : [];
  updatePickCount();
}

function updatePickCount() {
  document.getElementById('pickCountBadge').textContent = selSubjIds.length;
}

/* ── Step 3: Config cards ── */
function buildConfigCards() {
  const selected = pickedSubjects.filter(s => selSubjIds.includes(parseInt(s.subject_id)));
  let html = '';
  selected.forEach((s, i) => {
    const sid    = s.subject_id;
    const isNew  = !subjConfigs[sid]; // no cached config yet this session — derive defaults from the subject's own setup
    const cfg    = subjConfigs[sid] || {};

    const subWritten  = parseInt(s.written_marks)  || 0;
    const subMcq      = parseInt(s.mcq_marks)      || 0;
    const subDuration = parseInt(s.exam_duration)  || 0;

    let hasCq, hasMcq;
    if (isNew) {
      hasCq  = subWritten > 0;
      hasMcq = subMcq > 0;
      if (!hasCq && !hasMcq) hasCq = true; // subject has no marks configured yet — fall back to CQ only
    } else {
      hasCq  = cfg.has_cq  !== false;
      hasMcq = cfg.has_mcq !== undefined ? cfg.has_mcq : false;
    }

    const cqFullVal  = isNew ? (subWritten > 0 ? subWritten : '') : (cfg.cq_full_marks  || '');
    const mcqFullVal = isNew ? (subMcq     > 0 ? subMcq     : '') : (cfg.mcq_full_marks || '');
    const durTotal   = isNew ? subDuration : (parseInt(cfg.duration_min) || 0);
    const durH       = durTotal > 0 ? Math.floor(durTotal / 60) : '';
    const durM       = durTotal > 0 ? (durTotal % 60) : '';

    html += `
    <div class="subj-config-card" id="scc-${sid}">
      <div class="scc-header" onclick="toggleScc(${sid})">
        <div class="scc-num">${i+1}</div>
        <div class="scc-name">${esc(s.subject_name)}</div>
        ${s.subject_code ? `<div class="scc-code">${esc(s.subject_code)}</div>` : ''}
        <div class="scc-summary" id="scc-summary-${sid}">CQ only</div>
        <i class="fas fa-chevron-down scc-chevron"></i>
      </div>
      <div class="scc-body${i === 0 ? ' open' : ''}" id="scc-body-${sid}">

        <!-- CQ / MCQ toggles (modern switch style) -->
        <div class="part-toggle-row">
          <label class="part-toggle part-toggle-cq">
            <input type="checkbox" id="hasCq-${sid}" ${hasCq ? 'checked' : ''}
                   onchange="onPartToggle(${sid})">
            <i class="fas fa-pen-nib pt-icon"></i>
            <span class="part-toggle-label">CQ (Written)</span>
          </label>
          <label class="part-toggle">
            <input type="checkbox" id="hasMcq-${sid}" ${hasMcq ? 'checked' : ''}
                   onchange="onPartToggle(${sid})">
            <i class="fas fa-list-check pt-icon"></i>
            <span class="part-toggle-label">MCQ (Multiple Choice)</span>
          </label>
        </div>

        <!-- CQ config row -->
        <div class="cfg-row" id="cqRow-${sid}" ${!hasCq ? 'style="display:none"' : ''}>
          <div>
            <div class="cfg-label"><i class="fas fa-pen-nib" style="color:var(--green)"></i> CQ Full Marks <span style="color:var(--red)">*</span></div>
            <input type="number" class="cfg-input" id="cqFull-${sid}" min="1" max="200"
                   placeholder="e.g. 70" value="${cqFullVal}"
                   oninput="updateSccSummary(${sid})">
          </div>
          <div>
            <div class="cfg-label"><i class="fas fa-right-long"></i> Convert To <span style="font-size:10.5px;font-weight:400">(optional)</span></div>
            <input type="number" class="cfg-input" id="cqConvert-${sid}" min="1" max="200"
                   placeholder="Leave blank = no conversion" value="${cfg.cq_convert_to || ''}"
                   oninput="updateSccSummary(${sid})">
          </div>
          <div style="grid-column:span 2">
            <div class="cfg-label" style="color:var(--text-light);font-size:11px">If exam is in 70 marks but student result should be out of 50, enter 70 in Full Marks and 50 in Convert To</div>
          </div>
        </div>

        <!-- MCQ config row -->
        <div class="cfg-row" id="mcqRow-${sid}" ${!hasMcq ? 'style="display:none"' : ''}>
          <div>
            <div class="cfg-label"><i class="fas fa-list-check" style="color:var(--primary)"></i> MCQ Full Marks <span style="color:var(--red)">*</span></div>
            <input type="number" class="cfg-input" id="mcqFull-${sid}" min="1" max="200"
                   placeholder="e.g. 30" value="${mcqFullVal}"
                   oninput="updateSccSummary(${sid})">
          </div>
          <div>
            <div class="cfg-label"><i class="fas fa-right-long"></i> Convert To <span style="font-size:10.5px;font-weight:400">(optional)</span></div>
            <input type="number" class="cfg-input" id="mcqConvert-${sid}" min="1" max="200"
                   placeholder="Leave blank = no conversion" value="${cfg.mcq_convert_to || ''}"
                   oninput="updateSccSummary(${sid})">
          </div>
          <div style="grid-column:span 2">
            <div class="cfg-label" style="color:var(--text-light);font-size:11px">Students must pass MCQ separately (33% minimum) and combined with CQ</div>
          </div>
        </div>

        <!-- Exam date / time / duration -->
        <div class="cfg-datetime">
          <div>
            <div class="cfg-label"><i class="fas fa-calendar-day"></i> Exam Date <span style="font-size:10.5px;font-weight:400">(optional)</span></div>
            <input type="date" class="cfg-input" id="examDate-${sid}" value="${cfg.exam_date || ''}" style="padding:0 8px">
          </div>
          <div>
            <div class="cfg-label"><i class="fas fa-clock"></i> Start Time <span style="font-size:10.5px;font-weight:400">(optional)</span></div>
            <input type="time" class="cfg-input" id="startTime-${sid}" value="${cfg.start_time || ''}" style="padding:0 8px">
          </div>
          <div>
            <div class="cfg-label"><i class="fas fa-hourglass-half"></i> Duration <span style="font-size:10.5px;font-weight:400">(optional)</span></div>
            <div style="display:flex;gap:6px;align-items:center">
              <input type="number" class="cfg-input" id="durationH-${sid}" min="0" max="8"
                     placeholder="Hr" value="${durH}" style="padding:0 6px;text-align:center">
              <span style="font-size:11px;color:var(--text-muted);flex-shrink:0">hr</span>
              <input type="number" class="cfg-input" id="durationM-${sid}" min="0" max="59"
                     placeholder="Min" value="${durM}" style="padding:0 6px;text-align:center">
              <span style="font-size:11px;color:var(--text-muted);flex-shrink:0">min</span>
            </div>
          </div>
        </div>

        <!-- Pass percentage -->
        <div class="cfg-pass-row">
          <label>Pass Percentage:</label>
          <input type="number" class="cfg-pass-input" id="passPct-${sid}"
                 min="1" max="100" step="0.5"
                 value="${cfg.pass_percentage || 33}"
                 oninput="updateSccSummary(${sid})">
          <span>%</span>
          <span class="cfg-pass-note">
            <i class="fas fa-circle-info"></i>
            Students must score at least this percentage in CQ, MCQ (if any), and combined to pass this subject.
            Default: 33%
          </span>
        </div>

      </div><!-- /scc-body -->
    </div>`;
  });

  document.getElementById('subjConfigList').innerHTML = html;
  // update summaries
  selected.forEach(s => updateSccSummary(s.subject_id));
}

function toggleScc(sid) {
  const body = document.getElementById('scc-body-' + sid);
  const hdr  = body?.previousElementSibling;
  const isOpen = body?.classList.contains('open');
  body?.classList.toggle('open', !isOpen);
  hdr?.classList.toggle('open', !isOpen);
}

function onPartToggle(sid) {
  const hasCq  = document.getElementById('hasCq-'  + sid)?.checked;
  const hasMcq = document.getElementById('hasMcq-' + sid)?.checked;
  const cqRow  = document.getElementById('cqRow-'  + sid);
  const mcqRow = document.getElementById('mcqRow-' + sid);
  if (cqRow)  cqRow.style.display  = hasCq  ? '' : 'none';
  if (mcqRow) mcqRow.style.display = hasMcq ? '' : 'none';
  updateSccSummary(sid);
}

function updateSccSummary(sid) {
  const hasCq   = document.getElementById('hasCq-'    + sid)?.checked;
  const hasMcq  = document.getElementById('hasMcq-'   + sid)?.checked;
  const cqFull  = parseInt(document.getElementById('cqFull-'   + sid)?.value)  || 0;
  const cqConv  = parseInt(document.getElementById('cqConvert-'+ sid)?.value)  || 0;
  const mcqFull = parseInt(document.getElementById('mcqFull-'  + sid)?.value)  || 0;
  const mcqConv = parseInt(document.getElementById('mcqConvert-'+sid)?.value)  || 0;
  const passPct = parseFloat(document.getElementById('passPct-' + sid)?.value) || 33;

  const cqEffective  = (hasCq  ? (cqConv  || cqFull)  : 0);
  const mcqEffective = (hasMcq ? (mcqConv || mcqFull) : 0);
  const total = cqEffective + mcqEffective;

  let parts = [];
  if (hasCq)  parts.push('CQ ' + (cqFull  ? (cqConv  && cqConv  !== cqFull  ? `${cqFull}→${cqConv}`  : cqFull)  : '—') + ' marks');
  if (hasMcq) parts.push('MCQ ' + (mcqFull ? (mcqConv && mcqConv !== mcqFull ? `${mcqFull}→${mcqConv}` : mcqFull) : '—') + ' marks');
  const sumEl = document.getElementById('scc-summary-' + sid);
  if (sumEl) sumEl.textContent = parts.length ? parts.join(' + ') + (total ? ` = ${total}` : '') : 'Configure';
}

/* ── Read config from DOM ── */
function readSubjConfigs() {
  const selected = pickedSubjects.filter(s => selSubjIds.includes(parseInt(s.subject_id)));
  const configs = [];
  for (const s of selected) {
    const sid    = s.subject_id;
    const hasCq  = !!document.getElementById('hasCq-'  + sid)?.checked;
    const hasMcq = !!document.getElementById('hasMcq-' + sid)?.checked;
    if (!hasCq && !hasMcq) return { error: `Subject "${s.subject_name}" must have at least CQ or MCQ.` };

    const cqFull  = parseInt(document.getElementById('cqFull-'    + sid)?.value)  || 0;
    const cqConv  = parseInt(document.getElementById('cqConvert-' + sid)?.value)  || 0;
    const mcqFull = parseInt(document.getElementById('mcqFull-'   + sid)?.value)  || 0;
    const mcqConv = parseInt(document.getElementById('mcqConvert-'+ sid)?.value)  || 0;

    if (hasCq  && cqFull  < 1) return { error: `CQ marks required for "${s.subject_name}".` };
    if (hasMcq && mcqFull < 1) return { error: `MCQ marks required for "${s.subject_name}".` };

    const durH = (document.getElementById('durationH-' + sid)?.value || '').trim();
    const durM = (document.getElementById('durationM-' + sid)?.value || '').trim();
    if (durH !== '' && (isNaN(durH) || Number(durH) < 0)) return { error: `Duration hours is invalid for "${s.subject_name}".` };
    if (durM !== '' && (isNaN(durM) || Number(durM) < 0 || Number(durM) > 59)) return { error: `Duration minutes must be between 0 and 59 for "${s.subject_name}".` };
    let durationMin = null;
    if (durH !== '' || durM !== '') {
      durationMin = (Number(durH || 0) * 60) + Number(durM || 0);
      if (durationMin <= 0) durationMin = null;
    }

    configs.push({
      subject_id:      sid,
      has_cq:          hasCq  ? 1 : 0,
      cq_full_marks:   hasCq  ? cqFull  : null,
      cq_convert_to:   (hasCq  && cqConv  > 0 && cqConv  !== cqFull)  ? cqConv  : null,
      has_mcq:         hasMcq ? 1 : 0,
      mcq_full_marks:  hasMcq ? mcqFull : null,
      mcq_convert_to:  (hasMcq && mcqConv > 0 && mcqConv !== mcqFull) ? mcqConv : null,
      exam_date:       document.getElementById('examDate-'  + sid)?.value || null,
      start_time:      document.getElementById('startTime-' + sid)?.value || null,
      duration_min:    durationMin,
      pass_percentage: parseFloat(document.getElementById('passPct-'+ sid)?.value) || 33,
    });
  }
  return { configs };
}

/* ── Step 4: build summary ── */
function buildSummary() {
  const name    = document.getElementById('wExamName').value.trim();
  const sesEl   = document.getElementById('wSession');
  const clsEl   = document.getElementById('wClass');
  const year    = sesEl.options[sesEl.selectedIndex]?.dataset?.year || '';
  const clsLabel= clsEl.options[clsEl.selectedIndex]?.text || '';
  const finalName = name.includes(year) ? name : name + ' ' + year;
  const grpEl   = document.getElementById('wGroup');
  const grpLabel = grpEl?.value ? grpEl.options[grpEl.selectedIndex]?.text : 'All Groups';
  const classNum = parseInt(clsEl.options[clsEl.selectedIndex]?.dataset?.num || '0');

  const { configs, error } = readSubjConfigs();
  if (error) return null;

  const selectedSubs = pickedSubjects.filter(s => selSubjIds.includes(parseInt(s.subject_id)));
  let totalMarks = 0;
  configs.forEach(c => {
    totalMarks += (c.cq_convert_to || c.cq_full_marks || 0) + (c.mcq_convert_to || c.mcq_full_marks || 0);
  });

  let html = `
  <div class="esb-title">Exam Summary</div>
  <div class="esb-row"><div class="esb-row-label">Exam Name</div><div class="esb-row-value"><strong style="font-size:15px;color:var(--primary)">${esc(finalName)}</strong></div></div>
  <div class="esb-row"><div class="esb-row-label">Class</div><div class="esb-row-value">${esc(clsLabel)}${classNum >= 9 ? ' &nbsp;· ' + esc(grpLabel) : ''}</div></div>
  <div class="esb-row"><div class="esb-row-label">Session</div><div class="esb-row-value">${esc(sesEl.options[sesEl.selectedIndex]?.text || '')}</div></div>
  <div class="esb-row"><div class="esb-row-label">Subjects</div><div class="esb-row-value">
    ${selectedSubs.map(s => `<span class="badge badge-blue" style="margin:2px">${esc(s.subject_name)}</span>`).join('')}
  </div></div>
  <div class="esb-row"><div class="esb-row-label">Total Marks</div><div class="esb-row-value"><strong>${totalMarks > 0 ? totalMarks : '—'}</strong></div></div>
  <div class="esb-row"><div class="esb-row-label">Status</div><div class="esb-row-value"><span class="badge badge-amber"><i class="fas fa-clock"></i> Draft</span> (not visible to students yet)</div></div>
  `;

  document.getElementById('examSummaryBox').innerHTML = html;
  return configs;
}

/* ── Wizard navigation ── */
async function wizStep(dir) {
  const next = wizardStep + dir;

  // ── Validate before moving forward ──
  if (dir > 0) {
    if (wizardStep === 1) {
      const name = document.getElementById('wExamName').value.trim();
      const ses  = document.getElementById('wSession').value;
      const cls  = document.getElementById('wClass').value;
      if (!name) { showToast('warning','Required','Please enter an exam name.'); return; }
      if (!ses)  { showToast('warning','Required','Please select a session.'); return; }
      if (!cls)  { showToast('warning','Required','Please select a class.'); return; }
      // Load subjects for step 2 if class just selected
      await loadSubjectPicker();
    }

    if (wizardStep === 2) {
      if (selSubjIds.length === 0) { showToast('warning','No Subjects','Please select at least one subject.'); return; }
      buildConfigCards();
    }

    if (wizardStep === 3) {
      const { configs, error } = readSubjConfigs();
      if (error) { showToast('warning','Incomplete', error); return; }
      // Save to state so summary can use them
      const result = buildSummary();
      if (!result) return;
    }

    if (wizardStep === 4) {
      // Final submit
      await submitExam();
      return;
    }
  }

  wizardStep = next;
  updateWizardUI();
}

function updateWizardUI() {
  // Update step indicators
  [1,2,3,4].forEach(i => {
    const el = document.getElementById('ws' + i);
    if (!el) return;
    el.classList.remove('active','done');
    if (i < wizardStep)     el.classList.add('done');
    else if (i === wizardStep) el.classList.add('active');
  });

  // Show/hide step bodies
  [1,2,3,4].forEach(i => {
    document.getElementById('wsBody' + i)?.classList.toggle('active', i === wizardStep);
  });

  // Back button
  const back = document.getElementById('btnWizBack');
  back.style.display = wizardStep > 1 ? '' : 'none';

  // Next button label
  const next = document.getElementById('btnWizNext');
  if (wizardStep === 4) {
    next.innerHTML = '<i class="fas fa-circle-check"></i> Create Exam (Draft)';
    next.className = 'btn btn-success';
  } else {
    next.innerHTML = 'Next <i class="fas fa-arrow-right"></i>';
    next.className = 'btn btn-primary';
  }
}

/* ── Final submit ── */
async function submitExam() {
  const { configs, error } = readSubjConfigs();
  if (error) { showToast('warning','Incomplete',error); wizardStep = 3; updateWizardUI(); return; }

  const name      = document.getElementById('wExamName').value.trim();
  const sessionId = document.getElementById('wSession').value;
  const classId   = document.getElementById('wClass').value;
  const groupId   = document.getElementById('wGroup')?.value || '';

  const btn = document.getElementById('btnWizNext');
  btn.innerHTML = '<i class="fas fa-spinner spin"></i> Creating…';
  btn.disabled  = true;

  const r = await ajax({
    action:     'create_exam',
    exam_name:  name,
    session_id: sessionId,
    class_id:   classId,
    group_id:   groupId,
    subjects:   JSON.stringify(configs),
  });

  btn.disabled = false;
  updateWizardUI();

  if (!r.ok) {
    showToast('danger','Error', r.msg);
    return;
  }

  // Success
  exams = r.exams || exams;
  renderExams();
  toggleWizard();
  showToast('success','Exam Created!', r.msg);

  // Sync filter session to what was just created
  document.getElementById('fSession').value = sessionId;
}

/* ================================================================
   INIT
   ================================================================ */
loadExams();
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
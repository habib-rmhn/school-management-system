<?php
/**
 * EMS Student — Admit Card  v1.0
 * ─────────────────────────────────────────────────────────────────
 * Shows the logged-in student's admit card for an exam, once fees
 * are fully paid. Data loads via AJAX (admit-card-ajax.php) — no
 * page reload when switching sessions or opening an exam's card.
 *
 * Flow:
 *   1. Student picks a class/session (only shown if they have more
 *      than one — e.g. after being promoted).
 *   2. A list of exam "cards" appears, each showing a clear status:
 *      ready to download / fees due / seat plan not ready / exam over.
 *   3. Tapping a ready card opens the actual admit card — a clean,
 *      official-looking printable layout with photo, subjects table
 *      (date, time, room, seat), styled for both screen and print.
 *      The student downloads it via the browser's native Print →
 *      Save as PDF, which needs no server-side PDF library.
 *
 * Design notes for a rural Class 6–10 audience:
 *   - The payment-gate message is the single most important thing
 *     to get right: a student whose card is locked needs to know
 *     EXACTLY why and what to do ("pay ৳500 at the office"), not a
 *     vague "not available" message.
 *   - Status badges use color + icon + plain words together, never
 *     just one of those.
 *   - The actual admit card avoids tiny print-only fonts — it must
 *     be just as readable on a phone screen as on a printed page.
 */
require_once __DIR__ . '/../includes/student-session.php';

$pageTitle = 'Admit Card';
$activeNav = 'admit-card';

require_once __DIR__ . '/includes/student-sidebar.php';
?>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="ac-header">
  <div>
    <div class="ac-title"><i class="fas fa-id-card"></i> Admit Card</div>
    <div class="ac-sub" id="acSubLabel">Loading your exams…</div>
  </div>
  <div class="ac-session-wrap" id="acSessionWrap" style="display:none">
    <label class="ac-session-label" for="acSessionSelect">Class / Session</label>
    <select id="acSessionSelect" class="filter-select"></select>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     LOADING / ERROR / EMPTY STATES
     ═══════════════════════════════════════════════════════════════ -->
<div id="acLoading" class="card">
  <div class="empty-state">
    <i class="fas fa-spinner fa-spin"></i>
    <p>Loading your exams…</p>
  </div>
</div>

<div id="acError" class="alert alert-danger" style="display:none"></div>

<div id="acNoEnrollment" class="alert alert-warning" style="display:none">
  <i class="fas fa-triangle-exclamation"></i>
  <span>You don't have any class enrollment on record yet. Please contact your school admin office.</span>
</div>

<div id="acEmpty" class="card" style="display:none">
  <div class="empty-state">
    <i class="fas fa-id-card"></i>
    <p>No exams have been scheduled for your class yet. Please check back later.</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     FEE DUE BANNER (shown above exam list if balance > 0)
     ═══════════════════════════════════════════════════════════════ -->
<div class="ac-fee-banner" id="acFeeBanner" style="display:none"></div>

<!-- ═══════════════════════════════════════════════════════════════
     EXAM LIST VIEW (cards)
     ═══════════════════════════════════════════════════════════════ -->
<div id="acExamListView" style="display:none">
  <div class="ac-exam-grid" id="acExamGrid"></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     PRINTABLE ADMIT CARD VIEW
     ═══════════════════════════════════════════════════════════════ -->
<div id="acCardView" style="display:none">

  <div class="ac-card-toolbar no-print">
    <button type="button" class="btn btn-outline" id="acBackBtn">
      <i class="fas fa-arrow-left"></i> Back to all exams
    </button>
    <button type="button" class="btn btn-primary" id="acPrintBtn">
      <i class="fas fa-download"></i> Download / Print
    </button>
  </div>

  <div class="ac-print-area" id="acPrintArea">
    <!-- Filled dynamically by JS -->
  </div>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE-SPECIFIC STYLES
     ═══════════════════════════════════════════════════════════════ -->
<style>
  /* ── Page header ─────────────────────────────────────────────── */
  .ac-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 20px;
  }
  .ac-title {
    font-size: 20px;
    font-weight: 800;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -.02em;
  }
  .ac-title i { color: var(--primary); font-size: 19px; }
  .ac-sub {
    font-size: 13.5px;
    color: var(--text-muted);
    margin-top: 5px;
    font-weight: 500;
  }
  .ac-session-wrap { display: flex; flex-direction: column; gap: 5px; min-width: 220px; }
  .ac-session-label {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .04em;
  }

  /* ── Fee due banner ───────────────────────────────────────────── */
  .ac-fee-banner {
    background: linear-gradient(120deg, #dc2626 0%, #ef4444 100%);
    color: #fff;
    border-radius: var(--r-lg);
    padding: 20px 24px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 18px;
  }
  .ac-fee-icon {
    width: 52px; height: 52px;
    background: rgba(255,255,255,.18);
    border-radius: var(--r);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
  }
  .ac-fee-text { min-width: 0; }
  .ac-fee-title { font-size: 15.5px; font-weight: 800; }
  .ac-fee-sub { font-size: 13px; opacity: .92; margin-top: 4px; line-height: 1.5; }
  .ac-fee-amount { font-weight: 800; }
  .ac-fee-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 10px;
    background: rgba(255,255,255,.2);
    padding: 6px 13px;
    border-radius: 20px;
    font-size: 12.5px;
    font-weight: 700;
    color: #fff;
    text-decoration: none;
  }

  /* ── Exam cards grid ─────────────────────────────────────────── */
  .ac-exam-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
  }

  .ac-exam-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border);
    border-left: 5px solid var(--border);
    border-radius: var(--r-md);
    padding: 18px;
    transition: border-color var(--t), box-shadow var(--t), transform var(--t);
    box-shadow: var(--shadow-card);
  }
  .ac-exam-card.is-ready    { border-left-color: var(--green); }
  .ac-exam-card.is-locked   { border-left-color: var(--red); }
  .ac-exam-card.is-waiting  { border-left-color: var(--amber); }
  .ac-exam-card.is-past     { border-left-color: var(--text-light); opacity: .75; }
  .ac-exam-card.is-clickable { cursor: pointer; }
  .ac-exam-card.is-clickable:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }

  .acc-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
  .acc-name { font-size: 15.5px; font-weight: 800; color: var(--text); line-height: 1.3; }
  .acc-date { font-size: 12px; color: var(--text-muted); margin-top: 4px; display: flex; align-items: center; gap: 5px; }

  .acc-status-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--border-light);
    font-size: 12.5px;
    font-weight: 700;
  }
  .acc-status-row i { font-size: 13px; }
  .acc-status-row.txt-green  { color: var(--green); }
  .acc-status-row.txt-red    { color: var(--red); }
  .acc-status-row.txt-amber  { color: var(--amber); }
  .acc-status-row.txt-muted  { color: var(--text-muted); }

  .acc-cta {
    margin-top: 12px;
    font-size: 12.5px;
    font-weight: 700;
    color: var(--primary);
    display: flex;
    align-items: center;
    gap: 5px;
  }

  /* ── Toolbar above the admit card ─────────────────────────────── */
  .ac-card-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 18px;
    flex-wrap: wrap;
  }

  /* ── Printable admit card ─────────────────────────────────────── */
  .ac-print-area {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: var(--r-md);
    box-shadow: var(--shadow-card);
    max-width: 760px;
    margin: 0 auto;
    overflow: hidden;
  }

  .ac-doc-header {
    text-align: center;
    padding: 24px 28px 18px;
    border-bottom: 2px solid var(--text);
  }
  .ac-doc-school { font-size: 19px; font-weight: 800; color: var(--text); letter-spacing: -.01em; }
  .ac-doc-title  { font-size: 14px; font-weight: 700; color: var(--primary); margin-top: 6px; text-transform: uppercase; letter-spacing: .06em; }
  .ac-doc-exam   { font-size: 13px; color: var(--text-muted); margin-top: 4px; font-weight: 600; }

  .ac-doc-body { padding: 22px 28px; }

  .ac-doc-top-row { display: flex; gap: 20px; margin-bottom: 22px; }
  .ac-doc-photo {
    width: 96px; height: 116px;
    border: 1.5px solid var(--border);
    border-radius: var(--r-sm);
    object-fit: cover;
    flex-shrink: 0;
    background: var(--bg-elevated);
  }
  .ac-doc-info { flex: 1; min-width: 0; }
  .ac-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 18px; }
  .ac-info-item { }
  .ac-info-label { font-size: 10.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; font-weight: 700; }
  .ac-info-value { font-size: 14.5px; color: var(--text); font-weight: 700; margin-top: 2px; }

  .ac-doc-subjects-title {
    font-size: 13px;
    font-weight: 800;
    color: var(--text);
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border);
  }

  .ac-doc-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  .ac-doc-table th, .ac-doc-table td {
    padding: 9px 10px;
    font-size: 12.5px;
    border: 1px solid var(--border);
    text-align: left;
  }
  .ac-doc-table th {
    background: var(--bg-elevated);
    font-weight: 700;
    text-transform: uppercase;
    font-size: 10.5px;
    letter-spacing: .04em;
    color: var(--text-muted);
  }
  .ac-doc-table td { font-weight: 600; color: var(--text-sub); }
  .ac-doc-table .ac-room-cell { font-weight: 800; color: var(--primary); }

  .ac-doc-footer {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    margin-top: 36px;
    padding-top: 16px;
  }
  .ac-sign-box { text-align: center; }
  .ac-sign-line { width: 150px; border-top: 1.5px solid var(--text); margin-bottom: 6px; }
  .ac-sign-label { font-size: 11px; color: var(--text-muted); font-weight: 600; }

  .ac-doc-note {
    margin: 18px 28px 22px;
    padding: 12px 14px;
    background: var(--amber-bg);
    border: 1px solid var(--amber-border);
    border-radius: var(--r-sm);
    font-size: 12px;
    color: #78350f;
    display: flex;
    gap: 9px;
    align-items: flex-start;
  }
  .ac-doc-note i { margin-top: 1px; flex-shrink: 0; }

  /* ── Print-only rules ─────────────────────────────────────────── */
  @media print {
    .no-print, .ems-sidebar, .ems-topbar, .mobile-tabbar { display: none !important; }
    .ems-main { margin: 0 !important; padding: 0 !important; width: 100% !important; }
    body { background: #fff; }
    .ac-print-area { box-shadow: none; border: none; max-width: 100%; }
    .main-inner { padding: 0 !important; }
  }

  /* ── Responsive ───────────────────────────────────────────────── */
  @media (max-width: 1100px) {
    .ac-exam-grid { grid-template-columns: repeat(2, 1fr); }
  }
  @media (max-width: 700px) {
    .ac-exam-grid { grid-template-columns: 1fr; }
  }

  @media (max-width: 600px) {
    .ac-doc-top-row { flex-direction: column; align-items: center; text-align: center; }
    .ac-info-grid { grid-template-columns: 1fr 1fr; text-align: left; }
    .ac-doc-footer { flex-direction: column; gap: 24px; align-items: center; }
    .ac-doc-table th, .ac-doc-table td { font-size: 11.5px; padding: 7px 6px; }
  }

  @media (max-width: 480px) {
    .ac-header { flex-direction: column; align-items: stretch; }
    .ac-session-wrap { min-width: 0; width: 100%; }
    .ac-title { font-size: 18px; }
    .ac-doc-body { padding: 16px; }
    .ac-doc-header { padding: 18px 16px 14px; }
    .ac-doc-school { font-size: 16px; }
    .ac-card-toolbar { flex-direction: column-reverse; align-items: stretch; }
    .ac-card-toolbar .btn { width: 100%; }
  }
</style>

<!-- CSRF token for AJAX requests (matches admin panel convention) -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<!-- Expose the student's own avatar URL for use in the printable admit card -->
<script>window.EMS_STUDENT_AVATAR_URL = <?= json_encode($avatarUrl) ?>;</script>

<?php
// ── Page-specific JS — passed to the footer's $pageJs slot ────────
$pageJs = <<<'JS'
(function () {
  "use strict";

  const AJAX_URL = "admit-card-ajax.php";
  function getToken() { return document.getElementById("csrfToken")?.value || ""; }
  let csrfToken = getToken();

  const $ = (id) => document.getElementById(id);
  const escapeHtml = (str) => String(str ?? "").replace(/[&<>"']/g, (c) => ({
    "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;"
  }[c]));

  let enrollmentsCache  = [];
  let currentEnrollment = null;

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
    ["acLoading","acError","acNoEnrollment","acEmpty","acExamListView","acCardView"].forEach(elId => {
      const el = $(elId);
      if (el) el.style.display = (elId === id) ? "" : "none";
    });
  }

  function showError(msg) {
    const el = $("acError");
    el.textContent = msg;
    showOnly("acError");
  }

  function fmtTk(n) {
    return "৳" + Number(n).toLocaleString("en-IN", { maximumFractionDigits: 0 });
  }

  function fmtDate(d) {
    if (!d) return "—";
    return new Date(d + "T00:00:00").toLocaleDateString("en-GB", { day:"numeric", month:"short", year:"numeric" });
  }

  function fmtTime(t) {
    if (!t) return "—";
    const [h, m] = t.split(":").map(Number);
    const ampm = h >= 12 ? "PM" : "AM";
    const h12  = (h % 12) || 12;
    return String(h12).padStart(2,"0") + ":" + String(m).padStart(2,"0") + " " + ampm;
  }

  // ── Step 1: load this student's enrollment history for the picker ──
  async function loadSessions() {
    showOnly("acLoading");
    const data = await post("fetch_sessions", {});
    if (!data) { showError("Could not reach the server. Please check your connection."); return; }
    if (!data.ok) { showError(data.msg || "Something went wrong."); return; }

    enrollmentsCache = data.enrollments || [];
    if (enrollmentsCache.length === 0) { showOnly("acNoEnrollment"); return; }

    const wrap   = $("acSessionWrap");
    const select = $("acSessionSelect");
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

  // ── Step 2: load exam cards (with payment-gate + seat-plan status) ──
  async function loadExamList(enrollmentId) {
    showOnly("acLoading");
    const data = await post("fetch_exams", { enrollment_id: enrollmentId });
    if (!data) { showError("Could not reach the server. Please check your connection."); return; }
    if (!data.ok) { showError(data.msg || "Something went wrong."); return; }

    $("acSubLabel").textContent =
      data.class_label + " · Session " + data.session_year + (data.group_name ? " · " + data.group_name : "");

    renderFeeBanner(data.fee_clear, data.total_due, data.total_paid, data.balance);

    if (!data.exams || data.exams.length === 0) {
      $("acFeeBanner").style.display = data.fee_clear ? "none" : "";
      showOnly("acEmpty");
      return;
    }

    renderExamGrid(data.exams, data.fee_clear);
    showOnly("acExamListView");
  }

  function renderFeeBanner(feeClear, totalDue, totalPaid, balance) {
    const banner = $("acFeeBanner");
    if (feeClear) {
      banner.style.display = "none";
      return;
    }
    banner.style.display = "";
    banner.innerHTML =
      '<div class="ac-fee-icon"><i class="fas fa-triangle-exclamation"></i></div>' +
      '<div class="ac-fee-text">' +
        '<div class="ac-fee-title">Your admit card is locked</div>' +
        '<div class="ac-fee-sub">You have an unpaid balance of <span class="ac-fee-amount">' + fmtTk(balance) + '</span>. ' +
          'Admit cards can only be downloaded after all fees are fully paid.</div>' +
        '<a href="payments.php" class="ac-fee-link"><i class="fas fa-money-bill-wave"></i> View My Payments</a>' +
      '</div>';
  }

  function renderExamGrid(exams, feeClear) {
    const grid = $("acExamGrid");
    grid.innerHTML = exams.map(ex => {
      let cardCls = "ac-exam-card";
      let statusHtml = "";
      let ctaHtml = "";
      let clickable = false;

      if (ex.is_excluded) {
        cardCls += " is-locked";
        statusHtml = '<div class="acc-status-row txt-red"><i class="fas fa-ban"></i> Not registered for this exam</div>';
      } else if (ex.is_past) {
        cardCls += " is-past";
        statusHtml = '<div class="acc-status-row txt-muted"><i class="fas fa-clock-rotate-left"></i> This exam has ended</div>';
      } else if (!feeClear) {
        cardCls += " is-locked";
        statusHtml = '<div class="acc-status-row txt-red"><i class="fas fa-lock"></i> Locked — fees due</div>';
      } else if (!ex.has_seat_plan) {
        cardCls += " is-waiting";
        statusHtml = '<div class="acc-status-row txt-amber"><i class="fas fa-hourglass-half"></i> Seat plan not ready yet</div>';
      } else {
        cardCls += " is-ready is-clickable";
        clickable = true;
        statusHtml = '<div class="acc-status-row txt-green"><i class="fas fa-circle-check"></i> Ready to download</div>';
        ctaHtml = '<div class="acc-cta">Open Admit Card <i class="fas fa-arrow-right"></i></div>';
      }

      const dateRange = ex.first_date
        ? fmtDate(ex.first_date) + (ex.last_date && ex.last_date !== ex.first_date ? " – " + fmtDate(ex.last_date) : "")
        : "Dates not set";

      return (
        '<div class="' + cardCls + '" data-exam-id="' + ex.exam_id + '" data-clickable="' + clickable + '">' +
          '<div class="acc-top">' +
            '<div>' +
              '<div class="acc-name">' + escapeHtml(ex.exam_name) + '</div>' +
              '<div class="acc-date"><i class="fas fa-calendar"></i> ' + dateRange + '</div>' +
            '</div>' +
          '</div>' +
          '<div style="font-size:12px;color:var(--text-muted)">' + ex.subject_count + ' subject' + (ex.subject_count===1?'':'s') + '</div>' +
          statusHtml +
          ctaHtml +
        '</div>'
      );
    }).join("");

    grid.querySelectorAll('.ac-exam-card[data-clickable="true"]').forEach(card => {
      card.addEventListener("click", () => loadAdmitCard(card.dataset.examId));
    });
  }

  // ── Step 3: load + render the actual admit card ────────────────────
  async function loadAdmitCard(examId) {
    showOnly("acLoading");
    const data = await post("fetch_admit_card", { enrollment_id: currentEnrollment, exam_id: examId });
    if (!data) { showError("Could not reach the server. Please check your connection."); return; }
    if (!data.ok) { showError(data.msg || "Something went wrong."); return; }

    renderAdmitCard(data);
    showOnly("acCardView");
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function renderAdmitCard(data) {
    const area = $("acPrintArea");
    const st = data.student;
    const ex = data.exam;

    const photoUrl = window.EMS_STUDENT_AVATAR_URL || "";

    const subjectRows = data.subjects.map(s => {
      const room = s.room_number ? escapeHtml(s.room_number) : '<span style="color:var(--text-light)">TBA</span>';
      const seat = s.seat_number ? escapeHtml(s.seat_number) : '<span style="color:var(--text-light)">TBA</span>';
      return (
        '<tr>' +
          '<td>' + escapeHtml(s.subject_name) + (s.subject_code ? ' <span style="color:var(--text-muted);font-weight:500">(' + escapeHtml(s.subject_code) + ')</span>' : '') + '</td>' +
          '<td>' + fmtDate(s.exam_date) + '</td>' +
          '<td>' + fmtTime(s.start_time) + '</td>' +
          '<td class="ac-room-cell">' + room + '</td>' +
          '<td class="ac-room-cell">' + seat + '</td>' +
        '</tr>'
      );
    }).join("");

    area.innerHTML =
      '<div class="ac-doc-header">' +
        '<div class="ac-doc-school">' + escapeHtml(data.school_name) + '</div>' +
        '<div class="ac-doc-title">Admit Card</div>' +
        '<div class="ac-doc-exam">' + escapeHtml(ex.exam_name) + ' &middot; ' + escapeHtml(ex.class_label) +
          ' &middot; Session ' + escapeHtml(String(ex.session_year)) +
          (ex.group_name ? ' &middot; ' + escapeHtml(ex.group_name) : '') + '</div>' +
      '</div>' +
      '<div class="ac-doc-body">' +
        '<div class="ac-doc-top-row">' +
          '<img src="' + photoUrl + '" alt="Student Photo" class="ac-doc-photo">' +
          '<div class="ac-doc-info">' +
            '<div class="ac-info-grid">' +
              '<div class="ac-info-item"><div class="ac-info-label">Student Name</div><div class="ac-info-value">' + escapeHtml(st.full_name) + '</div></div>' +
              '<div class="ac-info-item"><div class="ac-info-label">Student ID</div><div class="ac-info-value">' + escapeHtml(st.student_id) + '</div></div>' +
              '<div class="ac-info-item"><div class="ac-info-label">Father\'s Name</div><div class="ac-info-value">' + escapeHtml(st.father_name || "—") + '</div></div>' +
              '<div class="ac-info-item"><div class="ac-info-label">Roll Number</div><div class="ac-info-value">' + escapeHtml(String(st.roll_number || "—")) + '</div></div>' +
              '<div class="ac-info-item"><div class="ac-info-label">Class</div><div class="ac-info-value">' + escapeHtml(ex.class_label) + '</div></div>' +
              '<div class="ac-info-item"><div class="ac-info-label">Date of Birth</div><div class="ac-info-value">' + fmtDate(st.date_of_birth) + '</div></div>' +
            '</div>' +
          '</div>' +
        '</div>' +
        '<div class="ac-doc-subjects-title">Examination Schedule</div>' +
        '<table class="ac-doc-table">' +
          '<thead><tr><th>Subject</th><th>Date</th><th>Time</th><th>Room</th><th>Seat</th></tr></thead>' +
          '<tbody>' + subjectRows + '</tbody>' +
        '</table>' +
        '<div class="ac-doc-footer">' +
          '<div class="ac-sign-box"><div class="ac-sign-line"></div><div class="ac-sign-label">Student Signature</div></div>' +
          '<div class="ac-sign-box"><div class="ac-sign-line"></div><div class="ac-sign-label">Headteacher / Principal</div></div>' +
        '</div>' +
      '</div>' +
      (data.missing_seat
        ? '<div class="ac-doc-note"><i class="fas fa-circle-info"></i> Room/seat for some subjects marked "TBA" will be confirmed soon. Please check again before the exam.</div>'
        : '');
  }

  $("acBackBtn").addEventListener("click", () => showOnly("acExamListView"));
  $("acPrintBtn").addEventListener("click", () => window.print());

  loadSessions();
})();
JS;

require_once __DIR__ . '/includes/student-footer.php';
?>
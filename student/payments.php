<?php
/**
 * EMS Student — My Payments  v1.0
 * ─────────────────────────────────────────────────────────────────
 * Shows the logged-in student's fee dues and payment history.
 * Data loads via AJAX (payments-ajax.php) — no page reload when
 * switching sessions or tabs.
 *
 * Important: this is a READ-ONLY view. There is no online payment
 * gateway in this system by design — fees are always collected and
 * recorded manually by the school office. This page exists purely
 * so a student/parent can check what's owed and what's been paid
 * without having to visit the office to ask.
 *
 * Flow:
 *   1. Student picks a class/session (only shown if more than one).
 *   2. A summary banner shows total due, total paid, and balance
 *      at a glance — colour-coded green (clear) or red (owing).
 *   3. Two tabs: "My Dues" (each fee with its own paid/balance and
 *      an expandable history of payments against it) and
 *      "Payment History" (a flat, most-recent-first list of every
 *      payment ever recorded, regardless of which fee it was for).
 *
 * Design notes for a rural Class 6–10 audience:
 *   - Money is shown with the Bangladeshi Taka symbol (৳) and
 *     formatted with thousands separators — never a raw decimal.
 *   - A scholarship percentage, if any, is explained plainly
 *     ("10% scholarship applied") rather than left as a bare number
 *     a student has to interpret themselves.
 *   - Status uses colour + icon + word together: green check "Paid
 *     in full", amber clock "Partially paid", red alert "Not paid
 *     yet" — never colour alone.
 */
require_once __DIR__ . '/../includes/student-session.php';

$pageTitle = 'My Payments';
$activeNav = 'payments';

require_once __DIR__ . '/includes/student-sidebar.php';
?>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="py-header">
  <div>
    <div class="py-title"><i class="fas fa-money-bill-wave"></i> My Payments</div>
    <div class="py-sub" id="pySubLabel">Loading your payment details…</div>
  </div>
  <div class="py-session-wrap" id="pySessionWrap" style="display:none">
    <label class="py-session-label" for="pySessionSelect">Class / Session</label>
    <select id="pySessionSelect" class="filter-select"></select>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     LOADING / ERROR / EMPTY STATES
     ═══════════════════════════════════════════════════════════════ -->
<div id="pyLoading" class="card">
  <div class="empty-state">
    <i class="fas fa-spinner fa-spin"></i>
    <p>Loading your payment details…</p>
  </div>
</div>

<div id="pyError" class="alert alert-danger" style="display:none"></div>

<div id="pyNoEnrollment" class="alert alert-warning" style="display:none">
  <i class="fas fa-triangle-exclamation"></i>
  <span>You don't have any class enrollment on record yet. Please contact your school admin office.</span>
</div>

<div id="pyEmpty" class="card" style="display:none">
  <div class="empty-state">
    <i class="fas fa-circle-check" style="color:var(--green)"></i>
    <p>No fees have been added for your account yet. Nothing is due right now.</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MAIN CONTENT (hidden until data loads)
     ═══════════════════════════════════════════════════════════════ -->
<div id="pyContent" style="display:none">

  <!-- ── Summary banner ──────────────────────────────────────────── -->
  <div class="py-summary-banner" id="pySummaryBanner"></div>

  <?php if (false): ?><!-- scholarship note rendered dynamically below banner via JS --><?php endif; ?>
  <div id="pyScholarshipNote" class="alert alert-info" style="display:none"></div>

  <!-- ── Tabs ─────────────────────────────────────────────────────── -->
  <div class="py-tabs" role="tablist">
    <button type="button" class="py-tab active" data-tab="dues" role="tab" aria-selected="true">
      <i class="fas fa-file-invoice-dollar"></i> My Dues
    </button>
    <button type="button" class="py-tab" data-tab="history" role="tab" aria-selected="false">
      <i class="fas fa-clock-rotate-left"></i> Payment History
    </button>
  </div>

  <!-- ── Tab: My Dues ─────────────────────────────────────────────── -->
  <div class="py-tab-panel" id="pyPanelDues">
    <div id="pyDuesList"></div>
  </div>

  <!-- ── Tab: Payment History ─────────────────────────────────────── -->
  <div class="py-tab-panel" id="pyPanelHistory" style="display:none">
    <div class="card">
      <div class="table-wrap py-history-table-wrap">
        <table class="ems-table" id="pyHistoryTable">
          <thead><tr><th>Date</th><th>Fee Type</th><th>Amount Paid</th><th>Note</th></tr></thead>
          <tbody id="pyHistoryTableBody"></tbody>
        </table>
      </div>
      <div class="py-history-cards" id="pyHistoryCards"></div>
      <div id="pyHistoryEmpty" class="empty-state" style="display:none">
        <i class="fas fa-receipt"></i>
        <p>No payments have been recorded yet.</p>
      </div>
    </div>
  </div>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE-SPECIFIC STYLES
     ═══════════════════════════════════════════════════════════════ -->
<style>
  /* ── Page header ─────────────────────────────────────────────── */
  .py-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 20px;
  }
  .py-title {
    font-size: 20px;
    font-weight: 800;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -.02em;
  }
  .py-title i { color: var(--primary); font-size: 19px; }
  .py-sub {
    font-size: 13.5px;
    color: var(--text-muted);
    margin-top: 5px;
    font-weight: 500;
  }
  .py-session-wrap { display: flex; flex-direction: column; gap: 5px; min-width: 220px; }
  .py-session-label {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .04em;
  }

  /* ── Summary banner ───────────────────────────────────────────── */
  .py-summary-banner {
    border-radius: var(--r-lg);
    padding: 22px 26px;
    margin-bottom: 16px;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
    position: relative;
    overflow: hidden;
  }
  .py-summary-banner::before {
    content: '';
    position: absolute;
    top: -45px; right: -35px;
    width: 170px; height: 170px;
    border-radius: 50%;
    background: rgba(255,255,255,.08);
  }
  .py-summary-banner.is-clear { background: linear-gradient(120deg, var(--green) 0%, #10b981 100%); }
  .py-summary-banner.is-due   { background: linear-gradient(120deg, #dc2626 0%, #ef4444 100%); }

  .psb-icon {
    width: 56px; height: 56px;
    background: rgba(255,255,255,.18);
    border-radius: var(--r);
    display: flex; align-items: center; justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
    position: relative; z-index: 1;
  }
  .psb-text { position: relative; z-index: 1; flex: 1; min-width: 200px; }
  .psb-title { font-size: 16px; font-weight: 800; }
  .psb-sub { font-size: 13px; opacity: .9; margin-top: 4px; }

  .psb-figures {
    display: flex;
    gap: 22px;
    position: relative; z-index: 1;
    flex-wrap: wrap;
  }
  .psb-fig { text-align: center; }
  .psb-fig-label { font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; opacity: .85; font-weight: 700; }
  .psb-fig-value { font-size: 19px; font-weight: 800; margin-top: 3px; }

  /* ── Tabs ─────────────────────────────────────────────────────── */
  .py-tabs {
    display: flex;
    gap: 6px;
    background: var(--bg-elevated);
    border-radius: var(--r);
    padding: 5px;
    margin-bottom: 18px;
    width: fit-content;
  }
  .py-tab {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border: none;
    background: transparent;
    border-radius: var(--r-sm);
    font-family: inherit;
    font-size: 13.5px;
    font-weight: 700;
    color: var(--text-muted);
    cursor: pointer;
    transition: all var(--t);
    min-height: var(--tap-min);
  }
  .py-tab i { font-size: 13px; }
  .py-tab.active { background: var(--bg-card); color: var(--primary); box-shadow: var(--shadow-xs); }
  .py-tab:hover:not(.active) { color: var(--text); }

  /* ── Due cards ────────────────────────────────────────────────── */
  .py-due-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border);
    border-left: 5px solid var(--border);
    border-radius: var(--r-md);
    margin-bottom: 14px;
    box-shadow: var(--shadow-card);
    overflow: hidden;
  }
  .py-due-card.status-paid    { border-left-color: var(--green); }
  .py-due-card.status-partial { border-left-color: var(--amber); }
  .py-due-card.status-unpaid  { border-left-color: var(--red); }

  .pdc-header {
    padding: 16px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    cursor: pointer;
    flex-wrap: wrap;
  }
  .pdc-left { min-width: 0; flex: 1; }
  .pdc-fee-type { font-size: 15px; font-weight: 800; color: var(--text); }
  .pdc-due-date { font-size: 12px; color: var(--text-muted); margin-top: 3px; display: flex; align-items: center; gap: 5px; }

  .pdc-right { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
  .pdc-amount-block { text-align: right; }
  .pdc-balance-label { font-size: 10.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; font-weight: 700; }
  .pdc-balance-value { font-size: 18px; font-weight: 800; margin-top: 2px; }
  .pdc-balance-value.is-zero { color: var(--green); }
  .pdc-balance-value.is-due  { color: var(--red); }

  .pdc-chevron { color: var(--text-light); font-size: 14px; transition: transform var(--t-slow); flex-shrink: 0; }
  .py-due-card.is-open .pdc-chevron { transform: rotate(180deg); }

  .pdc-body {
    display: none;
    padding: 0 18px 18px;
    border-top: 1px solid var(--border-light);
  }
  .py-due-card.is-open .pdc-body { display: block; padding-top: 16px; }

  .pdc-breakdown {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-bottom: 16px;
  }
  .pdc-mini-stat { background: var(--bg-elevated); border-radius: var(--r-sm); padding: 10px; text-align: center; }
  .pdc-mini-stat .ms-label { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; font-weight: 700; }
  .pdc-mini-stat .ms-value { font-size: 15px; font-weight: 800; color: var(--text); margin-top: 3px; }

  .pdc-history-title {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: 8px;
  }

  .pdc-payment-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-light);
  }
  .pdc-payment-row:last-child { border-bottom: none; }
  .ppr-icon {
    width: 32px; height: 32px;
    border-radius: 50%;
    background: var(--green-bg);
    color: var(--green);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px;
    flex-shrink: 0;
  }
  .ppr-text { flex: 1; min-width: 0; }
  .ppr-amount { font-size: 13.5px; font-weight: 700; color: var(--text); }
  .ppr-date { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }
  .ppr-note { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; font-style: italic; }

  .pdc-no-payments { font-size: 12.5px; color: var(--text-muted); font-style: italic; padding: 8px 0; }

  /* ── Status pill (on header) ──────────────────────────────────── */
  .pdc-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 700;
    white-space: nowrap;
  }
  .pdc-status-pill.status-paid    { background: var(--green-bg); color: var(--green); }
  .pdc-status-pill.status-partial { background: var(--amber-bg); color: var(--amber); }
  .pdc-status-pill.status-unpaid  { background: var(--red-bg);   color: var(--red); }

  /* ── Payment history table (desktop) / cards (mobile) ─────────── */
  .py-history-table-wrap { display: block; }
  .py-history-cards { display: none; }

  .phc-card {
    border: 1px solid var(--border);
    border-radius: var(--r);
    padding: 14px 16px;
    margin: 0 16px 10px;
  }
  .phc-card:last-child { margin-bottom: 16px; }
  .phc-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
  .phc-amount { font-size: 16px; font-weight: 800; color: var(--green); }
  .phc-date { font-size: 11.5px; color: var(--text-muted); }
  .phc-fee-type { font-size: 12.5px; font-weight: 700; color: var(--text-sub); margin-top: 6px; }
  .phc-note { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; font-style: italic; }

  /* ── Responsive ───────────────────────────────────────────────── */
  @media (max-width: 700px) {
    .py-summary-banner { flex-direction: column; align-items: flex-start; }
    .psb-figures { width: 100%; justify-content: space-between; gap: 12px; }
    .pdc-breakdown { grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
  }

  @media (max-width: 600px) {
    .py-history-table-wrap { display: none; }
    .py-history-cards { display: block; padding-top: 14px; }
    .pdc-right { width: 100%; justify-content: space-between; }
  }

  @media (max-width: 480px) {
    .py-header { flex-direction: column; align-items: stretch; }
    .py-session-wrap { min-width: 0; width: 100%; }
    .py-title { font-size: 18px; }
    .psb-icon { width: 46px; height: 46px; font-size: 19px; }
    .psb-figures { gap: 10px; }
    .psb-fig-value { font-size: 16px; }
    .py-tabs { width: 100%; }
    .py-tab { flex: 1; padding: 9px 10px; font-size: 12.5px; justify-content: center; }
    .pdc-header { padding: 14px; }
    .pdc-fee-type { font-size: 14px; }
    .pdc-breakdown { grid-template-columns: 1fr 1fr; }
  }
</style>

<!-- CSRF token for AJAX requests (matches admin panel convention) -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<?php
// ── Page-specific JS — passed to the footer's $pageJs slot ────────
$pageJs = <<<'JS'
(function () {
  "use strict";

  const AJAX_URL = "payments-ajax.php";
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
    ["pyLoading","pyError","pyNoEnrollment","pyEmpty","pyContent"].forEach(elId => {
      const el = $(elId);
      if (el) el.style.display = (elId === id) ? "" : "none";
    });
  }

  function showError(msg) {
    const el = $("pyError");
    el.textContent = msg;
    showOnly("pyError");
  }

  function fmtTk(n) {
    const num = Number(n) || 0;
    return "৳" + num.toLocaleString("en-IN", { maximumFractionDigits: 0 });
  }

  function fmtDate(d) {
    if (!d) return "—";
    const iso = d.includes("T") ? d : d.replace(" ", "T");
    return new Date(iso).toLocaleDateString("en-GB", { day:"numeric", month:"short", year:"numeric" });
  }

  function fmtDateTime(d) {
    if (!d) return "—";
    const iso = d.includes("T") ? d : d.replace(" ", "T");
    return new Date(iso).toLocaleDateString("en-GB", { day:"numeric", month:"short", year:"numeric" }) +
           " · " + new Date(iso).toLocaleTimeString("en-GB", { hour:"2-digit", minute:"2-digit" });
  }

  // ── Step 1: load this student's enrollment history for the picker ──
  async function loadSessions() {
    showOnly("pyLoading");
    const data = await post("fetch_sessions", {});
    if (!data) { showError("Could not reach the server. Please check your connection."); return; }
    if (!data.ok) { showError(data.msg || "Something went wrong."); return; }

    enrollmentsCache = data.enrollments || [];
    if (enrollmentsCache.length === 0) { showOnly("pyNoEnrollment"); return; }

    const wrap   = $("pySessionWrap");
    const select = $("pySessionSelect");
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
      loadLedger(currentEnrollment);
    });

    const activeOne = enrollmentsCache.find(e => e.status === "active");
    currentEnrollment = (activeOne || enrollmentsCache[0]).enrollment_id;
    select.value = currentEnrollment;

    loadLedger(currentEnrollment);
  }

  // ── Step 2: load full ledger (dues + history) for one enrollment ───
  async function loadLedger(enrollmentId) {
    showOnly("pyLoading");
    const data = await post("fetch_ledger", { enrollment_id: enrollmentId });
    if (!data) { showError("Could not reach the server. Please check your connection."); return; }
    if (!data.ok) { showError(data.msg || "Something went wrong."); return; }

    $("pySubLabel").textContent =
      data.class_label + " · Session " + data.session_year + (data.group_name ? " · " + data.group_name : "");

    renderScholarshipNote(data.scholarship);

    if (!data.dues || data.dues.length === 0) {
      showOnly("pyEmpty");
      return;
    }

    renderSummaryBanner(data.totals);
    renderDuesList(data.dues);
    renderHistory(data.all_payments);

    showOnly("pyContent");
  }

  function renderScholarshipNote(pct) {
    const el = $("pyScholarshipNote");
    if (pct && Number(pct) > 0) {
      el.style.display = "";
      el.innerHTML = '<i class="fas fa-award"></i><span>You have a <strong>' + Number(pct) + '%</strong> scholarship applied to your fees.</span>';
    } else {
      el.style.display = "none";
    }
  }

  function renderSummaryBanner(totals) {
    const banner = $("pySummaryBanner");
    const isClear = totals.balance <= 0;
    banner.className = "py-summary-banner " + (isClear ? "is-clear" : "is-due");

    banner.innerHTML =
      '<div class="psb-icon"><i class="fas ' + (isClear ? "fa-circle-check" : "fa-triangle-exclamation") + '"></i></div>' +
      '<div class="psb-text">' +
        '<div class="psb-title">' + (isClear ? "You're all paid up!" : "You have a balance due") + '</div>' +
        '<div class="psb-sub">' + (isClear
          ? "All your fees for this session have been fully paid."
          : "Please pay the remaining balance at the school office.") + '</div>' +
      '</div>' +
      '<div class="psb-figures">' +
        '<div class="psb-fig"><div class="psb-fig-label">Total Due</div><div class="psb-fig-value">' + fmtTk(totals.due) + '</div></div>' +
        '<div class="psb-fig"><div class="psb-fig-label">Total Paid</div><div class="psb-fig-value">' + fmtTk(totals.paid) + '</div></div>' +
        '<div class="psb-fig"><div class="psb-fig-label">Balance</div><div class="psb-fig-value">' + fmtTk(totals.balance) + '</div></div>' +
      '</div>';
  }

  function statusInfo(status) {
    return {
      paid:    { cls: "status-paid",    icon: "fa-circle-check",      label: "Paid in full" },
      partial: { cls: "status-partial", icon: "fa-clock",             label: "Partially paid" },
      unpaid:  { cls: "status-unpaid",  icon: "fa-circle-exclamation",label: "Not paid yet" },
    }[status] || { cls: "status-unpaid", icon: "fa-circle-exclamation", label: "Not paid yet" };
  }

  function renderDuesList(dues) {
    const wrap = $("pyDuesList");
    wrap.innerHTML = dues.map((d, idx) => {
      const info = statusInfo(d.status);
      const balanceCls = d.balance <= 0 ? "is-zero" : "is-due";

      const historyHtml = (d.records && d.records.length > 0)
        ? d.records.map(r =>
            '<div class="pdc-payment-row">' +
              '<div class="ppr-icon"><i class="fas fa-coins"></i></div>' +
              '<div class="ppr-text">' +
                '<div class="ppr-amount">' + fmtTk(r.amount_paid) + ' paid</div>' +
                '<div class="ppr-date"><i class="fas fa-calendar"></i> ' + fmtDateTime(r.paid_at) + '</div>' +
                (r.note ? '<div class="ppr-note">' + escapeHtml(r.note) + '</div>' : '') +
              '</div>' +
            '</div>'
          ).join("")
        : '<div class="pdc-no-payments">No payments have been recorded against this fee yet.</div>';

      return (
        '<div class="py-due-card ' + info.cls + '" data-idx="' + idx + '">' +
          '<div class="pdc-header" data-toggle="' + idx + '">' +
            '<div class="pdc-left">' +
              '<div class="pdc-fee-type">' + escapeHtml(d.fee_type) + '</div>' +
              '<div class="pdc-due-date"><i class="fas fa-calendar"></i> ' +
                (d.due_date ? "Due " + fmtDate(d.due_date) : "No due date set") + '</div>' +
            '</div>' +
            '<div class="pdc-right">' +
              '<span class="pdc-status-pill ' + info.cls + '"><i class="fas ' + info.icon + '"></i> ' + info.label + '</span>' +
              '<div class="pdc-amount-block">' +
                '<div class="pdc-balance-label">Balance</div>' +
                '<div class="pdc-balance-value ' + balanceCls + '">' + fmtTk(d.balance) + '</div>' +
              '</div>' +
              '<i class="fas fa-chevron-down pdc-chevron"></i>' +
            '</div>' +
          '</div>' +
          '<div class="pdc-body">' +
            '<div class="pdc-breakdown">' +
              '<div class="pdc-mini-stat"><div class="ms-label">Full Amount</div><div class="ms-value">' + fmtTk(d.amount_full) + '</div></div>' +
              '<div class="pdc-mini-stat"><div class="ms-label">Amount Due</div><div class="ms-value">' + fmtTk(d.amount_due) + '</div></div>' +
              '<div class="pdc-mini-stat"><div class="ms-label">Paid So Far</div><div class="ms-value" style="color:var(--green)">' + fmtTk(d.paid_amount) + '</div></div>' +
            '</div>' +
            (Number(d.scholarship_pct) > 0
              ? '<div style="font-size:12px;color:var(--text-muted);margin-bottom:14px"><i class="fas fa-award" style="color:var(--amber)"></i> ' + Number(d.scholarship_pct) + '% scholarship applied to this fee</div>'
              : '') +
            '<div class="pdc-history-title">Payment History for this Fee</div>' +
            historyHtml +
          '</div>' +
        '</div>'
      );
    }).join("");

    wrap.querySelectorAll(".pdc-header").forEach(header => {
      header.addEventListener("click", () => {
        const card = header.closest(".py-due-card");
        card.classList.toggle("is-open");
      });
    });

    // Auto-open the first unpaid/partial due so the student sees it immediately
    const firstUnpaid = wrap.querySelector(".py-due-card.status-unpaid, .py-due-card.status-partial");
    if (firstUnpaid) firstUnpaid.classList.add("is-open");
  }

  function renderHistory(payments) {
    const tbody = $("pyHistoryTableBody");
    const cardsWrap = $("pyHistoryCards");
    const emptyEl = $("pyHistoryEmpty");

    if (!payments || payments.length === 0) {
      tbody.innerHTML = "";
      cardsWrap.innerHTML = "";
      emptyEl.style.display = "";
      return;
    }
    emptyEl.style.display = "none";

    tbody.innerHTML = payments.map(p =>
      '<tr>' +
        '<td>' + fmtDateTime(p.paid_at) + '</td>' +
        '<td>' + escapeHtml(p.fee_type) + '</td>' +
        '<td style="font-weight:700;color:var(--green)">' + fmtTk(p.amount_paid) + '</td>' +
        '<td>' + (p.note ? escapeHtml(p.note) : '<span class="rs-na" style="color:var(--text-light);font-style:italic">—</span>') + '</td>' +
      '</tr>'
    ).join("");

    cardsWrap.innerHTML = payments.map(p =>
      '<div class="phc-card">' +
        '<div class="phc-top">' +
          '<span class="phc-amount">' + fmtTk(p.amount_paid) + '</span>' +
          '<span class="phc-date">' + fmtDateTime(p.paid_at) + '</span>' +
        '</div>' +
        '<div class="phc-fee-type">' + escapeHtml(p.fee_type) + '</div>' +
        (p.note ? '<div class="phc-note">' + escapeHtml(p.note) + '</div>' : '') +
      '</div>'
    ).join("");
  }

  // ── Tab switching ────────────────────────────────────────────────
  document.querySelectorAll(".py-tab").forEach(tab => {
    tab.addEventListener("click", () => {
      document.querySelectorAll(".py-tab").forEach(t => { t.classList.remove("active"); t.setAttribute("aria-selected","false"); });
      tab.classList.add("active");
      tab.setAttribute("aria-selected","true");

      const target = tab.dataset.tab;
      $("pyPanelDues").style.display    = (target === "dues")    ? "" : "none";
      $("pyPanelHistory").style.display = (target === "history") ? "" : "none";
    });
  });

  loadSessions();
})();
JS;

require_once __DIR__ . '/includes/student-footer.php';
?>
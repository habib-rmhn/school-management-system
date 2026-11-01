<?php
/**
 * EMS Student — My Profile  v1.0
 * ─────────────────────────────────────────────────────────────────
 * Shows the logged-in student's own profile (read-only — only the
 * school admin can edit personal details) and lets them change
 * their own password via a popup modal that requires their current
 * password first. Data loads via AJAX (profile-ajax.php) — no page
 * reload anywhere on this page.
 *
 * Design notes for a rural Class 6–10 audience:
 *   - Profile info is grouped into clearly labelled cards (Personal,
 *     Family, Contact, Academic) instead of one long list — easier
 *     to scan, easier to find one specific fact quickly.
 *   - Every field has a plain-language label and a fallback "Not
 *     provided" instead of an empty blank, so the page never looks
 *     broken when a field is genuinely empty in the database.
 *   - The password-change modal shows/hides each field individually
 *     (not one global toggle) so a student can double check what
 *     they typed without exposing all three fields at once.
 *   - Clear inline password rules (min 6 characters) shown up front
 *     rather than discovered only after a failed submit.
 */
require_once __DIR__ . '/../includes/student-session.php';

$pageTitle = 'My Profile';
$activeNav = 'profile';

require_once __DIR__ . '/includes/student-sidebar.php';
?>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="pr-header">
  <div>
    <div class="pr-title"><i class="fas fa-user"></i> My Profile</div>
    <div class="pr-sub">View your information and manage your account</div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     LOADING / ERROR STATES
     ═══════════════════════════════════════════════════════════════ -->
<div id="prLoading" class="card">
  <div class="empty-state">
    <i class="fas fa-spinner fa-spin"></i>
    <p>Loading your profile…</p>
  </div>
</div>

<div id="prError" class="alert alert-danger" style="display:none"></div>

<!-- ═══════════════════════════════════════════════════════════════
     MAIN PROFILE VIEW (hidden until data loads)
     ═══════════════════════════════════════════════════════════════ -->
<div id="prContent" style="display:none">

  <!-- ── Identity banner ─────────────────────────────────────────── -->
  <div class="pr-id-banner">
    <img src="<?= h($avatarUrl) ?>" alt="My Photo" class="pr-id-photo">
    <div class="pr-id-text">
      <div class="pr-id-name" id="prName">—</div>
      <div class="pr-id-meta" id="prMeta">—</div>
      <div class="pr-id-badges" id="prBadges"></div>
    </div>
    <button type="button" class="btn btn-outline pr-change-pwd-btn" id="prOpenPwdModal">
      <i class="fas fa-key"></i> Change Password
    </button>
  </div>

  <!-- ── Info card grid ──────────────────────────────────────────── -->
  <div class="pr-info-grid">

    <div class="card pr-info-card">
      <div class="card-header">
        <div class="card-title"><i class="fas fa-id-card"></i> Personal Information</div>
      </div>
      <div class="card-body">
        <div class="pr-field"><span class="pr-field-label">Full Name</span><span class="pr-field-value" id="fName">—</span></div>
        <div class="pr-field"><span class="pr-field-label">Student ID</span><span class="pr-field-value" id="fStudentId">—</span></div>
        <div class="pr-field"><span class="pr-field-label">Date of Birth</span><span class="pr-field-value" id="fDob">—</span></div>
        <div class="pr-field"><span class="pr-field-label">SSC Batch</span><span class="pr-field-value" id="fSscBatch">—</span></div>
      </div>
    </div>

    <div class="card pr-info-card">
      <div class="card-header">
        <div class="card-title"><i class="fas fa-graduation-cap"></i> Academic Information</div>
      </div>
      <div class="card-body">
        <div class="pr-field"><span class="pr-field-label">Current Class</span><span class="pr-field-value" id="fClass">—</span></div>
        <div class="pr-field"><span class="pr-field-label">Group</span><span class="pr-field-value" id="fGroup">—</span></div>
        <div class="pr-field"><span class="pr-field-label">Session</span><span class="pr-field-value" id="fSession">—</span></div>
        <div class="pr-field"><span class="pr-field-label">Roll Number</span><span class="pr-field-value" id="fRoll">—</span></div>
      </div>
    </div>

    <div class="card pr-info-card">
      <div class="card-header">
        <div class="card-title"><i class="fas fa-phone"></i> Contact Information</div>
      </div>
      <div class="card-body">
        <div class="pr-field"><span class="pr-field-label">Phone Number</span><span class="pr-field-value" id="fPhone">—</span></div>
        <div class="pr-field"><span class="pr-field-label">2nd Phone Number</span><span class="pr-field-value" id="fPhone2">—</span></div>
        <div class="pr-field"><span class="pr-field-label">Email Address</span><span class="pr-field-value" id="fEmail">—</span></div>
        <div class="pr-field pr-field-full"><span class="pr-field-label">Address</span><span class="pr-field-value" id="fAddress">—</span></div>
      </div>
    </div>

    <div class="card pr-info-card">
      <div class="card-header">
        <div class="card-title"><i class="fas fa-people-roof"></i> Family Information</div>
      </div>
      <div class="card-body">
        <div class="pr-field"><span class="pr-field-label">Father's Name</span><span class="pr-field-value" id="fFather">—</span></div>
        <div class="pr-field"><span class="pr-field-label">Mother's Name</span><span class="pr-field-value" id="fMother">—</span></div>
      </div>
    </div>

  </div>

  <!-- ── Login info footer note ──────────────────────────────────── -->
  <div class="pr-login-note" id="prLoginNote"></div>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     CHANGE PASSWORD MODAL
     ═══════════════════════════════════════════════════════════════ -->
<div class="ems-modal-overlay" id="pwdModal" role="dialog" aria-modal="true" aria-labelledby="pwdModalTitle">
  <div class="ems-modal pr-pwd-modal">
    <div class="ems-modal-icon pr-pwd-icon"><i class="fas fa-key"></i></div>
    <div class="ems-modal-title" id="pwdModalTitle">Change Password</div>
    <div class="ems-modal-text">Enter your current password and choose a new one.</div>

    <form id="pwdForm" class="pr-pwd-form" novalidate>

      <div class="pr-pwd-alert" id="pwdAlert" style="display:none"></div>

      <div class="pr-pwd-group">
        <label class="pr-pwd-label" for="pwdCurrent">Current Password</label>
        <div class="pr-pwd-input-wrap">
          <input type="password" id="pwdCurrent" class="pr-pwd-input" autocomplete="current-password" placeholder="Enter current password">
          <button type="button" class="pr-pwd-toggle" data-target="pwdCurrent" aria-label="Show password"><i class="fas fa-eye"></i></button>
        </div>
      </div>

      <div class="pr-pwd-group">
        <label class="pr-pwd-label" for="pwdNew">New Password</label>
        <div class="pr-pwd-input-wrap">
          <input type="password" id="pwdNew" class="pr-pwd-input" autocomplete="new-password" placeholder="At least 6 characters">
          <button type="button" class="pr-pwd-toggle" data-target="pwdNew" aria-label="Show password"><i class="fas fa-eye"></i></button>
        </div>
        <div class="pr-pwd-hint"><i class="fas fa-circle-info"></i> Must be at least 6 characters</div>
      </div>

      <div class="pr-pwd-group">
        <label class="pr-pwd-label" for="pwdConfirm">Confirm New Password</label>
        <div class="pr-pwd-input-wrap">
          <input type="password" id="pwdConfirm" class="pr-pwd-input" autocomplete="new-password" placeholder="Re-enter new password">
          <button type="button" class="pr-pwd-toggle" data-target="pwdConfirm" aria-label="Show password"><i class="fas fa-eye"></i></button>
        </div>
      </div>

      <div class="ems-modal-actions">
        <button type="button" class="btn btn-outline" id="pwdCancelBtn">Cancel</button>
        <button type="submit" class="btn btn-primary" id="pwdSubmitBtn">
          <i class="fas fa-check"></i> Update Password
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE-SPECIFIC STYLES
     ═══════════════════════════════════════════════════════════════ -->
<style>
  /* ── Page header ─────────────────────────────────────────────── */
  .pr-header { margin-bottom: 20px; }
  .pr-title {
    font-size: 20px;
    font-weight: 800;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -.02em;
  }
  .pr-title i { color: var(--primary); font-size: 19px; }
  .pr-sub { font-size: 13.5px; color: var(--text-muted); margin-top: 5px; font-weight: 500; }

  /* ── Identity banner ──────────────────────────────────────────── */
  .pr-id-banner {
    background: linear-gradient(120deg, var(--primary) 0%, var(--accent) 100%);
    border-radius: var(--r-lg);
    padding: 26px 28px;
    margin-bottom: 22px;
    display: flex;
    align-items: center;
    gap: 20px;
    color: #fff;
    flex-wrap: wrap;
    position: relative;
    overflow: hidden;
  }
  .pr-id-banner::before {
    content: '';
    position: absolute;
    top: -50px; right: -40px;
    width: 180px; height: 180px;
    border-radius: 50%;
    background: rgba(255,255,255,.08);
  }
  .pr-id-photo {
    width: 80px; height: 80px;
    border-radius: 50%;
    object-fit: cover;
    object-position: center top;
    border: 3px solid rgba(255,255,255,.4);
    flex-shrink: 0;
    background: rgba(255,255,255,.15);
    position: relative; z-index: 1;
  }
  .pr-id-text { flex: 1; min-width: 180px; position: relative; z-index: 1; }
  .pr-id-name { font-size: 20px; font-weight: 800; letter-spacing: -.01em; }
  .pr-id-meta { font-size: 13.5px; color: rgba(255,255,255,.88); margin-top: 4px; }
  .pr-id-badges { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
  .pr-id-badge {
    background: rgba(255,255,255,.2);
    padding: 4px 11px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 700;
  }
  .pr-change-pwd-btn {
    position: relative; z-index: 1;
    background: rgba(255,255,255,.15);
    border-color: rgba(255,255,255,.35);
    color: #fff;
  }
  .pr-change-pwd-btn:hover { background: rgba(255,255,255,.25); border-color: rgba(255,255,255,.5); color: #fff; }

  /* ── Info card grid ───────────────────────────────────────────── */
  .pr-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
  }
  .pr-info-card { margin-bottom: 0; }

  .pr-field {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    padding: 11px 0;
    border-bottom: 1px solid var(--border-light);
  }
  .pr-field:last-child { border-bottom: none; padding-bottom: 0; }
  .pr-field:first-child { padding-top: 0; }
  .pr-field-full { flex-direction: column; align-items: flex-start; gap: 4px; }

  .pr-field-label {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-muted);
    flex-shrink: 0;
  }
  .pr-field-value {
    font-size: 14px;
    font-weight: 700;
    color: var(--text);
    text-align: right;
  }
  .pr-field-full .pr-field-value { text-align: left; font-weight: 600; line-height: 1.5; }
  .pr-field-value.is-empty { color: var(--text-light); font-weight: 500; font-style: italic; }

  /* ── Login info note ───────────────────────────────────────────── */
  .pr-login-note {
    margin-top: 18px;
    font-size: 12px;
    color: var(--text-muted);
    text-align: center;
  }

  /* ── Password modal — extends .ems-modal with a form ─────────── */
  .pr-pwd-modal { max-width: 420px; text-align: left; }
  .pr-pwd-modal .ems-modal-icon { background: var(--primary-xlight); color: var(--primary); }
  .pr-pwd-modal .ems-modal-title { text-align: center; }
  .pr-pwd-modal .ems-modal-text { text-align: center; }

  .pr-pwd-form { padding: 4px 24px 24px; }

  .pr-pwd-alert {
    padding: 11px 14px;
    border-radius: var(--r-sm);
    font-size: 12.5px;
    font-weight: 600;
    margin-bottom: 16px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
  }
  .pr-pwd-alert.is-error   { background: var(--red-bg);   color: #7f1d1d; border: 1px solid var(--red-border); }
  .pr-pwd-alert.is-success { background: var(--green-bg); color: #064e3b; border: 1px solid var(--green-border); }

  .pr-pwd-group { margin-bottom: 16px; }
  .pr-pwd-label {
    display: block;
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text-sub);
    margin-bottom: 6px;
  }
  .pr-pwd-input-wrap { position: relative; }
  .pr-pwd-input {
    width: 100%;
    height: 44px;
    padding: 0 44px 0 14px;
    border: 1.5px solid var(--border);
    border-radius: var(--r-sm);
    font-family: inherit;
    font-size: 14px;
    color: var(--text);
    outline: none;
    transition: border-color var(--t), box-shadow var(--t);
  }
  .pr-pwd-input:focus { border-color: var(--border-focus); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
  .pr-pwd-input.is-invalid { border-color: var(--red); }

  .pr-pwd-toggle {
    position: absolute;
    right: 4px; top: 4px;
    width: 36px; height: 36px;
    border: none;
    background: transparent;
    color: var(--text-muted);
    border-radius: var(--r-sm);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: background var(--t);
  }
  .pr-pwd-toggle:hover { background: var(--bg); color: var(--primary); }

  .pr-pwd-hint {
    font-size: 11.5px;
    color: var(--text-muted);
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: 5px;
  }

  .pr-pwd-form .ems-modal-actions { padding: 6px 0 0; }

  /* ── Responsive ───────────────────────────────────────────────── */
  @media (max-width: 900px) {
    .pr-info-grid { grid-template-columns: 1fr; }
  }

  @media (max-width: 600px) {
    .pr-id-banner { flex-direction: column; align-items: center; text-align: center; }
    .pr-id-text { text-align: center; min-width: 0; width: 100%; }
    .pr-id-badges { justify-content: center; }
    .pr-change-pwd-btn { width: 100%; }
  }

  @media (max-width: 480px) {
    .pr-title { font-size: 18px; }
    .pr-id-banner { padding: 20px; }
    .pr-id-photo { width: 68px; height: 68px; }
    .pr-id-name { font-size: 17px; }
    .pr-field { flex-direction: column; align-items: flex-start; gap: 3px; }
    .pr-field-value { text-align: left; }
    .pr-pwd-form { padding: 4px 18px 20px; }
  }
</style>

<!-- CSRF token for AJAX requests (matches admin panel convention) -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<?php
// ── Page-specific JS — passed to the footer's $pageJs slot ────────
$pageJs = <<<'JS'
(function () {
  "use strict";

  const AJAX_URL = "profile-ajax.php";
  function getToken() { return document.getElementById("csrfToken")?.value || ""; }
  let csrfToken = getToken();

  const $ = (id) => document.getElementById(id);
  const escapeHtml = (str) => String(str ?? "").replace(/[&<>"']/g, (c) => ({
    "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;"
  }[c]));

  async function post(action, extra) {
    const body = new URLSearchParams({ action, csrf_token: csrfToken, ...(extra || {}) });
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
    ["prLoading","prError","prContent"].forEach(elId => {
      const el = $(elId);
      if (el) el.style.display = (elId === id) ? "" : "none";
    });
  }

  function showError(msg) {
    const el = $("prError");
    el.textContent = msg;
    showOnly("prError");
  }

  function fmtDate(d) {
    if (!d) return null;
    return new Date(d + "T00:00:00").toLocaleDateString("en-GB", { day:"numeric", month:"long", year:"numeric" });
  }

  function setField(id, value, emptyText) {
    const el = $(id);
    if (value === null || value === undefined || value === "") {
      el.textContent = emptyText || "Not provided";
      el.classList.add("is-empty");
    } else {
      el.textContent = value;
      el.classList.remove("is-empty");
    }
  }

  // ── Load profile ────────────────────────────────────────────────
  async function loadProfile() {
    showOnly("prLoading");
    const data = await post("fetch_profile", {});
    if (!data) { showError("Could not reach the server. Please check your connection."); return; }
    if (!data.ok) { showError(data.msg || "Something went wrong."); return; }

    renderProfile(data.profile, data.enrollment);
    showOnly("prContent");
  }

  function renderProfile(p, enroll) {
    $("prName").textContent = p.full_name;
    $("prMeta").textContent = "Student ID: " + p.student_id;

    const badges = [];
    if (enroll) {
      badges.push(enroll.class_label);
      if (enroll.group_name) badges.push(enroll.group_name);
      badges.push("Session " + enroll.session_year);
      if (enroll.status !== "active") badges.push("Inactive Enrollment");
    }
    $("prBadges").innerHTML = badges.map(b => '<span class="pr-id-badge">' + escapeHtml(b) + '</span>').join("");

    setField("fName", p.full_name);
    setField("fStudentId", p.student_id);
    setField("fDob", fmtDate(p.date_of_birth));
    setField("fSscBatch", p.ssc_batch);

    if (enroll) {
      setField("fClass", enroll.class_label);
      setField("fGroup", enroll.group_name, "No group (Class 6–8)");
      setField("fSession", enroll.session_year ? "Session " + enroll.session_year : null);
      setField("fRoll", enroll.roll_number);
    } else {
      setField("fClass", null, "No class enrollment on record");
      setField("fGroup", null);
      setField("fSession", null);
      setField("fRoll", null);
    }

    setField("fPhone", p.phone);
    setField("fPhone2", p.phone2);
    setField("fEmail", p.email);
    setField("fAddress", p.address);
    setField("fFather", p.father_name);
    setField("fMother", p.mother_name);

    const loginNote = $("prLoginNote");
    if (p.last_login) {
      const dt = new Date(p.last_login.replace(" ", "T"));
      loginNote.textContent = "Last login: " + dt.toLocaleDateString("en-GB", { day:"numeric", month:"short", year:"numeric" }) +
        " at " + dt.toLocaleTimeString("en-GB", { hour:"2-digit", minute:"2-digit" });
    } else {
      loginNote.textContent = "";
    }
  }

  // ── Password modal open/close ───────────────────────────────────
  const pwdModal  = $("pwdModal");
  const pwdForm   = $("pwdForm");
  const pwdAlert  = $("pwdAlert");
  const pwdSubmit = $("pwdSubmitBtn");

  function openPwdModal() {
    pwdForm.reset();
    pwdAlert.style.display = "none";
    document.querySelectorAll(".pr-pwd-input").forEach(i => i.classList.remove("is-invalid"));
    pwdModal.classList.add("show");
    setTimeout(() => $("pwdCurrent").focus(), 50);
  }
  function closePwdModal() {
    pwdModal.classList.remove("show");
  }

  $("prOpenPwdModal").addEventListener("click", openPwdModal);
  $("pwdCancelBtn").addEventListener("click", closePwdModal);
  pwdModal.addEventListener("click", (e) => { if (e.target === pwdModal) closePwdModal(); });
  document.addEventListener("keydown", (e) => { if (e.key === "Escape" && pwdModal.classList.contains("show")) closePwdModal(); });

  // ── Password show/hide toggles ──────────────────────────────────
  document.querySelectorAll(".pr-pwd-toggle").forEach(btn => {
    btn.addEventListener("click", () => {
      const input = $(btn.dataset.target);
      const isText = input.type === "text";
      input.type = isText ? "password" : "text";
      btn.innerHTML = isText ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
    });
  });

  function showPwdAlert(msg, isSuccess) {
    pwdAlert.style.display = "flex";
    pwdAlert.className = "pr-pwd-alert " + (isSuccess ? "is-success" : "is-error");
    pwdAlert.innerHTML = '<i class="fas ' + (isSuccess ? "fa-circle-check" : "fa-circle-exclamation") + '"></i><span>' + escapeHtml(msg) + '</span>';
  }

  // ── Submit password change ──────────────────────────────────────
  pwdForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const current = $("pwdCurrent").value;
    const next    = $("pwdNew").value;
    const confirm = $("pwdConfirm").value;

    document.querySelectorAll(".pr-pwd-input").forEach(i => i.classList.remove("is-invalid"));

    if (!current) { showPwdAlert("Please enter your current password.", false); $("pwdCurrent").classList.add("is-invalid"); return; }
    if (next.length < 6) { showPwdAlert("New password must be at least 6 characters.", false); $("pwdNew").classList.add("is-invalid"); return; }
    if (next !== confirm) { showPwdAlert("New password and confirmation do not match.", false); $("pwdConfirm").classList.add("is-invalid"); return; }
    if (next === current) { showPwdAlert("New password must be different from your current password.", false); $("pwdNew").classList.add("is-invalid"); return; }

    pwdSubmit.disabled = true;
    pwdSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating…';

    const data = await post("change_password", {
      current_password: current,
      new_password: next,
      confirm_password: confirm,
    });

    pwdSubmit.disabled = false;
    pwdSubmit.innerHTML = '<i class="fas fa-check"></i> Update Password';

    if (!data) { showPwdAlert("Could not reach the server. Please check your connection.", false); return; }

    if (!data.ok) {
      showPwdAlert(data.msg || "Something went wrong.", false);
      if (/current password/i.test(data.msg || "")) $("pwdCurrent").classList.add("is-invalid");
      return;
    }

    showPwdAlert(data.msg || "Password updated successfully.", true);
    pwdForm.reset();
    setTimeout(closePwdModal, 1600);
  });

  loadProfile();
})();
JS;

require_once __DIR__ . '/includes/student-footer.php';
?>
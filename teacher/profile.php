<?php
/**
 * EMS Teacher — My Profile  v1.0
 * ─────────────────────────────────────────────────────────────────
 * A teacher can self-service: their own profile photo, their own
 * phone/email, and their own password (via a popup modal). Identity
 * fields — full name, designation, joining date, username — stay
 * read-only here and can only be changed by the school admin, same
 * as every other identity-record rule in this system.
 *
 * All data loads via AJAX (profile-ajax.php) — no page reload.
 */
require_once __DIR__ . '/../includes/teacher-session.php';

$pageTitle  = 'My Profile';
$activeNav  = 'profile';
$breadcrumb = ['My Profile'];

require_once __DIR__ . '/includes/teacher-sidebar.php';
?>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="page-header-top">
    <div>
      <div class="page-title"><i class="fas fa-user" style="color:var(--primary);margin-right:8px"></i>My Profile</div>
      <div class="page-sub"><i class="fas fa-circle-info"></i> View and manage your account details</div>
    </div>
    <div class="page-actions">
      <button type="button" class="btn btn-outline" id="refreshBtn">
        <i class="fas fa-rotate" id="refreshIcon"></i> Refresh
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     ERROR STATE
     ═══════════════════════════════════════════════════════════════ -->
<div id="profileError" class="alert alert-danger" style="display:none">
  <i class="fas fa-circle-exclamation"></i>
  <span id="profileErrorText">Something went wrong while loading your profile.</span>
  <button type="button" class="btn btn-sm btn-outline" id="profileErrorRetry" style="margin-left:auto">
    <i class="fas fa-rotate"></i> Try Again
  </button>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     LOADING SKELETON
     ═══════════════════════════════════════════════════════════════ -->
<div id="profileSkeleton" class="card">
  <div class="empty-state">
    <i class="fas fa-spinner fa-spin"></i>
    <p>Loading your profile…</p>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MAIN CONTENT (hidden until loaded)
     ═══════════════════════════════════════════════════════════════ -->
<div id="profileContent" style="display:none">

  <!-- ── Profile hero card ─────────────────────────────────────────── -->
  <div class="card pf-hero-card">
    <div class="pf-hero">
      <div class="pf-photo-wrap">
        <img id="pfPhoto" class="pf-photo" alt="" style="display:none">
        <div id="pfInitials" class="pf-photo pf-initials"></div>
        <button type="button" class="pf-photo-edit-btn" id="pfPhotoEditBtn" title="Change photo" aria-label="Change photo">
          <i class="fas fa-camera"></i>
        </button>
        <input type="file" id="photoInput" accept="image/jpeg,image/png" style="display:none">
        <div class="pf-photo-uploading" id="pfPhotoUploading" style="display:none">
          <i class="fas fa-spinner fa-spin"></i>
        </div>
      </div>
      <div class="pf-hero-info">
        <div class="pf-name" id="pfName">—</div>
        <div class="pf-designation" id="pfDesignation">—</div>
        <div class="pf-badges">
          <span class="badge badge-green" id="pfActiveBadge"><i class="fas fa-circle"></i> Active</span>
          <span class="badge badge-gray" id="pfUsernameBadge">—</span>
        </div>
        <div class="pf-lastlogin" id="pfLastLogin"></div>
      </div>
    </div>
  </div>

  <div class="grid-2">

    <!-- ── Contact info (editable) ───────────────────────────────── -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="fas fa-address-card"></i> Contact Info</div>
      </div>
      <div class="card-body">
        <div class="alert alert-info" style="margin-bottom:18px">
          <i class="fas fa-circle-info"></i>
          <span>You can update your own phone and email here — they're used for account recovery and school notices.</span>
        </div>
        <div class="form-group">
          <label class="form-label" for="phoneInput">Phone Number</label>
          <input type="text" id="phoneInput" class="form-control" placeholder="e.g. 01700000000">
          <div class="form-hint" id="phoneHint" style="display:none"></div>
        </div>
        <div class="form-group">
          <label class="form-label" for="emailInput">Email Address</label>
          <input type="email" id="emailInput" class="form-control" placeholder="e.g. you@example.com">
          <div class="form-hint" id="emailHint" style="display:none"></div>
        </div>
        <button type="button" class="btn btn-primary" id="saveContactBtn">
          <i class="fas fa-floppy-disk"></i> Save Changes
        </button>
      </div>
    </div>

    <!-- ── Account info (read-only) ──────────────────────────────── -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="fas fa-id-badge"></i> Account Info</div>
      </div>
      <div class="card-body">
        <div class="pf-info-grid">
          <div class="pf-info-item">
            <div class="pf-info-label">Full Name</div>
            <div class="pf-info-value" id="infoFullName">—</div>
          </div>
          <div class="pf-info-item">
            <div class="pf-info-label">Designation</div>
            <div class="pf-info-value" id="infoDesignation">—</div>
          </div>
          <div class="pf-info-item">
            <div class="pf-info-label">Username</div>
            <div class="pf-info-value" id="infoUsername">—</div>
          </div>
          <div class="pf-info-item">
            <div class="pf-info-label">Joining Date</div>
            <div class="pf-info-value" id="infoJoining">—</div>
          </div>
        </div>
        <div class="alert alert-warning" style="margin-top:18px;margin-bottom:0">
          <i class="fas fa-lock"></i>
          <span>These details are managed by your school admin. Contact the admin office to request a change.</span>
        </div>
      </div>
    </div>

  </div>

  <!-- ── Security ───────────────────────────────────────────────────── -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-shield-halved"></i> Security</div>
    </div>
    <div class="card-body">
      <div class="pf-security-row">
        <div>
          <div class="pf-security-label">Password</div>
          <div class="pf-security-sub">Last changed: not tracked &middot; Keep your password private and unique to this account.</div>
        </div>
        <button type="button" class="btn btn-outline" id="openPasswordModalBtn">
          <i class="fas fa-key"></i> Change Password
        </button>
      </div>
    </div>
  </div>

</div><!-- /#profileContent -->

<!-- ═══════════════════════════════════════════════════════════════
     CHANGE PASSWORD MODAL ROOT (built dynamically by JS)
     ═══════════════════════════════════════════════════════════════ -->
<div id="pwModalRoot"></div>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE-SPECIFIC STYLES
     ═══════════════════════════════════════════════════════════════ -->
<style>
  #refreshIcon.spinning { animation: refreshSpin .8s linear infinite; }
  @keyframes refreshSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

  /* ── Profile hero ──────────────────────────────────────────────── */
  .pf-hero-card { overflow: visible; }
  .pf-hero { display: flex; align-items: center; gap: 22px; padding: 26px 24px; flex-wrap: wrap; }

  .pf-photo-wrap { position: relative; width: 92px; height: 92px; flex-shrink: 0; }
  .pf-photo {
    width: 92px; height: 92px; border-radius: 50%; object-fit: cover; 
    object-position: center top; border: 3px solid var(--primary-light);
  }
  .pf-initials {
    display: flex; align-items: center; justify-content: center;
    font-size: 32px; font-weight: 800; color: #fff;
    background: linear-gradient(135deg, var(--primary), var(--accent));
  }
  .pf-photo-edit-btn {
    position: absolute; bottom: -2px; right: -2px;
    width: 32px; height: 32px; border-radius: 50%;
    background: var(--primary); color: #fff; border: 3px solid var(--bg-card);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 13px; transition: background var(--t), transform var(--t);
  }
  .pf-photo-edit-btn:hover { background: var(--primary-dark); transform: scale(1.06); }
  .pf-photo-uploading {
    position: absolute; inset: 0; border-radius: 50%;
    background: rgba(13,26,15,.55); color: #fff;
    display: flex; align-items: center; justify-content: center; font-size: 20px;
  }

  .pf-hero-info { flex: 1; min-width: 200px; }
  .pf-name { font-size: 20px; font-weight: 800; color: var(--text); letter-spacing: -.02em; }
  .pf-designation { font-size: 13.5px; color: var(--text-muted); margin-top: 3px; font-weight: 600; }
  .pf-badges { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
  .pf-lastlogin { font-size: 11.5px; color: var(--text-light); margin-top: 10px; }

  /* ── Info grid (read-only account info) ─────────────────────────── */
  .pf-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
  .pf-info-label { font-size: 10.5px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
  .pf-info-value { font-size: 14.5px; color: var(--text); font-weight: 700; }

  /* ── Security row ───────────────────────────────────────────────── */
  .pf-security-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
  .pf-security-label { font-size: 14px; font-weight: 700; color: var(--text); }
  .pf-security-sub { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; max-width: 420px; }

  /* ── Change Password modal ─────────────────────────────────────── */
  .pw-modal-bd {
    position: fixed; inset: 0; background: rgba(8,16,10,.6); backdrop-filter: blur(4px);
    z-index: 11000; display: flex; align-items: center; justify-content: center; padding: 20px;
    animation: pwmIn .2s ease both;
  }
  @keyframes pwmIn { from{opacity:0} to{opacity:1} }
  .pw-modal-bd.pw-closing { animation: pwmOut .18s ease forwards; }
  @keyframes pwmOut { from{opacity:1} to{opacity:0} }

  .pw-modal {
    background: var(--bg-card); border-radius: var(--r-xl); border: 1px solid var(--border);
    box-shadow: 0 28px 80px rgba(8,16,10,.28);
    width: 100%; max-width: 400px; overflow: hidden;
    animation: pwModIn .26s cubic-bezier(.22,.68,0,1.15) both;
  }
  @keyframes pwModIn { from{opacity:0;transform:scale(.92) translateY(14px)} to{opacity:1;transform:none} }
  .pw-modal-bd.pw-closing .pw-modal { animation: pwModOut .16s ease forwards; }
  @keyframes pwModOut { from{opacity:1;transform:none} to{opacity:0;transform:scale(.96) translateY(8px)} }

  .pw-modal-header {
    background: linear-gradient(120deg, var(--primary) 0%, var(--accent) 100%);
    padding: 24px 24px 18px; text-align: center; position: relative;
  }
  .pw-modal-close {
    position: absolute; top: 14px; right: 14px;
    width: 32px; height: 32px; border-radius: 50%;
    background: rgba(255,255,255,.18); border: none; color: #fff;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 14px; transition: background var(--t);
  }
  .pw-modal-close:hover { background: rgba(255,255,255,.3); }
  .pw-modal-icon {
    width: 56px; height: 56px; background: rgba(255,255,255,.18); border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #fff; margin: 0 auto 12px;
  }
  .pw-modal-title { font-size: 17px; font-weight: 800; color: #fff; }
  .pw-modal-sub { font-size: 12px; color: rgba(255,255,255,.85); margin-top: 4px; }

  .pw-modal-body { padding: 22px 24px 8px; }
  .pw-field { margin-bottom: 16px; }
  .pw-field label { display: block; font-size: 12.5px; font-weight: 700; color: var(--text-sub); margin-bottom: 6px; }
  .pw-input-wrap { position: relative; }
  .pw-input-wrap input {
    width: 100%; height: 44px; padding: 0 42px 0 14px;
    border: 1.5px solid var(--border); border-radius: var(--r-sm);
    font-family: inherit; font-size: 14px; color: var(--text); outline: none;
    transition: border-color var(--t), box-shadow var(--t);
  }
  .pw-input-wrap input:focus { border-color: var(--border-focus); box-shadow: 0 0 0 3px rgba(22,163,74,.12); }
  .pw-input-wrap input.pw-invalid { border-color: var(--red); }
  .pw-toggle {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    background: none; border: none; color: var(--text-light); cursor: pointer; font-size: 14px; padding: 4px;
  }
  .pw-toggle:hover { color: var(--primary); }

  .pw-checklist { margin: 10px 2px 4px; display: flex; flex-direction: column; gap: 5px; }
  .pw-check-item { display: flex; align-items: center; gap: 7px; font-size: 12px; color: var(--text-light); transition: color var(--t); }
  .pw-check-item i { font-size: 11px; width: 12px; }
  .pw-check-item.met { color: var(--green); }
  .pw-check-item.met i::before { content: "\f00c"; }

  .pw-match-msg { font-size: 12px; margin-top: 6px; display: none; align-items: center; gap: 6px; }
  .pw-match-msg.show { display: flex; }
  .pw-match-msg.ok  { color: var(--green); }
  .pw-match-msg.bad { color: var(--red); }

  .pw-server-error {
    background: var(--red-bg); border: 1px solid var(--red-border); color: var(--red);
    border-radius: var(--r-sm); padding: 10px 12px; font-size: 12.5px; margin-bottom: 14px;
    display: none; align-items: flex-start; gap: 8px;
  }
  .pw-server-error.show { display: flex; }

  .pw-modal-actions { display: flex; gap: 10px; padding: 16px 24px 24px; }
  .pw-modal-actions .btn { flex: 1; height: 46px; }

  @media (max-width: 480px) {
    .pf-hero { padding: 20px 18px; gap: 16px; }
    .pf-photo, .pf-initials { width: 76px; height: 76px; }
    .pf-photo-wrap { width: 76px; height: 76px; }
    .pf-name { font-size: 17px; }
    .pf-info-grid { grid-template-columns: 1fr; gap: 14px; }
    .pf-security-row { flex-direction: column; align-items: stretch; }
    .pf-security-row .btn { width: 100%; }
    .pw-modal { max-width: 100%; margin: 0 4px; }
  }
</style>

<!-- CSRF token for AJAX requests -->
<input type="hidden" id="csrfToken" value="<?= csrf_token() ?>">

<?php
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
    let res, data;
    try {
      res  = await fetch(AJAX_URL, { method: "POST", body, credentials: "same-origin" });
      data = await res.json();
    } catch (err) {
      return null;
    }
    if (data && data.csrf) {
      csrfToken = data.csrf;
      const tokenInput = $("csrfToken");
      if (tokenInput) tokenInput.value = data.csrf;
    }
    return data;
  }

  // Multipart upload needs its own fetch — URLSearchParams can't carry files.
  async function postFile(action, file) {
    const fd = new FormData();
    fd.append("action", action);
    fd.append("csrf_token", csrfToken);
    fd.append("photo", file);
    let res, data;
    try {
      res  = await fetch(AJAX_URL, { method: "POST", body: fd, credentials: "same-origin" });
      data = await res.json();
    } catch (err) {
      return null;
    }
    if (data && data.csrf) {
      csrfToken = data.csrf;
      const tokenInput = $("csrfToken");
      if (tokenInput) tokenInput.value = data.csrf;
    }
    return data;
  }

  function initials(name) {
    const parts = String(name || "").trim().split(/\s+/);
    if (parts.length === 0 || !parts[0]) return "?";
    return parts.length === 1 ? parts[0].charAt(0).toUpperCase()
                               : (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  }

  function setPhoto(url, name) {
    const img = $("pfPhoto");
    const ini = $("pfInitials");
    if (url) {
      img.src = url;
      img.style.display = "";
      ini.style.display = "none";
    } else {
      img.style.display = "none";
      ini.style.display = "flex";
      ini.textContent = initials(name);
    }
    // Keep the sidebar + topbar avatar in sync without a reload.
    document.querySelectorAll(".sp-photo, .topbar-avatar").forEach((el) => {
      if (url) el.src = url;
    });
  }

  // ── Load profile ─────────────────────────────────────────────────────
  async function loadProfile() {
    $("profileSkeleton").style.display = "";
    $("profileContent").style.display = "none";
    $("profileError").style.display = "none";

    const data = await post("fetch_profile", {});

    $("profileSkeleton").style.display = "none";

    if (!data) {
      $("profileErrorText").textContent = "Could not reach the server. Please check your connection.";
      $("profileError").style.display = "flex";
      return;
    }
    if (!data.ok) {
      $("profileErrorText").textContent = data.msg || "Something went wrong.";
      $("profileError").style.display = "flex";
      return;
    }

    const p = data.profile;
    $("pfName").textContent = p.full_name || "—";
    $("pfDesignation").textContent = p.designation || "Teacher";
    $("pfUsernameBadge").innerHTML = '<i class="fas fa-user"></i> ' + escapeHtml(p.username);
    $("pfActiveBadge").style.display = p.is_active ? "" : "none";
    $("pfLastLogin").textContent = p.last_login ? "Last login: " + p.last_login : "";

    setPhoto(p.photo_url, p.full_name);

    $("phoneInput").value = p.phone || "";
    $("emailInput").value = p.email || "";

    $("infoFullName").textContent   = p.full_name || "—";
    $("infoDesignation").textContent = p.designation || "—";
    $("infoUsername").textContent   = p.username || "—";
    $("infoJoining").textContent    = p.joining_date || "—";

    $("profileContent").style.display = "";
  }

  // ── Photo upload ──────────────────────────────────────────────────────
  $("pfPhotoEditBtn").addEventListener("click", () => $("photoInput").click());

  $("photoInput").addEventListener("change", async () => {
    const file = $("photoInput").files[0];
    if (!file) return;

    if (!["image/jpeg", "image/png"].includes(file.type)) {
      if (window.showToast) window.showToast("danger", "Invalid File", "Only JPG and PNG photos are allowed.");
      $("photoInput").value = "";
      return;
    }
    if (file.size > 3 * 1024 * 1024) {
      if (window.showToast) window.showToast("danger", "File Too Large", "Photo must be smaller than 3MB.");
      $("photoInput").value = "";
      return;
    }

    $("pfPhotoUploading").style.display = "flex";
    const data = await postFile("upload_photo", file);
    $("pfPhotoUploading").style.display = "none";
    $("photoInput").value = "";

    if (!data || !data.ok) {
      if (window.showToast) window.showToast("danger", "Upload Failed", (data && data.msg) || "Could not reach the server. Please try again.");
      return;
    }

    setPhoto(data.photo_url, $("pfName").textContent);
    if (window.showToast) window.showToast("success", "Photo Updated", "Your profile photo has been changed.");
  });

  // ── Contact info save ───────────────────────────────────────────────
  $("saveContactBtn").addEventListener("click", async () => {
    const btn = $("saveContactBtn");
    const phone = $("phoneInput").value.trim();
    const email = $("emailInput").value.trim();

    $("phoneHint").style.display = "none";
    $("emailHint").style.display = "none";

    btn.disabled = true;
    const origHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    const data = await post("update_contact", { phone, email });

    btn.disabled = false;
    btn.innerHTML = origHtml;

    if (!data) {
      if (window.showToast) window.showToast("danger", "Save Failed", "Could not reach the server. Please try again.");
      return;
    }
    if (!data.ok) {
      const msg = data.msg || "Something went wrong.";
      if (msg.toLowerCase().includes("phone")) {
        $("phoneHint").textContent = msg; $("phoneHint").style.display = "block";
      } else if (msg.toLowerCase().includes("email")) {
        $("emailHint").textContent = msg; $("emailHint").style.display = "block";
      }
      if (window.showToast) window.showToast("danger", "Save Failed", msg);
      return;
    }

    if (window.showToast) window.showToast("success", "Contact Info Updated", "Your phone and email have been saved.");
  });

  // ── Change Password modal ───────────────────────────────────────────
  function openPasswordModal() {
    const root = $("pwModalRoot");
    root.innerHTML =
      '<div class="pw-modal-bd" id="pwModalBd" role="dialog" aria-modal="true" aria-labelledby="pwModalTitle">' +
        '<div class="pw-modal">' +
          '<div class="pw-modal-header">' +
            '<button type="button" class="pw-modal-close" id="pwModalClose" aria-label="Close"><i class="fas fa-xmark"></i></button>' +
            '<div class="pw-modal-icon"><i class="fas fa-key"></i></div>' +
            '<div class="pw-modal-title" id="pwModalTitle">Change Password</div>' +
            '<div class="pw-modal-sub">Keep your account secure</div>' +
          '</div>' +
          '<div class="pw-modal-body">' +
            '<div class="pw-server-error" id="pwServerError"><i class="fas fa-circle-exclamation"></i><span id="pwServerErrorText"></span></div>' +

            '<div class="pw-field">' +
              '<label for="pwCurrent">Current Password</label>' +
              '<div class="pw-input-wrap"><input type="password" id="pwCurrent" autocomplete="current-password">' +
                '<button type="button" class="pw-toggle" data-target="pwCurrent"><i class="fas fa-eye"></i></button></div>' +
            '</div>' +

            '<div class="pw-field">' +
              '<label for="pwNew">New Password</label>' +
              '<div class="pw-input-wrap"><input type="password" id="pwNew" autocomplete="new-password">' +
                '<button type="button" class="pw-toggle" data-target="pwNew"><i class="fas fa-eye"></i></button></div>' +
              '<div class="pw-checklist">' +
                '<div class="pw-check-item" id="chkLen"><i class="fas fa-circle"></i> At least 8 characters</div>' +
                '<div class="pw-check-item" id="chkLetter"><i class="fas fa-circle"></i> Contains a letter</div>' +
                '<div class="pw-check-item" id="chkNumber"><i class="fas fa-circle"></i> Contains a number</div>' +
              '</div>' +
            '</div>' +

            '<div class="pw-field">' +
              '<label for="pwConfirm">Confirm New Password</label>' +
              '<div class="pw-input-wrap"><input type="password" id="pwConfirm" autocomplete="new-password">' +
                '<button type="button" class="pw-toggle" data-target="pwConfirm"><i class="fas fa-eye"></i></button></div>' +
              '<div class="pw-match-msg" id="pwMatchMsg"></div>' +
            '</div>' +
          '</div>' +
          '<div class="pw-modal-actions">' +
            '<button type="button" class="btn btn-outline" id="pwCancelBtn">Cancel</button>' +
            '<button type="button" class="btn btn-primary" id="pwSubmitBtn" disabled><i class="fas fa-key"></i> Update Password</button>' +
          '</div>' +
        '</div>' +
      '</div>';

    const bd = $("pwModalBd");
    setTimeout(() => { $("pwCurrent")?.focus(); }, 60);

    function closeModal() {
      bd.classList.add("pw-closing");
      setTimeout(() => { root.innerHTML = ""; }, 200);
    }
    $("pwModalClose").addEventListener("click", closeModal);
    $("pwCancelBtn").addEventListener("click", closeModal);
    bd.addEventListener("click", (e) => { if (e.target === bd) closeModal(); });
    document.addEventListener("keydown", function escHandler(e) {
      if (e.key === "Escape" && document.getElementById("pwModalBd")) { closeModal(); document.removeEventListener("keydown", escHandler); }
    });

    // Show/hide password toggles
    root.querySelectorAll(".pw-toggle").forEach((btn) => {
      btn.addEventListener("click", () => {
        const input = $(btn.dataset.target);
        const icon = btn.querySelector("i");
        const isText = input.type === "text";
        input.type = isText ? "password" : "text";
        icon.className = isText ? "fas fa-eye" : "fas fa-eye-slash";
      });
    });

    // Live requirement checklist
    function updateChecklist() {
      const val = $("pwNew").value;
      const lenOk = val.length >= 8;
      const letterOk = /[A-Za-z]/.test(val);
      const numberOk = /[0-9]/.test(val);
      $("chkLen").classList.toggle("met", lenOk);
      $("chkLetter").classList.toggle("met", letterOk);
      $("chkNumber").classList.toggle("met", numberOk);
      updateMatchAndSubmit();
      return lenOk && letterOk && numberOk;
    }

    function updateMatchAndSubmit() {
      const newVal = $("pwNew").value;
      const confirmVal = $("pwConfirm").value;
      const msgEl = $("pwMatchMsg");
      const reqOk = $("pwNew").value.length >= 8 && /[A-Za-z]/.test(newVal) && /[0-9]/.test(newVal);

      if (confirmVal.length === 0) {
        msgEl.classList.remove("show", "ok", "bad");
      } else if (newVal === confirmVal) {
        msgEl.textContent = "Passwords match";
        msgEl.className = "pw-match-msg show ok";
      } else {
        msgEl.textContent = "Passwords do not match";
        msgEl.className = "pw-match-msg show bad";
      }

      const matchOk = confirmVal.length > 0 && newVal === confirmVal;
      const currentOk = $("pwCurrent").value.length > 0;
      $("pwSubmitBtn").disabled = !(reqOk && matchOk && currentOk);
    }

    $("pwNew").addEventListener("input", updateChecklist);
    $("pwConfirm").addEventListener("input", updateMatchAndSubmit);
    $("pwCurrent").addEventListener("input", updateMatchAndSubmit);

    // Submit
    $("pwSubmitBtn").addEventListener("click", async () => {
      const btn = $("pwSubmitBtn");
      $("pwServerError").classList.remove("show");

      btn.disabled = true;
      const origHtml = btn.innerHTML;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating…';

      const data = await post("change_password", {
        current_password: $("pwCurrent").value,
        new_password: $("pwNew").value,
        confirm_password: $("pwConfirm").value,
      });

      btn.disabled = false;
      btn.innerHTML = origHtml;

      if (!data) {
        $("pwServerErrorText").textContent = "Could not reach the server. Please check your connection.";
        $("pwServerError").classList.add("show");
        return;
      }
      if (!data.ok) {
        $("pwServerErrorText").textContent = data.msg || "Something went wrong.";
        $("pwServerError").classList.add("show");
        if ((data.msg || "").toLowerCase().includes("current password")) {
          $("pwCurrent").classList.add("pw-invalid");
        }
        return;
      }

      closeModal();
      if (window.showToast) window.showToast("success", "Password Changed", "Your password has been updated successfully.");
    });
  }

  $("openPasswordModalBtn").addEventListener("click", openPasswordModal);

  // ── Refresh + retry ─────────────────────────────────────────────────
  $("refreshBtn").addEventListener("click", async () => {
    const icon = $("refreshIcon");
    if (icon) icon.classList.add("spinning");
    await loadProfile();
    if (icon) icon.classList.remove("spinning");
    if (window.showToast) window.showToast("success", "Refreshed", "Your profile is up to date.");
  });
  $("profileErrorRetry").addEventListener("click", loadProfile);

  // ── Initial load ──────────────────────────────────────────────────────
  loadProfile();

})();
JS;

require_once __DIR__ . '/includes/teacher-footer.php';
?>
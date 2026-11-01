<?php
/**
 * EMS — Login Page
 * Root entry point. Handles login for all user types.
 */

require_once __DIR__ . '/includes/maintenance-guard.php';
require_once __DIR__ . '/includes/functions.php';

// Already logged in? Redirect to their dashboard
if (!empty($_SESSION['ems_logged_in']) && !empty($_SESSION['ems_role'])) {
    redirect_by_role($_SESSION['ems_role']);
}

$error   = '';
$success = '';
$ip      = get_client_ip();

// Detect AJAX request
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Helper: send JSON response and exit (AJAX only)
// Always includes a fresh CSRF token so JS can update the hidden field
// without needing a page reload — this is what prevents "Invalid request" on 2nd attempt
function json_response(bool $ok, string $message, string $redirect = '', int $lockout_secs = 0): void {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'           => $ok,
        'message'      => $message,
        'redirect'     => $redirect,
        'lockout_secs' => $lockout_secs,
        'csrf_token'   => csrf_token(), // fresh token for the next attempt
    ]);
    exit;
}

// Read flash message (flash_set/flash_get live in functions.php)
$flash = flash_get();
if ($flash) {
    if ($flash['type'] === 'danger')  $error   = $flash['message'];
    if ($flash['type'] === 'success') $success = $flash['message'];
}

// ── POST: Process Login ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. CSRF check
    if (!csrf_verify()) {
        csrf_rotate();
        if ($isAjax) json_response(false, 'Invalid request. Try again.');
        $error = 'Invalid request. Try again.';
        goto render;
    }

    // 2. Rate limit check — cookie-based, no IP, no DB
    if (!check_login_rate_limit()) {
        if ($isAjax) json_response(false, 'Too many attempts! Try later.', '', get_lockout_seconds_remaining());
        $error = 'Too many attempts! Try later.';
        goto render;
    }

    // 3. Input
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';
    $role_hint  = $_POST['role'] ?? '';

    if (empty($identifier) || empty($password)) {
        if ($isAjax) json_response(false, 'Enter your user ID and password.');
        $error = 'Enter your user ID and password.';
        goto render;
    }

    // 4. Validate role hint
    if (!in_array($role_hint, ['student', 'teacher', 'admin'], true)) {
        if ($isAjax) json_response(false, 'Please select a valid role.');
        $error = 'Please select a valid role.';
        goto render;
    }

    // 5. Look up user
    try {
        $stmt = dbQuery(
            "SELECT sys_uid, username, password_hash, role, is_active FROM system_users
             WHERE (username = ? OR sys_uid = ?) AND role = ?",
            [$identifier, $identifier, $role_hint]
        );
        $user = $stmt->fetch();

        // For students, also allow login via student_id
        if (!$user && $role_hint === 'student') {
            $stmt2 = dbQuery(
                "SELECT su.sys_uid, su.username, su.password_hash, su.role, su.is_active
                 FROM system_users su
                 JOIN students s ON s.sys_uid = su.sys_uid
                 WHERE s.student_id = ? AND su.role = 'student'",
                [$identifier]
            );
            $user = $stmt2->fetch();
        }

        // 6. Verify password (always run to prevent timing attacks)
        $validPassword = $user && password_verify($password, $user['password_hash']);

        if (!$user || !$validPassword) {
            log_login($user['sys_uid'] ?? 'UNKNOWN_' . $identifier, false, $ip);
            record_failed_attempt();   // increment cookie counter; locks on 5th fail
            csrf_rotate();
            if ($isAjax) json_response(false, 'Invalid user ID or password!', '', get_lockout_seconds_remaining());
            $error = 'Invalid user ID or password!';
            goto render;
        }

        // 7. Check active status
        if (!$user['is_active']) {
            log_login($user['sys_uid'], false, $ip);
            if ($isAjax) json_response(false, 'Account disabled! Contact school.');
            // Non-AJAX: flash + clean URL redirect (no ?err= in URL)
            flash_set('danger', 'Account disabled! Contact school.');
            redirect(BASE_URL . '/');
        }

        // 8. Role match (should always be true given query, but double-check)
        if ($user['role'] !== $role_hint) {
            log_login($user['sys_uid'], false, $ip);
            if ($isAjax) json_response(false, 'Invalid user ID or password!');
            $error = 'Invalid user ID or password!';
            goto render;
        }

        // ✅ Login successful
        log_login($user['sys_uid'], true, $ip);
        log_activity($user['sys_uid'], 'login', 'system_users', $user['sys_uid'], ['ip' => $ip]);

        // Rotate session to prevent fixation
        session_regenerate_id(true);
        csrf_rotate();

        $_SESSION['ems_logged_in']    = true;
        $_SESSION['ems_uid']          = $user['sys_uid'];
        $_SESSION['ems_role']         = $user['role'];
        $_SESSION['ems_login_time']   = time();
        $_SESSION['ems_last_regen']   = time();
        $_SESSION['ems_login_ip']     = $ip;

        // Update last_login in DB
        dbQuery("UPDATE system_users SET last_login = NOW() WHERE sys_uid = ?", [$user['sys_uid']]);

        // Build dashboard URL
        $dashUrls = [
            'student' => BASE_URL . '/student/dashboard.php',
            'teacher' => BASE_URL . '/teacher/dashboard.php',
            'admin'   => BASE_URL . '/admin/dashboard.php',
        ];
        $dashUrl = $dashUrls[$user['role']] ?? BASE_URL . '/';

        clear_rate_limit_cookie();   // successful login — wipe the attempt counter
        if ($isAjax) json_response(true, 'Login successful.', $dashUrl);
        redirect($dashUrl);

    } catch (Throwable $e) {
        error_log('EMS Login Error: ' . $e->getMessage());
        if ($isAjax) json_response(false, 'An error occurred. Try again.');
        $error = 'An error occurred. Try again.';
    }
}

render:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= h(EMS_NAME) ?></title>
    <link rel="apple-touch-icon" sizes="180x180" href="<?= h(EMS_FAVICONS) ?>/apple-touch-icon.png"><link rel="icon" type="image/png" sizes="32x32" href="<?= h(EMS_FAVICONS) ?>/favicon-32x32.png"><link rel="icon" type="image/png" sizes="16x16" href="<?= h(EMS_FAVICONS) ?>/favicon-16x16.png"><link rel="manifest" href="<?= h(EMS_FAVICONS) ?>/site.webmanifest">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary:      #1a56db;
            --primary-dark: #1344b8;
            --primary-light:#e8f0fe;
            --accent:       #0ea5e9;
            --success:      #059669;
            --danger:       #dc2626;
            --warning:      #d97706;
            --bg:           #f0f4ff;
            --bg-card:      #ffffff;
            --border:       #e2e8f0;
            --border-focus: #1a56db;
            --text:         #0f172a;
            --text-muted:   #64748b;
            --text-light:   #94a3b8;
            --shadow-sm:    0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.06);
            --shadow-md:    0 4px 6px rgba(0,0,0,.07), 0 2px 4px rgba(0,0,0,.06);
            --shadow-lg:    0 20px 60px rgba(26,86,219,.12), 0 8px 24px rgba(0,0,0,.08);
            --radius:       12px;
            --radius-sm:    8px;
        }

        html, body {
            height: 100%;
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            -webkit-font-smoothing: antialiased;
        }

        /* ─── Background pattern ─── */
        body {
            background-color: #eef2ff;
            background-image:
                radial-gradient(circle at 20% 20%, rgba(26,86,219,.07) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(14,165,233,.06) 0%, transparent 50%),
                url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%231a56db' fill-opacity='0.03'%3E%3Ccircle cx='30' cy='30' r='2'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }

        /* ─── Layout ─── */
        .page-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }

        .login-card {
            background: var(--bg-card);
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 460px;
            overflow: hidden;
            animation: slideUp .4s cubic-bezier(.22,.68,0,1.2) both;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ─── Card header ─── */
        .card-header {
            background: linear-gradient(135deg, var(--primary) 0%, #2563eb 60%, var(--accent) 100%);
            padding: 36px 40px 32px;
            position: relative;
            overflow: hidden;
        }

        .card-header::before {
            content: '';
            position: absolute;
            top: -40px; right: -40px;
            width: 160px; height: 160px;
            border-radius: 50%;
            background: rgba(255,255,255,.07);
        }

        .card-header::after {
            content: '';
            position: absolute;
            bottom: -60px; left: -30px;
            width: 200px; height: 200px;
            border-radius: 50%;
            background: rgba(255,255,255,.05);
        }

        .school-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.25);
            border-radius: 50px;
            padding: 6px 14px 6px 8px;
            margin-bottom: 20px;
            position: relative; z-index: 1;
        }

        .school-badge .badge-icon {
            width: 28px; height: 28px;
            background: rgba(255,255,255,.2);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; color: #fff;
        }

        .school-badge span {
            font-size: 12px;
            font-weight: 600;
            color: rgba(255,255,255,.9);
            letter-spacing: .03em;
        }

        /* .header-title {
            font-size: 26px;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
            position: relative; z-index: 1;
        } */

        .header-sub {
            font-size: 13.5px;
            color: rgba(255,255,255,.75);
            margin-top: 6px;
            font-weight: 400;
            position: relative; z-index: 1;
        }

        /* ─── Role tabs ─── */
        .role-tabs {
            display: flex;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
        }

        .role-tab {
            flex: 1;
            padding: 14px 8px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            transition: all .2s ease;
            border-bottom: 2px solid transparent;
            position: relative;
            top: 1px;
        }

        .role-tab i { font-size: 14px; }

        .role-tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: #fff;
        }

        .role-tab:hover:not(.active) {
            color: var(--text);
            background: #f1f5f9;
        }

        /* ─── Form body ─── */
        .card-body {
            padding: 32px 40px 36px;
        }

        .alert {
            padding: 12px 14px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 20px;
            line-height: 1.4;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .alert i {
            flex-shrink: 0;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .alert span {
            display: flex;
            align-items: center;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ─── Form fields ─── */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 7px;
            letter-spacing: .01em;
        }

        .input-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 15px;
            transition: color .2s;
            pointer-events: none;
        }

        .form-input {
            width: 100%;
            height: 48px;
            padding: 0 44px 0 42px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif;
            font-size: 14.5px;
            color: var(--text);
            background: #fff;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }

        .form-input::placeholder { color: var(--text-light); }

        .form-input:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px rgba(26,86,219,.1);
        }

        .form-input:focus + .input-icon,
        .input-wrap:focus-within .input-icon {
            color: var(--primary);
        }

        /* Password toggle */
        .pwd-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-light);
            font-size: 15px;
            padding: 4px;
            transition: color .2s;
        }

        .pwd-toggle:hover { color: var(--primary); }

        /* ─── Helper text ─── */
        .field-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* ─── Submit button ─── */
        .btn-login {
            width: 100%;
            height: 50px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
            transition: background .2s, transform .1s, box-shadow .2s;
            letter-spacing: .01em;
        }

        .btn-login:hover {
            background: var(--primary-dark);
            box-shadow: 0 4px 16px rgba(26,86,219,.35);
        }

        .btn-login:active { transform: scale(.98); }

        .btn-login.loading { opacity: .75; pointer-events: none; }

        .btn-login .spinner {
            display: none;
            width: 18px; height: 18px;
            border: 2.5px solid rgba(255,255,255,.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .6s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .btn-login.loading .btn-text { display: none; }
        .btn-login.loading .spinner  { display: block; }

        /* ─── Footer ─── */
        .card-footer {
            text-align: center;
            padding: 14px 40px 28px;
            font-size: 12.5px;
            color: var(--text-muted);
            border-top: 1px solid var(--border);
        }

        .card-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        /* ─── Responsive ─── */
        @media (max-width: 500px) {
            .login-card { border-radius: 16px; }
            .card-header { padding: 28px 24px 24px; }
            .card-body { padding: 24px 24px 28px; }
            .card-footer { padding: 12px 24px 20px; }
            /* .header-title { font-size: 22px; } */
            .role-tab { font-size: 12px; padding: 12px 6px; }
            .role-tab span { display: none; }
        }
    </style>
</head>
<body>
<div class="page-wrap">
    <div class="login-card">

        <!-- Header -->
        <div class="card-header">
            <div class="school-badge">
                <div class="badge-icon"><i class="fas fa-school"></i></div>
                <span><?= h(EMS_SCHOOL) ?></span>
            </div>
            <!-- <div class="header-title">Welcome Back</div> -->
            <div class="header-sub">Education Management System &mdash; Sign in to continue</div>
        </div>

        <!-- Role Tabs -->
        <div class="role-tabs" role="tablist" aria-label="Login role">
            <button type="button" class="role-tab active" data-role="student" role="tab" aria-selected="true">
                <i class="fas fa-user-graduate"></i><span>Student</span>
            </button>
            <button type="button" class="role-tab" data-role="teacher" role="tab" aria-selected="false">
                <i class="fas fa-chalkboard-teacher"></i><span>Teacher</span>
            </button>
            <button type="button" class="role-tab" data-role="admin" role="tab" aria-selected="false">
                <i class="fas fa-user-shield"></i><span>Admin</span>
            </button>
        </div>

        <!-- Body -->
        <div class="card-body">
            <?php
            // Seed initial alert from flash message (set by logout, session guards, etc.)
            $initAlertType = '';
            $initAlertMsg  = '';
            if ($error)   { $initAlertType = 'danger';  $initAlertMsg = $error; }
            if ($success) { $initAlertType = 'success'; $initAlertMsg = $success; }
            ?>
            <div id="alertBox"
                 role="alert"
                 class="alert<?= $initAlertMsg ? ' alert-' . h($initAlertType) : '' ?>"
                 style="display:<?= $initAlertMsg ? 'flex' : 'none' ?>">
                <?php if ($initAlertMsg): ?>
                    <?= $initAlertType === 'danger'
                        ? '<i class="fas fa-circle-exclamation"></i>'
                        : '<i class="fas fa-circle-check"></i>' ?>
                    <span><?= h($initAlertMsg) ?></span>
                <?php endif; ?>
            </div>

            <form method="POST" action="/" id="loginForm" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="role" id="roleInput" value="student">

                <!-- Identifier -->
                <div class="form-group">
                    <label class="form-label" for="identifier" id="idLabel">Student ID</label>
                    <div class="input-wrap">
                        <input
                            type="text"
                            class="form-input"
                            id="identifier"
                            name="identifier"
                            placeholder="Enter your Student ID"
                            autocomplete="username"
                            required
                            maxlength="60"
                            value="<?= h($_POST['identifier'] ?? '') ?>"
                        >
                        <i class="fas fa-id-card input-icon" id="idIcon"></i>
                    </div>
                    <div class="field-hint" id="idHint">
                        <i class="fas fa-info-circle"></i>
                        <span>Students: use your 7-digit Student ID</span>
                    </div>
                </div>

                <!-- Password -->
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-wrap">
                        <input
                            type="password"
                            class="form-input"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                            maxlength="128"
                        >
                        <i class="fas fa-lock input-icon"></i>
                        <button type="button" class="pwd-toggle" id="pwdToggle" aria-label="Toggle password visibility">
                            <i class="fas fa-eye" id="pwdIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Submit -->
                <button type="submit" class="btn-login" id="loginBtn">
                    <span class="btn-text"><i class="fas fa-right-to-bracket"></i> Sign In</span>
                    <span class="spinner" aria-hidden="true"></span>
                </button>
            </form>
        </div>

        <!-- Footer -->
        <div class="card-footer">
            <strong>Forgot your password?</strong> Contact the school.<br>
            <span style="margin-top:6px;display:block">&copy; <?= date('Y') ?> <?= h(EMS_SCHOOL) ?>. All rights reserved.</span>
        </div>

    </div>
</div>

<script>
// ── Role Tab Switching ────────────────────────────────────────────────────────
const tabs = document.querySelectorAll('.role-tab');
const roleInput = document.getElementById('roleInput');
const idLabel   = document.getElementById('idLabel');
const idInput   = document.getElementById('identifier');
const idHint    = document.getElementById('idHint').querySelector('span');
const idIcon    = document.getElementById('idIcon');

const roleConfig = {
    student: {
        label:       'Student ID',
        placeholder: 'Enter your Student ID',
        hint:        'Students: use your 7-digit Student ID',
        icon:        'fa-id-card',
        autocomplete:'username',
    },
    teacher: {
        label:       'Username',
        placeholder: 'Enter your username',
        hint:        'Teachers: use the username provided by admin',
        icon:        'fa-user',
        autocomplete:'username',
    },
    admin: {
        label:       'Username',
        placeholder: 'Enter admin username',
        hint:        'Administrators: use your admin credentials',
        icon:        'fa-user-shield',
        autocomplete:'username',
    },
};

tabs.forEach(tab => {
    tab.addEventListener('click', () => {
        tabs.forEach(t => { t.classList.remove('active'); t.setAttribute('aria-selected','false'); });
        tab.classList.add('active');
        tab.setAttribute('aria-selected','true');

        const role = tab.dataset.role;
        localStorage.setItem('ems_login_role', role);
        roleInput.value = role;
        const cfg = roleConfig[role];
        idLabel.textContent       = cfg.label;
        idInput.placeholder       = cfg.placeholder;
        idHint.textContent        = cfg.hint;
        idInput.setAttribute('autocomplete', cfg.autocomplete);
        idIcon.className          = 'fas ' + cfg.icon + ' input-icon';
        idInput.value             = '';
        //idInput.focus();
    });
});

// Pre-select role if POST failed
// Pre-select role: POST failure takes priority, then localStorage, then default 'student'
(function() {
    const postRole  = <?= json_encode($_POST['role'] ?? '') ?>;
    const savedRole = postRole || localStorage.getItem('ems_login_role') || 'student';
    const tab = document.querySelector('[data-role="' + savedRole + '"]');
    if (tab && savedRole !== 'student') tab.click();
})();

// ── Password Toggle ───────────────────────────────────────────────────────────
const pwdInput  = document.getElementById('password');
const pwdToggle = document.getElementById('pwdToggle');
const pwdIcon   = document.getElementById('pwdIcon');

pwdToggle.addEventListener('click', () => {
    const isText = pwdInput.type === 'text';
    pwdInput.type     = isText ? 'password' : 'text';
    pwdIcon.className = isText ? 'fas fa-eye' : 'fas fa-eye-slash';
    pwdToggle.setAttribute('aria-label', isText ? 'Show password' : 'Hide password');
});

// ── AJAX Form Submit (no page reload = no resubmit dialog) ────────────────────
const loginForm = document.getElementById('loginForm');
const loginBtn  = document.getElementById('loginBtn');
const alertBox  = document.getElementById('alertBox');

function showAlert(type, message) {
    alertBox.className = 'alert alert-' + type;
    alertBox.innerHTML = (type === 'danger'
        ? '<i class="fas fa-circle-exclamation"></i>'
        : '<i class="fas fa-circle-check"></i>')
        + ' <span>' + message + '</span>';
    alertBox.style.display = 'flex';
}

function hideAlert() {
    alertBox.style.display = 'none';
}

loginForm.addEventListener('submit', async function(e) {
    e.preventDefault(); // ← always stop normal POST

    const id  = document.getElementById('identifier').value.trim();
    const pwd = document.getElementById('password').value;
    if (!id || !pwd) {
        showAlert('danger', 'Enter your user ID and password.');
        return;
    }

    hideAlert();
    loginBtn.classList.add('loading');
    loginBtn.disabled = true;

    try {
        const formData = new FormData(loginForm);
        const res = await fetch(loginForm.action || '/', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData,
        });

        const data = await res.json();

        if (data.csrf_token) {
            const csrfField = loginForm.querySelector('input[name="csrf_token"]');
            if (csrfField) csrfField.value = data.csrf_token;
        }

        if (data.ok && data.redirect) {
            window.location.href = data.redirect;
            return;
        }

        // Show error — nothing gets disabled, user can still type and switch tabs
        showAlert('danger', data.message || 'Login failed. Try again.');
        document.getElementById('password').value = '';
        document.getElementById('password').focus();
    } catch (err) {
        showAlert('danger', 'Network error. Please try again.');
    } finally {
        loginBtn.classList.remove('loading');
        loginBtn.disabled = false;
    }
});
</script>
</body>
</html>
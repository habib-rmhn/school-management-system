<?php
/**
 * EMS — Core Helper Functions
 */

require_once __DIR__ . '/db.php';

// ── CSRF ─────────────────────────────────────────────────────────────────────

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function csrf_verify(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_rotate(): void {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── SECURITY ──────────────────────────────────────────────────────────────────

function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function generate_uid(string $prefix = ''): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $uid   = $prefix;
    for ($i = 0; $i < 10; $i++) {
        $uid .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $uid;
}

function get_client_ip(): string {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
            // Allow private IPs in dev
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ── DEVICE DETECTION ─────────────────────────────────────────────────────────

function detect_device(): array {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $info = [
        'device_type' => 'Unknown',
        'browser'     => 'Unknown',
        'os'          => 'Unknown',
        'ua'          => substr($ua, 0, 300),
    ];

    $autoload = __DIR__ . '/../extensions/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        try {
            $dd = new \DeviceDetector\DeviceDetector($ua);
            $dd->parse();
            $info['device_type'] = $dd->getDeviceName() ?: 'Unknown';
            $client = $dd->getClient();
            $info['browser'] = ($client['name'] ?? 'Unknown') . ' ' . ($client['version'] ?? '');
            $os = $dd->getOs();
            $info['os'] = ($os['name'] ?? 'Unknown') . ' ' . ($os['version'] ?? '');
        } catch (Throwable $e) {
            // Silently fall through to basic detection
        }
    }

    // Fallback basic detection if library not available
    if ($info['device_type'] === 'Unknown') {
        if (preg_match('/Mobile|Android|iPhone|iPad/i', $ua)) {
            $info['device_type'] = preg_match('/iPad/i', $ua) ? 'Tablet' : 'Smartphone';
        } else {
            $info['device_type'] = 'Desktop';
        }
        if (preg_match('/Chrome\/([0-9.]+)/i', $ua, $m)) $info['browser'] = 'Chrome ' . $m[1];
        elseif (preg_match('/Firefox\/([0-9.]+)/i', $ua, $m)) $info['browser'] = 'Firefox ' . $m[1];
        elseif (preg_match('/Safari\/([0-9.]+)/i', $ua, $m)) $info['browser'] = 'Safari';
        elseif (preg_match('/Edge\/([0-9.]+)/i', $ua, $m)) $info['browser'] = 'Edge ' . $m[1];

        if (preg_match('/Windows NT ([0-9.]+)/i', $ua, $m)) $info['os'] = 'Windows ' . $m[1];
        elseif (preg_match('/Android ([0-9.]+)/i', $ua, $m)) $info['os'] = 'Android ' . $m[1];
        elseif (preg_match('/iPhone OS ([0-9_]+)/i', $ua, $m)) $info['os'] = 'iOS ' . str_replace('_', '.', $m[1]);
        elseif (preg_match('/Mac OS X ([0-9_]+)/i', $ua, $m)) $info['os'] = 'macOS ' . str_replace('_', '.', $m[1]);
        elseif (preg_match('/Linux/i', $ua)) $info['os'] = 'Linux';
    }

    return $info;
}

function get_ip_info(string $ip): array {
    $info = ['isp' => '', 'location' => '', 'country' => ''];
    // Skip for private/local IPs
    if (in_array($ip, ['127.0.0.1', '::1']) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $info['location'] = 'Local / Private Network';
        return $info;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
    $raw = @file_get_contents(
        "http://ip-api.com/json/{$ip}?fields=status,country,regionName,city,district,zip,isp,query",
        false,
        $ctx
    );
    
    if ($raw) {
        $data = json_decode($raw, true);
    
        if (($data['status'] ?? '') === 'success') {
    
            $parts = array_filter([
                $data['city'] ?? '',
                $data['district'] ?? '',
                $data['regionName'] ?? '',
                $data['country'] ?? ''
            ]);
    
            $info['isp']      = $data['isp'] ?? '';
            $info['location'] = implode(', ', array_unique($parts));
            $info['country']  = $data['country'] ?? '';
        }
    }
    return $info;
}

// ── BROWSER RATE LIMITING — signed cookie, zero database, zero IP ─────────────
//
// State lives entirely in a persistent browser cookie that survives close/reopen.
// The cookie payload is HMAC-signed with RL_SECRET so the user cannot tamper with
// it — altering any byte breaks the signature and PHP treats it as a clean slate.
//
// Cookie payload (base64 of pipe-separated values):
//   attempts | lockout_until | window_start | hmac-sha256
//
// Workflow:
//   1. Every login attempt  → check_login_rate_limit()   (block if locked)
//   2. Every failed attempt → record_failed_attempt()    (increment; lock on max)
//   3. Successful login     → clear_rate_limit_cookie()  (wipe the cookie)
//   4. Page render          → get_lockout_seconds_remaining() → feed to JS countdown

define('RL_COOKIE_NAME',  'ems_rl');
define('RL_SECRET',       'REPLACE_WITH_32+_RANDOM_CHARS_eg_bin2hex_random_bytes_32');
define('RL_MAX_ATTEMPTS', 5);    // failures before lockout
define('RL_WINDOW',       600);  // seconds — sliding attempt window (10 min)
define('RL_LOCKOUT',      900);  // seconds — lockout duration after max failures (15 min)

/** Build a signed, base64-encoded cookie value. */
function _rl_make_cookie(int $attempts, int $lockout_until, int $window_start): string {
    $payload = $attempts . '|' . $lockout_until . '|' . $window_start;
    $sig     = hash_hmac('sha256', $payload, RL_SECRET);
    return base64_encode($payload . '|' . $sig);
}

/**
 * Parse and verify the cookie.
 * Returns [attempts, lockout_until, window_start] on success.
 * Returns [0, 0, time()] on missing / tampered / malformed cookie.
 */
function _rl_parse_cookie(): array {
    $raw = $_COOKIE[RL_COOKIE_NAME] ?? '';
    if ($raw === '') return [0, 0, time()];

    $decoded = base64_decode($raw, true);
    if ($decoded === false) return [0, 0, time()];

    $parts = explode('|', $decoded);
    if (count($parts) !== 4) return [0, 0, time()];

    [$attempts, $lockout_until, $window_start, $sig] = $parts;

    // Constant-time signature check — tampered cookie → fresh slate
    $expected = hash_hmac('sha256', $attempts . '|' . $lockout_until . '|' . $window_start, RL_SECRET);
    if (!hash_equals($expected, $sig)) return [0, 0, time()];

    return [(int)$attempts, (int)$lockout_until, (int)$window_start];
}

/**
 * Write the rate-limit cookie.
 * expires = 30 days so it survives browser restarts; actual gate is lockout_until.
 * Set 'secure' => false only on local dev without HTTPS.
 */
function _rl_set_cookie(int $attempts, int $lockout_until, int $window_start): void {
    setcookie(RL_COOKIE_NAME, _rl_make_cookie($attempts, $lockout_until, $window_start), [
        'expires'  => time() + 86400 * 30,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    // Also update $_COOKIE so same-request reads see the new value
    $_COOKIE[RL_COOKIE_NAME] = _rl_make_cookie($attempts, $lockout_until, $window_start);
}

/**
 * Check if this browser is allowed to attempt a login.
 * Returns true = allowed, false = still locked out.
 * Automatically resets expired lockouts / expired windows.
 * The $ip parameter is accepted but ignored — kept for call-site compatibility.
 */
function check_login_rate_limit(string $ip = ''): bool {
    [$attempts, $lockout_until, $window_start] = _rl_parse_cookie();
    $now = time();

    // Active lockout that hasn't expired yet
    if ($lockout_until > 0 && $now < $lockout_until) return false;

    // Lockout expired OR attempt window expired → reset silently
    if ($lockout_until > 0 || ($now - $window_start) > RL_WINDOW) {
        _rl_set_cookie(0, 0, $now);
    }

    return true;
}

/**
 * Record one failed login attempt.
 * Call this immediately after confirming bad credentials.
 * Triggers a lockout cookie when RL_MAX_ATTEMPTS is reached.
 */
function record_failed_attempt(): void {
    [$attempts, $lockout_until, $window_start] = _rl_parse_cookie();
    $now = time();

    // Reset if previous lockout expired or window has rolled over
    if ($lockout_until > 0 || ($now - $window_start) > RL_WINDOW) {
        $attempts     = 0;
        $lockout_until = 0;
        $window_start  = $now;
    }

    $attempts++;
    $new_lockout = ($attempts >= RL_MAX_ATTEMPTS) ? $now + RL_LOCKOUT : 0;
    _rl_set_cookie($attempts, $new_lockout, $window_start);
}

/**
 * Return seconds remaining in the current lockout (0 if not locked).
 * Feed this to the JS countdown on page render.
 */
function get_lockout_seconds_remaining(): int {
    [, $lockout_until] = _rl_parse_cookie();
    return ($lockout_until > 0) ? max(0, $lockout_until - time()) : 0;
}

/** Wipe the rate-limit cookie on successful login. */
function clear_rate_limit_cookie(): void {
    setcookie(RL_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    unset($_COOKIE[RL_COOKIE_NAME]);
}

// ── APP SETTINGS (key-value store, `app_settings` table) ─────────────────────
// Table is created by the standalone app_settings.sql (import once via
// phpMyAdmin) — these helpers just read/write it. If the table is missing
// (not imported yet) or the DB hiccups, everything degrades to the
// EMS_*_DEFAULT constants in db.php instead of fataling.

/** Loads the whole app_settings table once per request (cheap, one query). */
function _appSettingsCache(bool $reset = false): array {
    static $cache = null;
    if ($reset) $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = dbQuery("SELECT setting_key, setting_value FROM app_settings")->fetchAll();
            foreach ($rows as $r) $cache[$r['setting_key']] = $r['setting_value'];
        } catch (Throwable $e) {
            error_log('EMS app_settings cache error: ' . $e->getMessage());
        }
    }
    return $cache;
}

function getSetting(string $key, string $default = ''): string {
    $cache = _appSettingsCache();
    return (array_key_exists($key, $cache) && $cache[$key] !== null) ? (string)$cache[$key] : $default;
}

function setSetting(string $key, string $value): void {
    dbQuery(
        "INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
        [$key, $value]
    );
    _appSettingsCache(true); // bust cache so the rest of this request sees the fresh value
}

function getSchoolName(): string {
    return getSetting('school_name', EMS_SCHOOL_DEFAULT);
}

function getSchoolAddress(): string {
    return getSetting('school_address', EMS_SCHOOL_ADDRESS_DEFAULT);
}

function getSchoolPhone(): string {
    return getSetting('school_phone', EMS_SCHOOL_PHONE_DEFAULT);
}

function getSchoolEmail(): string {
    return getSetting('school_email', EMS_SCHOOL_EMAIL_DEFAULT);
}

/** Browser-facing URL for <img src>, e.g. on the Settings page. */
function getSchoolLogoUrl(): string {
    $path = getSetting('school_logo_path', '');
    if ($path !== '') {
        return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    }
    return EMS_SCHOOL_LOGO_DEFAULT;
}

/** Filesystem path for TCPDF/server-side use, e.g. on PDF headers. Empty if none. */
function getSchoolLogoPath(): string {
    $path = getSetting('school_logo_path', '');
    if ($path === '') return '';
    $full = __DIR__ . '/../' . ltrim($path, '/');
    return is_file($full) ? $full : '';
}

// ── LOGGING ──────────────────────────────────────────────────────────────────

function log_login(string $sys_uid, bool $success, string $ip): void {
    $device  = detect_device();
    $ipinfo  = get_ip_info($ip);
    try {
        dbQuery(
            "INSERT INTO login_logs (sys_uid, login_at, ip_address, isp, location, device_type, browser, os, success)
             VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)",
            [
                $sys_uid,
                $ip,
                $ipinfo['isp'],
                $ipinfo['location'],
                $device['device_type'],
                trim($device['browser']),
                trim($device['os']),
                $success ? 1 : 0,
            ]
        );
    } catch (Throwable $e) {
        error_log('EMS login log error: ' . $e->getMessage());
    }
}

function log_activity(string $actor_uid, string $action_type, string $target_type, string $target_uid, array $meta = []): void {
    try {
        dbQuery(
            "INSERT INTO activity_logs (actor_uid, action_type, target_type, target_uid, meta_json, logged_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [$actor_uid, $action_type, $target_type, $target_uid, json_encode($meta)]
        );
    } catch (Throwable $e) {
        error_log('EMS activity log error: ' . $e->getMessage());
    }
}

// ── FLASH MESSAGES ───────────────────────────────────────────────────────────
// Write once, read once — no query params in URLs, message gone after reload.

function flash_set(string $type, string $message): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
    }
}

function flash_get(): array {
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

// ── REDIRECT ─────────────────────────────────────────────────────────────────

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function redirect_by_role(string $role): void {
    switch ($role) {
        case 'student': redirect(BASE_URL . '/student/dashboard.php'); break;
        case 'teacher': redirect(BASE_URL . '/teacher/dashboard.php'); break;
        case 'admin':   redirect(BASE_URL . '/admin/dashboard.php');   break;
        default:        redirect(BASE_URL . '/');
    }
}

// ── RUNTIME SCHOOL CONSTANTS (database-backed, single source of truth) ───────
// Every page across the app (admin-sidebar.php, students-pdf.php, letterheads,
// admit cards, etc.) already reads EMS_SCHOOL / EMS_SCHOOL_ADDRESS /
// EMS_SCHOOL_PHONE / EMS_SCHOOL_EMAIL / EMS_SCHOOL_LOGO as plain constants.
// Rather than hunting down and editing every one of those files, we resolve
// the REAL constants right here, once per request, from the app_settings
// table — falling back to the *_DEFAULT constants in db.php if the table is
// empty or unreachable. Every existing usage elsewhere becomes
// database-driven automatically, with zero changes to those files.
if (!defined('EMS_SCHOOL'))         define('EMS_SCHOOL',         getSchoolName());
if (!defined('EMS_SCHOOL_ADDRESS')) define('EMS_SCHOOL_ADDRESS', getSchoolAddress());
if (!defined('EMS_SCHOOL_PHONE'))   define('EMS_SCHOOL_PHONE',   getSchoolPhone());
if (!defined('EMS_SCHOOL_EMAIL'))   define('EMS_SCHOOL_EMAIL',   getSchoolEmail());
if (!defined('EMS_SCHOOL_LOGO'))    define('EMS_SCHOOL_LOGO',    getSchoolLogoUrl());
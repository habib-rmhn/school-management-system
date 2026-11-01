<?php
/**
 * EMS — Admin Session Guard
 * Include at the very top of every admin page.
 * Usage: require_once __DIR__ . '/../includes/admin-session.php';
 */

require_once __DIR__ . '/maintenance-guard.php';
require_once __DIR__ . '/functions.php';

if (
    empty($_SESSION['ems_logged_in']) ||
    empty($_SESSION['ems_role'])      ||
    $_SESSION['ems_role'] !== 'admin' ||
    empty($_SESSION['ems_uid'])
) {
    session_unset();
    session_destroy();
    flash_set('danger', 'Session expired. Please log in again.');
    redirect(BASE_URL . '/');
}

try {
    $stmt = dbQuery(
        "SELECT su.is_active, su.username
         FROM system_users su
         WHERE su.sys_uid = ? AND su.role = 'admin'",
        [$_SESSION['ems_uid']]
    );
    $activeUser = $stmt->fetch();
    if (!$activeUser || !$activeUser['is_active']) {
        session_unset();
        session_destroy();
        flash_set('danger', 'Account disabled! Contact school.');
    redirect(BASE_URL . '/');
    }
    $_SESSION['ems_display_name'] = 'Administrator';
} catch (Throwable $e) {
    // Continue with cached data
}

$currentPath = $_SERVER['PHP_SELF'] ?? '';
if (preg_match('#/(student|teacher)/#', $currentPath)) {
    http_response_code(403);
    die('<h1>403 Forbidden</h1>');
}

// Admins get more frequent session regeneration
if (empty($_SESSION['ems_last_regen']) || (time() - $_SESSION['ems_last_regen']) > 900) {
    session_regenerate_id(true);
    $_SESSION['ems_last_regen'] = time();
}
<?php
/**
 * EMS — Teacher Session Guard
 * Include at the very top of every teacher page.
 * Usage: require_once __DIR__ . '/../includes/teacher-session.php';
 */

require_once __DIR__ . '/maintenance-guard.php';
require_once __DIR__ . '/functions.php';

if (
    empty($_SESSION['ems_logged_in']) ||
    empty($_SESSION['ems_role'])      ||
    $_SESSION['ems_role'] !== 'teacher' ||
    empty($_SESSION['ems_uid'])
) {
    session_unset();
    session_destroy();
    redirect(BASE_URL . '/');
}

try {
    $stmt = dbQuery(
        "SELECT su.is_active, t.full_name, t.photo_path, t.designation
         FROM system_users su
         JOIN teachers t ON t.sys_uid = su.sys_uid
         WHERE su.sys_uid = ? AND su.role = 'teacher'",
        [$_SESSION['ems_uid']]
    );
    $activeUser = $stmt->fetch();
    if (!$activeUser || !$activeUser['is_active']) {
        session_unset();
        session_destroy();
        redirect(BASE_URL . '/');
    }
    $_SESSION['ems_display_name'] = $activeUser['full_name'];
    $_SESSION['ems_designation']  = $activeUser['designation'];
    $_SESSION['ems_photo']        = $activeUser['photo_path'];
} catch (Throwable $e) {
    // Continue with cached data
}

$currentPath = $_SERVER['PHP_SELF'] ?? '';
if (preg_match('#/(student|admin)/#', $currentPath)) {
    http_response_code(403);
    die('<h1>403 Forbidden</h1>');
}

if (empty($_SESSION['ems_last_regen']) || (time() - $_SESSION['ems_last_regen']) > 1800) {
    session_regenerate_id(true);
    $_SESSION['ems_last_regen'] = time();
}
<?php
/**
 * EMS — Student Session Guard
 * Include at the very top of every student page.
 * Usage: require_once __DIR__ . '/../includes/student-session.php';
 */

require_once __DIR__ . '/maintenance-guard.php';
require_once __DIR__ . '/functions.php';

// Must be logged in as student
if (
    empty($_SESSION['ems_logged_in']) ||
    empty($_SESSION['ems_role'])      ||
    $_SESSION['ems_role'] !== 'student' ||
    empty($_SESSION['ems_uid'])
) {
    session_unset();
    session_destroy();
    redirect(BASE_URL . '/');
}

// Verify user still active in DB (in case admin deactivated mid-session)
try {
    $stmt = dbQuery(
        "SELECT su.is_active, s.full_name, s.student_id, s.photo_path
         FROM system_users su
         JOIN students s ON s.sys_uid = su.sys_uid
         WHERE su.sys_uid = ? AND su.role = 'student'",
        [$_SESSION['ems_uid']]
    );
    $activeUser = $stmt->fetch();
    if (!$activeUser || !$activeUser['is_active']) {
        session_unset();
        session_destroy();
        redirect(BASE_URL . '/');
    }
    // Refresh session display data
    $_SESSION['ems_display_name'] = $activeUser['full_name'];
    $_SESSION['ems_student_id']   = $activeUser['student_id'];
    $_SESSION['ems_photo']        = $activeUser['photo_path'];
} catch (Throwable $e) {
    // DB error — let them continue with cached session data
}

// Prevent access to teacher/admin paths
$currentPath = $_SERVER['PHP_SELF'] ?? '';
if (preg_match('#/(teacher|admin)/#', $currentPath)) {
    http_response_code(403);
    die('<h1>403 Forbidden</h1>');
}

// Regenerate session ID periodically (every 30 min)
if (empty($_SESSION['ems_last_regen']) || (time() - $_SESSION['ems_last_regen']) > 1800) {
    session_regenerate_id(true);
    $_SESSION['ems_last_regen'] = time();
}
<?php
/**
 * EMS — Maintenance Mode Guard
 * ─────────────────────────────────────────────────────────────────
 * Require this as the very FIRST line of any entry point:
 *   - index.php (the login gate)
 *   - includes/admin-session.php, teacher-session.php, student-session.php
 *     (which covers every single authenticated page in all 3 portals,
 *     since every one of those pages already starts by requiring its
 *     session guard — no need to touch dozens of individual pages)
 *
 * If maintenance mode is active, this serves maintenance.php with a
 * 503 status and stops the request right here — the page that
 * required this guard never actually runs.
 *
 * A logged-in ADMIN bypasses this automatically, so whoever is doing
 * the maintenance work can keep using the system while everyone else
 * sees the maintenance page. Toggle it from
 * Admin → Settings → Maintenance Mode.
 */

require_once __DIR__ . '/functions.php';

if (!defined('EMS_MAINTENANCE_CHECKED')) {
    define('EMS_MAINTENANCE_CHECKED', true);

    $__maintStatusFile = __DIR__ . '/maintenance-status.php';
    $__maintStatus = is_file($__maintStatusFile) ? (include $__maintStatusFile) : null;

    if (is_array($__maintStatus) && !empty($__maintStatus['active'])) {
        $__isAdminBypass = !empty($_SESSION['ems_logged_in']) && ($_SESSION['ems_role'] ?? '') === 'admin';
        $__isMaintenancePageItself = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'maintenance.php';

        if (!$__isAdminBypass && !$__isMaintenancePageItself) {
            http_response_code(503);
            header('Retry-After: 1800'); // hint to browsers/bots: try again in ~30 min
            $GLOBALS['__maintenance_message'] = $__maintStatus['message'] ?? '';
            $GLOBALS['__maintenance_eta']     = $__maintStatus['eta']     ?? '';
            require __DIR__ . '/../maintenance.php';
            exit;
        }
    }
    unset($__maintStatusFile, $__maintStatus, $__isAdminBypass, $__isMaintenancePageItself);
}
<?php
/**
 * EMS — Logout Handler
 */
require_once __DIR__ . '/includes/functions.php';

if (!empty($_SESSION['ems_uid'])) {
    log_activity($_SESSION['ems_uid'], 'logout', 'system_users', $_SESSION['ems_uid'], []);
}

session_unset();
session_destroy();

// Start a fresh session just to carry the flash message
// URL stays clean — no ?logout=1 param, message disappears on next reload
session_start();
flash_set('success', 'Logged out successfully.');
redirect(BASE_URL . '/');
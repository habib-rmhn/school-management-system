<?php
/**
 * EMS Admin — Attendance Summary AJAX Endpoint
 * ─────────────────────────────────────────────────────────────────
 * Used by dashboard.php's Attendance Summary chart.
 *
 * Returns, for the current session, the number of students marked
 * present each day within the requested window (7 / 30 / 90 days).
 * "Present" here counts both 'present' and 'late' statuses (a late
 * student still showed up), with the late portion also broken out
 * separately so the dashboard can display it as a sub-label.
 * Days on which no class took attendance at all are omitted from
 * the result entirely, rather than being sent as zero — the chart
 * simply skips them.
 *
 * Read-only, GET-based, admin-session gated. Like the notif_* code
 * path in activity-log-ajax.php, this is a pure data read (not a
 * state-changing action) triggered by button clicks and page load,
 * so it does not require/consume the rotating CSRF token used by
 * page-specific save actions elsewhere in the admin panel.
 * ─────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/../includes/admin-session.php';

header('Content-Type: application/json');

// ── Validate requested window ──────────────────────────────────────
$allowedDays = [7, 30, 90];
$days = (int)($_GET['days'] ?? $_POST['days'] ?? 30);
if (!in_array($days, $allowedDays, true)) {
    $days = 30;
}

// ── Current session ───────────────────────────────────────────────
$sid = null;
$sYear = null;
try {
    $session = dbQuery("SELECT session_id, session_year FROM sessions WHERE is_current=1 LIMIT 1")->fetch();
    $sid   = $session['session_id']   ?? null;
    $sYear = $session['session_year'] ?? null;
} catch (Throwable $e) {}

// ── Per-day totals (only days with at least one attendance record) ─
// Scoped to the CURRENT session only, via enrollments.session_id —
// attendance recorded under a previous session will not appear here,
// by design.
$rows = [];
if ($sid) {
    try {
        $fromDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $toDate   = date('Y-m-d');
        $rows = dbQuery(
            "SELECT a.att_date,
                    SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) AS present_total,
                    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_total
             FROM attendance a
             JOIN enrollments e ON e.enrollment_id = a.enrollment_id
             WHERE e.session_id = ? AND a.att_date BETWEEN ? AND ?
             GROUP BY a.att_date
             ORDER BY a.att_date ASC",
            [$sid, $fromDate, $toDate]
        )->fetchAll();
    } catch (Throwable $e) {}
}

$labels = [];
$data   = [];
$late   = [];
foreach ($rows as $r) {
    $labels[] = date('d M', strtotime($r['att_date']));
    $data[]   = (int)$r['present_total'];
    $late[]   = (int)$r['late_total'];
}

echo json_encode([
    'ok'          => true,
    'days'        => $days,
    'has_session' => (bool)$sid,   // false = no active session set in Settings → Sessions
    'session_year'=> $sYear,
    'labels'      => $labels,
    'data'        => $data,
    'late'        => $late,
]);
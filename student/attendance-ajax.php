<?php
/**
 * EMS Student — My Attendance AJAX Handler
 * ─────────────────────────────────────────────────────────────────
 * Actions:
 *   fetch_sessions  – list every enrollment this student has had
 *                     (for the class/session switcher)
 *   fetch_month     – one calendar month of attendance + a summary,
 *                     for ONE of this student's own enrollments
 *
 * Security: enrollment_id is always re-verified against the logged
 * in student's own sys_uid before any attendance row is returned.
 * A student can never view another student's attendance by
 * guessing an enrollment_id.
 */
require_once __DIR__ . '/../includes/student-session.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed.']); exit;
}
if (!csrf_verify()) {
    echo json_encode(['ok' => false, 'msg' => 'Session expired. Please refresh the page.']); exit;
}

csrf_rotate();
$newToken = csrf_token();
$myUid    = $_SESSION['ems_uid'];

function ok(array $data = []): void {
    global $newToken;
    echo json_encode(array_merge(['ok' => true, 'csrf' => $newToken], $data)); exit;
}
function fail(string $msg): void {
    global $newToken;
    echo json_encode(['ok' => false, 'msg' => $msg, 'csrf' => $newToken]); exit;
}

$action = trim($_POST['action'] ?? '');

// ── FETCH SESSIONS — every enrollment this student has had ────────
if ($action === 'fetch_sessions') {
    try {
        $rows = dbQuery(
            "SELECT e.enrollment_id, e.session_id, sess.session_year, e.status,
                    e.admitted_at, c.label AS class_label, g.group_name
             FROM enrollments e
             JOIN sessions sess ON sess.session_id = e.session_id
             JOIN classes   c    ON c.class_id      = e.class_id
             LEFT JOIN groups g  ON g.group_id      = e.group_id
             WHERE e.sys_uid = ?
             ORDER BY sess.session_year DESC, e.enrollment_id DESC",
            [$myUid]
        )->fetchAll();
        ok(['enrollments' => $rows]);
    } catch (Throwable $e) {
        error_log('EMS student attendance fetch_sessions: ' . $e->getMessage());
        fail('Could not load your class sessions.');
    }
}

// ── FETCH MONTH — calendar days + summary for one enrollment ──────
if ($action === 'fetch_month') {
    $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    $year         = (int)($_POST['year']  ?? 0);
    $month        = (int)($_POST['month'] ?? 0); // 1-12

    if ($enrollmentId < 1) fail('Please choose a class session.');
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) fail('Invalid month requested.');

    try {
        // Verify this enrollment belongs to the logged-in student
        $enroll = dbQuery(
            "SELECT e.enrollment_id, e.class_id, e.session_id, e.admitted_at, e.status,
                    c.label AS class_label, sess.session_year, g.group_name
             FROM enrollments e
             JOIN classes  c    ON c.class_id      = e.class_id
             JOIN sessions sess ON sess.session_id = e.session_id
             LEFT JOIN groups g ON g.group_id      = e.group_id
             WHERE e.enrollment_id = ? AND e.sys_uid = ?",
            [$enrollmentId, $myUid]
        )->fetch();

        if (!$enroll) fail('That class session was not found on your account.');

        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd   = date('Y-m-t', strtotime($monthStart)); // last day of month
        $today      = date('Y-m-d');

        // All attendance rows marked within this month for this enrollment
        $rows = dbQuery(
            "SELECT att_date, status
             FROM attendance
             WHERE enrollment_id = ? AND att_date BETWEEN ? AND ?
             ORDER BY att_date ASC",
            [$enrollmentId, $monthStart, $monthEnd]
        )->fetchAll();

        $byDate = [];
        foreach ($rows as $r) {
            $byDate[$r['att_date']] = $r['status'];
        }

        // Overall summary for the WHOLE enrollment (not just this month)
        // — gives the student a sense of their attendance for the full session
        $totalAll   = (int)dbQuery(
            "SELECT COUNT(*) FROM attendance WHERE enrollment_id = ?", [$enrollmentId]
        )->fetchColumn();
        $presentAll = (int)dbQuery(
            "SELECT COUNT(*) FROM attendance WHERE enrollment_id = ? AND status='present'", [$enrollmentId]
        )->fetchColumn();
        $absentAll  = (int)dbQuery(
            "SELECT COUNT(*) FROM attendance WHERE enrollment_id = ? AND status='absent'", [$enrollmentId]
        )->fetchColumn();
        $lateAll    = (int)dbQuery(
            "SELECT COUNT(*) FROM attendance WHERE enrollment_id = ? AND status='late'", [$enrollmentId]
        )->fetchColumn();

        $overallPct = $totalAll > 0 ? round(($presentAll + $lateAll) / $totalAll * 100, 1) : null;

        // This month's summary
        $totalMonth   = count($rows);
        $presentMonth = count(array_filter($rows, fn($r) => $r['status'] === 'present'));
        $absentMonth  = count(array_filter($rows, fn($r) => $r['status'] === 'absent'));
        $lateMonth    = count(array_filter($rows, fn($r) => $r['status'] === 'late'));
        $monthPct     = $totalMonth > 0 ? round(($presentMonth + $lateMonth) / $totalMonth * 100, 1) : null;

        ok([
            'days'          => $byDate,                 // { "2026-06-01": "present", ... }
            'class_label'   => $enroll['class_label'],
            'session_year'  => $enroll['session_year'],
            'group_name'    => $enroll['group_name'],
            'admitted_at'   => $enroll['admitted_at'],
            'month_summary' => [
                'total'   => $totalMonth,
                'present' => $presentMonth,
                'absent'  => $absentMonth,
                'late'    => $lateMonth,
                'pct'     => $monthPct,
            ],
            'overall_summary' => [
                'total'   => $totalAll,
                'present' => $presentAll,
                'absent'  => $absentAll,
                'late'    => $lateAll,
                'pct'     => $overallPct,
            ],
        ]);
    } catch (Throwable $e) {
        error_log('EMS student attendance fetch_month: ' . $e->getMessage());
        fail('Failed to load your attendance. Please try again.');
    }
}

fail('Unknown action.');
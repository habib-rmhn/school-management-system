<?php
/**
 * EMS Student — Class Routine AJAX Handler
 * ─────────────────────────────────────────────────────────────────
 * Actions:
 *   fetch_routine    – get the student's own weekly grid for one
 *                      of their enrollment records (current or past)
 *   fetch_sessions   – list all sessions this student has been
 *                      enrolled in (for the session switcher)
 *
 * Security: student can ONLY ever see routine data tied to their
 * own sys_uid's enrollment records — enrollment_id is never trusted
 * from the client without verifying it belongs to this student.
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

// ── FETCH SESSIONS — every session this student has been enrolled in ──
if ($action === 'fetch_sessions') {
    try {
        $rows = dbQuery(
            "SELECT e.enrollment_id, e.session_id, sess.session_year, e.status,
                    c.label AS class_label, g.group_name
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
        error_log('EMS student fetch_sessions: ' . $e->getMessage());
        fail('Could not load your sessions.');
    }
}

// ── FETCH ROUTINE — for ONE of this student's own enrollment records ──
if ($action === 'fetch_routine') {
    $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    if ($enrollmentId < 1) fail('Please choose a class session.');

    try {
        // Verify this enrollment belongs to the logged-in student — never trust client input
        $enroll = dbQuery(
            "SELECT e.enrollment_id, e.class_id, e.session_id, e.group_id,
                    c.class_num, c.label AS class_label,
                    sess.session_year, g.group_name
             FROM enrollments e
             JOIN classes   c    ON c.class_id      = e.class_id
             JOIN sessions  sess ON sess.session_id = e.session_id
             LEFT JOIN groups g  ON g.group_id      = e.group_id
             WHERE e.enrollment_id = ? AND e.sys_uid = ?",
            [$enrollmentId, $myUid]
        )->fetch();

        if (!$enroll) fail('That class session was not found on your account.');

        $classId   = (int)$enroll['class_id'];
        $sessionId = (int)$enroll['session_id'];

        // All real slots (period_number >= 1, exclude placeholder rows)
        $slots = dbQuery(
            "SELECT cr.day_of_week, cr.period_number,
                    cr.start_time, cr.end_time,
                    cr.subject_id, s.subject_name, s.subject_code,
                    t.full_name AS teacher_name, t.designation
             FROM class_routine cr
             LEFT JOIN subjects s ON s.subject_id = cr.subject_id
             LEFT JOIN teachers t ON t.sys_uid     = cr.teacher_uid
             WHERE cr.class_id   = ?
               AND cr.session_id = ?
               AND cr.period_number >= 1
             ORDER BY cr.period_number, cr.day_of_week",
            [$classId, $sessionId]
        )->fetchAll();

        // Derive period time rows (period_number → start/end time)
        $periodTimes = [];
        foreach ($slots as $sl) {
            $p = (int)$sl['period_number'];
            if (!isset($periodTimes[$p])) {
                $periodTimes[$p] = [
                    'period_number' => $p,
                    'start_time'    => $sl['start_time'],
                    'end_time'      => $sl['end_time'],
                ];
            }
        }
        ksort($periodTimes);

        ok([
            'slots'        => $slots,
            'period_times' => array_values($periodTimes),
            'class_num'    => (int)$enroll['class_num'],
            'class_label'  => $enroll['class_label'],
            'session_year' => $enroll['session_year'],
            'group_name'   => $enroll['group_name'],
        ]);
    } catch (Throwable $e) {
        error_log('EMS student fetch_routine: ' . $e->getMessage());
        fail('Failed to load your class routine. Please try again.');
    }
}

fail('Unknown action.');
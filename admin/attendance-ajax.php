<?php
/**
 * EMS Admin — Attendance AJAX Handler
 * Actions: fetch_students, save_attendance, fetch_report, fetch_date_status
 */
require_once __DIR__ . '/../includes/admin-session.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed.']); exit;
}
if (!csrf_verify()) {
    echo json_encode(['ok' => false, 'msg' => 'Session expired. Please refresh the page.']); exit;
}

csrf_rotate();
$newToken = csrf_token();

function ok(array $data = []): void {
    global $newToken;
    echo json_encode(array_merge(['ok' => true, 'csrf' => $newToken], $data));
    exit;
}
function fail(string $msg): void {
    global $newToken;
    echo json_encode(['ok' => false, 'msg' => $msg, 'csrf' => $newToken]);
    exit;
}
function sv(string $sql, array $p = []): mixed {
    try { $v = dbQuery($sql, $p)->fetchColumn(); return $v !== false ? $v : null; }
    catch (Throwable $e) { return null; }
}

$action = trim($_POST['action'] ?? '');

// ── FETCH STUDENTS for marking ────────────────────────────────────
if ($action === 'fetch_students') {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $classId   = (int)($_POST['class_id']   ?? 0);
    $groupId   = (int)($_POST['group_id']   ?? 0) ?: null;
    $date      = trim($_POST['att_date']    ?? '');

    if ($sessionId < 1 || $classId < 1) fail('Please select a session and class.');
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('Invalid date.');

    // Validate date not in future
    if ($date > date('Y-m-d')) fail('Cannot mark attendance for a future date.');

    try {
        $classRow = dbQuery("SELECT class_num, label FROM classes WHERE class_id=?", [$classId])->fetch();
        if (!$classRow) fail('Invalid class.');

        $classNum = (int)$classRow['class_num'];
        if ($classNum < 9) $groupId = null;

        // Build enrollments query
        $where  = 'e.session_id=? AND e.class_id=? AND e.status=\'active\'';
        $params = [$sessionId, $classId];
        if ($groupId) { $where .= ' AND e.group_id=?'; $params[] = $groupId; }

        $students = dbQuery(
            "SELECT
                s.sys_uid, s.student_id, s.full_name, s.gender, s.photo_path,
                e.enrollment_id, e.roll_number,
                COALESCE(a.status, 'present') AS att_status,
                a.attendance_id,
                a.marked_at,
                COALESCE(mu.username, '') AS marked_by_name
             FROM enrollments e
             JOIN students s ON s.sys_uid = e.sys_uid
             JOIN system_users su ON su.sys_uid = s.sys_uid AND su.is_active = 1
             LEFT JOIN attendance a ON a.enrollment_id = e.enrollment_id AND a.att_date = ?
             LEFT JOIN system_users mu ON mu.sys_uid = a.marked_by
             WHERE $where
             ORDER BY e.roll_number ASC, s.full_name ASC",
            array_merge([$date], $params)
        )->fetchAll();

        // Check if already marked
        $alreadyMarked = !empty(array_filter($students, fn($s) => $s['attendance_id']));

        // Summary counts if already marked
        $presentCount = count(array_filter($students, fn($s) => $s['attendance_id'] && $s['att_status'] === 'present'));
        $absentCount  = count(array_filter($students, fn($s) => $s['attendance_id'] && $s['att_status'] === 'absent'));
        $lateCount    = count(array_filter($students, fn($s) => $s['attendance_id'] && $s['att_status'] === 'late'));

        $dayName = date('l', strtotime($date)); // Saturday, Sunday...
        $isFriday = ($dayName === 'Friday'); // Bangladesh: Friday is holiday

        ok([
            'students'      => $students,
            'already_marked'=> $alreadyMarked,
            'present_count' => $presentCount,
            'absent_count'  => $absentCount,
            'late_count'    => $lateCount,
            'class_label'   => $classRow['label'],
            'class_num'     => $classNum,
            'day_name'      => $dayName,
            'is_holiday'    => $isFriday,
            'total'         => count($students),
        ]);
    } catch (Throwable $e) {
        error_log('EMS fetch_students: ' . $e->getMessage());
        fail('Failed to fetch students.');
    }
}

// ── SAVE ATTENDANCE (bulk upsert) ─────────────────────────────────
if ($action === 'save_attendance') {
    $sessionId  = (int)($_POST['session_id']   ?? 0);
    $classId    = (int)($_POST['class_id']     ?? 0);
    $date       = trim($_POST['att_date']      ?? '');
    $records    = $_POST['records'] ?? ''; // JSON: [{enrollment_id, status}, ...]

    if ($sessionId < 1 || $classId < 1) fail('Invalid session or class.');
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('Invalid date.');
    if ($date > date('Y-m-d')) fail('Cannot mark attendance for a future date.');

    $parsed = json_decode($records, true);
    if (!is_array($parsed) || empty($parsed)) fail('No attendance data provided.');

    $validStatuses = ['present', 'absent', 'late'];
    $actorUid      = $_SESSION['ems_uid'];
    $savedCount    = 0;
    $studentUidsByStatus = ['present' => [], 'absent' => [], 'late' => []];

    try {
        db()->beginTransaction();

        // Resolve enrollment_id -> sys_uid once for this class+session
        $enrollUidMap = [];
        $enrollRows = dbQuery(
            "SELECT enrollment_id, sys_uid FROM enrollments WHERE class_id=? AND session_id=?",
            [$classId, $sessionId]
        )->fetchAll();
        foreach ($enrollRows as $er) $enrollUidMap[(int)$er['enrollment_id']] = $er['sys_uid'];

        foreach ($parsed as $rec) {
            $enrollmentId = (int)($rec['enrollment_id'] ?? 0);
            $status       = trim($rec['status'] ?? '');

            if ($enrollmentId < 1 || !in_array($status, $validStatuses)) continue;

            // Verify enrollment belongs to this class+session
            if (!isset($enrollUidMap[$enrollmentId])) continue;

            // UPSERT — insert or update on duplicate key
            dbQuery(
                "INSERT INTO attendance (enrollment_id, att_date, status, marked_by, marked_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                     status     = VALUES(status),
                     updated_by = VALUES(marked_by),
                     updated_at = NOW()",
                [$enrollmentId, $date, $status, $actorUid]
            );
            $savedCount++;
            $studentUidsByStatus[$status][] = $enrollUidMap[$enrollmentId];
        }

        db()->commit();

        // Counts — derived from validated/saved records, not raw client input
        $presentCount = count($studentUidsByStatus['present']);
        $absentCount  = count($studentUidsByStatus['absent']);
        $lateCount    = count($studentUidsByStatus['late']);

        $classLabel = (string)(sv("SELECT label FROM classes WHERE class_id=?", [$classId]) ?? '');

        log_activity($actorUid, 'mark_attendance', 'attendance',
            $classId . '_' . $date,
            [
                'class_id'        => $classId,
                'session_id'      => $sessionId,
                'date'            => $date,
                'present'         => $presentCount,
                'absent'          => $absentCount,
                'late'            => $lateCount,
                'class'           => $classLabel,
                'student_uids'    => array_merge(...array_values($studentUidsByStatus)),
                'student_uids_by_status' => $studentUidsByStatus,
            ]
        );

        ok([
            'saved'         => $savedCount,
            'present_count' => $presentCount,
            'absent_count'  => $absentCount,
            'late_count'    => $lateCount,
            'msg'           => "Attendance saved for $savedCount student" . ($savedCount !== 1 ? 's' : '') .
                               " — Present: $presentCount, Absent: $absentCount, Late: $lateCount.",
        ]);
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('EMS save_attendance: ' . $e->getMessage());
        fail('Failed to save attendance. Please try again.');
    }
}

// ── FETCH REPORT (per-student summary for a class+session) ────────
if ($action === 'fetch_report') {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $classId   = (int)($_POST['class_id']   ?? 0);
    $groupId   = (int)($_POST['group_id']   ?? 0) ?: null;

    if ($sessionId < 1 || $classId < 1) fail('Please select a session and class.');

    try {
        $classRow = dbQuery("SELECT class_num, label FROM classes WHERE class_id=?", [$classId])->fetch();
        if (!$classRow) fail('Invalid class.');
        $classNum = (int)$classRow['class_num'];
        if ($classNum < 9) $groupId = null;

        $where  = 'e.session_id=? AND e.class_id=? AND e.status=\'active\'';
        $params = [$sessionId, $classId];
        if ($groupId) { $where .= ' AND e.group_id=?'; $params[] = $groupId; }

        $rows = dbQuery(
            "SELECT
                s.sys_uid, s.student_id, s.full_name, s.gender, s.photo_path,
                e.enrollment_id, e.roll_number,
                COUNT(a.attendance_id)                                           AS total_marked,
                SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END)             AS present_days,
                SUM(CASE WHEN a.status='absent'  THEN 1 ELSE 0 END)             AS absent_days,
                SUM(CASE WHEN a.status='late'    THEN 1 ELSE 0 END)             AS late_days
             FROM enrollments e
             JOIN students s ON s.sys_uid = e.sys_uid
             JOIN system_users su ON su.sys_uid = s.sys_uid AND su.is_active = 1
             LEFT JOIN attendance a ON a.enrollment_id = e.enrollment_id
             WHERE $where
             GROUP BY e.enrollment_id
             ORDER BY e.roll_number ASC, s.full_name ASC",
            $params
        )->fetchAll();

        // Total distinct dates attendance was marked for this class
        $totalDays = (int)(sv(
            "SELECT COUNT(DISTINCT a.att_date)
             FROM attendance a
             JOIN enrollments e ON e.enrollment_id = a.enrollment_id
             WHERE e.session_id=? AND e.class_id=?",
            [$sessionId, $classId]
        ) ?? 0);

        // Last attendance date
        $lastDate = sv(
            "SELECT MAX(a.att_date)
             FROM attendance a
             JOIN enrollments e ON e.enrollment_id = a.enrollment_id
             WHERE e.session_id=? AND e.class_id=?",
            [$sessionId, $classId]
        );

        // Class-level stats — 'late' counts as present (student did attend)
        $classPresentDays = (int)(sv(
            "SELECT SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END)
             FROM attendance a
             JOIN enrollments e ON e.enrollment_id=a.enrollment_id
             WHERE e.session_id=? AND e.class_id=?",
            [$sessionId, $classId]
        ) ?? 0);

        $classTotalRecords = (int)(sv(
            "SELECT COUNT(*) FROM attendance a
             JOIN enrollments e ON e.enrollment_id=a.enrollment_id
             WHERE e.session_id=? AND e.class_id=?",
            [$sessionId, $classId]
        ) ?? 0);

        $classAvgPct = $classTotalRecords > 0
            ? round($classPresentDays / $classTotalRecords * 100, 1) : 0;

        ok([
            'students'         => $rows,
            'total_days'       => $totalDays,
            'last_date'        => $lastDate,
            'class_label'      => $classRow['label'],
            'class_avg_pct'    => $classAvgPct,
            'class_total_rec'  => $classTotalRecords,
        ]);
    } catch (Throwable $e) {
        error_log('EMS fetch_report: ' . $e->getMessage());
        fail('Failed to fetch report.');
    }
}

// ── FETCH DATES that have attendance marked ───────────────────────
if ($action === 'fetch_date_status') {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $classId   = (int)($_POST['class_id']   ?? 0);
    $month     = trim($_POST['month']        ?? date('Y-m')); // YYYY-MM

    if ($sessionId < 1 || $classId < 1) fail('Invalid session or class.');

    try {
        $rows = dbQuery(
            "SELECT DISTINCT a.att_date,
                COUNT(*) AS total,
                SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN a.status='absent'  THEN 1 ELSE 0 END) AS absent_count
             FROM attendance a
             JOIN enrollments e ON e.enrollment_id = a.enrollment_id
             WHERE e.session_id=? AND e.class_id=?
             AND DATE_FORMAT(a.att_date,'%Y-%m')=?
             GROUP BY a.att_date
             ORDER BY a.att_date ASC",
            [$sessionId, $classId, $month]
        )->fetchAll();

        ok(['dates' => $rows, 'month' => $month]);
    } catch (Throwable $e) {
        fail('Failed to fetch date status.');
    }
}

fail('Unknown action.');
<?php
/**
 * EMS Teacher — Dashboard AJAX Handler
 * ─────────────────────────────────────────────────────────────────
 * Action:
 *   fetch_dashboard – returns everything the teacher dashboard needs:
 *                     quick stats, today's schedule, pending marks
 *                     entry list, and a recent activity feed.
 *
 * Security: every query is scoped to the logged-in teacher's own
 * sys_uid (via $_SESSION['ems_uid']) — never trusts any client input
 * for identity. This endpoint takes no identifying parameters at all.
 */
require_once __DIR__ . '/../includes/teacher-session.php';

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

// ── Safe query helpers (local to this file, project convention) ───
function tsafeVal(string $sql, array $p = []): mixed {
    try { $v = dbQuery($sql, $p)->fetchColumn(); return $v !== false ? $v : null; }
    catch (Throwable $e) { error_log('EMS teacher dashboard tsafeVal: ' . $e->getMessage()); return null; }
}
function tsafeRow(string $sql, array $p = []): ?array {
    try { $r = dbQuery($sql, $p)->fetch(); return $r ?: null; }
    catch (Throwable $e) { error_log('EMS teacher dashboard tsafeRow: ' . $e->getMessage()); return null; }
}
function tsafeAll(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); }
    catch (Throwable $e) { error_log('EMS teacher dashboard tsafeAll: ' . $e->getMessage()); return []; }
}

function timeAgoLocal(string $dt): string {
    $d = time() - strtotime($dt);
    if ($d < 60)     return 'Just now';
    if ($d < 3600)   return floor($d / 60) . ' min ago';
    if ($d < 86400)  return floor($d / 3600) . ' hr ago';
    if ($d < 172800) return 'Yesterday';
    return floor($d / 86400) . ' days ago';
}

// Maps an activity_logs row to display-ready text/icon/color.
// Unrecognised action types still render gracefully with a generic label.
function teacherActivityDisplay(array $row): array {
    $map = [
        'login'             => ['fa-right-to-bracket',  'green', 'You logged in'],
        'logout'            => ['fa-right-from-bracket', 'gray', 'You logged out'],
        'mark_attendance'   => ['fa-calendar-check',    'green', 'You marked attendance'],
        'update_attendance' => ['fa-calendar-check',    'amber', 'You updated attendance'],
        'submit_marks'      => ['fa-pencil',              'sky', 'You submitted marks'],
        'update_marks'      => ['fa-pencil',            'amber', 'You updated marks'],
    ];
    [$icon, $color, $label] = $map[$row['action_type']] ?? ['fa-circle-dot', 'gray', 'You performed an action'];

    $meta = [];
    if (!empty($row['meta_json'])) {
        $decoded = json_decode($row['meta_json'], true);
        if (is_array($decoded)) $meta = $decoded;
    }

    $detail = '';
    if (!empty($meta['class_label']))   $detail .= ' for ' . $meta['class_label'];
    if (!empty($meta['subject_name']))  $detail .= ' — ' . $meta['subject_name'];
    if (!empty($meta['count']))         $detail .= ' (' . (int)$meta['count'] . ' students)';

    return [
        'icon'     => $icon,
        'color'    => $color,
        'text'     => $label . $detail,
        'time_ago' => timeAgoLocal($row['logged_at']),
    ];
}

$action = trim($_POST['action'] ?? '');

if ($action !== 'fetch_dashboard') {
    fail('Unknown action.');
}

try {
    // ── Current academic session ───────────────────────────────────
    $session = tsafeRow("SELECT session_id, session_year FROM sessions WHERE is_current = 1 LIMIT 1");
    $sid     = $session['session_id']   ?? null;
    $sYear   = $session['session_year'] ?? null;
    $noSession = $sid === null;

    // ── Bangladesh school week: Sat=0 ... Thu=5, Friday = weekend ──
    $phpDow    = (int)date('w');                                   // PHP: 0=Sun...6=Sat
    $dowMap    = [0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 0, 6 => 0];
    $isFriday  = ($phpDow === 5);
    $schoolDow = $dowMap[$phpDow] ?? null;

    // ── Today's classes (real scheduled slots only — period_number >= 1
    //     excludes the placeholder rows created by assign-teacher) ──
    $todayRows = [];
    if (!$isFriday && $sid !== null) {
        $todayRows = tsafeAll(
            "SELECT cr.routine_id, cr.period_number, cr.start_time, cr.end_time,
                    sub.subject_name, c.label AS class_label, g.group_name
             FROM class_routine cr
             JOIN subjects sub ON sub.subject_id = cr.subject_id
             JOIN classes  c   ON c.class_id      = cr.class_id
             LEFT JOIN groups g ON g.group_id     = cr.group_id
             WHERE cr.teacher_uid = ? AND cr.session_id = ? AND cr.day_of_week = ? AND cr.period_number >= 1
             ORDER BY cr.start_time ASC, cr.period_number ASC",
            [$myUid, $sid, $schoolDow]
        );
    }

    // Attach live status (done / current / upcoming) by comparing to now
    $nowTime = date('H:i:s');
    foreach ($todayRows as &$row) {
        if ($row['end_time'] && $nowTime > $row['end_time']) {
            $row['status'] = 'done';
        } elseif ($row['start_time'] && $row['end_time'] && $nowTime >= $row['start_time'] && $nowTime <= $row['end_time']) {
            $row['status'] = 'current';
        } else {
            $row['status'] = 'upcoming';
        }
    }
    unset($row);

    // ── My Students — distinct active enrollments across every class
    //     this teacher is assigned to teach this session ─────────────
    $myStudents = 0;
    if ($sid !== null) {
        $myStudents = (int)(tsafeVal(
            "SELECT COUNT(DISTINCT e.enrollment_id)
             FROM enrollments e
             WHERE e.session_id = ? AND e.status = 'active'
             AND EXISTS (
                 SELECT 1 FROM class_routine cr
                 WHERE cr.teacher_uid = ? AND cr.session_id = ? AND cr.class_id = e.class_id
                 AND (cr.group_id IS NULL OR cr.group_id = e.group_id)
             )",
            [$sid, $myUid, $sid]
        ) ?? 0);
    }

    // ── Attendance rate this month, for classes this teacher teaches ─
    $attendanceRate = null;
    if ($sid !== null) {
        $attRow = tsafeRow(
            "SELECT
                SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_cnt,
                COUNT(*) AS total_cnt
             FROM attendance a
             JOIN enrollments e ON e.enrollment_id = a.enrollment_id
             WHERE e.session_id = ? AND a.att_date >= DATE_FORMAT(NOW(), '%Y-%m-01')
             AND EXISTS (
                 SELECT 1 FROM class_routine cr
                 WHERE cr.teacher_uid = ? AND cr.session_id = ? AND cr.class_id = e.class_id
                 AND (cr.group_id IS NULL OR cr.group_id = e.group_id)
             )",
            [$sid, $myUid, $sid]
        );
        if ($attRow && (int)$attRow['total_cnt'] > 0) {
            $attendanceRate = round(((int)$attRow['present_cnt'] / (int)$attRow['total_cnt']) * 100, 1);
        }
    }

    // ── Pending marks entry — draft exams, subjects this teacher
    //     teaches, where fewer marks exist than active enrollments ───
    $pendingList  = [];
    $pendingCount = 0;
    if ($sid !== null) {
        $candidateRows = tsafeAll(
            "SELECT es.exam_subject_id, ex.exam_id, ex.exam_name, ex.group_id AS exam_group_id,
                    c.label AS class_label, sub.subject_name, es.exam_date,
                    (SELECT COUNT(*) FROM enrollments en
                       WHERE en.class_id = ex.class_id AND en.session_id = ex.session_id AND en.status = 'active'
                       AND (ex.group_id IS NULL OR en.group_id = ex.group_id)) AS total_students,
                    (SELECT COUNT(*) FROM student_marks sm WHERE sm.exam_subject_id = es.exam_subject_id) AS marked_count
             FROM exam_subjects es
             JOIN exams   ex  ON ex.exam_id     = es.exam_id
             JOIN subjects sub ON sub.subject_id = es.subject_id
             JOIN classes  c  ON c.class_id      = ex.class_id
             WHERE ex.status = 'draft' AND ex.session_id = ?
             AND EXISTS (
                 SELECT 1 FROM class_routine cr
                 WHERE cr.teacher_uid = ? AND cr.session_id = ex.session_id
                 AND cr.class_id = ex.class_id AND cr.subject_id = es.subject_id
             )
             ORDER BY (es.exam_date IS NULL) ASC, es.exam_date ASC, ex.exam_name ASC",
            [$sid, $myUid]
        );

        $pendingAll = array_values(array_filter($candidateRows, function ($r) {
            return (int)$r['total_students'] > 0 && (int)$r['marked_count'] < (int)$r['total_students'];
        }));

        $pendingCount = count($pendingAll);
        $pendingList  = array_slice($pendingAll, 0, 5);
    }

    // ── Recent activity (this teacher's own actions) ────────────────
    $activityRows = tsafeAll(
        "SELECT action_type, target_type, target_uid, meta_json, logged_at
         FROM activity_logs
         WHERE actor_uid = ?
         ORDER BY logged_at DESC
         LIMIT 6",
        [$myUid]
    );
    $activity = array_map('teacherActivityDisplay', $activityRows);

    // ── Assemble response ────────────────────────────────────────────
    ok([
        'no_session'   => $noSession,
        'session_year' => $sYear,
        'is_friday'    => $isFriday,
        'stats' => [
            'today_classes'   => count($todayRows),
            'pending_marks'   => $pendingCount,
            'my_students'     => $myStudents,
            'attendance_rate' => $attendanceRate,
        ],
        'today' => array_map(function ($r) {
            return [
                'routine_id'    => (int)$r['routine_id'],
                'period_number' => (int)$r['period_number'],
                'start_time'    => $r['start_time'],
                'end_time'      => $r['end_time'],
                'subject_name'  => $r['subject_name'],
                'class_label'   => $r['class_label'],
                'group_name'    => $r['group_name'],
                'status'        => $r['status'],
            ];
        }, $todayRows),
        'pending_marks_list' => array_map(function ($r) {
            return [
                'exam_subject_id' => (int)$r['exam_subject_id'],
                'exam_id'         => (int)$r['exam_id'],
                'exam_name'       => $r['exam_name'],
                'class_label'     => $r['class_label'],
                'subject_name'    => $r['subject_name'],
                'total_students'  => (int)$r['total_students'],
                'marked_count'    => (int)$r['marked_count'],
            ];
        }, $pendingList),
        'activity' => $activity,
    ]);

} catch (Throwable $e) {
    error_log('EMS teacher dashboard fetch_dashboard: ' . $e->getMessage());
    fail('Failed to load your dashboard. Please try again.');
}
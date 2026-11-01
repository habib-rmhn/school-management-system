<?php
/**
 * EMS Student — Seat Plan AJAX Handler
 * ─────────────────────────────────────────────────────────────────
 * Actions:
 *   fetch_sessions  – every enrollment this student has had
 *                     (for the class/session switcher)
 *   fetch_exams     – exams for ONE enrollment with seat-plan
 *                     readiness status (per subject)
 *   fetch_seat_plan – this student's room/seat for every subject of
 *                     one exam, plus enough room context (capacity,
 *                     description) to draw a simple seat-finder grid
 *
 * Business rules:
 *   - A student excluded from an exam (seat_exclusions) sees that
 *     plainly instead of a blank/broken seat plan.
 *   - Seat plan visibility does NOT depend on fee payment — unlike
 *     the admit card, a student should be able to find their seat
 *     even before clearing dues, since the seat plan itself isn't
 *     the gated document (the printable admit card is).
 *   - We never expose other students' names/IDs here — only seat
 *     numbers and whether a seat is occupied — for privacy. Admin's
 *     own room sheet (which does show names) is a separate, staff-
 *     only view and is intentionally not replicated here.
 *
 * Security: enrollment_id is always re-verified against the logged
 * in student's own sys_uid before anything is returned.
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
function sprows(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); }
    catch (Throwable $e) { return []; }
}
function spval(string $sql, array $p = []): mixed {
    try { $v = dbQuery($sql, $p)->fetchColumn(); return $v !== false ? $v : null; }
    catch (Throwable $e) { return null; }
}

/** Verify an enrollment_id genuinely belongs to this student. */
function myEnrollmentSP(int $enrollmentId, string $myUid): ?array {
    $row = dbQuery(
        "SELECT e.enrollment_id, e.class_id, e.session_id, e.group_id, e.status,
                c.label AS class_label, sess.session_year, g.group_name
         FROM enrollments e
         JOIN classes  c    ON c.class_id      = e.class_id
         JOIN sessions sess ON sess.session_id = e.session_id
         LEFT JOIN groups g ON g.group_id      = e.group_id
         WHERE e.enrollment_id = ? AND e.sys_uid = ?",
        [$enrollmentId, $myUid]
    )->fetch();
    return $row ?: null;
}

$action = trim($_POST['action'] ?? '');

// ── FETCH SESSIONS — every enrollment this student has had ────────
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
        error_log('EMS student seat-plan fetch_sessions: ' . $e->getMessage());
        fail('Could not load your class sessions.');
    }
}

// ── FETCH EXAMS — exams with seat-plan status, one enrollment ──────
if ($action === 'fetch_exams') {
    $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    if ($enrollmentId < 1) fail('Please choose a class session.');

    $enroll = myEnrollmentSP($enrollmentId, $myUid);
    if (!$enroll) fail('That class session was not found on your account.');

    try {
        $gWhere = $enroll['group_id'] ? ' AND (ex.group_id IS NULL OR ex.group_id = ?)' : '';
        $params = [$enroll['class_id'], $enroll['session_id']];
        if ($enroll['group_id']) $params[] = $enroll['group_id'];

        $exams = dbQuery(
            "SELECT ex.exam_id, ex.exam_name,
                    MIN(es.exam_date) AS first_date, MAX(es.exam_date) AS last_date,
                    COUNT(es.exam_subject_id) AS subject_count
             FROM exams ex
             LEFT JOIN exam_subjects es ON es.exam_id = ex.exam_id
             WHERE ex.class_id = ? AND ex.session_id = ? $gWhere
             GROUP BY ex.exam_id
             ORDER BY first_date ASC",
            $params
        )->fetchAll();

        $today = date('Y-m-d');
        $examList = [];
        foreach ($exams as $ex) {
            if ((int)$ex['subject_count'] === 0) continue;

            $isPast = $ex['last_date'] && $ex['last_date'] < $today;

            $excluded = (bool)spval(
                "SELECT 1 FROM seat_exclusions WHERE exam_id = ? AND enrollment_id = ?",
                [$ex['exam_id'], $enrollmentId]
            );

            // How many of this exam's subjects already have this student's seat assigned?
            $seatedCount = (int)spval(
                "SELECT COUNT(*) FROM seat_allocations_v2 sa
                 JOIN exam_subjects es2 ON es2.exam_subject_id = sa.exam_subject_id
                 WHERE es2.exam_id = ? AND sa.enrollment_id = ?",
                [$ex['exam_id'], $enrollmentId]
            );

            $examList[] = [
                'exam_id'       => (int)$ex['exam_id'],
                'exam_name'     => $ex['exam_name'],
                'first_date'    => $ex['first_date'],
                'last_date'     => $ex['last_date'],
                'subject_count' => (int)$ex['subject_count'],
                'seated_count'  => $seatedCount,
                'is_past'       => $isPast,
                'is_excluded'   => $excluded,
                'is_ready'      => ($seatedCount > 0),
            ];
        }

        ok([
            'exams'        => $examList,
            'class_label'  => $enroll['class_label'],
            'session_year' => $enroll['session_year'],
            'group_name'   => $enroll['group_name'],
        ]);
    } catch (Throwable $e) {
        error_log('EMS student seat-plan fetch_exams: ' . $e->getMessage());
        fail('Failed to load your exams. Please try again.');
    }
}

// ── FETCH SEAT PLAN — room/seat + grid context for one exam ────────
if ($action === 'fetch_seat_plan') {
    $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    $examId       = (int)($_POST['exam_id']       ?? 0);
    if ($enrollmentId < 1 || $examId < 1) fail('Invalid request.');

    $enroll = myEnrollmentSP($enrollmentId, $myUid);
    if (!$enroll) fail('That class session was not found on your account.');

    try {
        $exam = dbQuery(
            "SELECT exam_id, exam_name FROM exams
             WHERE exam_id = ? AND class_id = ? AND session_id = ?",
            [$examId, $enroll['class_id'], $enroll['session_id']]
        )->fetch();
        if (!$exam) fail('This exam was not found for your class.');

        $excluded = (bool)spval(
            "SELECT 1 FROM seat_exclusions WHERE exam_id = ? AND enrollment_id = ?",
            [$examId, $enrollmentId]
        );
        if ($excluded) {
            fail('You are not registered to sit this exam. Please contact your school admin office.');
        }

        // Every subject for this exam, with this student's seat (if assigned)
        // and the room's capacity/description for drawing a seat-finder grid.
        $subjects = sprows(
            "SELECT es.exam_subject_id, es.sort_order, es.exam_date, es.start_time,
                    s.subject_name, s.subject_code,
                    sa.seat_number, sa.usage_id,
                    erg.room_number, erg.capacity, erg.description
             FROM exam_subjects es
             JOIN subjects s ON s.subject_id = es.subject_id
             LEFT JOIN seat_allocations_v2 sa
                    ON sa.exam_subject_id = es.exam_subject_id AND sa.enrollment_id = ?
             LEFT JOIN exam_room_usage eru ON eru.usage_id = sa.usage_id
             LEFT JOIN exam_rooms_global erg ON erg.room_id = eru.room_id
             WHERE es.exam_id = ?
             ORDER BY es.sort_order, es.exam_date, es.start_time, s.subject_name",
            [$enrollmentId, $examId]
        );

        if (empty($subjects)) fail('No subjects have been scheduled for this exam yet.');

        // For each subject that DOES have a seat, also count how many total
        // seats are filled in that room+subject — purely a count, never names,
        // so the student can see "32 of 40 seats filled" without identities.
        $result = [];
        foreach ($subjects as $sub) {
            $filledCount = null;
            if ($sub['usage_id']) {
                $filledCount = (int)spval(
                    "SELECT COUNT(*) FROM seat_allocations_v2
                     WHERE usage_id = ? AND exam_subject_id = ?",
                    [$sub['usage_id'], $sub['exam_subject_id']]
                );
            }
            $result[] = [
                'subject_name' => $sub['subject_name'],
                'subject_code' => $sub['subject_code'],
                'exam_date'    => $sub['exam_date'],
                'start_time'   => $sub['start_time'],
                'room_number'  => $sub['room_number'],
                'description'  => $sub['description'],
                'capacity'     => $sub['capacity'] ? (int)$sub['capacity'] : null,
                'seat_number'  => $sub['seat_number'],
                'filled_count' => $filledCount,
            ];
        }

        ok([
            'exam'     => ['exam_name' => $exam['exam_name']],
            'class_label'  => $enroll['class_label'],
            'session_year' => $enroll['session_year'],
            'group_name'   => $enroll['group_name'],
            'subjects' => $result,
        ]);
    } catch (Throwable $e) {
        error_log('EMS student fetch_seat_plan: ' . $e->getMessage());
        fail('Failed to load your seat plan. Please try again.');
    }
}

fail('Unknown action.');
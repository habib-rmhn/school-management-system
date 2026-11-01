<?php
/**
 * EMS Student — Admit Card AJAX Handler
 * ─────────────────────────────────────────────────────────────────
 * Actions:
 *   fetch_sessions   – every enrollment this student has had
 *                      (for the class/session switcher)
 *   fetch_exams      – exams for ONE enrollment where an admit card
 *                      could exist (exam created, not yet over),
 *                      with payment-gate + seat-plan readiness status
 *   fetch_admit_card – the full admit card data (subjects, dates,
 *                      times, room, seat) — ONLY if fees are fully
 *                      paid and the student is not excluded
 *
 * Business rules (must mirror the admin side exactly):
 *   - Admit card becomes visible from the day the exam is CREATED
 *     until its LAST subject's exam date (after that it's history,
 *     no longer "upcoming", so we stop offering the download).
 *   - Fully paid fees are mandatory: ANY unpaid/partial due for this
 *     enrollment blocks the admit card, regardless of which exam.
 *   - A student in `seat_exclusions` for this exam gets no admit
 *     card at all (excluded from sitting this exam).
 *   - Seat plan (room/seat) must exist per-subject; if the admin
 *     hasn't finalized the seat plan yet, we say so plainly instead
 *     of showing a blank room.
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
function arows(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); }
    catch (Throwable $e) { return []; }
}
function aval(string $sql, array $p = []): mixed {
    try { $v = dbQuery($sql, $p)->fetchColumn(); return $v !== false ? $v : null; }
    catch (Throwable $e) { return null; }
}

/** Verify an enrollment_id genuinely belongs to this student. */
function myEnrollmentAC(int $enrollmentId, string $myUid): ?array {
    $row = dbQuery(
        "SELECT e.enrollment_id, e.class_id, e.session_id, e.group_id, e.status,
                e.roll_number, e.scholarship_pct,
                c.label AS class_label, sess.session_year, g.group_name,
                s.full_name, s.student_id, s.photo_path, s.father_name, s.date_of_birth
         FROM enrollments e
         JOIN classes  c    ON c.class_id      = e.class_id
         JOIN sessions sess ON sess.session_id = e.session_id
         JOIN students s    ON s.sys_uid       = e.sys_uid
         LEFT JOIN groups g ON g.group_id      = e.group_id
         WHERE e.enrollment_id = ? AND e.sys_uid = ?",
        [$enrollmentId, $myUid]
    )->fetch();
    return $row ?: null;
}

/** Whether this enrollment has any unpaid/partial due. Returns [isClear, totalDue, totalPaid]. */
function feeStatus(int $enrollmentId): array {
    $totalDue = (float)(aval(
        "SELECT COALESCE(SUM(amount_due),0) FROM payment_dues WHERE enrollment_id = ?",
        [$enrollmentId]
    ) ?? 0);
    $totalPaid = (float)(aval(
        "SELECT COALESCE(SUM(pr.amount_paid),0)
         FROM payment_dues pd
         LEFT JOIN payment_records pr ON pr.due_id = pd.due_id
         WHERE pd.enrollment_id = ?",
        [$enrollmentId]
    ) ?? 0);
    $balance = round($totalDue - $totalPaid, 2);
    return [$balance <= 0, $totalDue, $totalPaid, $balance];
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
        error_log('EMS student admit-card fetch_sessions: ' . $e->getMessage());
        fail('Could not load your class sessions.');
    }
}

// ── FETCH EXAMS — exams with a potential admit card, one enrollment ──
if ($action === 'fetch_exams') {
    $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    if ($enrollmentId < 1) fail('Please choose a class session.');

    $enroll = myEnrollmentAC($enrollmentId, $myUid);
    if (!$enroll) fail('That class session was not found on your account.');

    try {
        // Group-aware: include exams with NULL group (whole class) or matching group
        $gWhere = $enroll['group_id'] ? ' AND (ex.group_id IS NULL OR ex.group_id = ?)' : '';
        $params = [$enroll['class_id'], $enroll['session_id']];
        if ($enroll['group_id']) $params[] = $enroll['group_id'];

        // An exam is "admit-card eligible" once created; we show it until its
        // last subject's exam_date has passed (then it moves to history).
        $exams = dbQuery(
            "SELECT ex.exam_id, ex.exam_name, ex.created_at,
                    MIN(es.exam_date) AS first_date, MAX(es.exam_date) AS last_date,
                    COUNT(es.exam_subject_id) AS subject_count
             FROM exams ex
             LEFT JOIN exam_subjects es ON es.exam_id = ex.exam_id
             WHERE ex.class_id = ? AND ex.session_id = ? $gWhere
             GROUP BY ex.exam_id
             ORDER BY first_date ASC",
            $params
        )->fetchAll();

        [$isClear, $totalDue, $totalPaid, $balance] = feeStatus($enrollmentId);

        $today = date('Y-m-d');
        $examList = [];
        foreach ($exams as $ex) {
            // Skip exams with no subjects configured yet — nothing to show
            if ((int)$ex['subject_count'] === 0) continue;

            $isPast = $ex['last_date'] && $ex['last_date'] < $today;

            // Is this student excluded from this exam entirely?
            $excluded = (bool)aval(
                "SELECT 1 FROM seat_exclusions WHERE exam_id = ? AND enrollment_id = ?",
                [$ex['exam_id'], $enrollmentId]
            );

            // Has the seat plan been finalized for at least one subject?
            $hasSeatPlan = (bool)aval(
                "SELECT 1 FROM seat_allocations_v2 sa
                 JOIN exam_subjects es2 ON es2.exam_subject_id = sa.exam_subject_id
                 WHERE es2.exam_id = ? AND sa.enrollment_id = ?",
                [$ex['exam_id'], $enrollmentId]
            );

            $examList[] = [
                'exam_id'        => (int)$ex['exam_id'],
                'exam_name'      => $ex['exam_name'],
                'first_date'     => $ex['first_date'],
                'last_date'      => $ex['last_date'],
                'subject_count'  => (int)$ex['subject_count'],
                'is_past'        => $isPast,
                'is_excluded'    => $excluded,
                'has_seat_plan'  => $hasSeatPlan,
            ];
        }

        ok([
            'exams'        => $examList,
            'class_label'  => $enroll['class_label'],
            'session_year' => $enroll['session_year'],
            'group_name'   => $enroll['group_name'],
            'fee_clear'    => $isClear,
            'total_due'    => $totalDue,
            'total_paid'   => $totalPaid,
            'balance'      => $balance,
        ]);
    } catch (Throwable $e) {
        error_log('EMS student admit-card fetch_exams: ' . $e->getMessage());
        fail('Failed to load your exams. Please try again.');
    }
}

// ── FETCH ADMIT CARD — full data, only if fees clear & not excluded ──
if ($action === 'fetch_admit_card') {
    $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    $examId       = (int)($_POST['exam_id']       ?? 0);
    if ($enrollmentId < 1 || $examId < 1) fail('Invalid request.');

    $enroll = myEnrollmentAC($enrollmentId, $myUid);
    if (!$enroll) fail('That class session was not found on your account.');

    try {
        // Exam must belong to this enrollment's class+session
        $exam = dbQuery(
            "SELECT ex.exam_id, ex.exam_name, ex.class_id, ex.session_id
             FROM exams ex
             WHERE ex.exam_id = ? AND ex.class_id = ? AND ex.session_id = ?",
            [$examId, $enroll['class_id'], $enroll['session_id']]
        )->fetch();
        if (!$exam) fail('This exam was not found for your class.');

        // Payment gate — block regardless of which exam
        [$isClear, $totalDue, $totalPaid, $balance] = feeStatus($enrollmentId);
        if (!$isClear) {
            fail('Your admit card is locked because you have unpaid fees. Please clear your dues first.');
        }

        // Exclusion check
        $excluded = (bool)aval(
            "SELECT 1 FROM seat_exclusions WHERE exam_id = ? AND enrollment_id = ?",
            [$examId, $enrollmentId]
        );
        if ($excluded) {
            fail('You are not registered to sit this exam. Please contact your school admin office.');
        }

        // Subjects with date/time + this student's room & seat (if assigned)
        $subjects = arows(
            "SELECT es.exam_subject_id, es.sort_order, es.exam_date, es.start_time, es.duration_min,
                    s.subject_name, s.subject_code,
                    sa.seat_number, erg.room_number
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

        $missingSeat = false;
        foreach ($subjects as $s) {
            if (!$s['room_number'] || !$s['seat_number']) { $missingSeat = true; break; }
        }

        ok([
            'exam'      => [
                'exam_id'      => (int)$exam['exam_id'],
                'exam_name'    => $exam['exam_name'],
                'class_label'  => $enroll['class_label'],
                'session_year' => $enroll['session_year'],
                'group_name'   => $enroll['group_name'],
            ],
            'student' => [
                'full_name'     => $enroll['full_name'],
                'student_id'    => $enroll['student_id'],
                'father_name'   => $enroll['father_name'],
                'date_of_birth' => $enroll['date_of_birth'],
                'roll_number'   => $enroll['roll_number'],
                'photo_path'    => $enroll['photo_path'],
            ],
            'subjects'      => $subjects,
            'missing_seat'  => $missingSeat,
            'school_name'   => EMS_SCHOOL,
        ]);
    } catch (Throwable $e) {
        error_log('EMS student fetch_admit_card: ' . $e->getMessage());
        fail('Failed to load your admit card. Please try again.');
    }
}

fail('Unknown action.');
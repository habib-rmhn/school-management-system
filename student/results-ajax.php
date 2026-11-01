<?php
/**
 * EMS Student — My Results AJAX Handler
 * ─────────────────────────────────────────────────────────────────
 * Actions:
 *   fetch_sessions   – every enrollment this student has had
 *                      (for the class/session switcher)
 *   fetch_exams      – published exams for ONE of this student's
 *                      own enrollments (exam list / cards)
 *   fetch_result     – full per-subject mark sheet + GPA summary
 *                      for ONE published exam, ONE enrollment
 *
 * Security rules (strict, never relaxed):
 *   1. enrollment_id is ALWAYS re-verified against the logged-in
 *      student's own sys_uid before anything is returned.
 *   2. Only exams with status = 'published' are ever visible here.
 *      A draft exam's marks must never leak to a student, even if
 *      a teacher has already entered some marks for it.
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
function srows(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); }
    catch (Throwable $e) { return []; }
}

/**
 * Verify an enrollment_id genuinely belongs to this student, and
 * return its row. Returns null if not found / not owned.
 */
function myEnrollment(int $enrollmentId, string $myUid): ?array {
    $row = dbQuery(
        "SELECT e.enrollment_id, e.class_id, e.session_id, e.group_id, e.status,
                e.main_subject_id, e.fourth_subject_id, e.eliminated_subject_id,
                c.label AS class_label, c.class_num,
                sess.session_year, g.group_name
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
        error_log('EMS student results fetch_sessions: ' . $e->getMessage());
        fail('Could not load your class sessions.');
    }
}

// ── FETCH EXAMS — published exams for one enrollment ───────────────
if ($action === 'fetch_exams') {
    $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    if ($enrollmentId < 1) fail('Please choose a class session.');

    $enroll = myEnrollment($enrollmentId, $myUid);
    if (!$enroll) fail('That class session was not found on your account.');

    try {
        // Published exams for this class+session (group-aware for class 9-10)
        $gWhere  = $enroll['group_id'] ? ' AND (ex.group_id IS NULL OR ex.group_id = ?)' : '';
        $params  = [$enroll['class_id'], $enroll['session_id']];
        if ($enroll['group_id']) $params[] = $enroll['group_id'];

        $exams = dbQuery(
            "SELECT ex.exam_id, ex.exam_name, ex.published_at,
                    ers.summary_id, ers.gpa, ers.total_marks, ers.overall_status, ers.position
             FROM exams ex
             LEFT JOIN exam_results_summary ers
                    ON ers.exam_id = ex.exam_id AND ers.enrollment_id = ?
             WHERE ex.class_id = ? AND ex.session_id = ? AND ex.status = 'published' $gWhere
             ORDER BY ex.published_at DESC",
            array_merge([$enrollmentId], $params)
        )->fetchAll();

        // Total students in this class+session+group, to show rank as "3rd of 28"
        $gWhere2 = $enroll['group_id'] ? ' AND group_id = ?' : '';
        $params2 = [$enroll['class_id'], $enroll['session_id']];
        if ($enroll['group_id']) $params2[] = $enroll['group_id'];
        $classSize = (int)dbQuery(
            "SELECT COUNT(*) FROM enrollments WHERE class_id = ? AND session_id = ? AND status='active' $gWhere2",
            $params2
        )->fetchColumn();

        ok([
            'exams'        => $exams,
            'class_label'  => $enroll['class_label'],
            'session_year' => $enroll['session_year'],
            'group_name'   => $enroll['group_name'],
            'class_size'   => $classSize,
        ]);
    } catch (Throwable $e) {
        error_log('EMS student results fetch_exams: ' . $e->getMessage());
        fail('Failed to load your exams. Please try again.');
    }
}

// ── FETCH RESULT — full per-subject sheet for one published exam ──
if ($action === 'fetch_result') {
    $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    $examId       = (int)($_POST['exam_id']       ?? 0);
    if ($enrollmentId < 1 || $examId < 1) fail('Invalid request.');

    $enroll = myEnrollment($enrollmentId, $myUid);
    if (!$enroll) fail('That class session was not found on your account.');

    try {
        // The exam MUST belong to this enrollment's class+session AND be published —
        // a student can never view a draft exam's result, even their own.
        $exam = dbQuery(
            "SELECT ex.exam_id, ex.exam_name, ex.status, ex.published_at,
                    c.label AS class_label, sess.session_year, g.group_name
             FROM exams ex
             JOIN classes  c    ON c.class_id      = ex.class_id
             JOIN sessions sess ON sess.session_id = ex.session_id
             LEFT JOIN groups g ON g.group_id      = ex.group_id
             WHERE ex.exam_id = ? AND ex.class_id = ? AND ex.session_id = ? AND ex.status = 'published'",
            [$examId, $enroll['class_id'], $enroll['session_id']]
        )->fetch();

        if (!$exam) fail('This exam result is not available.');

        // Subjects configured for this exam, in display order.
        // Join subjects table to get is_choice/group_id for per-student filtering.
        $subjects = srows(
            "SELECT es.exam_subject_id, es.sort_order, es.has_mcq, es.has_cq,
                    es.mcq_full_marks, es.mcq_convert_to,
                    es.cq_full_marks, es.cq_convert_to,
                    es.pass_percentage,
                    s.subject_id, s.subject_name, s.subject_code,
                    s.is_choice, s.group_id AS subj_group_id
             FROM exam_subjects es
             JOIN subjects s ON s.subject_id = es.subject_id
             WHERE es.exam_id = ?
             ORDER BY es.sort_order, s.subject_name",
            [$examId]
        );

        // Resolve this student's subject-choice context for class 9-10 filtering.
        $fourthSubjectId   = $enroll['fourth_subject_id']   ? (int)$enroll['fourth_subject_id']   : null;
        $mainSubjectId     = $enroll['main_subject_id']     ? (int)$enroll['main_subject_id']     : null;
        $elimSubjectId     = $enroll['eliminated_subject_id'] ? (int)$enroll['eliminated_subject_id'] : null;
        $examGroupId       = $exam['group_id'] ? (int)$exam['group_id'] : null;

        // Filter subjects to only those that apply to this student:
        // - Non-choice (compulsory) subjects: always shown UNLESS it is the student's eliminated subject
        // - Choice subject, own exam's group: shown only if this is the student's main_subject_id
        // - Choice subject, other group: shown only if this is the student's fourth_subject_id
        //   (cross-group 4th subject appears in its own group's exam, not the student's home exam,
        //    so it will only appear here if the exam's group_id matches the subject's group_id)
        $filteredSubjects = array_filter($subjects, function($sub) use
            ($fourthSubjectId, $mainSubjectId, $elimSubjectId, $examGroupId) {
            $subjId      = (int)$sub['subject_id'];
            $isChoice    = (int)$sub['is_choice'] === 1;
            $subjGroupId = $sub['subj_group_id'] ? (int)$sub['subj_group_id'] : null;

            if (!$isChoice) {
                // Compulsory subject — exclude only if this is the student's eliminated subject
                return $subjId !== $elimSubjectId;
            }

            // Choice subject in the exam's own group → only shown if it's the student's main subject
            if ($examGroupId && $subjGroupId === $examGroupId) {
                return $subjId === $mainSubjectId;
            }

            // Choice subject from another group → only shown if it's the student's 4th subject
            return $subjId === $fourthSubjectId;
        });
        $subjects = array_values($filteredSubjects);

        // This student's own marks for each subject of this exam
        $marks = srows(
            "SELECT sm.exam_subject_id,
                    sm.mcq_marks_raw, sm.mcq_marks_converted,
                    sm.cq_marks_raw,  sm.cq_marks_converted,
                    sm.total_marks, sm.grade, sm.grade_point, sm.subject_status
             FROM student_marks sm
             WHERE sm.enrollment_id = ? AND sm.exam_subject_id IN (
                 SELECT exam_subject_id FROM exam_subjects WHERE exam_id = ?
             )",
            [$enrollmentId, $examId]
        );
        $marksIdx = [];
        foreach ($marks as $m) {
            $marksIdx[(int)$m['exam_subject_id']] = $m;
        }

        // Build the final per-subject rows in subject order
        $subjectRows = [];
        foreach ($subjects as $sub) {
            $esId          = (int)$sub['exam_subject_id'];
            $subjId        = (int)$sub['subject_id'];
            $m             = $marksIdx[$esId] ?? null;
            $isFourth      = ($fourthSubjectId !== null && $subjId === $fourthSubjectId);

            $subjectRows[] = [
                'subject_name'     => $sub['subject_name'],
                'subject_code'     => $sub['subject_code'],
                'has_mcq'          => (bool)$sub['has_mcq'],
                'has_cq'           => (bool)$sub['has_cq'],
                'mcq_full'         => $sub['mcq_convert_to'] ?? $sub['mcq_full_marks'],
                'cq_full'          => $sub['cq_convert_to']  ?? $sub['cq_full_marks'],
                'mcq_marks'        => $m['mcq_marks_converted'] ?? $m['mcq_marks_raw']  ?? null,
                'cq_marks'         => $m['cq_marks_converted']  ?? $m['cq_marks_raw']   ?? null,
                'total_marks'      => $m['total_marks']     ?? null,
                'grade'            => $m['grade']            ?? null,
                'grade_point'      => $m['grade_point']      ?? null,
                'subject_status'   => $m['subject_status']   ?? null,
                // 4th subject flag: tells the frontend to render it differently —
                // its subject_status does NOT affect overall pass/fail; only a bonus is added.
                'is_fourth_subject'=> $isFourth,
            ];
        }

        // Overall summary from exam_results_summary (calculated at publish time)
        $summary = dbQuery(
            "SELECT total_marks, gpa, overall_status, position
             FROM exam_results_summary
             WHERE enrollment_id = ? AND exam_id = ?",
            [$enrollmentId, $examId]
        )->fetch();

        // Class size for "X of Y" rank display
        $gWhere2 = $enroll['group_id'] ? ' AND group_id = ?' : '';
        $params2 = [$enroll['class_id'], $enroll['session_id']];
        if ($enroll['group_id']) $params2[] = $enroll['group_id'];
        $classSize = (int)dbQuery(
            "SELECT COUNT(*) FROM enrollments WHERE class_id = ? AND session_id = ? AND status='active' $gWhere2",
            $params2
        )->fetchColumn();

        ok([
            'exam'         => [
                'exam_name'    => $exam['exam_name'],
                'class_label'  => $exam['class_label'],
                'session_year' => $exam['session_year'],
                'group_name'   => $exam['group_name'],
                'published_at' => $exam['published_at'],
            ],
            'subjects'   => $subjectRows,
            'summary'    => $summary ?: null,
            'class_size' => $classSize,
        ]);
    } catch (Throwable $e) {
        error_log('EMS student results fetch_result: ' . $e->getMessage());
        fail('Failed to load this result. Please try again.');
    }
}

fail('Unknown action.');
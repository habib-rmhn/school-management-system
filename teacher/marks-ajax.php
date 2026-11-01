<?php
/**
 * EMS Teacher — Upload Marks AJAX Handler
 * ─────────────────────────────────────────────────────────────────
 * Actions:
 *   fetch_assignments – list of (exam + subject) combos this teacher
 *                       may enter marks for: only DRAFT exams, only
 *                       subjects they actually teach in that class.
 *   fetch_marks        – roster + existing raw marks for one
 *                       exam_subject, plus its MCQ/CQ configuration.
 *   save_marks         – bulk upsert. Grade/GPA/pass-fail are always
 *                       computed HERE, server-side — the client's
 *                       live preview is for feedback only and is
 *                       never trusted.
 *
 * Business rules enforced (per spec):
 *   - Marks are only editable while the exam is still 'draft'.
 *     Once admin publishes, this endpoint refuses all writes.
 *   - A subject with both MCQ and CQ requires a student to pass each
 *     part separately AND the combined total, all at pass_percentage.
 *   - Raw marks may be entered on a different scale than the final
 *     total (e.g. out of 30) and are converted to the configured
 *     scale (e.g. scaled to 50) before grading.
 *   - A row is only saved once every applicable component (MCQ if
 *     used, CQ if used) has a valid value — partial rows are skipped
 *     so "marked_count" elsewhere in the system keeps meaning
 *     "fully entered", not "touched".
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

function tsafeRow(string $sql, array $p = []): ?array {
    try { $r = dbQuery($sql, $p)->fetch(); return $r ?: null; }
    catch (Throwable $e) { error_log('EMS teacher marks tsafeRow: ' . $e->getMessage()); return null; }
}
function tsafeAll(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); }
    catch (Throwable $e) { error_log('EMS teacher marks tsafeAll: ' . $e->getMessage()); return []; }
}
function tsafeVal(string $sql, array $p = []): mixed {
    try { $v = dbQuery($sql, $p)->fetchColumn(); return $v !== false ? $v : null; }
    catch (Throwable $e) { error_log('EMS teacher marks tsafeVal: ' . $e->getMessage()); return null; }
}

// Percentage → { grade letter, grade point } per the school's fixed scale.
function calcGrade(float $pct): array {
    if ($pct >= 80) return ['A+', 5.00];
    if ($pct >= 70) return ['A',  4.00];
    if ($pct >= 60) return ['A-', 3.50];
    if ($pct >= 50) return ['B',  3.00];
    if ($pct >= 40) return ['C',  2.00];
    if ($pct >= 33) return ['D',  1.00];
    return ['F', 0.00];
}

// Loads one exam_subject + its parent exam, or null if it doesn't exist.
function loadExamSubject(int $examSubjectId): ?array {
    return tsafeRow(
        "SELECT es.exam_subject_id, es.exam_id, es.subject_id,
                es.has_mcq, es.mcq_full_marks, es.mcq_convert_to,
                es.has_cq, es.cq_full_marks, es.cq_convert_to,
                es.pass_percentage, es.exam_date,
                ex.exam_name, ex.class_id, ex.group_id AS exam_group_id, ex.session_id, ex.status AS exam_status,
                c.label AS class_label, g.group_name,
                sub.subject_name, sub.subject_code, sub.is_choice, sub.group_id AS subj_group_id
         FROM exam_subjects es
         JOIN exams ex     ON ex.exam_id     = es.exam_id
         JOIN classes c    ON c.class_id     = ex.class_id
         LEFT JOIN groups g ON g.group_id    = ex.group_id
         JOIN subjects sub ON sub.subject_id = es.subject_id
         WHERE es.exam_subject_id = ?",
        [$examSubjectId]
    );
}

// Confirms this teacher actually teaches this subject in this class this session.
function teacherOwnsSubject(string $uid, int $sessionId, int $classId, int $subjectId): bool {
    return tsafeVal(
        "SELECT 1 FROM class_routine
         WHERE teacher_uid = ? AND session_id = ? AND class_id = ? AND subject_id = ? LIMIT 1",
        [$uid, $sessionId, $classId, $subjectId]
    ) !== null;
}

/**
 * Returns [whereSql, params] to filter enrollments to only students
 * who actually sit this specific exam subject, applying class 9-10
 * choice-subject rules (main/4th/eliminated). Safe to use for both
 * the roster fetch and the valid-enrollment check in save_marks.
 *
 * Non-choice (compulsory):  all students in the exam's group, EXCEPT
 *   those whose eliminated_subject_id matches this subject.
 * Choice subject, own group: only students whose main_subject_id matches.
 * Choice subject, other group: only students whose fourth_subject_id matches.
 */
function eligibleRosterWhere(array $es): array {
    $classId    = (int)$es['class_id'];
    $sessionId  = (int)$es['session_id'];
    $examGroup  = $es['exam_group_id'] !== null ? (int)$es['exam_group_id'] : null;
    $isChoice   = (int)$es['is_choice'] === 1;
    $subjGroupId = $es['subj_group_id'] !== null ? (int)$es['subj_group_id'] : null;
    $subjectId  = (int)$es['subject_id'];

    $baseWhere  = "e.class_id = ? AND e.session_id = ? AND e.status = 'active'";
    $baseParams = [$classId, $sessionId];

    if ($isChoice) {
        if ($examGroup && $subjGroupId === $examGroup) {
            // Own-group choice subject → only students who picked it as their Main subject.
            $sql    = "$baseWhere AND e.group_id = ? AND e.main_subject_id = ?";
            $params = array_merge($baseParams, [$examGroup, $subjectId]);
        } else {
            // Cross-group choice subject → only students who picked it as their 4th subject.
            $sql    = "$baseWhere AND e.fourth_subject_id = ?";
            $params = array_merge($baseParams, [$subjectId]);
        }
    } else {
        // Compulsory subject — exclude students whose eliminated_subject_id matches.
        if ($examGroup) {
            $sql    = "$baseWhere AND e.group_id = ? AND (e.eliminated_subject_id IS NULL OR e.eliminated_subject_id != ?)";
            $params = array_merge($baseParams, [$examGroup, $subjectId]);
        } else {
            $sql    = "$baseWhere AND (e.eliminated_subject_id IS NULL OR e.eliminated_subject_id != ?)";
            $params = array_merge($baseParams, [$subjectId]);
        }
    }

    return [$sql, $params];
}

$action = trim($_POST['action'] ?? '');

// ── Current academic session (used to scope every action below) ────
$session = tsafeRow("SELECT session_id, session_year FROM sessions WHERE is_current = 1 LIMIT 1");
$sid     = $session['session_id']   ?? null;
$sYear   = $session['session_year'] ?? null;

// ═══════════════════════════════════════════════════════════════════
//  ACTION: fetch_assignments
// ═══════════════════════════════════════════════════════════════════
if ($action === 'fetch_assignments') {
    if ($sid === null) {
        ok(['no_session' => true, 'assignments' => []]);
    }

    $rows = tsafeAll(
        "SELECT es.exam_subject_id, ex.exam_id, ex.exam_name, ex.class_id, ex.group_id AS exam_group_id, es.subject_id,
                c.label AS class_label, g.group_name, sub.subject_name, sub.subject_code, es.exam_date,
                (SELECT COUNT(*) FROM enrollments en
                   JOIN subjects sub2 ON sub2.subject_id = es.subject_id
                   WHERE en.class_id = ex.class_id AND en.session_id = ex.session_id AND en.status = 'active'
                   AND CASE
                     -- Non-choice subject: all group students except those who eliminated it
                     WHEN sub2.is_choice = 0 OR sub2.is_choice IS NULL THEN
                       (ex.group_id IS NULL OR en.group_id = ex.group_id)
                       AND (en.eliminated_subject_id IS NULL OR en.eliminated_subject_id != es.subject_id)
                     -- Own-group choice subject: only students who picked it as Main
                     WHEN sub2.group_id = ex.group_id THEN
                       en.group_id = ex.group_id AND en.main_subject_id = es.subject_id
                     -- Cross-group choice (4th subject): only students who picked it as 4th
                     ELSE
                       en.fourth_subject_id = es.subject_id
                   END
                ) AS total_students,
                (SELECT COUNT(*) FROM student_marks sm WHERE sm.exam_subject_id = es.exam_subject_id) AS entered_count
         FROM exam_subjects es
         JOIN exams   ex  ON ex.exam_id     = es.exam_id
         JOIN subjects sub ON sub.subject_id = es.subject_id
         JOIN classes  c  ON c.class_id      = ex.class_id
         LEFT JOIN groups g ON g.group_id    = ex.group_id
         WHERE ex.status = 'draft' AND ex.session_id = ?
         AND EXISTS (
             SELECT 1 FROM class_routine cr
             WHERE cr.teacher_uid = ? AND cr.session_id = ex.session_id
             AND cr.class_id = ex.class_id AND cr.subject_id = es.subject_id
         )
         ORDER BY (es.exam_date IS NULL) ASC, es.exam_date ASC, ex.exam_name ASC",
        [$sid, $myUid]
    );

    ok([
        'no_session'   => false,
        'session_year' => $sYear,
        'assignments'  => array_map(function ($r) {
            return [
                'exam_subject_id' => (int)$r['exam_subject_id'],
                'exam_id'         => (int)$r['exam_id'],
                'class_id'        => (int)$r['class_id'],
                'subject_id'      => (int)$r['subject_id'],
                'exam_name'       => $r['exam_name'],
                'class_label'     => $r['class_label'],
                'group_name'      => $r['group_name'],
                'subject_name'    => $r['subject_name'],
                'subject_code'    => $r['subject_code'],
                'exam_date'       => $r['exam_date'],
                'total_students'  => (int)$r['total_students'],
                'entered_count'   => (int)$r['entered_count'],
            ];
        }, $rows),
    ]);
}

// ═══════════════════════════════════════════════════════════════════
//  ACTION: fetch_marks
// ═══════════════════════════════════════════════════════════════════
if ($action === 'fetch_marks') {
    if ($sid === null) fail('There is no active academic session right now.');

    $examSubjectId = (int)($_POST['exam_subject_id'] ?? 0);
    if ($examSubjectId < 1) fail('Please choose an exam and subject.');

    $es = loadExamSubject($examSubjectId);
    if (!$es) fail('That exam subject could not be found.');
    if ((int)$es['session_id'] !== (int)$sid) fail('That exam belongs to a different session.');
    if ($es['exam_status'] !== 'draft') fail('This exam has already been published and is locked for editing.');
    if (!teacherOwnsSubject($myUid, (int)$sid, (int)$es['class_id'], (int)$es['subject_id'])) {
        fail('You are not assigned to teach this subject in this class.');
    }

    // ── Active roster for this exam subject, applying choice-subject
    //    filtering (main/4th/eliminated) for class 9-10 ──────────────
    [$rosterWhere, $rosterParams] = eligibleRosterWhere($es);
    $sql = "SELECT e.enrollment_id, e.roll_number, s.student_id, s.full_name
            FROM enrollments e
            JOIN students s ON s.sys_uid = e.sys_uid
            WHERE $rosterWhere
            ORDER BY (e.roll_number IS NULL), e.roll_number ASC, s.full_name ASC";

    $roster = tsafeAll($sql, $rosterParams);

    $students = [];
    if (!empty($roster)) {
        $enrollmentIds = array_column($roster, 'enrollment_id');
        $placeholders  = implode(',', array_fill(0, count($enrollmentIds), '?'));

        $existingRows = tsafeAll(
            "SELECT enrollment_id, mcq_marks_raw, cq_marks_raw, total_marks, grade, grade_point, subject_status, is_locked
             FROM student_marks
             WHERE exam_subject_id = ? AND enrollment_id IN ($placeholders)",
            array_merge([$examSubjectId], $enrollmentIds)
        );
        $existingByEnrollment = [];
        foreach ($existingRows as $er) { $existingByEnrollment[(int)$er['enrollment_id']] = $er; }

        foreach ($roster as $r) {
            $eid = (int)$r['enrollment_id'];
            $ex  = $existingByEnrollment[$eid] ?? null;
            $students[] = [
                'enrollment_id'  => $eid,
                'student_id'     => $r['student_id'],
                'full_name'      => $r['full_name'],
                'roll_number'    => $r['roll_number'] !== null ? (int)$r['roll_number'] : null,
                'mcq_raw'        => $ex && $ex['mcq_marks_raw'] !== null ? (float)$ex['mcq_marks_raw'] : null,
                'cq_raw'         => $ex && $ex['cq_marks_raw']  !== null ? (float)$ex['cq_marks_raw']  : null,
                'total_marks'    => $ex && $ex['total_marks']   !== null ? (float)$ex['total_marks']   : null,
                'grade'          => $ex['grade'] ?? null,
                'grade_point'    => $ex && $ex['grade_point'] !== null ? (float)$ex['grade_point'] : null,
                'subject_status' => $ex['subject_status'] ?? null,
                'is_locked'      => $ex ? (bool)$ex['is_locked'] : false,
            ];
        }
    }

    ok([
        'no_session' => false,
        'config' => [
            'exam_subject_id'  => (int)$es['exam_subject_id'],
            'exam_name'        => $es['exam_name'],
            'class_label'      => $es['class_label'],
            'group_name'       => $es['group_name'],
            'subject_name'     => $es['subject_name'],
            'subject_code'     => $es['subject_code'],
            'is_choice'        => (bool)$es['is_choice'],
            // is_fourth_subject = true when this is an other-group choice subject
            // that students take as their 4th (bonus) subject.
            // For these, subject F does NOT fail the student overall;
            // only GP above 2.00 contributes as a bonus to their GPA.
            'is_fourth_subject'=> (bool)$es['is_choice'] && ((int)$es['subj_group_id'] !== (int)$es['exam_group_id']),
            'has_mcq'          => (bool)$es['has_mcq'],
            'mcq_full_marks'   => $es['mcq_full_marks']   !== null ? (float)$es['mcq_full_marks']   : null,
            'mcq_convert_to'   => $es['mcq_convert_to']   !== null ? (float)$es['mcq_convert_to']   : null,
            'has_cq'           => (bool)$es['has_cq'],
            'cq_full_marks'    => $es['cq_full_marks']    !== null ? (float)$es['cq_full_marks']    : null,
            'cq_convert_to'    => $es['cq_convert_to']    !== null ? (float)$es['cq_convert_to']    : null,
            'pass_percentage'  => (float)$es['pass_percentage'],
            'exam_date'        => $es['exam_date'],
        ],
        'students' => $students,
    ]);
}

// ═══════════════════════════════════════════════════════════════════
//  ACTION: save_marks
// ═══════════════════════════════════════════════════════════════════
if ($action === 'save_marks') {
    if ($sid === null) fail('There is no active academic session right now.');

    $examSubjectId = (int)($_POST['exam_subject_id'] ?? 0);
    $recordsRaw    = $_POST['records'] ?? '';

    if ($examSubjectId < 1) fail('Please choose an exam and subject.');

    $es = loadExamSubject($examSubjectId);
    if (!$es) fail('That exam subject could not be found.');
    if ((int)$es['session_id'] !== (int)$sid) fail('That exam belongs to a different session.');
    if ($es['exam_status'] !== 'draft') fail('This exam has already been published and is locked for editing.');
    if (!teacherOwnsSubject($myUid, (int)$sid, (int)$es['class_id'], (int)$es['subject_id'])) {
        fail('You are not assigned to teach this subject in this class.');
    }

    $records = json_decode($recordsRaw, true);
    if (!is_array($records) || empty($records)) fail('No marks were submitted.');

    $hasMcq   = (bool)$es['has_mcq'];
    $hasCq    = (bool)$es['has_cq'];
    $mcqFull  = $es['mcq_full_marks']  !== null ? (float)$es['mcq_full_marks']  : null;
    $mcqScale = $es['mcq_convert_to']  !== null ? (float)$es['mcq_convert_to']  : $mcqFull;
    $cqFull   = $es['cq_full_marks']   !== null ? (float)$es['cq_full_marks']   : null;
    $cqScale  = $es['cq_convert_to']   !== null ? (float)$es['cq_convert_to']   : $cqFull;
    $passPct  = (float)$es['pass_percentage'];

    // ── Rebuild the valid, active roster server-side ─────────────────
    // Uses the same choice-subject filtering as fetch_marks so a teacher
    // can never accidentally save marks for a student who doesn't take
    // this subject (e.g. a student whose eliminated_subject_id matches).
    [$rosterWhere, $rosterParams] = eligibleRosterWhere($es);
    $validEnrollmentIds = array_map('intval', array_column(
        tsafeAll("SELECT e.enrollment_id FROM enrollments e WHERE $rosterWhere", $rosterParams),
        'enrollment_id'
    ));
    $validSet = array_flip($validEnrollmentIds);

    // ── Skip any rows already locked (published mid-session, defensive) ──
    $lockedSet = [];
    if (!empty($validEnrollmentIds)) {
        $placeholders = implode(',', array_fill(0, count($validEnrollmentIds), '?'));
        $lockedRows = tsafeAll(
            "SELECT enrollment_id FROM student_marks
             WHERE exam_subject_id = ? AND is_locked = 1 AND enrollment_id IN ($placeholders)",
            array_merge([$examSubjectId], $validEnrollmentIds)
        );
        foreach ($lockedRows as $lr) { $lockedSet[(int)$lr['enrollment_id']] = true; }
    }

    $toSave  = [];   // eid => [mcq_raw, cq_raw, mcq_conv, cq_conv, total, grade, gp, status]
    $skippedIncomplete = 0;
    $skippedInvalid    = 0;
    $skippedLocked     = 0;

    foreach ($records as $rec) {
        if (!is_array($rec)) continue;
        $eid = (int)($rec['enrollment_id'] ?? 0);
        if (!isset($validSet[$eid])) continue; // not a real active roster member — silently ignore

        if (isset($lockedSet[$eid])) { $skippedLocked++; continue; }

        $mcqRawIn = $rec['mcq_raw'] ?? null;
        $cqRawIn  = $rec['cq_raw']  ?? null;
        $mcqRaw   = ($mcqRawIn === null || $mcqRawIn === '') ? null : (float)$mcqRawIn;
        $cqRaw    = ($cqRawIn  === null || $cqRawIn  === '') ? null : (float)$cqRawIn;

        // Every applicable component must be present to save this row at all.
        if ($hasMcq && $mcqRaw === null) { $skippedIncomplete++; continue; }
        if ($hasCq  && $cqRaw  === null) { $skippedIncomplete++; continue; }

        // Range validation for whichever components are configured.
        if ($hasMcq && $mcqFull !== null && ($mcqRaw < 0 || $mcqRaw > $mcqFull)) { $skippedInvalid++; continue; }
        if ($hasCq  && $cqFull  !== null && ($cqRaw  < 0 || $cqRaw  > $cqFull))  { $skippedInvalid++; continue; }

        $mcqConverted = ($hasMcq && $mcqFull) ? ($mcqRaw / $mcqFull) * $mcqScale : null;
        $cqConverted  = ($hasCq  && $cqFull)  ? ($cqRaw  / $cqFull)  * $cqScale  : null;

        $totalScale = ($hasMcq ? $mcqScale : 0) + ($hasCq ? $cqScale : 0);
        $totalMarks = ($hasMcq ? $mcqConverted : 0) + ($hasCq ? $cqConverted : 0);
        $percentage = $totalScale > 0 ? ($totalMarks / $totalScale) * 100 : 0;

        $mcqPassPct = ($hasMcq && $mcqScale) ? ($mcqConverted / $mcqScale) * 100 : null;
        $cqPassPct  = ($hasCq  && $cqScale)  ? ($cqConverted  / $cqScale)  * 100 : null;

        $mcqPass = !$hasMcq || $mcqPassPct >= $passPct;
        $cqPass  = !$hasCq  || $cqPassPct  >= $passPct;
        $combinedPass = $percentage >= $passPct;
        $overallPass  = $mcqPass && $cqPass && $combinedPass;

        [$gradeLetter, $gradePoint] = calcGrade($percentage);
        $status = $overallPass ? 'pass' : 'fail';

        $toSave[$eid] = [
            'mcq_raw' => $mcqRaw, 'cq_raw' => $cqRaw,
            'mcq_conv' => $mcqConverted, 'cq_conv' => $cqConverted,
            'total' => round($totalMarks, 2), 'grade' => $gradeLetter, 'gp' => $gradePoint, 'status' => $status,
        ];
    }

    if (empty($toSave)) {
        ok([
            'saved' => false, 'total_saved' => 0,
            'skipped_incomplete' => $skippedIncomplete, 'skipped_invalid' => $skippedInvalid, 'skipped_locked' => $skippedLocked,
            'msg' => 'No rows were complete enough to save yet.',
        ]);
    }

    // ── Mark vs update, for the activity log ──────────────────────────
    $placeholders  = implode(',', array_fill(0, count($toSave), '?'));
    $existingCount = (int)(tsafeVal(
        "SELECT COUNT(*) FROM student_marks WHERE exam_subject_id = ? AND enrollment_id IN ($placeholders)",
        array_merge([$examSubjectId], array_keys($toSave))
    ) ?? 0);
    $actionType = $existingCount > 0 ? 'update_marks' : 'submit_marks';

    try {
        $pdo = db();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO student_marks
                (enrollment_id, exam_subject_id, mcq_marks_raw, mcq_marks_converted, cq_marks_raw, cq_marks_converted,
                 total_marks, grade, grade_point, subject_status, submitted_by, submitted_at, is_locked)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0)
             ON DUPLICATE KEY UPDATE
                mcq_marks_raw = VALUES(mcq_marks_raw), mcq_marks_converted = VALUES(mcq_marks_converted),
                cq_marks_raw  = VALUES(cq_marks_raw),  cq_marks_converted  = VALUES(cq_marks_converted),
                total_marks = VALUES(total_marks), grade = VALUES(grade), grade_point = VALUES(grade_point),
                subject_status = VALUES(subject_status), updated_at = NOW()"
        );

        foreach ($toSave as $eid => $v) {
            $stmt->execute([
                $eid, $examSubjectId, $v['mcq_raw'], $v['mcq_conv'], $v['cq_raw'], $v['cq_conv'],
                $v['total'], $v['grade'], $v['gp'], $v['status'], $myUid,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('EMS teacher save_marks: ' . $e->getMessage());
        fail('Failed to save marks. Please try again.');
    }

    log_activity($myUid, $actionType, 'exam_subject', (string)$examSubjectId, [
        'class_label'  => $es['exam_name'] . ' — ' . $es['class_label'] . ($es['group_name'] ? ' (' . $es['group_name'] . ')' : ''),
        'subject_name' => $es['subject_name'],
        'count'        => count($toSave),
        'session_year' => $sYear,
    ]);

    ok([
        'saved'              => true,
        'action'             => $actionType,
        'total_saved'        => count($toSave),
        'skipped_incomplete' => $skippedIncomplete,
        'skipped_invalid'    => $skippedInvalid,
        'skipped_locked'     => $skippedLocked,
        'exam_name'          => $es['exam_name'],
        'subject_name'       => $es['subject_name'],
        'results' => array_map(function ($v, $eid) {
            return ['enrollment_id' => $eid, 'total' => $v['total'], 'grade' => $v['grade'], 'grade_point' => $v['gp'], 'status' => $v['status']];
        }, $toSave, array_keys($toSave)),
    ]);
}

fail('Unknown action.');
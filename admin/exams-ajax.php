<?php
/**
 * EMS Admin — Exams AJAX Handler
 * Actions: fetch_exams, fetch_subjects, create_exam, get_exam,
 *          fetch_exam_available_subjects, update_exam_subject,
 *          add_exam_subject, remove_exam_subject, delete_exam,
 *          start_ongoing, revert_to_draft, publish_exam, unpublish_exam
 *
 * Exam status lifecycle: draft -> ongoing -> published (fully manual, button-driven)
 *   draft     = admin is configuring/editing subjects, nothing visible yet
 *   ongoing   = admit card + seat plan visible to students, teachers can enter marks
 *               (admin explicitly clicks "Start On-going" to enter this phase)
 *   published = results published (final)
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
    echo json_encode(array_merge(['ok' => true, 'csrf' => $newToken], $data)); exit;
}
function fail(string $msg): void {
    global $newToken;
    echo json_encode(['ok' => false, 'msg' => $msg, 'csrf' => $newToken]); exit;
}
function sv(string $sql, array $p = []): mixed {
    try { $v = dbQuery($sql, $p)->fetchColumn(); return $v !== false ? $v : null; }
    catch (Throwable $e) { return null; }
}

function validateExamSubjectConfig(array $sub, int $i = 0): array {
    $sid    = (int)($sub['subject_id'] ?? 0);
    $hasCq  = (bool)($sub['has_cq'] ?? false);
    $hasMcq = (bool)($sub['has_mcq'] ?? false);

    if ($sid < 1) fail($i ? "Subject #$i is invalid." : 'Invalid subject.');
    if (!$hasCq && !$hasMcq) fail($i ? "Subject #$i must have at least CQ or MCQ." : 'Subject must have at least CQ or MCQ.');

    $cqFull  = $hasCq  ? (int)($sub['cq_full_marks'] ?? 0) : null;
    $mcqFull = $hasMcq ? (int)($sub['mcq_full_marks'] ?? 0) : null;
    if ($hasCq && $cqFull < 1) fail($i ? "CQ marks required for subject #$i." : 'CQ marks are required.');
    if ($hasMcq && $mcqFull < 1) fail($i ? "MCQ marks required for subject #$i." : 'MCQ marks are required.');

    $cqConvert = ($hasCq && ($sub['cq_convert_to'] ?? '') !== '') ? (int)$sub['cq_convert_to'] : null;
    $mcqConvert = ($hasMcq && ($sub['mcq_convert_to'] ?? '') !== '') ? (int)$sub['mcq_convert_to'] : null;
    if ($cqConvert !== null && $cqConvert < 1) fail('CQ convert marks must be greater than zero.');
    if ($mcqConvert !== null && $mcqConvert < 1) fail('MCQ convert marks must be greater than zero.');

    $examDate = trim((string)($sub['exam_date'] ?? '')) ?: null;
    if ($examDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $examDate)) fail($i ? "Invalid exam date for subject #$i." : 'Invalid exam date.');

    $startTime = trim((string)($sub['start_time'] ?? '')) ?: null;
    if ($startTime && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime)) fail('Invalid start time.');

    $durationMin = ($sub['duration_min'] ?? '') !== '' ? (int)$sub['duration_min'] : null;
    if ($durationMin !== null && ($durationMin < 10 || $durationMin > 360)) fail('Duration must be between 10 and 360 minutes.');

    $passPct = isset($sub['pass_percentage']) && $sub['pass_percentage'] !== '' ? (float)$sub['pass_percentage'] : 33.00;
    if ($passPct < 1 || $passPct > 100) fail('Pass percentage must be between 1 and 100.');

    return [
        'subject_id' => $sid,
        'has_cq' => $hasCq ? 1 : 0,
        'cq_full_marks' => $cqFull,
        'cq_convert_to' => $cqConvert,
        'has_mcq' => $hasMcq ? 1 : 0,
        'mcq_full_marks' => $mcqFull,
        'mcq_convert_to' => $mcqConvert,
        'exam_date' => $examDate,
        'start_time' => $startTime,
        'duration_min' => $durationMin,
        'pass_percentage' => $passPct,
    ];
}

function examRowForEdit(int $examId): array {
    $exam = dbQuery(
        "SELECT e.exam_id, e.exam_name, e.status, e.class_id, e.session_id, e.group_id,
                c.class_num
         FROM exams e
         JOIN classes c ON c.class_id = e.class_id
         WHERE e.exam_id = ?",
        [$examId]
    )->fetch();
    if (!$exam) fail('Exam not found.');
    if ($exam['status'] !== 'draft') fail('Move this exam back to draft before editing subjects.');
    return $exam;
}

function subjectBelongsToExamScope(array $exam, int $subjectId): bool {
    $subject = dbQuery(
        "SELECT subject_id, class_id, group_id, is_active FROM subjects WHERE subject_id = ?",
        [$subjectId]
    )->fetch();
    if (!$subject || !(int)$subject['is_active']) return false;
    if ((int)$subject['class_id'] !== (int)$exam['class_id']) return false;

    $classNum = (int)$exam['class_num'];
    $examGroupId = (int)($exam['group_id'] ?? 0);
    $subjectGroupId = (int)($subject['group_id'] ?? 0);

    if ($classNum < 9) return $subjectGroupId === 0;
    if ($examGroupId > 0) return $subjectGroupId === 0 || $subjectGroupId === $examGroupId;
    return $subjectGroupId === 0;
}

function examSubjectHasMarks(int $examSubjectId): bool {
    return (int)(sv("SELECT COUNT(*) FROM student_marks WHERE exam_subject_id = ?", [$examSubjectId]) ?? 0) > 0;
}

$action = trim($_POST['action'] ?? '');

// ── Helper: fetch exams list with subject counts ──────────────────
function fetchExams(int $sessionId, int $classId, string $status): array {
    $where  = ['1=1'];
    $params = [];
    if ($sessionId > 0) { $where[] = 'e.session_id=?'; $params[] = $sessionId; }
    if ($classId   > 0) { $where[] = 'e.class_id=?';   $params[] = $classId; }
    if ($status !== '') { $where[] = 'e.status=?';      $params[] = $status; }

    return dbQuery(
        "SELECT e.exam_id, e.exam_name, e.status, e.created_at, e.ongoing_at, e.published_at,
                e.class_id, e.session_id, e.group_id,
                c.label AS class_label, c.class_num,
                sess.label AS session_label, sess.session_year,
                g.group_name,
                su.username AS created_by_name,
                COUNT(DISTINCT es.exam_subject_id) AS subject_count,
                MIN(es.exam_date) AS first_date,
                MAX(es.exam_date) AS last_date,
                SUM(COALESCE(es.cq_convert_to, es.cq_full_marks, 0) +
                    COALESCE(es.mcq_convert_to, es.mcq_full_marks, 0)) AS total_marks
         FROM exams e
         JOIN classes c    ON c.class_id   = e.class_id
         JOIN sessions sess ON sess.session_id = e.session_id
         LEFT JOIN groups g ON g.group_id  = e.group_id
         JOIN system_users su ON su.sys_uid = e.created_by
         LEFT JOIN exam_subjects es ON es.exam_id = e.exam_id
         WHERE " . implode(' AND ', $where) . "
         GROUP BY e.exam_id
         ORDER BY e.created_at DESC",
        $params
    )->fetchAll();
}

// ── FETCH EXAMS LIST ──────────────────────────────────────────────
if ($action === 'fetch_exams') {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $classId   = (int)($_POST['class_id']   ?? 0);
    $status    = trim($_POST['status']      ?? '');
    try {
        ok(['exams' => fetchExams($sessionId, $classId, $status)]);
    } catch (Throwable $e) {
        error_log('EMS fetch_exams: ' . $e->getMessage());
        fail('Failed to fetch exams.');
    }
}

// ── FETCH SUBJECTS for a class+group (for exam creation) ──────────
if ($action === 'fetch_subjects') {
    $classId = (int)($_POST['class_id'] ?? 0);
    $groupId = (int)($_POST['group_id'] ?? 0) ?: null;
    if ($classId < 1) fail('Invalid class.');

    try {
        $classNum = (int)(sv("SELECT class_num FROM classes WHERE class_id=?", [$classId]) ?? 0);
        if ($classNum < 9) $groupId = null;

        $where  = 's.class_id=? AND s.is_active=1';
        $params = [$classId];
        if ($classNum < 9) {
            $where .= ' AND s.group_id IS NULL';
        } elseif ($groupId) {
            $where  .= ' AND (s.group_id IS NULL OR s.group_id=?)';
            $params[] = $groupId;
        }
        // else: class 9/10 with "All Groups" selected — show common + every group's subjects

        $subjects = dbQuery(
            "SELECT s.subject_id, s.subject_name, s.subject_code, s.group_id,
                    s.written_marks, s.mcq_marks, s.exam_duration, s.is_choice,
                    g.group_name
             FROM subjects s
             LEFT JOIN groups g ON g.group_id = s.group_id
             WHERE $where
             ORDER BY s.group_id IS NULL DESC, g.group_name, s.subject_name",
            $params
        )->fetchAll();

        ok(['subjects' => $subjects, 'class_num' => $classNum]);
    } catch (Throwable $e) {
        error_log('EMS fetch_subjects: ' . $e->getMessage());
        fail('Failed to fetch subjects.');
    }
}

// ── GET SINGLE EXAM (for edit/view) ──────────────────────────────
if ($action === 'get_exam') {
    $examId = (int)($_POST['exam_id'] ?? 0);
    if ($examId < 1) fail('Invalid exam.');

    try {
        $exam = dbQuery(
            "SELECT e.*, c.label AS class_label, c.class_num,
                    sess.label AS session_label, g.group_name
             FROM exams e
             JOIN classes c ON c.class_id=e.class_id
             JOIN sessions sess ON sess.session_id=e.session_id
             LEFT JOIN groups g ON g.group_id=e.group_id
             WHERE e.exam_id=?", [$examId]
        )->fetch();
        if (!$exam) fail('Exam not found.');

        $subjects = dbQuery(
            "SELECT es.*, s.subject_name, s.subject_code, s.group_id, g.group_name
             FROM exam_subjects es
             JOIN subjects s ON s.subject_id=es.subject_id
             LEFT JOIN groups g ON g.group_id=s.group_id
             WHERE es.exam_id=?
             ORDER BY es.sort_order, s.subject_name",
            [$examId]
        )->fetchAll();

        ok(['exam' => $exam, 'subjects' => $subjects]);
    } catch (Throwable $e) {
        error_log('EMS get_exam: ' . $e->getMessage());
        fail('Failed to fetch exam.');
    }
}

// ── CREATE EXAM ───────────────────────────────────────────────────
if ($action === 'create_exam') {
    $examName  = trim($_POST['exam_name']  ?? '');
    $classId   = (int)($_POST['class_id']  ?? 0);
    $sessionId = (int)($_POST['session_id']?? 0);
    $groupId   = (int)($_POST['group_id']  ?? 0) ?: null;
    $subjects  = $_POST['subjects'] ?? ''; // JSON

    if (empty($examName))   fail('Exam name is required.');
    if (strlen($examName) > 80) fail('Exam name too long (max 80 characters).');
    if ($classId   < 1)     fail('Please select a class.');
    if ($sessionId < 1)     fail('Please select a session.');

    $classRow = dbQuery("SELECT class_num FROM classes WHERE class_id=?", [$classId])->fetch();
    if (!$classRow) fail('Invalid class.');
    if ((int)$classRow['class_num'] < 9) $groupId = null;

    $subjectData = json_decode($subjects, true);
    if (!is_array($subjectData) || empty($subjectData)) fail('Please configure at least one subject.');

    // Auto-append session year to exam name if not already present
    $sessionYear = (string)(sv("SELECT session_year FROM sessions WHERE session_id=?", [$sessionId]) ?? '');
    if ($sessionYear && strpos($examName, $sessionYear) === false) {
        $examName = $examName . ' ' . $sessionYear;
    }

    // Validate each subject config
    foreach ($subjectData as $i => $sub) {
        $sid    = (int)($sub['subject_id'] ?? 0);
        $hasCq  = (bool)($sub['has_cq']  ?? false);
        $hasMcq = (bool)($sub['has_mcq'] ?? false);
        if ($sid < 1) fail("Subject #" . ($i+1) . " is invalid.");
        if (!$hasCq && !$hasMcq) fail("Subject #" . ($i+1) . " must have at least CQ or MCQ.");
        if ($hasCq  && (int)($sub['cq_full_marks']  ?? 0) < 1) fail("CQ marks required for subject #" . ($i+1) . ".");
        if ($hasMcq && (int)($sub['mcq_full_marks'] ?? 0) < 1) fail("MCQ marks required for subject #" . ($i+1) . ".");
        // Validate exam_date if provided
        $eDate = trim($sub['exam_date'] ?? '');
        if ($eDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eDate)) fail("Invalid exam date for subject #" . ($i+1) . ".");
    }

    try {
        db()->beginTransaction();

        dbQuery(
            "INSERT INTO exams (exam_name, class_id, session_id, group_id, status, created_by, created_at)
             VALUES (?, ?, ?, ?, 'draft', ?, NOW())",
            [$examName, $classId, $sessionId, $groupId, $_SESSION['ems_uid']]
        );
        $examId = (int)db()->lastInsertId();

        foreach ($subjectData as $i => $sub) {
            $hasCq        = (int)(bool)($sub['has_cq']        ?? false);
            $hasMcq       = (int)(bool)($sub['has_mcq']       ?? false);
            $cqFull       = $hasCq  ? (int)($sub['cq_full_marks']   ?? 0) : null;
            $cqConvert    = $hasCq  && ($sub['cq_convert_to']  ?? '') !== '' ? (int)$sub['cq_convert_to']  : null;
            $mcqFull      = $hasMcq ? (int)($sub['mcq_full_marks']  ?? 0) : null;
            $mcqConvert   = $hasMcq && ($sub['mcq_convert_to'] ?? '') !== '' ? (int)$sub['mcq_convert_to'] : null;
            $examDate     = trim($sub['exam_date']    ?? '') ?: null;
            $startTime    = trim($sub['start_time']   ?? '') ?: null;
            $durationMin  = ($sub['duration_min'] ?? '') !== '' ? (int)$sub['duration_min'] : null;
            $passPct      = isset($sub['pass_percentage']) && $sub['pass_percentage'] !== '' ? (float)$sub['pass_percentage'] : 33.00;

            dbQuery(
                "INSERT INTO exam_subjects
                    (exam_id, subject_id, has_mcq, mcq_full_marks, mcq_convert_to,
                     has_cq, cq_full_marks, cq_convert_to, pass_percentage,
                     exam_date, start_time, duration_min, sort_order)
                 VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?,?)",
                [$examId, (int)$sub['subject_id'], $hasMcq, $mcqFull, $mcqConvert,
                 $hasCq, $cqFull, $cqConvert, $passPct,
                 $examDate, $startTime, $durationMin, $i]
            );
        }

        db()->commit();

        log_activity($_SESSION['ems_uid'], 'create_exam', 'exams', (string)$examId,
            ['name' => $examName, 'class_id' => $classId, 'session_id' => $sessionId]);

        $exams = fetchExams($sessionId, 0, '');
        ok(['exams' => $exams, 'exam_id' => $examId,
            'msg' => "\"$examName\" created successfully as draft."]);

    } catch (Throwable $e) {
        db()->rollBack();
        error_log('EMS create_exam: ' . $e->getMessage());
        fail('Database error. Please try again.');
    }
}

// ── DELETE EXAM ───────────────────────────────────────────────────
// Fetch subjects from this exam's class/group that are not already selected.
if ($action === 'fetch_exam_available_subjects') {
    $examId = (int)($_POST['exam_id'] ?? 0);
    if ($examId < 1) fail('Invalid exam.');

    try {
        $exam = examRowForEdit($examId);

        $where = [
            's.class_id = ?',
            's.is_active = 1',
            'NOT EXISTS (SELECT 1 FROM exam_subjects es WHERE es.exam_id = ? AND es.subject_id = s.subject_id)',
        ];
        $params = [(int)$exam['class_id'], $examId];

        if ((int)$exam['class_num'] < 9) {
            $where[] = 's.group_id IS NULL';
        } elseif (!empty($exam['group_id'])) {
            $where[] = '(s.group_id IS NULL OR s.group_id = ?)';
            $params[] = (int)$exam['group_id'];
        }
        // else: class 9/10 exam with "All Groups" — show common + every group's subjects

        $subjects = dbQuery(
            "SELECT s.subject_id, s.subject_name, s.subject_code, s.group_id,
                    s.written_marks, s.mcq_marks, s.exam_duration, s.is_choice,
                    g.group_name
             FROM subjects s
             LEFT JOIN groups g ON g.group_id = s.group_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY s.group_id IS NULL DESC, g.group_name, s.subject_name",
            $params
        )->fetchAll();

        ok(['subjects' => $subjects]);
    } catch (Throwable $e) {
        error_log('EMS fetch_exam_available_subjects: ' . $e->getMessage());
        fail('Failed to fetch available subjects.');
    }
}

if ($action === 'update_exam_subject') {
    $examId = (int)($_POST['exam_id'] ?? 0);
    $examSubjectId = (int)($_POST['exam_subject_id'] ?? 0);
    $rawConfig = $_POST['config'] ?? '';
    if ($examId < 1 || $examSubjectId < 1) fail('Invalid subject.');

    $config = json_decode($rawConfig, true);
    if (!is_array($config)) fail('Invalid subject configuration.');

    try {
        $exam = examRowForEdit($examId);
        $current = dbQuery(
            "SELECT exam_subject_id, subject_id FROM exam_subjects WHERE exam_subject_id = ? AND exam_id = ?",
            [$examSubjectId, $examId]
        )->fetch();
        if (!$current) fail('Exam subject not found.');
        if (examSubjectHasMarks($examSubjectId)) fail('Cannot edit this subject because marks have already been submitted.');

        $config['subject_id'] = (int)$current['subject_id'];
        $cfg = validateExamSubjectConfig($config);
        if (!subjectBelongsToExamScope($exam, (int)$cfg['subject_id'])) fail('Subject does not belong to this exam class/group.');

        dbQuery(
            "UPDATE exam_subjects
             SET has_mcq = ?, mcq_full_marks = ?, mcq_convert_to = ?,
                 has_cq = ?, cq_full_marks = ?, cq_convert_to = ?,
                 pass_percentage = ?, exam_date = ?, start_time = ?, duration_min = ?
             WHERE exam_subject_id = ? AND exam_id = ?",
            [
                $cfg['has_mcq'], $cfg['mcq_full_marks'], $cfg['mcq_convert_to'],
                $cfg['has_cq'], $cfg['cq_full_marks'], $cfg['cq_convert_to'],
                $cfg['pass_percentage'], $cfg['exam_date'], $cfg['start_time'], $cfg['duration_min'],
                $examSubjectId, $examId,
            ]
        );

        log_activity($_SESSION['ems_uid'], 'update_exam_subject', 'exam_subjects', (string)$examSubjectId, [
            'exam_id' => $examId,
            'subject_id' => (int)$cfg['subject_id'],
        ]);

        ok(['msg' => 'Subject configuration updated.']);
    } catch (Throwable $e) {
        error_log('EMS update_exam_subject: ' . $e->getMessage());
        fail('Failed to update subject.');
    }
}

if ($action === 'add_exam_subject') {
    $examId = (int)($_POST['exam_id'] ?? 0);
    $rawConfig = $_POST['config'] ?? '';
    if ($examId < 1) fail('Invalid exam.');

    $config = json_decode($rawConfig, true);
    if (!is_array($config)) fail('Invalid subject configuration.');

    try {
        $exam = examRowForEdit($examId);
        $cfg = validateExamSubjectConfig($config);
        if (!subjectBelongsToExamScope($exam, (int)$cfg['subject_id'])) fail('Subject does not belong to this exam class/group.');

        $exists = (int)(sv("SELECT COUNT(*) FROM exam_subjects WHERE exam_id = ? AND subject_id = ?", [$examId, (int)$cfg['subject_id']]) ?? 0);
        if ($exists) fail('This subject is already selected for the exam.');

        $sortOrder = (int)(sv("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM exam_subjects WHERE exam_id = ?", [$examId]) ?? 0);

        dbQuery(
            "INSERT INTO exam_subjects
                (exam_id, subject_id, has_mcq, mcq_full_marks, mcq_convert_to,
                 has_cq, cq_full_marks, cq_convert_to, pass_percentage,
                 exam_date, start_time, duration_min, sort_order)
             VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?,?)",
            [
                $examId, (int)$cfg['subject_id'], $cfg['has_mcq'], $cfg['mcq_full_marks'], $cfg['mcq_convert_to'],
                $cfg['has_cq'], $cfg['cq_full_marks'], $cfg['cq_convert_to'], $cfg['pass_percentage'],
                $cfg['exam_date'], $cfg['start_time'], $cfg['duration_min'], $sortOrder,
            ]
        );
        $examSubjectId = (int)db()->lastInsertId();

        log_activity($_SESSION['ems_uid'], 'add_exam_subject', 'exam_subjects', (string)$examSubjectId, [
            'exam_id' => $examId,
            'subject_id' => (int)$cfg['subject_id'],
        ]);

        ok(['msg' => 'Subject added to exam.', 'exam_subject_id' => $examSubjectId]);
    } catch (Throwable $e) {
        error_log('EMS add_exam_subject: ' . $e->getMessage());
        fail('Failed to add subject.');
    }
}

if ($action === 'remove_exam_subject') {
    $examId = (int)($_POST['exam_id'] ?? 0);
    $examSubjectId = (int)($_POST['exam_subject_id'] ?? 0);
    if ($examId < 1 || $examSubjectId < 1) fail('Invalid subject.');

    try {
        $exam = examRowForEdit($examId);
        $row = dbQuery(
            "SELECT es.exam_subject_id, es.subject_id, s.subject_name
             FROM exam_subjects es
             JOIN subjects s ON s.subject_id = es.subject_id
             WHERE es.exam_subject_id = ? AND es.exam_id = ?",
            [$examSubjectId, $examId]
        )->fetch();
        if (!$row) fail('Exam subject not found.');

        $subjectCount = (int)(sv("SELECT COUNT(*) FROM exam_subjects WHERE exam_id = ?", [$examId]) ?? 0);
        if ($subjectCount <= 1) fail('At least one subject must remain in the exam.');
        if (examSubjectHasMarks($examSubjectId)) fail('Cannot remove this subject because marks have already been submitted.');

        dbQuery("DELETE FROM exam_subjects WHERE exam_subject_id = ? AND exam_id = ?", [$examSubjectId, $examId]);

        log_activity($_SESSION['ems_uid'], 'remove_exam_subject', 'exam_subjects', (string)$examSubjectId, [
            'exam_id' => $examId,
            'subject_id' => (int)$row['subject_id'],
            'subject_name' => $row['subject_name'],
        ]);

        ok(['msg' => '"' . $row['subject_name'] . '" removed from exam.']);
    } catch (Throwable $e) {
        error_log('EMS remove_exam_subject: ' . $e->getMessage());
        fail('Failed to remove subject.');
    }
}

if ($action === 'delete_exam') {
    $examId = (int)($_POST['exam_id'] ?? 0);
    if ($examId < 1) fail('Invalid exam.');

    $exam = dbQuery("SELECT exam_name, status FROM exams WHERE exam_id=?", [$examId])->fetch();
    if (!$exam) fail('Exam not found.');
    if ($exam['status'] !== 'draft') fail('Cannot delete an exam that is on-going or published. Move it back to draft first.');

    // Check if marks have been submitted
    $hasMarks = (int)(sv("SELECT COUNT(*) FROM student_marks sm JOIN exam_subjects es ON es.exam_subject_id=sm.exam_subject_id WHERE es.exam_id=?", [$examId]) ?? 0);
    if ($hasMarks > 0) fail("Cannot delete: $hasMarks mark records exist. Remove marks first.");

    try {
        $name = $exam['exam_name'];
        dbQuery("DELETE FROM exams WHERE exam_id=?", [$examId]); // cascades to exam_subjects
        log_activity($_SESSION['ems_uid'], 'delete_exam', 'exams', (string)$examId, ['name' => $name]);
        ok(['exam_id' => $examId, 'msg' => "\"$name\" deleted."]);
    } catch (Throwable $e) {
        error_log('EMS delete_exam: ' . $e->getMessage());
        fail('Failed to delete exam.');
    }
}

// ── PHASE TRANSITIONS: draft -> ongoing -> published (and back) ───
// draft -> ongoing (manual early trigger; auto-trigger happens in autoPromoteOngoing())
if ($action === 'start_ongoing') {
    $examId = (int)($_POST['exam_id'] ?? 0);
    if ($examId < 1) fail('Invalid exam.');

    $exam = dbQuery("SELECT exam_name, status, session_id FROM exams WHERE exam_id=?", [$examId])->fetch();
    if (!$exam) fail('Exam not found.');
    if ($exam['status'] !== 'draft') fail('Exam is already on-going or published.');

    $subCount = (int)(sv("SELECT COUNT(*) FROM exam_subjects WHERE exam_id=?", [$examId]) ?? 0);
    if ($subCount < 1) fail('Cannot start: no subjects configured for this exam.');

    try {
        dbQuery("UPDATE exams SET status='ongoing', ongoing_at=NOW() WHERE exam_id=?", [$examId]);
        log_activity($_SESSION['ems_uid'], 'start_ongoing_exam', 'exams', (string)$examId, ['name' => $exam['exam_name']]);
        $exams = fetchExams((int)$exam['session_id'], 0, '');
        ok(['exams' => $exams, 'msg' => "\"" . $exam['exam_name'] . "\" is now on-going. Admit cards, seat plans, and marks entry are open."]);
    } catch (Throwable $e) {
        error_log('EMS start_ongoing: ' . $e->getMessage());
        fail('Failed to start on-going phase.');
    }
}

// ongoing -> draft (undo, only if nothing has been submitted yet)
if ($action === 'revert_to_draft') {
    $examId = (int)($_POST['exam_id'] ?? 0);
    if ($examId < 1) fail('Invalid exam.');

    $exam = dbQuery("SELECT exam_name, status, session_id FROM exams WHERE exam_id=?", [$examId])->fetch();
    if (!$exam) fail('Exam not found.');
    if ($exam['status'] !== 'ongoing') fail('Only an on-going exam can be reverted to draft.');

    $hasMarks = (int)(sv("SELECT COUNT(*) FROM student_marks sm JOIN exam_subjects es ON es.exam_subject_id=sm.exam_subject_id WHERE es.exam_id=?", [$examId]) ?? 0);
    if ($hasMarks > 0) fail("Cannot revert to draft: $hasMarks mark record(s) already submitted by teachers.");

    try {
        dbQuery("UPDATE exams SET status='draft', ongoing_at=NULL WHERE exam_id=?", [$examId]);
        log_activity($_SESSION['ems_uid'], 'revert_exam_to_draft', 'exams', (string)$examId, ['name' => $exam['exam_name']]);
        $exams = fetchExams((int)$exam['session_id'], 0, '');
        ok(['exams' => $exams, 'msg' => "\"" . $exam['exam_name'] . "\" moved back to draft."]);
    } catch (Throwable $e) {
        error_log('EMS revert_to_draft: ' . $e->getMessage());
        fail('Failed to revert exam to draft.');
    }
}

// ── PUBLISH / UNPUBLISH RESULTS (ongoing <-> published) ────────────
if ($action === 'publish_exam') {
    $examId = (int)($_POST['exam_id'] ?? 0);
    if ($examId < 1) fail('Invalid exam.');

    $exam = dbQuery("SELECT exam_name, status, class_id, session_id FROM exams WHERE exam_id=?", [$examId])->fetch();
    if (!$exam) fail('Exam not found.');
    if ($exam['status'] === 'published') fail('Exam is already published.');
    if ($exam['status'] === 'draft') fail('Move the exam to On-going before publishing results.');

    // Must have at least one subject with an exam date
    $subCount = (int)(sv("SELECT COUNT(*) FROM exam_subjects WHERE exam_id=?", [$examId]) ?? 0);
    if ($subCount < 1) fail('Cannot publish: no subjects configured for this exam.');

    try {
        dbQuery("UPDATE exams SET status='published', published_at=NOW() WHERE exam_id=?", [$examId]);
        log_activity($_SESSION['ems_uid'], 'publish_exam', 'exams', (string)$examId, ['name' => $exam['exam_name']]);
        $exams = fetchExams((int)$exam['session_id'], 0, '');
        ok(['exams' => $exams, 'msg' => "\"" . $exam['exam_name'] . "\" results published successfully."]);
    } catch (Throwable $e) {
        error_log('EMS publish_exam: ' . $e->getMessage());
        fail('Failed to publish exam.');
    }
}

if ($action === 'unpublish_exam') {
    $examId = (int)($_POST['exam_id'] ?? 0);
    if ($examId < 1) fail('Invalid exam.');

    $exam = dbQuery("SELECT exam_name, status, session_id FROM exams WHERE exam_id=?", [$examId])->fetch();
    if (!$exam) fail('Exam not found.');
    if ($exam['status'] !== 'published') fail('Exam is not published.');

    // If marks are locked (submitted), warn
    $lockedMarks = (int)(sv(
        "SELECT COUNT(*) FROM student_marks sm
         JOIN exam_subjects es ON es.exam_subject_id=sm.exam_subject_id
         WHERE es.exam_id=? AND sm.is_locked=1", [$examId]
    ) ?? 0);
    if ($lockedMarks > 0) fail("Cannot unpublish: $lockedMarks mark records are locked. Results have been shared with students.");

    try {
        dbQuery("UPDATE exams SET status='ongoing', published_at=NULL WHERE exam_id=?", [$examId]);
        log_activity($_SESSION['ems_uid'], 'unpublish_exam', 'exams', (string)$examId, ['name' => $exam['exam_name']]);
        $exams = fetchExams((int)$exam['session_id'], 0, '');
        ok(['exams' => $exams, 'msg' => "\"" . $exam['exam_name'] . "\" moved back to On-going."]);
    } catch (Throwable $e) {
        fail('Failed to unpublish exam.');
    }
}

fail('Unknown action.');
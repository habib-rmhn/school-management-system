<?php
/**
 * EMS Admin — Marks Entry AJAX Handler  (marks-ajax.php)
 *
 * Actions:
 *   fetch_exams_for_marks   — list exams (with session/class filter)
 *   fetch_marks_sheet       — students + existing marks for exam+subject
 *   fetch_subject_progress  — per-subject entered/total counts (tab dots)
 *   save_marks              — insert/update marks, compute grade/GPA, log
 *   clear_subject_marks     — wipe one subject's marks (admin only)
 *   get_exam_summary        — per-student totals for an exam
 */
require_once __DIR__ . '/../includes/admin-session.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed.']); exit;
}
if (!csrf_verify()) {
    echo json_encode(['ok' => false, 'msg' => 'Session expired. Please refresh.']); exit;
}

csrf_rotate();
$newToken = csrf_token();

function ok(array $d = []): void  { global $newToken; echo json_encode(array_merge(['ok'=>true,'csrf'=>$newToken],$d)); exit; }
function fail(string $m): void    { global $newToken; echo json_encode(['ok'=>false,'msg'=>$m,'csrf'=>$newToken]); exit; }
function sv(string $sql,array $p=[]): mixed { try{$v=dbQuery($sql,$p)->fetchColumn();return $v!==false?$v:null;}catch(Throwable $e){return null;} }
function rows(string $sql,array $p=[]): array { try{return dbQuery($sql,$p)->fetchAll();}catch(Throwable $e){return [];} }

$action = trim($_POST['action'] ?? '');
$uid    = $_SESSION['ems_uid'];

/* ── GRADING FUNCTION ─────────────────────────────────────────────
   Bangladesh board: pass = 33%. Grade on percentage of effective marks.
   Returns ['grade'=>'A+','gp'=>5.00]
 ─────────────────────────────────────────────────────────────────── */
function calcGrade(float $pct): array {
    if ($pct >= 80) return ['grade'=>'A+','gp'=>5.00];
    if ($pct >= 70) return ['grade'=>'A', 'gp'=>4.00];
    if ($pct >= 60) return ['grade'=>'A-','gp'=>3.50];
    if ($pct >= 50) return ['grade'=>'B', 'gp'=>3.00];
    if ($pct >= 40) return ['grade'=>'C', 'gp'=>2.00];
    if ($pct >= 33) return ['grade'=>'D', 'gp'=>1.00];
    return ['grade'=>'F','gp'=>0.00];
}

/* ── COMPUTE MARKS for one subject-row ────────────────────────────
   Returns array ready to INSERT/UPDATE into student_marks.
 ─────────────────────────────────────────────────────────────────── */
function computeMarks(array $es, ?float $mcqRaw, ?float $cqRaw): array {
    $hasMcq   = (bool)(int)$es['has_mcq'];
    $hasCq    = (bool)(int)$es['has_cq'];
    $passPct  = (float)($es['pass_percentage'] ?? 33);

    // Effective full marks (after conversion)
    $mcqEff   = $hasMcq ? (int)($es['mcq_convert_to'] ?: $es['mcq_full_marks']) : 0;
    $cqEff    = $hasCq  ? (int)($es['cq_convert_to']  ?: $es['cq_full_marks'])  : 0;
    $totalEff = $mcqEff + $cqEff;

    // Convert raw → effective
    $mcqConverted = null;
    $cqConverted  = null;

    if ($hasMcq && $mcqRaw !== null) {
        $mcqFull      = (int)$es['mcq_full_marks'];
        $mcqConverted = $mcqFull > 0
            ? round(($mcqRaw / $mcqFull) * $mcqEff, 2)
            : $mcqRaw;
        $mcqConverted = min($mcqConverted, $mcqEff); // cap
    }
    if ($hasCq && $cqRaw !== null) {
        $cqFull      = (int)$es['cq_full_marks'];
        $cqConverted = $cqFull > 0
            ? round(($cqRaw / $cqFull) * $cqEff, 2)
            : $cqRaw;
        $cqConverted = min($cqConverted, $cqEff); // cap
    }

    $totalMarks = ($mcqConverted ?? 0) + ($cqConverted ?? 0);
    $pct        = $totalEff > 0 ? ($totalMarks / $totalEff) * 100 : 0;

    // Pass logic — must pass each component AND combined
    $pass = true;
    if ($hasMcq && $mcqConverted !== null) {
        $mcqPct = $mcqEff > 0 ? ($mcqConverted / $mcqEff) * 100 : 0;
        if ($mcqPct < $passPct) $pass = false;
    }
    if ($hasCq && $cqConverted !== null) {
        $cqPct = $cqEff > 0 ? ($cqConverted / $cqEff) * 100 : 0;
        if ($cqPct < $passPct) $pass = false;
    }
    if ($pct < $passPct) $pass = false;

    $g = calcGrade($pct);

    return [
        'mcq_marks_raw'       => $hasMcq ? $mcqRaw       : null,
        'mcq_marks_converted' => $hasMcq ? $mcqConverted : null,
        'cq_marks_raw'        => $hasCq  ? $cqRaw        : null,
        'cq_marks_converted'  => $hasCq  ? $cqConverted  : null,
        'total_marks'         => round($totalMarks, 2),
        'grade'               => $g['grade'],
        'grade_point'         => $g['gp'],
        'subject_status'      => $pass ? 'pass' : 'fail',
    ];
}

/* ── Rebuild exam result summary for one enrollment ──────────────── */
function rebuildSummary(int $enrollmentId, int $examId): void {
    // Fetch all saved marks for this student in this exam, including
    // the subject_id so we can identify the 4th subject vs compulsory.
    $marks = rows(
        "SELECT sm.grade_point, sm.subject_status, sm.total_marks,
                es.subject_id,
                es.cq_convert_to, es.cq_full_marks, es.mcq_convert_to, es.mcq_full_marks,
                es.has_cq, es.has_mcq
         FROM student_marks sm
         JOIN exam_subjects es ON es.exam_subject_id = sm.exam_subject_id
         WHERE sm.enrollment_id=? AND es.exam_id=? AND sm.total_marks IS NOT NULL",
        [$enrollmentId, $examId]
    );
    if (empty($marks)) return;

    // Look up this student's 4th subject_id for this exam (may be null for non-9-10 classes).
    $fourthSubjectId = sv(
        "SELECT e.fourth_subject_id FROM enrollments e WHERE e.enrollment_id=?",
        [$enrollmentId]
    );
    $fourthSubjectId = $fourthSubjectId !== null ? (int)$fourthSubjectId : null;

    // ── SSC GPA calculation (Bangladesh Board) ────────────────────
    // Compulsory subjects: every subject EXCEPT the 4th subject.
    // 4th subject: provides only a bonus of max(0, GP - 2.00) — never counted
    // in the average, and an F on the 4th subject does NOT fail the student.
    $compulsoryGP   = 0.0;
    $compulsoryCount= 0;
    $compulsoryFail = false; // true if any compulsory subject is F
    $totalMarks     = 0.0;
    $fourthGP       = null; // null = no 4th subject mark in this exam

    foreach ($marks as $m) {
        $gp      = (float)$m['grade_point'];
        $subjId  = (int)$m['subject_id'];
        $isFourth = ($fourthSubjectId !== null && $subjId === $fourthSubjectId);

        $totalMarks += (float)$m['total_marks'];

        if ($isFourth) {
            $fourthGP = $gp;
            // 4th subject F does NOT fail — intentionally not setting $compulsoryFail here.
        } else {
            $compulsoryGP += $gp;
            $compulsoryCount++;
            if ($m['subject_status'] === 'fail') $compulsoryFail = true;
        }
    }

    // Bonus from 4th subject
    $bonus = ($fourthGP !== null) ? max(0.0, $fourthGP - 2.0) : 0.0;

    // Final GPA = (sum of compulsory GPs + bonus) / number of compulsory subjects
    // Capped at 5.00. Falls back to simple average if no compulsory subjects (edge case).
    if ($compulsoryCount > 0) {
        $gpa = round(min(5.00, ($compulsoryGP + $bonus) / $compulsoryCount), 2);
    } else {
        // No compulsory marks yet (only 4th subject saved) — defer to 0.
        $gpa = 0.00;
    }

    // Pass only if ALL compulsory subjects passed; 4th subject result is irrelevant.
    $status = $compulsoryFail ? 'fail' : 'pass';

    // Upsert summary
    $existing = sv("SELECT summary_id FROM exam_results_summary WHERE enrollment_id=? AND exam_id=?",
        [$enrollmentId, $examId]);
    if ($existing) {
        dbQuery(
            "UPDATE exam_results_summary SET total_marks=?,gpa=?,overall_status=?,calculated_at=NOW()
             WHERE enrollment_id=? AND exam_id=?",
            [$totalMarks, $gpa, $status, $enrollmentId, $examId]
        );
    } else {
        dbQuery(
            "INSERT INTO exam_results_summary (enrollment_id,exam_id,total_marks,gpa,overall_status,calculated_at)
             VALUES (?,?,?,?,?,NOW())",
            [$enrollmentId, $examId, $totalMarks, $gpa, $status]
        );
    }
}

// ═════════════════════════════════════════════════════════════════
// ACTION: fetch_exams_for_marks
// Returns exams list for marks page selector
// ═════════════════════════════════════════════════════════════════
if ($action === 'fetch_exams_for_marks') {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $classId   = (int)($_POST['class_id']   ?? 0);
    $where     = ['1=1'];
    $params    = [];
    if ($sessionId > 0) { $where[] = 'e.session_id=?'; $params[] = $sessionId; }
    if ($classId   > 0) { $where[] = 'e.class_id=?';   $params[] = $classId; }
    try {
        $exams = rows(
            "SELECT e.exam_id, e.exam_name, e.status,
                    c.label AS class_label, sess.label AS session_label, sess.session_year,
                    e.group_id, g.group_name,
                    COUNT(DISTINCT es.exam_subject_id) AS subject_count
             FROM exams e
             JOIN classes c ON c.class_id=e.class_id
             JOIN sessions sess ON sess.session_id=e.session_id
             LEFT JOIN groups g ON g.group_id=e.group_id
             LEFT JOIN exam_subjects es ON es.exam_id=e.exam_id
             WHERE " . implode(' AND ',$where) . "
             GROUP BY e.exam_id
             ORDER BY sess.session_year DESC, e.created_at DESC",
            $params
        );
        ok(['exams' => $exams]);
    } catch (Throwable $e) {
        fail('Failed to fetch exams.');
    }
}

// ═════════════════════════════════════════════════════════════════
// ACTION: fetch_marks_sheet
// Returns students + subjects + existing marks for exam+subject
// ═════════════════════════════════════════════════════════════════
if ($action === 'fetch_marks_sheet') {
    $examId     = (int)($_POST['exam_id']     ?? 0);
    $subjectIdx = (int)($_POST['subject_idx'] ?? 0); // 0-based index into exam_subjects
    if ($examId < 1) fail('Invalid exam.');

    try {
        // Get exam + all its subjects
        $exam = dbQuery(
            "SELECT e.*, c.label AS class_label, c.class_num,
                    sess.label AS session_label, sess.session_id AS sess_id,
                    g.group_name
             FROM exams e
             JOIN classes c ON c.class_id=e.class_id
             JOIN sessions sess ON sess.session_id=e.session_id
             LEFT JOIN groups g ON g.group_id=e.group_id
             WHERE e.exam_id=?", [$examId]
        )->fetch();
        if (!$exam) fail('Exam not found.');

        $subjects = rows(
            "SELECT es.*, s.subject_name, s.subject_code
             FROM exam_subjects es
             JOIN subjects s ON s.subject_id=es.subject_id
             WHERE es.exam_id=?
             ORDER BY es.sort_order, s.subject_name",
            [$examId]
        );
        if (empty($subjects)) fail('No subjects configured for this exam.');

        // Clamp subject index
        if ($subjectIdx < 0 || $subjectIdx >= count($subjects)) $subjectIdx = 0;
        $curSubj = $subjects[$subjectIdx];
        $esId    = (int)$curSubj['exam_subject_id'];

        // Resolve whether the current subject is a choice subject and what group it belongs to.
        // This drives per-student filtering: choice subjects only appear for students who
        // chose them as main or 4th subject, and NOT for students who have them as eliminated.
        $subjectRow = dbQuery(
            "SELECT is_choice, group_id FROM subjects WHERE subject_id=?",
            [(int)$curSubj['subject_id']]
        )->fetch();
        $subjectIsChoice  = $subjectRow && (int)$subjectRow['is_choice'] === 1;
        $subjectGroupId   = $subjectRow ? (int)($subjectRow['group_id'] ?? 0) : 0;
        $examGroupId      = (int)($exam['group_id'] ?? 0);
        $curSubjectId     = (int)$curSubj['subject_id'];

        // A subject's OWN group (if it has one) always decides which students see it —
        // this matters for "All Groups" exams where $examGroupId is empty but a subject
        // like Physics/Economics still belongs to one specific group. Only fall back to
        // the exam's own group for common subjects (no group of their own) inside a
        // group-restricted exam.
        $requiredGroupId  = $subjectGroupId ?: $examGroupId;

        // Get enrolled students for this exam's class+session+group, with per-student
        // subject-choice columns so we can apply choice-subject filtering below.
        $baseWhere  = 'e.class_id=? AND e.session_id=? AND e.status=\'active\'';
        $baseParams = [(int)$exam['class_id'], (int)$exam['sess_id']];

        if ($subjectIsChoice && $subjectGroupId) {
            // Choice subject that belongs to a specific group (e.g. Biology/Higher Math
            // for Science, Economics/General Science for Arts/Commerce).
            // Eligible students are:
            //   - students in THIS subject's own group who picked it as their main subject, OR
            //   - ANY student (own-group or cross-group) who picked it as their 4th subject —
            //     4th-subject students may come from a different group entirely.
            $baseWhere  .= ' AND ((e.group_id=? AND e.main_subject_id=?) OR e.fourth_subject_id=?)';
            $baseWhere  .= ' AND (e.eliminated_subject_id IS NULL OR e.eliminated_subject_id != ?)';
            $baseParams  = array_merge($baseParams, [$subjectGroupId, $curSubjectId, $curSubjectId, $curSubjectId]);
        } else {
            // Compulsory subject — either group-owned (e.g. Physics) or common to all groups.
            // Group-owned compulsory subjects are restricted to that group's students even
            // when the exam itself spans "All Groups".
            if ($requiredGroupId) {
                $baseWhere  .= ' AND e.group_id=?';
                $baseParams[] = $requiredGroupId;
            }
            // Exclude students whose eliminated subject is this one.
            $baseWhere  .= ' AND (e.eliminated_subject_id IS NULL OR e.eliminated_subject_id != ?)';
            $baseParams[] = $curSubjectId;
        }

        $students = rows(
            "SELECT e.enrollment_id, e.roll_number,
                    e.main_subject_id, e.fourth_subject_id, e.eliminated_subject_id,
                    s.student_id, s.full_name, s.photo_path, s.sys_uid, s.gender
             FROM enrollments e
             JOIN students s ON s.sys_uid=e.sys_uid
             WHERE $baseWhere
             ORDER BY e.roll_number, s.full_name",
            $baseParams
        );

        // Tag each student row with their relationship to this subject
        // (compulsory / main_choice / fourth_subject) — used by the frontend
        // to show a small badge on the student row so the admin has visual context.
        foreach ($students as &$st) {
            if (!$subjectIsChoice) {
                $st['subject_role'] = 'compulsory';
            } elseif ((int)($st['main_subject_id'] ?? 0) === $curSubjectId) {
                $st['subject_role'] = 'main';
            } elseif ((int)($st['fourth_subject_id'] ?? 0) === $curSubjectId) {
                $st['subject_role'] = 'fourth';
            } else {
                $st['subject_role'] = 'compulsory'; // fallback, shouldn't happen
            }
        }
        unset($st);

        // Get existing marks for this subject
        $existingMarks = [];
        if (!empty($students)) {
            $eIds   = array_column($students, 'enrollment_id');
            $placeholders = implode(',', array_fill(0, count($eIds), '?'));
            $mRows  = rows(
                "SELECT * FROM student_marks
                 WHERE exam_subject_id=? AND enrollment_id IN ($placeholders)",
                array_merge([$esId], $eIds)
            );
            foreach ($mRows as $m) {
                $existingMarks[(int)$m['enrollment_id']] = $m;
            }
        }

        ok([
            'exam'           => $exam,
            'subjects'       => $subjects,
            'subject_idx'    => $subjectIdx,
            'current_subject'=> $curSubj,
            'students'       => $students,
            'marks'          => $existingMarks,
        ]);
    } catch (Throwable $e) {
        error_log('EMS fetch_marks_sheet: ' . $e->getMessage());
        fail('Failed to load marks sheet.');
    }
}

// ═════════════════════════════════════════════════════════════════
// ACTION: fetch_subject_progress
// Returns, for every subject in the exam, how many eligible students
// already have a saved mark (total_marks IS NOT NULL) vs. the total
// eligible student count — computed straight from the DB so the
// subject-tab progress dots are accurate for every admin on every
// page load, not just the tab that happens to be open right now.
// ═════════════════════════════════════════════════════════════════
if ($action === 'fetch_subject_progress') {
    $examId = (int)($_POST['exam_id'] ?? 0);
    if ($examId < 1) fail('Invalid exam.');

    try {
        $exam = dbQuery(
            "SELECT class_id, session_id, group_id FROM exams WHERE exam_id=?",
            [$examId]
        )->fetch();
        if (!$exam) fail('Exam not found.');

        $examGroupId = (int)($exam['group_id'] ?? 0);

        // Get every subject in this exam with its is_choice and group_id,
        // so we can compute the correct eligible student count per subject.
        $examSubjects = rows(
            "SELECT es.exam_subject_id, es.subject_id,
                    s.is_choice, s.group_id AS subj_group_id
             FROM exam_subjects es
             JOIN subjects s ON s.subject_id = es.subject_id
             WHERE es.exam_id=?",
            [$examId]
        );

        $classId   = (int)$exam['class_id'];
        $sessionId = (int)$exam['session_id'];
        $progress  = [];

        foreach ($examSubjects as $es) {
            $esId           = (int)$es['exam_subject_id'];
            $isChoice       = (int)$es['is_choice'] === 1;
            $subjGroupId    = (int)($es['subj_group_id'] ?? 0);
            $subjId         = (int)$es['subject_id'];
            // Subject's own group wins; fall back to the exam's group only for
            // common subjects (no group of their own) in a group-restricted exam.
            $requiredGroupId = $subjGroupId ?: $examGroupId;

            // Build the eligible-student WHERE for this specific subject
            if ($isChoice && $subjGroupId) {
                // Own-group choice subject — eligible students are either
                // this group's students who picked it as main_subject_id, OR
                // any student (own-group or cross-group) who picked it as fourth_subject_id.
                $eligibleWhere   = "class_id=? AND session_id=? AND status='active' AND ((group_id=? AND main_subject_id=?) OR fourth_subject_id=?) AND (eliminated_subject_id IS NULL OR eliminated_subject_id != ?)";
                $eligibleWhereEn = "en.class_id=? AND en.session_id=? AND en.status='active' AND ((en.group_id=? AND en.main_subject_id=?) OR en.fourth_subject_id=?) AND (en.eliminated_subject_id IS NULL OR en.eliminated_subject_id != ?)";
                $eligibleParams  = [$classId, $sessionId, $subjGroupId, $subjId, $subjId, $subjId];
            } else {
                // Compulsory subject — group-owned (e.g. Physics) or common to all groups.
                if ($requiredGroupId) {
                    $eligibleWhere   = "class_id=? AND session_id=? AND status='active' AND group_id=? AND (eliminated_subject_id IS NULL OR eliminated_subject_id != ?)";
                    $eligibleWhereEn = "en.class_id=? AND en.session_id=? AND en.status='active' AND en.group_id=? AND (en.eliminated_subject_id IS NULL OR en.eliminated_subject_id != ?)";
                    $eligibleParams  = [$classId, $sessionId, $requiredGroupId, $subjId];
                } else {
                    $eligibleWhere   = "class_id=? AND session_id=? AND status='active' AND (eliminated_subject_id IS NULL OR eliminated_subject_id != ?)";
                    $eligibleWhereEn = "en.class_id=? AND en.session_id=? AND en.status='active' AND (en.eliminated_subject_id IS NULL OR en.eliminated_subject_id != ?)";
                    $eligibleParams  = [$classId, $sessionId, $subjId];
                }
            }

            $total   = (int)(sv("SELECT COUNT(*) FROM enrollments WHERE $eligibleWhere", $eligibleParams) ?? 0);
            $entered = (int)(sv(
                "SELECT COUNT(*) FROM student_marks sm
                 JOIN enrollments en ON en.enrollment_id = sm.enrollment_id
                 WHERE sm.exam_subject_id=? AND sm.total_marks IS NOT NULL
                   AND $eligibleWhereEn",
                array_merge([$esId], $eligibleParams)
            ) ?? 0);

            $progress[$esId] = ['total' => $total, 'entered' => $entered];
        }

        ok([
            'total_students' => null, // deprecated — per-subject totals now in progress
            'entered'        => $progress,
        ]);
    } catch (Throwable $e) {
        error_log('EMS fetch_subject_progress: ' . $e->getMessage());
        fail('Failed to load subject progress.');
    }
}

// ═════════════════════════════════════════════════════════════════
// ACTION: save_marks
// Accepts JSON array of { enrollment_id, mcq_raw, cq_raw, absent }
// Computes grade/GPA inline, upserts student_marks, rebuilds summary
// ═════════════════════════════════════════════════════════════════
if ($action === 'save_marks') {
    $examSubjectId = (int)($_POST['exam_subject_id'] ?? 0);
    $marksJson     = trim($_POST['marks_data'] ?? '');

    if ($examSubjectId < 1)   fail('Invalid exam subject.');
    if (empty($marksJson))    fail('No marks data received.');

    $marksData = json_decode($marksJson, true);
    if (!is_array($marksData)) fail('Invalid marks format.');

    // Load exam subject config
    $es = dbQuery(
        "SELECT es.*, e.exam_id, e.status AS exam_status,
                s.subject_name
         FROM exam_subjects es
         JOIN exams e ON e.exam_id=es.exam_id
         JOIN subjects s ON s.subject_id=es.subject_id
         WHERE es.exam_subject_id=?",
        [$examSubjectId]
    )->fetch();
    if (!$es) fail('Exam subject not found.');

    // Admin can always save — locked flag is for teachers only
    // (admin can edit even after publish per requirements)

    try {
        db()->beginTransaction();

        $saved = 0;
        $affected = []; // [enrollment_id => true]

        foreach ($marksData as $row) {
            $enrollId = (int)($row['enrollment_id'] ?? 0);
            if ($enrollId < 1) continue;

            // Absent flag — store nulls
            $absent = !empty($row['absent']);

            $mcqRaw = null;
            $cqRaw  = null;
            if (!$absent) {
                if ((bool)(int)$es['has_mcq'] && isset($row['mcq_raw']) && $row['mcq_raw'] !== '') {
                    $mcqRaw = max(0.0, (float)$row['mcq_raw']);
                    $mcqMax = (int)$es['mcq_full_marks'];
                    if ($mcqRaw > $mcqMax) fail("MCQ marks for enrollment #$enrollId exceed maximum ($mcqMax).");
                }
                if ((bool)(int)$es['has_cq'] && isset($row['cq_raw']) && $row['cq_raw'] !== '') {
                    $cqRaw = max(0.0, (float)$row['cq_raw']);
                    $cqMax = (int)$es['cq_full_marks'];
                    if ($cqRaw > $cqMax) fail("CQ marks for enrollment #$enrollId exceed maximum ($cqMax).");
                }
            }

            if ($absent) {
                // Absent = store 0s and F grade
                $computed = [
                    'mcq_marks_raw'=>null,'mcq_marks_converted'=>null,
                    'cq_marks_raw'=>null,'cq_marks_converted'=>null,
                    'total_marks'=>0,'grade'=>'F','grade_point'=>0.00,'subject_status'=>'fail',
                ];
            } elseif ($mcqRaw === null && $cqRaw === null) {
                // No data entered — skip this student (don't save blank)
                continue;
            } else {
                $computed = computeMarks($es, $mcqRaw, $cqRaw);
            }

            // Check existing
            $existing = sv(
                "SELECT mark_id FROM student_marks WHERE enrollment_id=? AND exam_subject_id=?",
                [$enrollId, $examSubjectId]
            );

            if ($existing) {
                dbQuery(
                    "UPDATE student_marks SET
                         mcq_marks_raw=?, mcq_marks_converted=?,
                         cq_marks_raw=?,  cq_marks_converted=?,
                         total_marks=?, grade=?, grade_point=?, subject_status=?,
                         submitted_by=?, updated_at=NOW(), is_locked=0
                     WHERE mark_id=?",
                    [
                        $computed['mcq_marks_raw'], $computed['mcq_marks_converted'],
                        $computed['cq_marks_raw'],  $computed['cq_marks_converted'],
                        $computed['total_marks'], $computed['grade'],
                        $computed['grade_point'], $computed['subject_status'],
                        $uid, (int)$existing
                    ]
                );
            } else {
                dbQuery(
                    "INSERT INTO student_marks
                         (enrollment_id, exam_subject_id,
                          mcq_marks_raw, mcq_marks_converted,
                          cq_marks_raw,  cq_marks_converted,
                          total_marks, grade, grade_point, subject_status,
                          submitted_by, submitted_at, is_locked)
                     VALUES (?,?, ?,?, ?,?, ?,?,?,?, ?,NOW(),0)",
                    [
                        $enrollId, $examSubjectId,
                        $computed['mcq_marks_raw'], $computed['mcq_marks_converted'],
                        $computed['cq_marks_raw'],  $computed['cq_marks_converted'],
                        $computed['total_marks'], $computed['grade'],
                        $computed['grade_point'], $computed['subject_status'],
                        $uid
                    ]
                );
            }

            $affected[$enrollId] = true;
            $saved++;
        }

        // Rebuild result summaries for affected students
        $examId = (int)$es['exam_id'];
        foreach (array_keys($affected) as $enrollId) {
            rebuildSummary($enrollId, $examId);
        }

        db()->commit();

        // Resolve sys_uids for all affected students (for activity log history)
        $affectedStudentUids = [];
        if (!empty($affected)) {
            $eIdsForLog  = array_keys($affected);
            $logPlaceholders = implode(',', array_fill(0, count($eIdsForLog), '?'));
            $uidRows = rows(
                "SELECT sys_uid FROM enrollments WHERE enrollment_id IN ($logPlaceholders)",
                $eIdsForLog
            );
            foreach ($uidRows as $r) $affectedStudentUids[] = $r['sys_uid'];
        }

        log_activity($uid, 'upload_marks', 'exams', (string)$examId, [
            'exam_subject_id' => $examSubjectId,
            'subject'         => $es['subject_name'],
            'count'           => $saved,
            'student_uids'    => $affectedStudentUids,
        ]);

        // Return updated marks for this subject
        $eIds        = array_keys($affected);
        $placeholders = implode(',', array_fill(0, count($eIds), '?'));
        $updatedMarks = [];
        if (!empty($eIds)) {
            $mRows = rows(
                "SELECT * FROM student_marks
                 WHERE exam_subject_id=? AND enrollment_id IN ($placeholders)",
                array_merge([$examSubjectId], $eIds)
            );
            foreach ($mRows as $m) $updatedMarks[(int)$m['enrollment_id']] = $m;
        }

        ok(['saved' => $saved, 'marks' => $updatedMarks,
            'msg' => "Marks saved for $saved student" . ($saved !== 1 ? 's' : '') . "."]);

    } catch (Throwable $e) {
        db()->rollBack();
        error_log('EMS save_marks: ' . $e->getMessage());
        fail('Database error. Please try again.');
    }
}

// ═════════════════════════════════════════════════════════════════
// ACTION: clear_subject_marks  (admin only)
// ═════════════════════════════════════════════════════════════════
if ($action === 'clear_subject_marks') {
    $examSubjectId = (int)($_POST['exam_subject_id'] ?? 0);
    if ($examSubjectId < 1) fail('Invalid exam subject.');

    $es = dbQuery(
        "SELECT es.exam_id, e.status, s.subject_name
         FROM exam_subjects es
         JOIN exams e ON e.exam_id=es.exam_id
         JOIN subjects s ON s.subject_id=es.subject_id
         WHERE es.exam_subject_id=?", [$examSubjectId]
    )->fetch();
    if (!$es) fail('Exam subject not found.');

    try {
        // Capture affected enrollments + student sys_uids BEFORE deleting
        // (must run before DELETE, otherwise no student_marks rows remain to join against)
        $affectedRows = rows(
            "SELECT DISTINCT sm.enrollment_id, e.sys_uid
             FROM student_marks sm
             JOIN enrollments e ON e.enrollment_id = sm.enrollment_id
             WHERE sm.exam_subject_id=?", [$examSubjectId]
        );
        $enrollIds = array_map(fn($r) => (int)$r['enrollment_id'], $affectedRows);
        $affectedStudentUids = array_values(array_unique(array_map(fn($r) => $r['sys_uid'], $affectedRows)));

        $deleted = (int)(sv("SELECT COUNT(*) FROM student_marks WHERE exam_subject_id=?", [$examSubjectId]) ?? 0);
        dbQuery("DELETE FROM student_marks WHERE exam_subject_id=?", [$examSubjectId]);

        // Rebuild summaries for all students in this exam
        foreach ($enrollIds as $enrollId) rebuildSummary($enrollId, (int)$es['exam_id']);

        log_activity($uid, 'clear_marks', 'exams', (string)$es['exam_id'], [
            'exam_subject_id' => $examSubjectId,
            'subject'         => $es['subject_name'],
            'deleted'         => $deleted,
            'student_uids'    => $affectedStudentUids,
        ]);

        ok(['deleted' => $deleted, 'msg' => "Cleared $deleted mark record" . ($deleted !== 1 ? 's' : '') . " for {$es['subject_name']}."]);
    } catch (Throwable $e) {
        error_log('EMS clear_subject_marks: ' . $e->getMessage());
        fail('Failed to clear marks.');
    }
}

// ═════════════════════════════════════════════════════════════════
// ACTION: get_exam_summary
// Per-student totals across all subjects for an exam
// ═════════════════════════════════════════════════════════════════
if ($action === 'get_exam_summary') {
    $examId = (int)($_POST['exam_id'] ?? 0);
    if ($examId < 1) fail('Invalid exam.');

    try {
        $exam = dbQuery(
            "SELECT e.*, c.label AS class_label, sess.label AS session_label
             FROM exams e
             JOIN classes c ON c.class_id=e.class_id
             JOIN sessions sess ON sess.session_id=e.session_id
             WHERE e.exam_id=?", [$examId]
        )->fetch();
        if (!$exam) fail('Exam not found.');

        // Subject list with total possible marks
        $subjects = rows(
            "SELECT es.exam_subject_id, es.sort_order,
                    s.subject_name, s.subject_code,
                    COALESCE(es.cq_convert_to,  es.cq_full_marks,  0) AS cq_eff,
                    COALESCE(es.mcq_convert_to, es.mcq_full_marks, 0) AS mcq_eff,
                    es.pass_percentage
             FROM exam_subjects es
             JOIN subjects s ON s.subject_id=es.subject_id
             WHERE es.exam_id=?
             ORDER BY es.sort_order, s.subject_name", [$examId]
        );

        // Per-student summary from exam_results_summary
        $summaries = rows(
            "SELECT ers.*, st.full_name, st.student_id, e.roll_number,
                    GROUP_CONCAT(
                        CONCAT(sm2.exam_subject_id,':',
                               COALESCE(sm2.grade,'—'),':',
                               COALESCE(sm2.total_marks,'—'),':',
                               COALESCE(sm2.subject_status,'—'))
                        ORDER BY es2.sort_order SEPARATOR '|'
                    ) AS subject_marks_raw
             FROM exam_results_summary ers
             JOIN enrollments e   ON e.enrollment_id=ers.enrollment_id
             JOIN students st     ON st.sys_uid=e.sys_uid
             LEFT JOIN student_marks sm2  ON sm2.enrollment_id=ers.enrollment_id
             LEFT JOIN exam_subjects es2  ON es2.exam_subject_id=sm2.exam_subject_id
                                         AND es2.exam_id=?
             WHERE ers.exam_id=?
             GROUP BY ers.summary_id
             ORDER BY ers.gpa DESC, st.full_name",
            [$examId, $examId]
        );

        // Also get count of students with no marks yet
        $totalStudents = (int)(sv(
            "SELECT COUNT(*) FROM enrollments
             WHERE class_id=? AND session_id=? AND status='active'" .
            ($exam['group_id'] ? " AND group_id=?" : ""),
            $exam['group_id']
                ? [(int)$exam['class_id'],(int)$exam['session_id'],(int)$exam['group_id']]
                : [(int)$exam['class_id'],(int)$exam['session_id']]
        ) ?? 0);

        ok([
            'exam'           => $exam,
            'subjects'       => $subjects,
            'summaries'      => $summaries,
            'total_students' => $totalStudents,
        ]);
    } catch (Throwable $e) {
        error_log('EMS get_exam_summary: ' . $e->getMessage());
        fail('Failed to load summary.');
    }
}

fail('Unknown action.');
<?php
/**
 * EMS Admin — Assign Teacher AJAX Handler
 * ─────────────────────────────────────────────────────────────────
 * Actions:
 *   fetch_assignments  — get all subject→teacher assignments for a class+session
 *   save_assignments   — bulk save/update all assignments for a class+session
 *   remove_assignment  — remove one subject's teacher assignment
 *   fetch_teachers     — list active teachers for dropdowns
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

$action = trim($_POST['action'] ?? '');

// ── FETCH TEACHERS (for dropdown) ────────────────────────────────
if ($action === 'fetch_teachers') {
    try {
        $rows = dbQuery(
            "SELECT t.sys_uid, t.full_name, t.designation
             FROM teachers t
             JOIN system_users su ON su.sys_uid = t.sys_uid
             WHERE su.is_active = 1
             ORDER BY t.full_name ASC"
        )->fetchAll();
        ok(['teachers' => $rows]);
    } catch (Throwable $e) {
        fail('Failed to fetch teachers.');
    }
}

// ── FETCH ASSIGNMENTS for a class+session ─────────────────────────
if ($action === 'fetch_assignments') {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $classId   = (int)($_POST['class_id']   ?? 0);
    $groupId   = (int)($_POST['group_id']   ?? 0) ?: null;

    if ($sessionId < 1 || $classId < 1) fail('Please select a session and class.');

    // Validate class exists
    $classRow = dbQuery("SELECT class_num, label FROM classes WHERE class_id=?", [$classId])->fetch();
    if (!$classRow) fail('Invalid class.');

    $classNum = (int)$classRow['class_num'];
    // For class 6-8, group is irrelevant
    if ($classNum < 9) $groupId = null;

    try {
        // Get subjects for this class (and matching group)
        // group_id NULL = all groups, specific group_id = only that group
        $subjWhere  = 's.class_id = ? AND s.is_active = 1';
        $subjParams = [$classId];

        if ($groupId) {
            // Show subjects that apply to all groups OR to this specific group
            $subjWhere  .= ' AND (s.group_id IS NULL OR s.group_id = ?)';
            $subjParams[] = $groupId;
        } else {
            // No group filter (class 6-8 or no group selected)
            $subjWhere .= ' AND (s.group_id IS NULL)';
        }

        $subjects = dbQuery(
            "SELECT s.subject_id, s.subject_name, s.subject_code, s.group_id,
                    g.group_name
             FROM subjects s
             LEFT JOIN groups g ON g.group_id = s.group_id
             WHERE $subjWhere
             ORDER BY s.group_id IS NULL DESC, g.group_name, s.subject_name",
            $subjParams
        )->fetchAll();

        if (empty($subjects)) {
            ok([
                'subjects'     => [],
                'assignments'  => [],
                'class_num'    => $classNum,
                'class_label'  => $classRow['label'],
                'warning'      => 'No active subjects found for this class' . ($groupId ? '/group' : '') . '. Add subjects first.',
            ]);
        }

        // Get existing assignments — one teacher per subject for this class+session
        // We store assignments in class_routine but for assign-teacher we just need
        // subject→teacher mapping (no time slots — that's the class routine builder).
        //
        // Design decision: assign-teacher sets teacher_uid on ALL existing routine slots
        // for that subject+class+session. If no slots exist yet, we create a placeholder
        // (day=6, period_number=NULL, start=00:00, end=00:00) so the assignment is saved
        // without faking a real timetable period.

        $assignRows = dbQuery(
            "SELECT DISTINCT cr.subject_id, cr.teacher_uid,
                    t.full_name AS teacher_name, t.designation,
                    t.gender, t.photo_path
             FROM class_routine cr
             LEFT JOIN teachers t ON t.sys_uid = cr.teacher_uid
             WHERE cr.session_id = ? AND cr.class_id = ?
             AND cr.teacher_uid IS NOT NULL",
            [$sessionId, $classId]
        )->fetchAll();

        // Index by subject_id
        $assignments = [];
        foreach ($assignRows as $r) {
            $assignments[$r['subject_id']] = [
                'teacher_uid'  => $r['teacher_uid'],
                'teacher_name' => $r['teacher_name'],
                'designation'  => $r['designation'],
                'gender'       => $r['gender'],
                'photo_path'   => $r['photo_path'],
            ];
        }

        // Count students in this class+session for context
        $studentCount = (int)(sv(
            "SELECT COUNT(*) FROM enrollments WHERE class_id=? AND session_id=? AND status='active'",
            [$classId, $sessionId]
        ) ?? 0);

        ok([
            'subjects'      => $subjects,
            'assignments'   => $assignments,
            'class_num'     => $classNum,
            'class_label'   => $classRow['label'],
            'student_count' => $studentCount,
        ]);
    } catch (Throwable $e) {
        error_log('EMS fetch_assignments: ' . $e->getMessage());
        fail('Failed to fetch assignments.');
    }
}

// ── SAVE ASSIGNMENTS (bulk) ───────────────────────────────────────
if ($action === 'save_assignments') {
    $sessionId   = (int)($_POST['session_id']    ?? 0);
    $classId     = (int)($_POST['class_id']      ?? 0);
    $groupId     = (int)($_POST['group_id']      ?? 0) ?: null;
    $assignments = $_POST['assignments'] ?? ''; // JSON string: {subject_id: teacher_uid, ...}

    if ($sessionId < 1 || $classId < 1) fail('Invalid session or class.');

    $parsed = json_decode($assignments, true);
    if (!is_array($parsed)) fail('Invalid assignment data.');

    // Validate session + class exist
    $sessionExists = sv("SELECT COUNT(*) FROM sessions WHERE session_id=?", [$sessionId]);
    $classExists   = sv("SELECT COUNT(*) FROM classes  WHERE class_id=?",   [$classId]);
    if (!$sessionExists) fail('Invalid session.');
    if (!$classExists)   fail('Invalid class.');

    $classNum = (int)(sv("SELECT class_num FROM classes WHERE class_id=?", [$classId]) ?? 0);
    if ($classNum < 9) $groupId = null;

    try {
        db()->beginTransaction();
        $savedCount   = 0;
        $removedCount = 0;

        foreach ($parsed as $subjectId => $teacherUid) {
            $subjectId  = (int)$subjectId;
            $teacherUid = trim((string)$teacherUid);

            if ($subjectId < 1) continue;

            // Validate subject belongs to this class
            $subExists = sv(
                "SELECT COUNT(*) FROM subjects WHERE subject_id=? AND class_id=?",
                [$subjectId, $classId]
            );
            if (!$subExists) continue;

            if ($teacherUid === '' || $teacherUid === '0') {
                // Remove teacher from this subject's routine slots
                $existing = sv(
                    "SELECT COUNT(*) FROM class_routine
                     WHERE session_id=? AND class_id=? AND subject_id=?",
                    [$sessionId, $classId, $subjectId]
                );
                if ($existing > 0) {
                    // Delete placeholder-only rows (day=6 = our placeholder marker)
                    dbQuery(
                        "DELETE FROM class_routine
                         WHERE session_id=? AND class_id=? AND subject_id=?
                         AND day_of_week=6",
                        [$sessionId, $classId, $subjectId]
                    );
                    dbQuery(
                        "UPDATE class_routine SET teacher_uid=NULL
                         WHERE session_id=? AND class_id=? AND subject_id=?",
                        [$sessionId, $classId, $subjectId]
                    );
                    $removedCount++;
                }
                continue;
            }

            // Validate teacher exists and is active
            $tActive = sv(
                "SELECT is_active FROM system_users WHERE sys_uid=? AND role='teacher'",
                [$teacherUid]
            );
            if (!$tActive) continue;

            // Check if any routine slots exist for this subject+class+session
            $slotCount = (int)(sv(
                "SELECT COUNT(*) FROM class_routine
                 WHERE session_id=? AND class_id=? AND subject_id=?",
                [$sessionId, $classId, $subjectId]
            ) ?? 0);

            if ($slotCount > 0) {
                // Update all existing slots for this subject
                dbQuery(
                    "UPDATE class_routine SET teacher_uid=?
                     WHERE session_id=? AND class_id=? AND subject_id=?",
                    [$teacherUid, $sessionId, $classId, $subjectId]
                );
            } else {
                // No real slots yet — create a placeholder assignment row.
                // Use day_of_week=6 (invalid school day) as the placeholder marker.
                // period_number is left NULL — this is just a teacher↔subject
                // assignment, not a real timetable slot, so no period applies.
                // (NULL period_number means the unique key can't dedupe placeholder
                // rows for us, so we check for an existing placeholder by subject_id
                // first and UPDATE it instead of relying on ON DUPLICATE KEY.)
                $placeholderExists = sv(
                    "SELECT COUNT(*) FROM class_routine
                     WHERE session_id=? AND class_id=? AND subject_id=? AND day_of_week=6",
                    [$sessionId, $classId, $subjectId]
                );

                if ($placeholderExists > 0) {
                    dbQuery(
                        "UPDATE class_routine SET teacher_uid=?
                         WHERE session_id=? AND class_id=? AND subject_id=? AND day_of_week=6",
                        [$teacherUid, $sessionId, $classId, $subjectId]
                    );
                } else {
                    dbQuery(
                        "INSERT INTO class_routine
                            (class_id, session_id, group_id, day_of_week, period_number,
                             start_time, end_time, subject_id, teacher_uid)
                         VALUES (?, ?, ?, 6, NULL, '00:00:00', '00:00:00', ?, ?)",
                        [$classId, $sessionId, $groupId ?? 0, $subjectId, $teacherUid]
                    );
                }
            }
            $savedCount++;
        }

        db()->commit();

        log_activity(
            $_SESSION['ems_uid'], 'assign_teachers', 'class_routine',
            $classId . '_' . $sessionId,
            ['class_id' => $classId, 'session_id' => $sessionId, 'count' => $savedCount]
        );

        $msg = $savedCount . ' assignment' . ($savedCount !== 1 ? 's' : '') . ' saved';
        if ($removedCount > 0) $msg .= ', ' . $removedCount . ' removed';
        $msg .= ' successfully.';

        ok(['msg' => $msg, 'saved' => $savedCount, 'removed' => $removedCount]);

    } catch (Throwable $e) {
        db()->rollBack();
        error_log('EMS save_assignments: ' . $e->getMessage());
        fail('Failed to save assignments. Please try again.');
    }
}

// ── REMOVE single assignment ──────────────────────────────────────
if ($action === 'remove_assignment') {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $classId   = (int)($_POST['class_id']   ?? 0);
    $subjectId = (int)($_POST['subject_id'] ?? 0);

    if ($sessionId < 1 || $classId < 1 || $subjectId < 1) fail('Invalid data.');

    try {
        // Delete placeholder slots (day=6 = our placeholder marker)
        dbQuery(
            "DELETE FROM class_routine
             WHERE session_id=? AND class_id=? AND subject_id=?
             AND day_of_week=6",
            [$sessionId, $classId, $subjectId]
        );
        // Null out teacher on real timetable slots
        dbQuery(
            "UPDATE class_routine SET teacher_uid=NULL
             WHERE session_id=? AND class_id=? AND subject_id=?",
            [$sessionId, $classId, $subjectId]
        );

        $sName = (string)(sv("SELECT subject_name FROM subjects WHERE subject_id=?", [$subjectId]) ?? '');
        log_activity($_SESSION['ems_uid'], 'remove_assignment', 'class_routine',
            $classId . '_' . $sessionId, ['subject' => $sName]);

        ok(['msg' => "Assignment for \"$sName\" removed."]);
    } catch (Throwable $e) {
        error_log('EMS remove_assignment: ' . $e->getMessage());
        fail('Failed to remove assignment.');
    }
}

// ── FETCH SUMMARY (all assignments across all classes for a session) ──
if ($action === 'fetch_summary') {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    if ($sessionId < 1) fail('Invalid session.');

    try {
        $rows = dbQuery(
            "SELECT DISTINCT c.class_num, c.label AS class_label,
                    s.subject_id, s.subject_name, s.subject_code,
                    t.full_name AS teacher_name, t.sys_uid AS teacher_uid,
                    t.designation,
                    g.group_name
             FROM class_routine cr
             JOIN classes c ON c.class_id = cr.class_id
             JOIN subjects s ON s.subject_id = cr.subject_id
             JOIN teachers t ON t.sys_uid = cr.teacher_uid
             LEFT JOIN groups g ON g.group_id = s.group_id
             WHERE cr.session_id = ? AND cr.teacher_uid IS NOT NULL
             ORDER BY c.class_num, g.group_name, s.subject_name",
            [$sessionId]
        )->fetchAll();

        ok(['summary' => $rows]);
    } catch (Throwable $e) {
        fail('Failed to fetch summary.');
    }
}

fail('Unknown action.');
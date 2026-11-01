<?php
/**
 * EMS Teacher — Attendance AJAX Handler
 * ─────────────────────────────────────────────────────────────────
 * Actions:
 *   fetch_classes    – list of class(+group) combos this teacher is
 *                      assigned to for the current session (feeds
 *                      the class selector dropdown)
 *   fetch_students    – active roster for one class+group+date, with
 *                      existing attendance pre-filled if any exists
 *   save_attendance   – bulk upsert attendance for one class+date
 *
 * Security: every class_id/group_id submitted by the client is
 * re-verified against class_routine for THIS teacher before any read
 * or write happens — a teacher can only ever touch classes they are
 * actually assigned to teach. enrollment_id values in save requests
 * are filtered against the server's own roster query — client input
 * is never trusted to select which enrollment gets written.
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
    catch (Throwable $e) { error_log('EMS teacher attendance tsafeRow: ' . $e->getMessage()); return null; }
}
function tsafeAll(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); }
    catch (Throwable $e) { error_log('EMS teacher attendance tsafeAll: ' . $e->getMessage()); return []; }
}
function tsafeVal(string $sql, array $p = []): mixed {
    try { $v = dbQuery($sql, $p)->fetchColumn(); return $v !== false ? $v : null; }
    catch (Throwable $e) { error_log('EMS teacher attendance tsafeVal: ' . $e->getMessage()); return null; }
}

// Resolves a sys_uid to a friendly display name for the "last updated by" note.
function resolveActorName(string $uid, string $myUid): string {
    if ($uid === $myUid) return 'You';
    try {
        $t = dbQuery("SELECT full_name FROM teachers WHERE sys_uid = ?", [$uid])->fetch();
        if ($t) return $t['full_name'];
    } catch (Throwable $e) {}
    try {
        $a = dbQuery("SELECT username FROM system_users WHERE sys_uid = ? AND role = 'admin'", [$uid])->fetch();
        if ($a) return 'Admin (' . $a['username'] . ')';
    } catch (Throwable $e) {}
    return 'another user';
}

// Resolves a student's photo to a real uploaded file, or null (client shows initials).
function resolveStudentPhoto(?string $photoPath, string $sysUid): ?string {
    if (empty($photoPath)) return null;
    $diskPath = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/uploads/student/photos/' . basename($photoPath);
    if (@file_exists($diskPath)) {
        return rtrim(BASE_URL, '/') . '/uploads/student/photos/' . basename($photoPath);
    }
    return null;
}

// Verifies this teacher is actually assigned to the given class+group
// this session — returns true/false. group_id may be null.
function teacherOwnsClass(string $uid, int $sid, int $classId, ?int $groupId): bool {
    $sql = "SELECT 1 FROM class_routine WHERE teacher_uid = ? AND session_id = ? AND class_id = ?";
    $p   = [$uid, $sid, $classId];
    if ($groupId === null) {
        $sql .= " AND group_id IS NULL";
    } else {
        $sql .= " AND group_id = ?";
        $p[]  = $groupId;
    }
    $sql .= " LIMIT 1";
    return tsafeVal($sql, $p) !== null;
}

$action = trim($_POST['action'] ?? '');

// ── Resolve current session once, used by every action below ────────
$session = tsafeRow("SELECT session_id, session_year FROM sessions WHERE is_current = 1 LIMIT 1");
$sid     = $session['session_id']   ?? null;
$sYear   = $session['session_year'] ?? null;

// ═══════════════════════════════════════════════════════════════════
//  ACTION: fetch_classes
// ═══════════════════════════════════════════════════════════════════
if ($action === 'fetch_classes') {
    if ($sid === null) {
        ok(['no_session' => true, 'classes' => []]);
    }

    $rows = tsafeAll(
        "SELECT DISTINCT cr.class_id, cr.group_id, c.label AS class_label, c.class_num, g.group_name
         FROM class_routine cr
         JOIN classes c ON c.class_id = cr.class_id
         LEFT JOIN groups g ON g.group_id = cr.group_id
         WHERE cr.teacher_uid = ? AND cr.session_id = ?
         ORDER BY c.class_num ASC, g.group_name ASC",
        [$myUid, $sid]
    );

    ok([
        'no_session'   => false,
        'session_year' => $sYear,
        'classes' => array_map(function ($r) {
            return [
                'class_id'    => (int)$r['class_id'],
                'group_id'    => $r['group_id'] !== null ? (int)$r['group_id'] : null,
                'class_label' => $r['class_label'],
                'group_name'  => $r['group_name'],
            ];
        }, $rows),
    ]);
}

// ═══════════════════════════════════════════════════════════════════
//  ACTION: fetch_students
// ═══════════════════════════════════════════════════════════════════
if ($action === 'fetch_students') {
    if ($sid === null) fail('There is no active academic session right now.');

    $classId  = (int)($_POST['class_id'] ?? 0);
    $groupRaw = trim($_POST['group_id'] ?? '');
    $groupId  = ($groupRaw === '' || $groupRaw === 'null') ? null : (int)$groupRaw;
    $attDate  = trim($_POST['att_date'] ?? '');

    if ($classId < 1) fail('Please choose a class.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $attDate)) fail('Please choose a valid date.');
    if ($attDate > date('Y-m-d')) fail('You cannot take attendance for a future date.');

    if (!teacherOwnsClass($myUid, (int)$sid, $classId, $groupId)) {
        fail('You are not assigned to that class.');
    }

    // ── Active roster for this class+group+session ──────────────────
    $sql = "SELECT e.enrollment_id, e.roll_number, s.sys_uid, s.student_id, s.full_name, s.photo_path
            FROM enrollments e
            JOIN students s ON s.sys_uid = e.sys_uid
            WHERE e.class_id = ? AND e.session_id = ? AND e.status = 'active'";
    $params = [$classId, $sid];
    if ($groupId === null) { $sql .= " AND e.group_id IS NULL"; }
    else                   { $sql .= " AND e.group_id = ?"; $params[] = $groupId; }
    $sql .= " ORDER BY (e.roll_number IS NULL), e.roll_number ASC, s.full_name ASC";

    $roster = tsafeAll($sql, $params);

    if (empty($roster)) {
        ok([
            'no_session' => false, 'students' => [], 'already_marked' => false,
            'marked_info' => null, 'class_label' => null, 'group_name' => null,
        ]);
    }

    $enrollmentIds = array_column($roster, 'enrollment_id');
    $placeholders  = implode(',', array_fill(0, count($enrollmentIds), '?'));

    // ── Existing attendance for this date (pre-fill + "already marked") ──
    $existingRows = [];
    if (!empty($enrollmentIds)) {
        $existingRows = tsafeAll(
            "SELECT enrollment_id, status, marked_by, marked_at, updated_by, updated_at
             FROM attendance
             WHERE att_date = ? AND enrollment_id IN ($placeholders)",
            array_merge([$attDate], $enrollmentIds)
        );
    }
    $existingByEnrollment = [];
    foreach ($existingRows as $er) {
        $existingByEnrollment[(int)$er['enrollment_id']] = $er;
    }

    // ── Class/group label for display ────────────────────────────────
    $classMeta = tsafeRow(
        "SELECT c.label AS class_label, g.group_name
         FROM classes c LEFT JOIN groups g ON g.group_id = ?
         WHERE c.class_id = ?",
        [$groupId, $classId]
    );

    // ── "Last updated" info banner ───────────────────────────────────
    $alreadyMarked = count($existingRows) > 0;
    $markedInfo    = null;
    if ($alreadyMarked) {
        usort($existingRows, function ($a, $b) {
            $ta = strtotime($a['updated_at'] ?? $a['marked_at']);
            $tb = strtotime($b['updated_at'] ?? $b['marked_at']);
            return $tb <=> $ta;
        });
        $latest = $existingRows[0];
        $actorUid = $latest['updated_by'] ?? $latest['marked_by'];
        $actorAt  = $latest['updated_at'] ?? $latest['marked_at'];
        $markedInfo = [
            'by' => resolveActorName($actorUid, $myUid),
            'at' => date('d M Y, h:i A', strtotime($actorAt)),
        ];
    }

    $students = array_map(function ($r) use ($existingByEnrollment) {
        $eid = (int)$r['enrollment_id'];
        $existing = $existingByEnrollment[$eid] ?? null;
        return [
            'enrollment_id' => $eid,
            'student_id'    => $r['student_id'],
            'full_name'     => $r['full_name'],
            'roll_number'   => $r['roll_number'] !== null ? (int)$r['roll_number'] : null,
            'photo_url'     => resolveStudentPhoto($r['photo_path'], $r['sys_uid']),
            'status'        => $existing['status'] ?? 'present', // default: present (fast workflow)
        ];
    }, $roster);

    ok([
        'no_session'     => false,
        'students'       => $students,
        'already_marked' => $alreadyMarked,
        'marked_info'    => $markedInfo,
        'class_label'    => $classMeta['class_label'] ?? null,
        'group_name'     => $classMeta['group_name']  ?? null,
    ]);
}

// ═══════════════════════════════════════════════════════════════════
//  ACTION: save_attendance
// ═══════════════════════════════════════════════════════════════════
if ($action === 'save_attendance') {
    if ($sid === null) fail('There is no active academic session right now.');

    $classId  = (int)($_POST['class_id'] ?? 0);
    $groupRaw = trim($_POST['group_id'] ?? '');
    $groupId  = ($groupRaw === '' || $groupRaw === 'null') ? null : (int)$groupRaw;
    $attDate  = trim($_POST['att_date'] ?? '');
    $recordsRaw = $_POST['records'] ?? '';

    if ($classId < 1) fail('Please choose a class.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $attDate)) fail('Please choose a valid date.');
    if ($attDate > date('Y-m-d')) fail('You cannot save attendance for a future date.');

    if (!teacherOwnsClass($myUid, (int)$sid, $classId, $groupId)) {
        fail('You are not assigned to that class.');
    }

    $records = json_decode($recordsRaw, true);
    if (!is_array($records) || empty($records)) fail('No attendance data was submitted.');

    $validStatuses = ['present', 'absent', 'late'];

    // ── Rebuild the valid enrollment set server-side — never trust
    //     enrollment_id values coming from the client directly ────────
    $sql = "SELECT enrollment_id FROM enrollments WHERE class_id = ? AND session_id = ? AND status = 'active'";
    $params = [$classId, $sid];
    if ($groupId === null) { $sql .= " AND group_id IS NULL"; }
    else                   { $sql .= " AND group_id = ?"; $params[] = $groupId; }
    $validEnrollmentIds = array_map('intval', array_column(tsafeAll($sql, $params), 'enrollment_id'));
    $validSet = array_flip($validEnrollmentIds);

    $toSave = [];
    foreach ($records as $rec) {
        if (!is_array($rec)) continue;
        $eid    = (int)($rec['enrollment_id'] ?? 0);
        $status = trim((string)($rec['status'] ?? ''));
        if (!isset($validSet[$eid])) continue;              // not a real, active roster member — skip
        if (!in_array($status, $validStatuses, true)) continue; // invalid status value — skip
        $toSave[$eid] = $status;
    }

    if (empty($toSave)) fail('None of the submitted attendance records were valid.');

    // ── Determine mark vs update, for the activity log entry ─────────
    $placeholders = implode(',', array_fill(0, count($toSave), '?'));
    $existingCount = (int)(tsafeVal(
        "SELECT COUNT(*) FROM attendance WHERE att_date = ? AND enrollment_id IN ($placeholders)",
        array_merge([$attDate], array_keys($toSave))
    ) ?? 0);
    $actionType = $existingCount > 0 ? 'update_attendance' : 'mark_attendance';

    try {
        $pdo = db();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO attendance (enrollment_id, att_date, status, marked_by, marked_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = ?, updated_at = NOW()"
        );

        $counts = ['present' => 0, 'absent' => 0, 'late' => 0];
        foreach ($toSave as $eid => $status) {
            $stmt->execute([$eid, $attDate, $status, $myUid, $myUid]);
            $counts[$status]++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('EMS teacher save_attendance: ' . $e->getMessage());
        fail('Failed to save attendance. Please try again.');
    }

    // ── Class/group label for the toast + activity log ────────────────
    $classMeta = tsafeRow(
        "SELECT c.label AS class_label, g.group_name
         FROM classes c LEFT JOIN groups g ON g.group_id = ?
         WHERE c.class_id = ?",
        [$groupId, $classId]
    );
    $classLabel = $classMeta['class_label'] ?? 'the class';
    $groupName  = $classMeta['group_name']  ?? null;

    log_activity($myUid, $actionType, 'attendance', (string)$classId, [
        'class_label'  => $classLabel . ($groupName ? ' (' . $groupName . ')' : ''),
        'count'        => count($toSave),
        'session_year' => $sYear,
        'att_date'     => $attDate,
    ]);

    ok([
        'saved'       => true,
        'action'      => $actionType,
        'counts'      => $counts,
        'total'       => count($toSave),
        'class_label' => $classLabel,
        'group_name'  => $groupName,
        'att_date'    => $attDate,
        'marked_info' => ['by' => 'You', 'at' => date('d M Y, h:i A')],
    ]);
}

fail('Unknown action.');
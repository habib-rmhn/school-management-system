<?php
/**
 * EMS Teacher — Student List AJAX Handler
 * ─────────────────────────────────────────────────────────────────
 * Actions:
 *   fetch_classes  – class(+group) combos this teacher is assigned
 *                    to for the current session (feeds the selector)
 *   fetch_students – full read-only profile info for one class+group
 *
 * This page is entirely read-only — teachers can view student info
 * but never edit it here (per spec, only Admin can edit student
 * records). Every class_id/group_id is still re-verified against
 * class_routine before any data is returned, same as every other
 * mutation-capable page — viewing someone else's class roster is
 * exactly the kind of thing that should never be reachable just by
 * changing a query parameter.
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
    catch (Throwable $e) { error_log('EMS teacher students tsafeRow: ' . $e->getMessage()); return null; }
}
function tsafeAll(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); }
    catch (Throwable $e) { error_log('EMS teacher students tsafeAll: ' . $e->getMessage()); return []; }
}
function tsafeVal(string $sql, array $p = []): mixed {
    try { $v = dbQuery($sql, $p)->fetchColumn(); return $v !== false ? $v : null; }
    catch (Throwable $e) { error_log('EMS teacher students tsafeVal: ' . $e->getMessage()); return null; }
}

function resolveStudentPhoto(?string $photoPath): ?string {
    if (empty($photoPath)) return null;
    $diskPath = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/uploads/student/photos/' . basename($photoPath);
    if (@file_exists($diskPath)) {
        return rtrim(BASE_URL, '/') . '/uploads/student/photos/' . basename($photoPath);
    }
    return null;
}

function teacherOwnsClass(string $uid, int $sid, int $classId, ?int $groupId): bool {
    $sql = "SELECT 1 FROM class_routine WHERE teacher_uid = ? AND session_id = ? AND class_id = ?";
    $p   = [$uid, $sid, $classId];
    if ($groupId === null) { $sql .= " AND group_id IS NULL"; }
    else                   { $sql .= " AND group_id = ?"; $p[] = $groupId; }
    $sql .= " LIMIT 1";
    return tsafeVal($sql, $p) !== null;
}

$action = trim($_POST['action'] ?? '');

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
        "SELECT DISTINCT cr.class_id, cr.group_id, c.label AS class_label, c.class_num, g.group_name,
                (SELECT COUNT(*) FROM enrollments en
                   WHERE en.class_id = cr.class_id AND en.session_id = cr.session_id AND en.status = 'active'
                   AND (cr.group_id IS NULL OR en.group_id = cr.group_id)) AS student_count
         FROM class_routine cr
         JOIN classes c ON c.class_id = cr.class_id
         LEFT JOIN groups g ON g.group_id = cr.group_id
         WHERE cr.teacher_uid = ? AND cr.session_id = ?
         ORDER BY c.class_num ASC, g.group_name ASC",
        [$myUid, $sid]
    );

    $totalStudents = 0;
    $seen = [];
    foreach ($rows as $r) {
        $key = $r['class_id'] . '-' . ($r['group_id'] ?? '0');
        if (!isset($seen[$key])) { $seen[$key] = true; $totalStudents += (int)$r['student_count']; }
    }

    ok([
        'no_session'   => false,
        'session_year' => $sYear,
        'classes' => array_map(function ($r) {
            return [
                'class_id'      => (int)$r['class_id'],
                'group_id'      => $r['group_id'] !== null ? (int)$r['group_id'] : null,
                'class_label'   => $r['class_label'],
                'group_name'    => $r['group_name'],
                'student_count' => (int)$r['student_count'],
            ];
        }, $rows),
        'summary' => [
            'total_classes'  => count($rows),
            'total_students' => $totalStudents,
        ],
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

    if ($classId < 1) fail('Please choose a class.');
    if (!teacherOwnsClass($myUid, (int)$sid, $classId, $groupId)) {
        fail('You are not assigned to that class.');
    }

    $sql = "SELECT e.enrollment_id, e.roll_number, s.student_id, s.full_name, s.date_of_birth,
                   s.phone, s.phone2, s.email, s.father_name, s.mother_name, s.address, s.photo_path
            FROM enrollments e
            JOIN students s ON s.sys_uid = e.sys_uid
            WHERE e.class_id = ? AND e.session_id = ? AND e.status = 'active'";
    $params = [$classId, $sid];
    if ($groupId === null) { $sql .= " AND e.group_id IS NULL"; }
    else                   { $sql .= " AND e.group_id = ?"; $params[] = $groupId; }
    $sql .= " ORDER BY (e.roll_number IS NULL), e.roll_number ASC, s.full_name ASC";

    $roster = tsafeAll($sql, $params);

    $classMeta = tsafeRow(
        "SELECT c.label AS class_label, g.group_name
         FROM classes c LEFT JOIN groups g ON g.group_id = ?
         WHERE c.class_id = ?",
        [$groupId, $classId]
    );

    $students = array_map(function ($r) {
        $age = null;
        $dobFormatted = null;
        if (!empty($r['date_of_birth'])) {
            try {
                $dob = new DateTime($r['date_of_birth']);
                $age = (new DateTime())->diff($dob)->y;
                $dobFormatted = $dob->format('d M Y');
            } catch (Throwable $e) { /* leave null on bad date */ }
        }
        return [
            'enrollment_id' => (int)$r['enrollment_id'],
            'student_id'    => $r['student_id'],
            'full_name'     => $r['full_name'],
            'roll_number'   => $r['roll_number'] !== null ? (int)$r['roll_number'] : null,
            'photo_url'     => resolveStudentPhoto($r['photo_path']),
            'dob'           => $dobFormatted,
            'age'           => $age,
            'phone'         => $r['phone'],
            'phone2'        => $r['phone2'],
            'email'         => $r['email'],
            'father_name'   => $r['father_name'],
            'mother_name'   => $r['mother_name'],
            'address'       => $r['address'],
        ];
    }, $roster);

    ok([
        'no_session'  => false,
        'students'    => $students,
        'class_label' => $classMeta['class_label'] ?? null,
        'group_name'  => $classMeta['group_name']  ?? null,
    ]);
}

fail('Unknown action.');
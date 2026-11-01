<?php
/**
 * EMS Teacher — My Routine AJAX Handler
 * ─────────────────────────────────────────────────────────────────
 * Action:
 *   fetch_routine – the teacher's own combined weekly schedule
 *                   across every class/subject they're assigned to,
 *                   for the current academic session.
 *
 * Unlike the student portal (which needs a session switcher because
 * a student's enrollment changes every year), a teacher's routine is
 * always "my current assignments" — so this endpoint takes no
 * parameters at all beyond identity, which comes from the session.
 *
 * Security: scoped entirely to $_SESSION['ems_uid'].
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
    catch (Throwable $e) { error_log('EMS teacher routine tsafeRow: ' . $e->getMessage()); return null; }
}
function tsafeAll(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); }
    catch (Throwable $e) { error_log('EMS teacher routine tsafeAll: ' . $e->getMessage()); return []; }
}

$action = trim($_POST['action'] ?? '');

if ($action !== 'fetch_routine') {
    fail('Unknown action.');
}

try {
    // ── Current academic session ───────────────────────────────────
    $session = tsafeRow("SELECT session_id, session_year FROM sessions WHERE is_current = 1 LIMIT 1");
    $sid     = $session['session_id']   ?? null;
    $sYear   = $session['session_year'] ?? null;

    if ($sid === null) {
        ok([
            'no_session'   => true,
            'session_year' => null,
            'slots'        => [],
            'period_times' => [],
            'summary'      => ['total_slots' => 0, 'distinct_classes' => 0, 'distinct_subjects' => 0],
        ]);
    }

    // ── All real scheduled slots (period_number >= 1 excludes the
    //     placeholder rows created by assign-teacher) ────────────────
    $slots = tsafeAll(
        "SELECT cr.day_of_week, cr.period_number, cr.start_time, cr.end_time,
                cr.class_id, cr.group_id, cr.subject_id,
                sub.subject_name, sub.subject_code,
                c.label AS class_label, g.group_name
         FROM class_routine cr
         JOIN subjects sub ON sub.subject_id = cr.subject_id
         JOIN classes  c   ON c.class_id      = cr.class_id
         LEFT JOIN groups g ON g.group_id     = cr.group_id
         WHERE cr.teacher_uid = ? AND cr.session_id = ? AND cr.period_number >= 1
         ORDER BY cr.period_number ASC, cr.day_of_week ASC",
        [$myUid, $sid]
    );

    // Derive period time rows (period_number → representative start/end)
    $periodTimes = [];
    foreach ($slots as $sl) {
        $p = (int)$sl['period_number'];
        if (!isset($periodTimes[$p])) {
            $periodTimes[$p] = [
                'period_number' => $p,
                'start_time'    => $sl['start_time'],
                'end_time'      => $sl['end_time'],
            ];
        }
    }
    ksort($periodTimes);

    // ── Small summary stats for the strip at the top of the page ────
    $distinctClasses = [];
    $distinctSubjects = [];
    foreach ($slots as $sl) {
        $distinctClasses[$sl['class_id'] . '-' . ($sl['group_id'] ?? '0')] = true;
        $distinctSubjects[$sl['subject_id']] = true;
    }

    ok([
        'no_session'   => false,
        'session_year' => $sYear,
        'slots'        => array_map(function ($r) {
            return [
                'day_of_week'   => (int)$r['day_of_week'],
                'period_number' => (int)$r['period_number'],
                'start_time'    => $r['start_time'],
                'end_time'      => $r['end_time'],
                'class_id'      => (int)$r['class_id'],
                'group_id'      => $r['group_id'] !== null ? (int)$r['group_id'] : null,
                'subject_id'    => (int)$r['subject_id'],
                'subject_name'  => $r['subject_name'],
                'subject_code'  => $r['subject_code'],
                'class_label'   => $r['class_label'],
                'group_name'    => $r['group_name'],
            ];
        }, $slots),
        'period_times' => array_values($periodTimes),
        'summary' => [
            'total_slots'       => count($slots),
            'distinct_classes'  => count($distinctClasses),
            'distinct_subjects' => count($distinctSubjects),
        ],
    ]);

} catch (Throwable $e) {
    error_log('EMS teacher routine fetch_routine: ' . $e->getMessage());
    fail('Failed to load your routine. Please try again.');
}
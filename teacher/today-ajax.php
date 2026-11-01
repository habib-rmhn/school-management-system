<?php
/**
 * EMS Teacher — Today's Classes AJAX Handler
 * ─────────────────────────────────────────────────────────────────
 * Action:
 *   fetch_today – returns the teacher's own schedule for TODAY only.
 *
 * Deliberately returns raw start_time/end_time and NO pre-computed
 * "current/done/upcoming" status. The page computes that client-side
 * (ticking every 20s against Bangladesh time) so the "Now teaching"
 * banner and period statuses stay accurate between page loads without
 * needing another round trip just because a minute passed.
 *
 * Security: scoped entirely to $_SESSION['ems_uid'] — no client input
 * is used to select whose schedule is returned.
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
    catch (Throwable $e) { error_log('EMS teacher today tsafeRow: ' . $e->getMessage()); return null; }
}
function tsafeAll(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); }
    catch (Throwable $e) { error_log('EMS teacher today tsafeAll: ' . $e->getMessage()); return []; }
}

$action = trim($_POST['action'] ?? '');

if ($action !== 'fetch_today') {
    fail('Unknown action.');
}

try {
    // ── Current academic session ───────────────────────────────────
    $session = tsafeRow("SELECT session_id, session_year FROM sessions WHERE is_current = 1 LIMIT 1");
    $sid     = $session['session_id']   ?? null;
    $sYear   = $session['session_year'] ?? null;
    $noSession = $sid === null;

    // ── Bangladesh school week: Sat=0 ... Thu=5, Friday = weekend ──
    $phpDow    = (int)date('w');                                 // PHP: 0=Sun...6=Sat
    $dowMap    = [0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 0, 6 => 0];
    $isFriday  = ($phpDow === 5);
    $schoolDow = $dowMap[$phpDow] ?? null;

    // ── Today's real scheduled periods (period_number >= 1 excludes
    //     the placeholder rows created by assign-teacher) ───────────
    $periods = [];
    if (!$isFriday && $sid !== null) {
        $periods = tsafeAll(
            "SELECT cr.routine_id, cr.period_number, cr.start_time, cr.end_time,
                    cr.class_id, cr.group_id, cr.subject_id, cr.session_id,
                    sub.subject_name, sub.subject_code,
                    c.label AS class_label, g.group_name
             FROM class_routine cr
             JOIN subjects sub ON sub.subject_id = cr.subject_id
             JOIN classes  c   ON c.class_id      = cr.class_id
             LEFT JOIN groups g ON g.group_id     = cr.group_id
             WHERE cr.teacher_uid = ? AND cr.session_id = ? AND cr.day_of_week = ? AND cr.period_number >= 1
             ORDER BY cr.start_time ASC, cr.period_number ASC",
            [$myUid, $sid, $schoolDow]
        );
    }

    ok([
        'no_session'   => $noSession,
        'is_friday'    => $isFriday,
        'session_year' => $sYear,
        'date_label'   => date('l, d F Y'),
        'periods' => array_map(function ($r) {
            return [
                'routine_id'    => (int)$r['routine_id'],
                'period_number' => (int)$r['period_number'],
                'start_time'    => $r['start_time'],
                'end_time'      => $r['end_time'],
                'class_id'      => (int)$r['class_id'],
                'group_id'      => $r['group_id'] !== null ? (int)$r['group_id'] : null,
                'subject_id'    => (int)$r['subject_id'],
                'session_id'    => (int)$r['session_id'],
                'subject_name'  => $r['subject_name'],
                'subject_code'  => $r['subject_code'],
                'class_label'   => $r['class_label'],
                'group_name'    => $r['group_name'],
            ];
        }, $periods),
    ]);

} catch (Throwable $e) {
    error_log('EMS teacher today fetch_today: ' . $e->getMessage());
    fail('Failed to load today\'s classes. Please try again.');
}
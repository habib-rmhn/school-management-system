<?php
/**
 * EMS Student — My Payments AJAX Handler
 * ─────────────────────────────────────────────────────────────────
 * Actions:
 *   fetch_sessions – every enrollment this student has had
 *                    (for the class/session switcher)
 *   fetch_ledger   – every due + its payment history for ONE of
 *                    this student's own enrollments, plus totals
 *
 * Notes:
 *   - This is a READ-ONLY view. Students can see what they owe and
 *     what's already been paid, but cannot add, edit, or delete
 *     anything here — all payment entry remains admin-only, exactly
 *     as the original spec requires ("no online pay, admin adds
 *     payment manually").
 *   - Mirrors the admin's own fetch_student_ledger calculation
 *     exactly (SUM of payment_records per due, balance = due - paid)
 *     so the numbers a student sees always agree with what the
 *     school office sees.
 *
 * Security: enrollment_id is always re-verified against the logged
 * in student's own sys_uid before any financial data is returned.
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
function pyrows(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); }
    catch (Throwable $e) { return []; }
}

/** Verify an enrollment_id genuinely belongs to this student. */
function myEnrollmentPY(int $enrollmentId, string $myUid): ?array {
    $row = dbQuery(
        "SELECT e.enrollment_id, e.class_id, e.session_id, e.status, e.scholarship_pct,
                c.label AS class_label, sess.session_year, g.group_name
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
        error_log('EMS student payments fetch_sessions: ' . $e->getMessage());
        fail('Could not load your class sessions.');
    }
}

// ── FETCH LEDGER — every due + payment history, one enrollment ────
if ($action === 'fetch_ledger') {
    $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    if ($enrollmentId < 1) fail('Please choose a class session.');

    $enroll = myEnrollmentPY($enrollmentId, $myUid);
    if (!$enroll) fail('That class session was not found on your account.');

    try {
        // Every fee due for this enrollment, with total paid against it
        $dues = pyrows(
            "SELECT pd.due_id, pd.fee_type, pd.amount_full, pd.scholarship_pct,
                    pd.amount_due, pd.due_date, pd.status, pd.created_at,
                    COALESCE(SUM(pr.amount_paid), 0) AS paid_amount
             FROM payment_dues pd
             LEFT JOIN payment_records pr ON pr.due_id = pd.due_id
             WHERE pd.enrollment_id = ?
             GROUP BY pd.due_id
             ORDER BY pd.due_date IS NULL, pd.due_date ASC, pd.created_at ASC",
            [$enrollmentId]
        );

        // Attach payment history to each due, and compute its balance
        foreach ($dues as &$d) {
            $d['paid_amount'] = (float)$d['paid_amount'];
            $d['amount_due']  = (float)$d['amount_due'];
            $d['amount_full'] = (float)$d['amount_full'];
            $d['balance']     = round($d['amount_due'] - $d['paid_amount'], 2);

            $d['records'] = pyrows(
                "SELECT pr.amount_paid, pr.paid_at, pr.note
                 FROM payment_records pr
                 WHERE pr.due_id = ?
                 ORDER BY pr.paid_at DESC",
                [$d['due_id']]
            );
        }
        unset($d);

        $totalDue  = array_sum(array_column($dues, 'amount_due'));
        $totalPaid = array_sum(array_column($dues, 'paid_amount'));
        $balance   = round($totalDue - $totalPaid, 2);

        // Flat list of all payment records across all dues, for a
        // combined "Payment History" tab sorted by most recent first
        $allPayments = pyrows(
            "SELECT pr.amount_paid, pr.paid_at, pr.note, pd.fee_type
             FROM payment_records pr
             JOIN payment_dues pd ON pd.due_id = pr.due_id
             WHERE pd.enrollment_id = ?
             ORDER BY pr.paid_at DESC",
            [$enrollmentId]
        );

        ok([
            'dues'          => $dues,
            'all_payments'  => $allPayments,
            'class_label'   => $enroll['class_label'],
            'session_year'  => $enroll['session_year'],
            'group_name'    => $enroll['group_name'],
            'scholarship'   => (float)$enroll['scholarship_pct'],
            'totals'        => [
                'due'     => $totalDue,
                'paid'    => $totalPaid,
                'balance' => $balance,
            ],
        ]);
    } catch (Throwable $e) {
        error_log('EMS student payments fetch_ledger: ' . $e->getMessage());
        fail('Failed to load your payment details. Please try again.');
    }
}

fail('Unknown action.');
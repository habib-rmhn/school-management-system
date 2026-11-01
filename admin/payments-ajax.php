<?php
/**
 * EMS Admin — Payments AJAX Handler  (payments-ajax.php)
 *
 * Actions:
 *   fetch_students        — students for a session/class/group with due summary
 *   fetch_student_ledger  — full due+payment history for one enrollment
 *   add_due               — create a new due (single student OR bulk to whole class)
 *   record_payment        — record a manual payment against a due
 *   edit_due               — edit fee_type/amount/scholarship/due_date on an unpaid/partial due
 *   delete_due             — delete a due (only if no payments recorded)
 *   delete_payment_record  — remove a wrongly-entered payment record (admin correction)
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
$uid      = $_SESSION['ems_uid'];

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
function rows(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); } catch (Throwable $e) { return []; }
}

$action = trim($_POST['action'] ?? '');

/* ── Recalculate a due's status based on amount_due vs total paid ─── */
function recalcDueStatus(int $dueId): string {
    $row = dbQuery(
        "SELECT pd.amount_due, COALESCE(SUM(pr.amount_paid),0) AS paid
         FROM payment_dues pd
         LEFT JOIN payment_records pr ON pr.due_id = pd.due_id
         WHERE pd.due_id = ?
         GROUP BY pd.due_id", [$dueId]
    )->fetch();
    if (!$row) return 'unpaid';

    $due  = (float)$row['amount_due'];
    $paid = (float)$row['paid'];

    if ($paid <= 0)        $status = 'unpaid';
    elseif ($paid >= $due) $status = 'paid';
    else                    $status = 'partial';

    dbQuery("UPDATE payment_dues SET status=? WHERE due_id=?", [$status, $dueId]);
    return $status;
}

/* ── Compute amount_due from full amount + scholarship % ─────────── */
function computeAmountDue(float $full, float $scholarshipPct): float {
    $scholarshipPct = max(0, min(100, $scholarshipPct));
    return round($full * (1 - $scholarshipPct / 100), 2);
}

// ═══════════════════════════════════════════════════════════════
// ACTION: fetch_students
// List students for session+class(+group) with due/paid totals
// ═══════════════════════════════════════════════════════════════
if ($action === 'fetch_students') {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $classId   = (int)($_POST['class_id']   ?? 0);
    $groupId   = (int)($_POST['group_id']   ?? 0) ?: null;
    $status    = trim($_POST['status']      ?? ''); // '', 'unpaid', 'partial', 'paid', 'none'
    $search    = trim($_POST['search']      ?? '');

    if ($sessionId < 1) fail('Please select a session.');

    try {
        $where  = ['e.session_id=?', "e.status='active'"];
        $params = [$sessionId];

        if ($classId > 0) { $where[] = 'e.class_id=?'; $params[] = $classId; }
        if ($groupId)      { $where[] = 'e.group_id=?'; $params[] = $groupId; }
        if ($search !== '') {
            $where[] = '(s.full_name LIKE ? OR s.student_id LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like; $params[] = $like;
        }

        $students = rows(
            "SELECT
                s.sys_uid, s.student_id, s.full_name, s.gender, s.photo_path,
                e.enrollment_id, e.roll_number, e.scholarship_pct AS default_scholarship,
                c.label AS class_label, c.class_num,
                g.group_name,
                COALESCE(SUM(pd.amount_due),0)                                  AS total_due,
                COALESCE(SUM(pr_agg.paid),0)                                    AS total_paid,
                COUNT(DISTINCT pd.due_id)                                       AS due_count,
                COUNT(DISTINCT CASE WHEN pd.status!='paid' THEN pd.due_id END)  AS unpaid_due_count
             FROM enrollments e
             JOIN students s ON s.sys_uid = e.sys_uid
             JOIN system_users su ON su.sys_uid = s.sys_uid
             JOIN classes c ON c.class_id = e.class_id
             LEFT JOIN groups g ON g.group_id = e.group_id
             LEFT JOIN payment_dues pd ON pd.enrollment_id = e.enrollment_id
             LEFT JOIN (
                 SELECT due_id, SUM(amount_paid) AS paid
                 FROM payment_records GROUP BY due_id
             ) pr_agg ON pr_agg.due_id = pd.due_id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY e.enrollment_id
             ORDER BY e.roll_number ASC, s.full_name ASC",
            $params
        );

        // Compute status per student in PHP (clean + simple)
        foreach ($students as &$st) {
            $due  = (float)$st['total_due'];
            $paid = (float)$st['total_paid'];
            $bal  = round($due - $paid, 2);
            $st['balance'] = $bal;
            if ($due <= 0)          $st['pay_status'] = 'none';
            elseif ($bal <= 0)      $st['pay_status'] = 'paid';
            elseif ($paid > 0)      $st['pay_status'] = 'partial';
            else                     $st['pay_status'] = 'unpaid';
        }
        unset($st);

        if ($status !== '') {
            $students = array_values(array_filter($students, fn($s) => $s['pay_status'] === $status));
        }

        // Summary across the filtered class/group (before status filter, for header stats)
        $allDue  = rows(
            "SELECT
                COALESCE(SUM(pd.amount_due),0) AS total_due,
                COALESCE(SUM(pr_agg.paid),0)   AS total_paid
             FROM enrollments e
             LEFT JOIN payment_dues pd ON pd.enrollment_id=e.enrollment_id
             LEFT JOIN (SELECT due_id, SUM(amount_paid) paid FROM payment_records GROUP BY due_id) pr_agg
                 ON pr_agg.due_id = pd.due_id
             WHERE e.session_id=? AND e.status='active'" .
             ($classId>0 ? ' AND e.class_id=?' : '') .
             ($groupId ? ' AND e.group_id=?' : ''),
            array_values(array_filter([$sessionId, $classId>0?$classId:null, $groupId], fn($v)=>$v!==null))
        );
        $summary = $allDue[0] ?? ['total_due'=>0,'total_paid'=>0];

        ok([
            'students' => $students,
            'summary'  => [
                'total_due'   => (float)$summary['total_due'],
                'total_paid'  => (float)$summary['total_paid'],
                'total_balance'=> round((float)$summary['total_due'] - (float)$summary['total_paid'], 2),
            ],
        ]);
    } catch (Throwable $e) {
        error_log('EMS fetch_students(payments): ' . $e->getMessage());
        fail('Failed to load students.');
    }
}

// ═══════════════════════════════════════════════════════════════
// ACTION: fetch_student_ledger
// Full due + payment history for one enrollment
// ═══════════════════════════════════════════════════════════════
if ($action === 'fetch_student_ledger') {
    $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    if ($enrollmentId < 1) fail('Invalid student.');

    try {
        $student = dbQuery(
            "SELECT s.sys_uid, s.student_id, s.full_name, s.gender, s.photo_path,
                    e.enrollment_id, e.roll_number, e.scholarship_pct AS default_scholarship,
                    c.label AS class_label, sess.label AS session_label
             FROM enrollments e
             JOIN students s ON s.sys_uid=e.sys_uid
             JOIN classes c ON c.class_id=e.class_id
             JOIN sessions sess ON sess.session_id=e.session_id
             WHERE e.enrollment_id=?", [$enrollmentId]
        )->fetch();
        if (!$student) fail('Student not found.');

        $dues = rows(
            "SELECT pd.*, COALESCE(SUM(pr.amount_paid),0) AS paid_amount,
                    su.username AS created_by_name
             FROM payment_dues pd
             LEFT JOIN payment_records pr ON pr.due_id=pd.due_id
             LEFT JOIN system_users su ON su.sys_uid=pd.created_by
             WHERE pd.enrollment_id=?
             GROUP BY pd.due_id
             ORDER BY pd.due_date IS NULL, pd.due_date ASC, pd.created_at ASC",
            [$enrollmentId]
        );

        // Attach payment records to each due
        foreach ($dues as &$d) {
            $d['records'] = rows(
                "SELECT pr.*, su.username AS received_by_name
                 FROM payment_records pr
                 LEFT JOIN system_users su ON su.sys_uid=pr.received_by
                 WHERE pr.due_id=?
                 ORDER BY pr.paid_at DESC", [$d['due_id']]
            );
            $d['balance'] = round((float)$d['amount_due'] - (float)$d['paid_amount'], 2);
        }
        unset($d);

        $totalDue   = array_sum(array_column($dues,'amount_due'));
        $totalPaid  = array_sum(array_column($dues,'paid_amount'));

        ok([
            'student' => $student,
            'dues'    => $dues,
            'totals'  => [
                'due'   => $totalDue,
                'paid'  => $totalPaid,
                'balance' => round($totalDue - $totalPaid, 2),
            ],
        ]);
    } catch (Throwable $e) {
        error_log('EMS fetch_student_ledger: ' . $e->getMessage());
        fail('Failed to load student ledger.');
    }
}

// ═══════════════════════════════════════════════════════════════
// ACTION: add_due
// Create due — single student OR bulk to all students in class/group
// ═══════════════════════════════════════════════════════════════
if ($action === 'add_due') {
    $mode          = trim($_POST['mode'] ?? 'single'); // 'single' | 'bulk'
    $feeType       = trim($_POST['fee_type']   ?? '');
    $amountFull    = (float)($_POST['amount_full'] ?? 0);
    $dueDate       = trim($_POST['due_date']   ?? '') ?: null;
    $useDefaultSch = (int)($_POST['use_default_scholarship'] ?? 1);
    $customSch     = isset($_POST['scholarship_pct']) ? (float)$_POST['scholarship_pct'] : null;

    if (empty($feeType))        fail('Fee type is required.');
    if (strlen($feeType) > 60)  fail('Fee type too long (max 60 characters).');
    if ($amountFull <= 0)       fail('Amount must be greater than zero.');
    if ($dueDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) fail('Invalid due date.');
    if ($customSch !== null && ($customSch < 0 || $customSch > 100)) fail('Scholarship percentage must be between 0 and 100.');

    try {
        db()->beginTransaction();
        $created = 0;
        $skipped = 0;

        if ($mode === 'single') {
            $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
            if ($enrollmentId < 1) fail('Invalid student.');

            $enr = dbQuery("SELECT scholarship_pct FROM enrollments WHERE enrollment_id=?", [$enrollmentId])->fetch();
            if (!$enr) fail('Enrollment not found.');

            $sch = $useDefaultSch ? (float)$enr['scholarship_pct'] : ($customSch ?? 0);
            $amountDue = computeAmountDue($amountFull, $sch);

            dbQuery(
                "INSERT INTO payment_dues (enrollment_id, fee_type, amount_full, scholarship_pct, amount_due, due_date, status, created_by, created_at)
                 VALUES (?,?,?,?,?,?,'unpaid',?,NOW())",
                [$enrollmentId, $feeType, $amountFull, $sch, $amountDue, $dueDate, $uid]
            );
            $created = 1;

            log_activity($uid, 'add_payment_due', 'payment_dues', (string)$enrollmentId,
                ['fee_type'=>$feeType, 'amount'=>$amountDue]);

        } else {
            // Bulk: all active students in session+class(+group)
            $sessionId = (int)($_POST['session_id'] ?? 0);
            $classId   = (int)($_POST['class_id']   ?? 0);
            $groupId   = (int)($_POST['group_id']   ?? 0) ?: null;
            $excludeIds = trim($_POST['exclude_ids'] ?? ''); // comma-separated enrollment_ids to skip

            if ($sessionId < 1 || $classId < 1) fail('Please select session and class for bulk due.');

            $excludeArr = $excludeIds !== '' ? array_map('intval', explode(',', $excludeIds)) : [];

            $where  = 'e.session_id=? AND e.class_id=? AND e.status=\'active\'';
            $params = [$sessionId, $classId];
            if ($groupId) { $where .= ' AND e.group_id=?'; $params[] = $groupId; }

            $targets = rows(
                "SELECT e.enrollment_id, e.scholarship_pct FROM enrollments e WHERE $where",
                $params
            );

            foreach ($targets as $t) {
                $eid = (int)$t['enrollment_id'];
                if (in_array($eid, $excludeArr, true)) { $skipped++; continue; }

                $sch = $useDefaultSch ? (float)$t['scholarship_pct'] : ($customSch ?? 0);
                $amountDue = computeAmountDue($amountFull, $sch);

                dbQuery(
                    "INSERT INTO payment_dues (enrollment_id, fee_type, amount_full, scholarship_pct, amount_due, due_date, status, created_by, created_at)
                     VALUES (?,?,?,?,?,?,'unpaid',?,NOW())",
                    [$eid, $feeType, $amountFull, $sch, $amountDue, $dueDate, $uid]
                );
                $created++;
            }

            if ($created === 0) fail('No students found to apply this due to.');

            log_activity($uid, 'add_payment_due', 'payment_dues', "bulk_class_{$classId}",
                ['fee_type'=>$feeType, 'amount'=>$amountFull, 'students'=>$created, 'class_id'=>$classId]);
        }

        db()->commit();
        ok([
            'created' => $created,
            'skipped' => $skipped,
            'msg' => $mode === 'single'
                ? "Due of " . number_format($amountFull,2) . " (\"$feeType\") added."
                : "Due \"$feeType\" added for $created student" . ($created!==1?'s':'') . ($skipped>0 ? ", $skipped skipped." : '.'),
        ]);
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('EMS add_due: ' . $e->getMessage());
        fail('Database error while adding due.');
    }
}

// ═══════════════════════════════════════════════════════════════
// ACTION: edit_due
// Edit an existing due (only allowed pre-payment or partially paid)
// ═══════════════════════════════════════════════════════════════
if ($action === 'edit_due') {
    $dueId        = (int)($_POST['due_id'] ?? 0);
    $feeType      = trim($_POST['fee_type'] ?? '');
    $amountFull   = (float)($_POST['amount_full'] ?? 0);
    $scholarship  = (float)($_POST['scholarship_pct'] ?? 0);
    $dueDate      = trim($_POST['due_date'] ?? '') ?: null;

    if ($dueId < 1) fail('Invalid due record.');
    if (empty($feeType)) fail('Fee type is required.');
    if ($amountFull <= 0) fail('Amount must be greater than zero.');
    if ($scholarship < 0 || $scholarship > 100) fail('Scholarship percentage must be between 0 and 100.');
    if ($dueDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) fail('Invalid due date.');

    $due = dbQuery("SELECT * FROM payment_dues WHERE due_id=?", [$dueId])->fetch();
    if (!$due) fail('Due record not found.');

    $amountDue = computeAmountDue($amountFull, $scholarship);
    $paidSoFar = (float)(sv("SELECT COALESCE(SUM(amount_paid),0) FROM payment_records WHERE due_id=?", [$dueId]) ?? 0);
    if ($amountDue < $paidSoFar) {
        fail('New amount (after scholarship) is less than the amount already paid (' . number_format($paidSoFar,2) . '). Adjust scholarship or amount.');
    }

    try {
        dbQuery(
            "UPDATE payment_dues SET fee_type=?, amount_full=?, scholarship_pct=?, amount_due=?, due_date=? WHERE due_id=?",
            [$feeType, $amountFull, $scholarship, $amountDue, $dueDate, $dueId]
        );
        recalcDueStatus($dueId);
        log_activity($uid, 'edit_payment_due', 'payment_dues', (string)$dueId, ['fee_type'=>$feeType,'amount'=>$amountDue]);
        ok(['msg' => "Due \"$feeType\" updated."]);
    } catch (Throwable $e) {
        error_log('EMS edit_due: ' . $e->getMessage());
        fail('Failed to update due.');
    }
}

// ═══════════════════════════════════════════════════════════════
// ACTION: delete_due
// Only allowed if no payments have been recorded against it
// ═══════════════════════════════════════════════════════════════
if ($action === 'delete_due') {
    $dueId = (int)($_POST['due_id'] ?? 0);
    if ($dueId < 1) fail('Invalid due record.');

    $due = dbQuery("SELECT * FROM payment_dues WHERE due_id=?", [$dueId])->fetch();
    if (!$due) fail('Due record not found.');

    $paidCount = (int)(sv("SELECT COUNT(*) FROM payment_records WHERE due_id=?", [$dueId]) ?? 0);
    if ($paidCount > 0) fail('Cannot delete: payments have already been recorded against this due. Remove the payment records first.');

    try {
        dbQuery("DELETE FROM payment_dues WHERE due_id=?", [$dueId]);
        log_activity($uid, 'delete_payment_due', 'payment_dues', (string)$dueId, ['fee_type'=>$due['fee_type']]);
        ok(['msg' => "Due \"{$due['fee_type']}\" deleted."]);
    } catch (Throwable $e) {
        error_log('EMS delete_due: ' . $e->getMessage());
        fail('Failed to delete due.');
    }
}

// ═══════════════════════════════════════════════════════════════
// ACTION: record_payment
// Add a manual payment record against a due
// ═══════════════════════════════════════════════════════════════
if ($action === 'record_payment') {
    $dueId       = (int)($_POST['due_id'] ?? 0);
    $amountPaid  = (float)($_POST['amount_paid'] ?? 0);
    $note        = trim($_POST['note'] ?? '');

    if ($dueId < 1)       fail('Invalid due record.');
    if ($amountPaid <= 0) fail('Payment amount must be greater than zero.');
    if (strlen($note) > 255) fail('Note too long (max 255 characters).');

    $due = dbQuery("SELECT * FROM payment_dues WHERE due_id=?", [$dueId])->fetch();
    if (!$due) fail('Due record not found.');

    $alreadyPaid = (float)(sv("SELECT COALESCE(SUM(amount_paid),0) FROM payment_records WHERE due_id=?", [$dueId]) ?? 0);
    $remaining   = round((float)$due['amount_due'] - $alreadyPaid, 2);

    if ($remaining <= 0) fail('This due is already fully paid.');
    if ($amountPaid > $remaining + 0.01) {
        fail('Payment amount (' . number_format($amountPaid,2) . ') exceeds the remaining balance (' . number_format($remaining,2) . ').');
    }

    try {
        db()->beginTransaction();

        dbQuery(
            "INSERT INTO payment_records (due_id, amount_paid, paid_at, received_by, note)
             VALUES (?,?,NOW(),?,?)",
            [$dueId, $amountPaid, $uid, $note ?: null]
        );

        $newStatus = recalcDueStatus($dueId);

        db()->commit();

        log_activity($uid, 'record_payment', 'payment_dues', (string)$dueId,
            ['fee_type'=>$due['fee_type'], 'amount'=>$amountPaid, 'new_status'=>$newStatus]);

        ok([
            'msg'        => "Payment of " . number_format($amountPaid,2) . " recorded.",
            'new_status' => $newStatus,
        ]);
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('EMS record_payment: ' . $e->getMessage());
        fail('Database error while recording payment.');
    }
}

// ═══════════════════════════════════════════════════════════════
// ACTION: delete_payment_record
// Admin correction — remove a wrongly entered payment
// ═══════════════════════════════════════════════════════════════
if ($action === 'delete_payment_record') {
    $recordId = (int)($_POST['record_id'] ?? 0);
    if ($recordId < 1) fail('Invalid payment record.');

    $rec = dbQuery("SELECT * FROM payment_records WHERE record_id=?", [$recordId])->fetch();
    if (!$rec) fail('Payment record not found.');

    try {
        db()->beginTransaction();
        $dueId = (int)$rec['due_id'];
        dbQuery("DELETE FROM payment_records WHERE record_id=?", [$recordId]);
        $newStatus = recalcDueStatus($dueId);
        db()->commit();

        log_activity($uid, 'delete_payment_record', 'payment_records', (string)$recordId,
            ['amount'=>$rec['amount_paid'], 'due_id'=>$dueId]);

        ok(['msg' => 'Payment record removed.', 'new_status' => $newStatus]);
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('EMS delete_payment_record: ' . $e->getMessage());
        fail('Failed to remove payment record.');
    }
}

fail('Unknown action.');
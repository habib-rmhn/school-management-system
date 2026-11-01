<?php
/**
 * EMS Student — My Profile AJAX Handler
 * ─────────────────────────────────────────────────────────────────
 * Actions:
 *   fetch_profile    – this student's full profile (name, IDs,
 *                      contact info, family info, address, DOB,
 *                      SSC batch, current class/roll) — read-only
 *   change_password  – change own password, requires the CURRENT
 *                      password to be entered correctly first
 *
 * Notes:
 *   - Profile fields here are READ-ONLY by design. A student cannot
 *     edit their own name, phone, address, etc. — only the admin
 *     office can, exactly as the rest of this system works (admin
 *     controls all student records). This page only ever writes to
 *     the password_hash column, nothing else.
 *   - Password change requires re-entering the CURRENT password —
 *     this prevents someone who finds an already-logged-in session
 *     unattended from silently locking the real student out by
 *     changing the password without knowing it.
 *
 * Security: every query is scoped to $_SESSION['ems_uid'] — a
 * student can only ever see or change their own account, never
 * another sys_uid, regardless of any client input.
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

$action = trim($_POST['action'] ?? '');

// ── FETCH PROFILE — this student's own full profile, read-only ────
if ($action === 'fetch_profile') {
    try {
        $profile = dbQuery(
            "SELECT s.full_name, s.student_id, s.date_of_birth, s.phone, s.phone2,
                    s.email, s.father_name, s.mother_name, s.address, s.photo_path,
                    s.gender, s.ssc_batch,
                    su.username, su.last_login, su.created_at
             FROM students s
             JOIN system_users su ON su.sys_uid = s.sys_uid
             WHERE s.sys_uid = ?",
            [$myUid]
        )->fetch();

        if (!$profile) fail('Your profile could not be found.');

        // Current active enrollment — class, session, roll, group
        $enroll = dbQuery(
            "SELECT c.label AS class_label, sess.session_year, g.group_name,
                    e.roll_number, e.status
             FROM enrollments e
             JOIN classes  c    ON c.class_id      = e.class_id
             JOIN sessions sess ON sess.session_id = e.session_id
             LEFT JOIN groups g ON g.group_id      = e.group_id
             WHERE e.sys_uid = ? AND e.status = 'active'
             ORDER BY e.enrollment_id DESC LIMIT 1",
            [$myUid]
        )->fetch();

        // Fall back to the most recent enrollment if none is currently active
        if (!$enroll) {
            $enroll = dbQuery(
                "SELECT c.label AS class_label, sess.session_year, g.group_name,
                        e.roll_number, e.status
                 FROM enrollments e
                 JOIN classes  c    ON c.class_id      = e.class_id
                 JOIN sessions sess ON sess.session_id = e.session_id
                 LEFT JOIN groups g ON g.group_id      = e.group_id
                 WHERE e.sys_uid = ?
                 ORDER BY e.enrollment_id DESC LIMIT 1",
                [$myUid]
            )->fetch();
        }

        ok([
            'profile'    => $profile,
            'enrollment' => $enroll ?: null,
        ]);
    } catch (Throwable $e) {
        error_log('EMS student fetch_profile: ' . $e->getMessage());
        fail('Failed to load your profile. Please try again.');
    }
}

// ── CHANGE PASSWORD — requires correct current password ───────────
if ($action === 'change_password') {
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword     = (string)($_POST['new_password']     ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        fail('Please fill in all password fields.');
    }
    if (strlen($newPassword) < 6) {
        fail('Your new password must be at least 6 characters.');
    }
    if (strlen($newPassword) > 72) {
        fail('Your new password is too long (max 72 characters).');
    }
    if ($newPassword !== $confirmPassword) {
        fail('New password and confirmation do not match.');
    }
    if ($newPassword === $currentPassword) {
        fail('Your new password must be different from your current password.');
    }

    try {
        $row = dbQuery(
            "SELECT password_hash FROM system_users WHERE sys_uid = ? AND role = 'student'",
            [$myUid]
        )->fetch();

        if (!$row) fail('Your account could not be found.');

        if (!password_verify($currentPassword, $row['password_hash'])) {
            fail('Your current password is incorrect.');
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        dbQuery(
            "UPDATE system_users SET password_hash = ? WHERE sys_uid = ? AND role = 'student'",
            [$newHash, $myUid]
        );

        log_activity($myUid, 'change_password', 'system_users', $myUid, ['self_service' => true]);

        ok(['msg' => 'Your password has been changed successfully.']);
    } catch (Throwable $e) {
        error_log('EMS student change_password: ' . $e->getMessage());
        fail('Failed to change your password. Please try again.');
    }
}

fail('Unknown action.');
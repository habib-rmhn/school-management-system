<?php
/**
 * EMS Teacher — My Profile AJAX Handler
 * ─────────────────────────────────────────────────────────────────
 * Actions:
 *   fetch_profile    – the teacher's own info (name, designation,
 *                      contact, photo, joining date, username)
 *   update_contact    – self-service update of phone + email only.
 *                      Name/designation/joining date stay admin-only.
 *   upload_photo      – replace the teacher's own profile photo
 *                      (multipart upload, validated server-side by
 *                      actual image content, not by filename/MIME
 *                      claimed by the browser)
 *   change_password   – verifies the current password before setting
 *                      a new one; never trusts the client's idea of
 *                      whether the passwords are strong enough
 *
 * Every action is scoped to $_SESSION['ems_uid'] only — a teacher can
 * only ever read or write their own profile through this endpoint.
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
    catch (Throwable $e) { error_log('EMS teacher profile tsafeRow: ' . $e->getMessage()); return null; }
}

function resolveTeacherPhotoUrl(?string $photoPath): ?string {
    if (empty($photoPath)) return null;
    $diskPath = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/' . ltrim($photoPath, '/');
    if (@file_exists($diskPath)) {
        return rtrim(BASE_URL, '/') . '/' . ltrim($photoPath, '/') . '?v=' . filemtime($diskPath);
    }
    return null;
}

$action = trim($_POST['action'] ?? '');

// ═══════════════════════════════════════════════════════════════════
//  ACTION: fetch_profile
// ═══════════════════════════════════════════════════════════════════
if ($action === 'fetch_profile') {
    $row = tsafeRow(
        "SELECT su.username, su.is_active, su.last_login,
                t.full_name, t.designation, t.phone, t.email, t.photo_path, t.joining_date, t.gender
         FROM system_users su
         JOIN teachers t ON t.sys_uid = su.sys_uid
         WHERE su.sys_uid = ?",
        [$myUid]
    );
    if (!$row) fail('Could not load your profile. Please try again.');

    $joining = null;
    if (!empty($row['joining_date'])) {
        try { $joining = (new DateTime($row['joining_date']))->format('d M Y'); } catch (Throwable $e) {}
    }
    $lastLogin = null;
    if (!empty($row['last_login'])) {
        try { $lastLogin = (new DateTime($row['last_login']))->format('d M Y, h:i A'); } catch (Throwable $e) {}
    }

    ok([
        'profile' => [
            'full_name'    => $row['full_name'],
            'designation'  => $row['designation'],
            'phone'        => $row['phone'],
            'email'        => $row['email'],
            'photo_url'    => resolveTeacherPhotoUrl($row['photo_path']),
            'joining_date' => $joining,
            'username'     => $row['username'],
            'is_active'    => (bool)$row['is_active'],
            'last_login'   => $lastLogin,
        ],
    ]);
}

// ═══════════════════════════════════════════════════════════════════
//  ACTION: update_contact
// ═══════════════════════════════════════════════════════════════════
if ($action === 'update_contact') {
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $phone)) {
        fail('Please enter a valid phone number.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Please enter a valid email address.');
    }

    try {
        dbQuery(
            "UPDATE teachers SET phone = ?, email = ? WHERE sys_uid = ?",
            [$phone !== '' ? $phone : null, $email !== '' ? $email : null, $myUid]
        );
    } catch (Throwable $e) {
        error_log('EMS teacher update_contact: ' . $e->getMessage());
        fail('Failed to save your contact info. Please try again.');
    }

    log_activity($myUid, 'update_profile', 'teachers', $myUid, []);

    ok(['saved' => true, 'phone' => $phone !== '' ? $phone : null, 'email' => $email !== '' ? $email : null]);
}

// ═══════════════════════════════════════════════════════════════════
//  ACTION: upload_photo
// ═══════════════════════════════════════════════════════════════════
if ($action === 'upload_photo') {
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        fail('Please choose a photo to upload.');
    }

    $file = $_FILES['photo'];
    $maxBytes = 3 * 1024 * 1024; // 3MB
    if ($file['size'] > $maxBytes) fail('Photo must be smaller than 3MB.');

    // Verify actual image content — never trust the client's filename or MIME claim.
    $imgInfo = @getimagesize($file['tmp_name']);
    if ($imgInfo === false) fail('That file does not look like a valid image.');

    $ext = null;
    if ($imgInfo[2] === IMAGETYPE_JPEG) $ext = 'jpg';
    elseif ($imgInfo[2] === IMAGETYPE_PNG) $ext = 'png';
    else fail('Only JPG and PNG photos are allowed.');

    $uploadDir = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/uploads/teacher/photos';
    if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            fail('Server could not prepare the upload folder. Please contact admin.');
        }
    }

    // Remove any previous photo for this teacher (any extension) so we
    // never leave a stale file behind after a format change.
    foreach (glob($uploadDir . '/' . preg_quote($myUid, '/') . '.*') ?: [] as $old) {
        @unlink($old);
    }

    $filename    = $myUid . '.' . $ext;
    $destination = $uploadDir . '/' . $filename;

    if (!@move_uploaded_file($file['tmp_name'], $destination)) {
        fail('Failed to save the uploaded photo. Please try again.');
    }

    $relativePath = 'uploads/teacher/photos/' . $filename;
    try {
        dbQuery("UPDATE teachers SET photo_path = ? WHERE sys_uid = ?", [$relativePath, $myUid]);
    } catch (Throwable $e) {
        error_log('EMS teacher upload_photo db: ' . $e->getMessage());
        fail('Photo saved but could not be linked to your profile. Please try again.');
    }

    log_activity($myUid, 'update_profile', 'teachers', $myUid, []);

    ok(['uploaded' => true, 'photo_url' => resolveTeacherPhotoUrl($relativePath)]);
}

// ═══════════════════════════════════════════════════════════════════
//  ACTION: change_password
// ═══════════════════════════════════════════════════════════════════
if ($action === 'change_password') {
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword     = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        fail('Please fill in all three password fields.');
    }

    $row = tsafeRow("SELECT password_hash FROM system_users WHERE sys_uid = ?", [$myUid]);
    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        fail('Your current password is incorrect.');
    }

    if (strlen($newPassword) < 8) fail('New password must be at least 8 characters long.');
    if (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        fail('New password must contain at least one letter and one number.');
    }
    if ($newPassword !== $confirmPassword) fail('New password and confirmation do not match.');
    if (password_verify($newPassword, $row['password_hash'])) {
        fail('New password must be different from your current password.');
    }

    try {
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        dbQuery("UPDATE system_users SET password_hash = ? WHERE sys_uid = ?", [$newHash, $myUid]);
    } catch (Throwable $e) {
        error_log('EMS teacher change_password: ' . $e->getMessage());
        fail('Failed to update your password. Please try again.');
    }

    log_activity($myUid, 'change_password', 'system_users', $myUid, []);

    ok(['changed' => true]);
}

fail('Unknown action.');
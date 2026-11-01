<?php
/**
 * EMS Admin — New Student Admission
 * ─────────────────────────────────────────────────────────────────
 * Dedicated full page (not a modal) because:
 *  - Photo upload needs proper file input UX
 *  - Many fields — needs breathing room especially on mobile
 *  - Student ID preview shown prominently after class+session pick
 *
 * Flow:
 *  1. Admin fills form (name, dob, gender, class, session, contacts, photo)
 *  2. Student ID auto-previewed via JS as class+session+serial are resolved
 *  3. On submit → POST → PHP validates → inserts system_users + students + enrollments
 *  4. Redirects to students.php with success toast via flash
 *
 * Student ID formula: {ssc_batch_2digit}{class_num_2digit}{serial_3digit}
 *   e.g. Diya admitted Class 6, Session 2016 → SSC 2021 → ID 2106024
 *   ssc_batch = session_year + (10 - class_num) + 1
 */

require_once __DIR__ . '/../includes/admin-session.php';

$pageTitle  = 'New Admission';
$activeNav  = 'admission';
$breadcrumb = [['label' => 'Students', 'url' => BASE_URL . '/admin/students.php'], 'New Admission'];

// ── Reference data ────────────────────────────────────────────────
function safeRows(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); } catch (Throwable $e) { return []; }
}
function sv(string $sql, array $p = []): mixed {
    try { $v = dbQuery($sql, $p)->fetchColumn(); return $v !== false ? $v : null; } catch (Throwable $e) { return null; }
}

$sessions = safeRows("SELECT session_id, session_year, label, is_current FROM sessions ORDER BY session_year DESC");
$classes  = safeRows("SELECT class_id, class_num, label FROM classes ORDER BY class_num");
$groups   = safeRows("SELECT group_id, group_name FROM groups ORDER BY group_id");

$currentSession = null;
foreach ($sessions as $s) { if ($s['is_current']) { $currentSession = $s; break; } }

// Session to preselect: the one right after the current session's year,
// or the current session itself if no later session exists yet.
$preselectSessionId = null;
if ($currentSession) {
    $nextSessions = array_filter($sessions, fn($s) => (int)$s['session_year'] > (int)$currentSession['session_year']);
    if (!empty($nextSessions)) {
        usort($nextSessions, fn($a, $b) => (int)$a['session_year'] <=> (int)$b['session_year']);
        $preselectSessionId = (int)$nextSessions[0]['session_id']; // closest future year
    } else {
        $preselectSessionId = (int)$currentSession['session_id']; // fallback
    }
}

// ── Flash ─────────────────────────────────────────────────────────
function setFlash(string $type, string $msg): void {
    $_SESSION['ems_flash'] = ['type' => $type, 'msg' => $msg];
}

// ── POST: Process admission ───────────────────────────────────────
$errors    = [];
$formData  = [];   // repopulate on error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify()) {
        setFlash('danger', 'Invalid request. Please try again.');
        redirect(BASE_URL . '/admin/admission.php');
    }

    // ── Collect & sanitise inputs ──────────────────────────────────
    $fullName   = trim($_POST['full_name']   ?? '');
    $gender     = trim($_POST['gender']      ?? '');
    $dob        = trim($_POST['date_of_birth'] ?? '');
    $phone      = trim($_POST['phone']       ?? '');
    $phone2     = trim($_POST['phone2']      ?? '');
    $email      = trim($_POST['email']       ?? '');
    $fatherName = trim($_POST['father_name'] ?? '');
    $motherName = trim($_POST['mother_name'] ?? '');
    $address    = trim($_POST['address']     ?? '');
    $classId    = (int)($_POST['class_id']   ?? 0);
    $sessionId  = (int)($_POST['session_id'] ?? 0);
    $groupId    = (int)($_POST['group_id']   ?? 0) ?: null;
    $password   = trim($_POST['password']    ?? '');

    // Repopulate for error display
    $formData = compact('fullName','gender','dob','phone','phone2','email',
                        'fatherName','motherName','address','classId','sessionId','groupId','password');

    // ── Validate ───────────────────────────────────────────────────
    if (empty($fullName))               $errors[] = 'Full name is required.';
    if (strlen($fullName) > 120)        $errors[] = 'Full name is too long.';
    if (!in_array($gender, ['male','female','other'])) $errors[] = 'Please select gender.';
    if ($dob && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) $errors[] = 'Invalid date of birth.';
    if (empty($phone))                  $errors[] = 'Phone number is required.';
    if ($classId < 1)                   $errors[] = 'Please select a class.';
    if ($sessionId < 1)                 $errors[] = 'Please select a session.';
    if (strlen($password) < 6)          $errors[] = 'Password must be at least 6 characters.';

    // Validate class & session exist
    $classRow   = $classId   ? dbQuery("SELECT class_num, label FROM classes  WHERE class_id=?",  [$classId])->fetch()   : null;
    $sessionRow = $sessionId ? dbQuery("SELECT session_year, label FROM sessions WHERE session_id=?", [$sessionId])->fetch() : null;
    if ($classId   && !$classRow)   $errors[] = 'Invalid class selected.';
    if ($sessionId && !$sessionRow) $errors[] = 'Invalid session selected.';

    // Class 6-8: no group
    if ($classRow && (int)$classRow['class_num'] < 9) $groupId = null;

    // Validate group for class 9-10
    if ($classRow && (int)$classRow['class_num'] >= 9 && !$groupId) {
        $errors[] = 'Please select a group for Class 9 or 10.';
    }

    // ── Subject choices (main/4th/eliminated) — class 9-10 group students only ──
    $mainSubjectId   = (int)($_POST['main_subject_id']   ?? 0) ?: null;
    $fourthSubjectId = (int)($_POST['fourth_subject_id'] ?? 0) ?: null;
    $elimSubjectId   = (int)($_POST['eliminated_subject_id'] ?? 0) ?: null;

    $formData['mainSubjectId']   = $mainSubjectId;
    $formData['fourthSubjectId'] = $fourthSubjectId;
    $formData['elimSubjectId']   = $elimSubjectId;

    if ($groupId) {
        // Group selected (class 9-10) — main & 4th subject are required
        if (!$mainSubjectId)   $errors[] = 'Please select a Main Subject.';
        if (!$fourthSubjectId) $errors[] = 'Please select a 4th Subject.';

        if ($mainSubjectId && $fourthSubjectId && $mainSubjectId === $fourthSubjectId) {
            $errors[] = 'Main Subject and 4th Subject cannot be the same.';
        }

        if ($mainSubjectId) {
            // Main subject MUST be a choice subject from the student's own group
            $mainRow = dbQuery(
                "SELECT subject_id FROM subjects WHERE subject_id=? AND class_id=? AND group_id=? AND is_choice=1 AND is_active=1",
                [$mainSubjectId, $classId, $groupId]
            )->fetch();
            if (!$mainRow) $errors[] = 'Invalid Main Subject selection.';
        }

        $fourthIsOwnGroup = null; // tri-state until checked
        if ($fourthSubjectId) {
            // 4th subject must be a choice subject — either from own group, or from another group
            $fourthRow = dbQuery(
                "SELECT subject_id, group_id FROM subjects WHERE subject_id=? AND class_id=? AND is_choice=1 AND is_active=1 AND group_id IS NOT NULL",
                [$fourthSubjectId, $classId]
            )->fetch();
            if (!$fourthRow) {
                $errors[] = 'Invalid 4th Subject selection.';
            } else {
                $fourthIsOwnGroup = ((int)$fourthRow['group_id'] === $groupId);
            }
        }

        // Eliminated subject only applies when 4th subject is from OUTSIDE own group
        if ($fourthIsOwnGroup === false) {
            if (!$elimSubjectId) {
                $errors[] = 'Please select the Eliminated Subject (4th subject is from another group).';
            } else {
                $elimRow = dbQuery(
                    "SELECT subject_id FROM subjects WHERE subject_id=? AND class_id=? AND group_id=? AND is_choice=1 AND is_active=1",
                    [$elimSubjectId, $classId, $groupId]
                )->fetch();
                if (!$elimRow) $errors[] = 'Invalid Eliminated Subject selection.';
                if ($elimSubjectId === $mainSubjectId) $errors[] = 'Eliminated Subject cannot be the same as Main Subject.';
            }
        } else {
            // 4th subject is from own group (or not yet determined) — eliminated subject must not be set
            $elimSubjectId = null;
            $formData['elimSubjectId'] = null;
        }
    } else {
        // No group (class 6-8, or All Groups) — subject choices never apply
        $mainSubjectId = $fourthSubjectId = $elimSubjectId = null;
        $formData['mainSubjectId'] = $formData['fourthSubjectId'] = $formData['elimSubjectId'] = null;
    }

    // Photo upload
    $photoPath  = null;
    $photoError = null;
    $uploadedFile = $_FILES['photo'] ?? null;
    $hasPhoto = $uploadedFile && $uploadedFile['error'] === UPLOAD_ERR_OK && $uploadedFile['size'] > 0;

    if ($hasPhoto) {
        $imageInfo = @getimagesize($uploadedFile['tmp_name']);
        $mime = $imageInfo['mime'] ?? '';
        
        $allowed = [
            'image/jpeg',
            'image/png',
            'image/webp'
        ];
        if (!in_array($mime, $allowed)) {
            $photoError = 'Photo must be JPG, PNG, or WebP.';
        } elseif ($uploadedFile['size'] > 3 * 1024 * 1024) {
            $photoError = 'Photo must be under 3MB.';
        }
        if ($photoError) $errors[] = $photoError;
    }

    // ── If valid, insert ───────────────────────────────────────────
    if (empty($errors)) {
        try {
            db()->beginTransaction();

            // Generate system UID
            $sysUid = generate_uid();
            // Ensure uniqueness
            while (sv("SELECT COUNT(*) FROM system_users WHERE sys_uid=?", [$sysUid])) {
                $sysUid = generate_uid();
            }

            // Calculate SSC batch
            $classNum = (int)$classRow['class_num'];
            $sessionYear = (int)$sessionRow['session_year'];
            $sscBatch = $sessionYear + (10 - $classNum) + 1;

            // Calculate serial — how many students already in this class+session
            $serial = (int)(sv(
                "SELECT COUNT(*) FROM enrollments WHERE class_id=? AND session_id=?",
                [$classId, $sessionId]
            ) ?? 0) + 1;

            // Build Student ID: {ssc_batch_2digit}{class_num_2digit}{serial_3digit}
            $studentId = sprintf('%02d%02d%03d',
                $sscBatch % 100,
                $classNum,
                $serial
            );

            // Ensure student ID unique (unlikely but safe)
            $idAttempt = 0;
            while (sv("SELECT COUNT(*) FROM students WHERE student_id=?", [$studentId]) && $idAttempt < 10) {
                $serial++;
                $studentId = sprintf('%02d%02d%03d', $sscBatch % 100, $classNum, $serial);
                $idAttempt++;
            }

            // Username = student_id (used for login)
            $username     = $studentId;
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            // Insert system_users
            dbQuery(
                "INSERT INTO system_users (sys_uid, role, username, password_hash, is_active, created_at)
                 VALUES (?, 'student', ?, ?, 1, NOW())",
                [$sysUid, $username, $passwordHash]
            );

            // Handle photo upload
            if ($hasPhoto) {
                $uploadDir = __DIR__ . '/../uploads/student/photos/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $ext      = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION) ?: 'jpg';
                $destFile = $uploadDir . $sysUid . '.' . strtolower($ext);
                if (move_uploaded_file($uploadedFile['tmp_name'], $destFile)) {
                    $photoPath = 'uploads/student/photos/' . $sysUid . '.' . strtolower($ext);
                }
            }

            // Insert students
            dbQuery(
                "INSERT INTO students
                    (sys_uid, student_id, full_name, gender, date_of_birth, phone, phone2,
                     email, father_name, mother_name, address, photo_path, ssc_batch)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [
                    $sysUid, $studentId, $fullName, $gender,
                    $dob ?: null, $phone, $phone2 ?: null,
                    $email ?: null, $fatherName ?: null, $motherName ?: null,
                    $address ?: null, $photoPath, $sscBatch
                ]
            );

            // Insert enrollment — roll_number stores the same serial used in the Student ID
            dbQuery(
                "INSERT INTO enrollments
                    (sys_uid, class_id, session_id, group_id, status, roll_number,
                     main_subject_id, fourth_subject_id, eliminated_subject_id, admitted_at)
                 VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, NOW())",
                [$sysUid, $classId, $sessionId, $groupId, $serial,
                 $mainSubjectId, $fourthSubjectId, $elimSubjectId]
            );

            db()->commit();

            log_activity($_SESSION['ems_uid'], 'admit_student', 'students', $sysUid, [
                'name'       => $fullName,
                'student_id' => $studentId,
                'class'      => $classRow['label'],
            ]);

            setFlash('success',
                "Student <strong>" . h($fullName) . "</strong> admitted successfully! " .
                "Student ID: <strong>" . h($studentId) . "</strong> &nbsp;·&nbsp; " .
                "Password: <code style='background:rgba(255,255,255,.2);padding:1px 6px;border-radius:3px'>" . h($password) . "</code>"
            );
            redirect(BASE_URL . '/admin/students.php');

        } catch (Throwable $e) {
            db()->rollBack();
            error_log('EMS admission: ' . $e->getMessage());
            $errors[] = 'A database error occurred. Please try again.';
        }
    }
}

require_once __DIR__ . '/includes/admin-sidebar.php';
?>

<style>
/* ═══════════════════════════════════════════════════════════════
   ADMISSION PAGE
   ═══════════════════════════════════════════════════════════════ */

.adm-layout {
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: 24px;
  align-items: start;
}

/* ── Section cards ──────────────────────────────────────────────── */
.adm-section {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  overflow: hidden;
  margin-bottom: 18px;
}

.adm-section:last-child { margin-bottom: 0; }

.section-hd {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 20px;
  border-bottom: 1px solid var(--border-light);
  background: var(--bg-elevated);
}

.section-hd-icon {
  width: 30px; height: 30px;
  border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; flex-shrink: 0;
}

.section-hd-title {
  font-size: 13.5px;
  font-weight: 700;
  color: var(--text);
  flex: 1;
}

.section-body { padding: 20px; }

/* ── Form grid ──────────────────────────────────────────────────── */
.form-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.form-full { grid-column: 1 / -1; }

/* ── Gender selector ────────────────────────────────────────────── */
.gender-opts { display: flex; gap: 10px; }
.gender-opt {
  flex: 1;
  display: flex; align-items: center; gap: 9px;
  padding: 10px 14px;
  border: 1.5px solid var(--border);
  border-radius: var(--r-sm);
  cursor: pointer;
  transition: all .15s;
  user-select: none;
}
.gender-opt:hover { border-color: var(--primary); background: var(--primary-xlight); }
.gender-opt input { display: none; }
.gender-opt.selected-male   { border-color: var(--sky);    background: var(--sky-bg); }
.gender-opt.selected-female { border-color: #ec4899;       background: #fdf2f8; }
.gender-opt.selected-other  { border-color: var(--purple); background: var(--purple-bg); }
.gender-icon { font-size: 18px; }
.gender-label { font-size: 13px; font-weight: 600; color: var(--text); }

/* ── Photo upload ───────────────────────────────────────────────── */
.photo-upload-area {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
}

.photo-preview-ring {
  width: 110px; height: 110px;
  border-radius: 50%;
  border: 3px dashed var(--border);
  display: flex; align-items: center; justify-content: center;
  position: relative;
  overflow: hidden;
  cursor: pointer;
  transition: border-color .2s;
  background: var(--bg-elevated);
}

.photo-preview-ring:hover { border-color: var(--primary); }
.photo-preview-ring:hover .photo-overlay { opacity: 1; }

.photo-preview-ring img {
  width: 100%; height: 100%;
  object-fit: cover;
  object-position: center top;
  border-radius: 50%;
}

.photo-overlay {
  position: absolute; inset: 0;
  background: rgba(37,99,235,.5);
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  border-radius: 50%;
  opacity: 0; transition: opacity .2s;
  color: #fff; font-size: 12px; font-weight: 600; gap: 4px;
}
.photo-overlay i { font-size: 18px; }

.photo-hint { font-size: 12px; color: var(--text-muted); text-align: center; line-height: 1.5; }

/* ── ID Preview card ────────────────────────────────────────────── */
.id-preview-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  overflow: hidden;
  margin-bottom: 18px;
}

.id-preview-inner {
  padding: 20px;
  text-align: center;
}

.id-preview-label {
  font-size: 11px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: var(--text-muted); margin-bottom: 10px;
}

.id-preview-num {
  font-size: 36px; font-weight: 900; letter-spacing: .08em;
  color: var(--primary); font-family: monospace;
  line-height: 1; margin-bottom: 8px;
  min-height: 44px;
  display: flex; align-items: center; justify-content: center;
}

.id-preview-num.placeholder {
  font-size: 14px; color: var(--text-light); font-family: inherit;
  font-weight: 400; letter-spacing: 0;
}

.id-breakdown {
  display: flex; justify-content: center; gap: 0;
  font-size: 11px; color: var(--text-muted);
  margin-top: 6px; flex-wrap: wrap; gap: 2px;
}

.id-part {
  padding: 3px 8px;
  border-radius: 3px;
  font-weight: 600;
  font-family: monospace;
}

.id-part-ssc   { background: var(--amber-bg);       color: var(--amber); }
.id-part-class { background: var(--primary-xlight);  color: var(--primary); }
.id-part-serial{ background: var(--green-bg);        color: var(--green); }

.id-preview-meta {
  font-size: 12px; color: var(--text-muted);
  margin-top: 10px; line-height: 1.5;
}

/* ── Summary sidebar card ───────────────────────────────────────── */
.summary-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  overflow: hidden;
  margin-bottom: 18px;
}

.summary-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 11px 16px;
  border-bottom: 1px solid var(--border-light);
  font-size: 13px;
}
.summary-item:last-child { border-bottom: none; }
.summary-item i { font-size: 13px; color: var(--text-light); width: 16px; text-align: center; flex-shrink: 0; }
.summary-key   { color: var(--text-muted); font-weight: 500; flex-shrink: 0; min-width: 80px; }
.summary-val   { color: var(--text); font-weight: 600; word-break: break-word; }

/* ── Password field ─────────────────────────────────────────────── */
.pwd-input-wrap { position: relative; }
.pwd-input-wrap input { padding-right: 90px; }
.pwd-actions {
  position: absolute; right: 8px; top: 50%;
  transform: translateY(-50%);
  display: flex; gap: 4px;
}
.pwd-action-btn {
  height: 24px; padding: 0 8px;
  font-size: 11px; font-weight: 600;
  border-radius: 3px; border: none;
  cursor: pointer; font-family: inherit;
  transition: all .13s;
}
.pwd-toggle-btn { background: var(--bg); color: var(--text-muted); }
.pwd-toggle-btn:hover { background: var(--border); color: var(--text); }
.pwd-gen-btn { background: var(--primary-xlight); color: var(--primary); }
.pwd-gen-btn:hover { background: var(--primary-light); }

.pwd-strength-bar-wrap { height: 3px; background: var(--border); border-radius: 2px; margin-top: 5px; overflow: hidden; }
.pwd-strength-bar { height: 100%; border-radius: 2px; transition: width .3s, background .3s; }

/* ── Submit bar ─────────────────────────────────────────────────── */
.submit-bar {
  display: flex; align-items: center; gap: 12px;
  padding: 16px 20px;
  background: var(--bg-elevated);
  border-top: 1px solid var(--border);
  flex-wrap: wrap;
}

/* ── Error list ─────────────────────────────────────────────────── */
.error-list { list-style: none; padding: 0; margin: 0; }
.error-list li {
  display: flex; align-items: flex-start; gap: 8px;
  font-size: 13.5px; padding: 4px 0;
  color: var(--red);
}
.error-list li i { margin-top: 3px; font-size: 11px; flex-shrink: 0; }

/* ── Responsive ─────────────────────────────────────────────────── */
@media (max-width: 1024px) {
  .adm-layout { grid-template-columns: 1fr 300px; }
}

@media (max-width: 820px) {
  .adm-layout { grid-template-columns: 1fr; }
  /* Move sidebar sections to top on mobile */
  .adm-sidebar { order: -1; }
}

@media (max-width: 600px) {
  .form-2 { grid-template-columns: 1fr; }
  .form-3 { grid-template-columns: 1fr 1fr; }
  .gender-opts { gap: 6px; }
  .gender-label { font-size: 12px; }
}

@media (max-width: 400px) {
  .form-3 { grid-template-columns: 1fr; }
}
</style>



<!-- ══ Page header ════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="page-header-top">
    <div>
      <div class="page-title">New Admission</div>
      <div class="page-sub">
        <i class="fas fa-user-plus"></i>
        Admit a new student into the school
      </div>
    </div>
    <div class="page-actions">
      <a href="<?= BASE_URL ?>/admin/students.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Back to Students
      </a>
    </div>
  </div>
</div>

<!-- ══ Error banner ═══════════════════════════════════════════════ -->
<?php if (!empty($errors)): ?>
<div class="alert alert-danger mb-4">
  <i class="fas fa-circle-exclamation" style="margin-top:4px;flex-shrink:0"></i>
  <div>
    <strong>Please fix the following errors:</strong>
    <ul class="error-list" style="margin-top:6px">
      <?php foreach ($errors as $err): ?>
        <li><i class="fas fa-circle-xmark" style="margin-top:4px;"></i><?= h($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<!-- ══ Main Form ══════════════════════════════════════════════════ -->
<form method="POST" action="admission.php" enctype="multipart/form-data" id="admForm" novalidate>
  <input type="hidden" name="csrf_token" id="csrfToken" value="<?= csrf_token() ?>">

  <div class="adm-layout">

    <!-- ════════════════════════════════════════
         LEFT COLUMN — Main form
         ════════════════════════════════════════ -->
    <div class="adm-main">

      <!-- ── Section 1: Academic placement ──────────────────────── -->
      <div class="adm-section">
        <div class="section-hd">
          <div class="section-hd-icon si-blue"><i class="fas fa-school"></i></div>
          <div class="section-hd-title">Academic Placement</div>
          <span class="badge badge-blue" style="font-size:11px">Step 1 — Start here</span>
        </div>
        <div class="section-body">
          <div class="form-2">

            <!-- Session -->
            <div class="form-group mb-0">
              <label class="form-label">Academic Session <span class="req">*</span></label>
              <select name="session_id" class="form-control" id="selSession"
`                      onchange="updateIdPreview()" required>
                <option value="">— Select Session —</option>
                <?php foreach ($sessions as $s): ?>
                  <option value="<?= $s['session_id'] ?>"
                          data-year="<?= $s['session_year'] ?>"
                    <?= ((int)($formData['sessionId'] ?? 0) === (int)$s['session_id']
                          || (!$formData && (int)$s['session_id'] === $preselectSessionId)) ? 'selected' : '' ?>>
                    <?= h($s['label']) ?><?= $s['is_current'] ? ' (Current)' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>`
              <div class="form-hint"><i class="fas fa-circle-info"></i> The academic year this student begins</div>
            </div>

            <!-- Class -->
            <div class="form-group mb-0">
              <label class="form-label">Class <span class="req">*</span></label>
              <select name="class_id" class="form-control" id="selClass"
                      onchange="onClassChange(); updateIdPreview()" required>
                <option value="">— Select Class —</option>
                <?php foreach ($classes as $c): ?>
                  <option value="<?= $c['class_id'] ?>"
                          data-num="<?= $c['class_num'] ?>"
                    <?= ((int)($formData['classId'] ?? 0) === (int)$c['class_id']) ? 'selected' : '' ?>>
                    <?= h($c['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Group — shown only for class 9-10 -->
            <div class="form-group mb-0" id="groupWrap" style="display:none">
              <label class="form-label">Group <span class="req" id="groupReq">*</span></label>
              <select name="group_id" class="form-control" id="selGroup" onchange="onGroupChange()">
                <option value="">— Select Group —</option>
                <?php foreach ($groups as $g): ?>
                  <option value="<?= $g['group_id'] ?>"
                    <?= ((int)($formData['groupId'] ?? 0) === (int)$g['group_id']) ? 'selected' : '' ?>>
                    <?= h($g['group_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-hint"><i class="fas fa-circle-info"></i> Required for Class 9 &amp; 10</div>
            </div>

          </div>

          <!-- Subject choices — shown only once a group is selected (class 9-10) -->
          <div class="form-3" id="subjectChoiceWrap" style="display:none;margin-top:16px"
               data-prev-main="<?= h($formData['mainSubjectId'] ?? '') ?>"
               data-prev-fourth="<?= h($formData['fourthSubjectId'] ?? '') ?>"
               data-prev-elim="<?= h($formData['elimSubjectId'] ?? '') ?>">
            <div class="form-group mb-0">
              <label class="form-label">Main Subject <span class="req">*</span></label>
              <select name="main_subject_id" class="form-control" id="selMainSubject" onchange="onSubjectChoiceChange()">
                <option value="">— Select —</option>
              </select>
              <div class="form-hint"><i class="fas fa-circle-info"></i> Choice subject from own group</div>
            </div>

            <div class="form-group mb-0">
              <label class="form-label">4th Subject <span class="req">*</span></label>
              <select name="fourth_subject_id" class="form-control" id="selFourthSubject" onchange="onSubjectChoiceChange()">
                <option value="">— Select —</option>
              </select>
              <div class="form-hint"><i class="fas fa-circle-info"></i> Own group, or another group's choice subject</div>
            </div>

            <div class="form-group mb-0" id="elimSubjectWrap" style="display:none">
              <label class="form-label">Eliminated Subject <span class="req">*</span></label>
              <select name="eliminated_subject_id" class="form-control" id="selElimSubject">
                <option value="">— Select —</option>
              </select>
              <div class="form-hint"><i class="fas fa-circle-info"></i> Own-group subject dropped for the 4th subject</div>
            </div>
          </div>

        </div>
      </div>


      <!-- ── Section 2: Personal Information ───────────────────── -->
      <div class="adm-section">
        <div class="section-hd">
          <div class="section-hd-icon si-green"><i class="fas fa-user"></i></div>
          <div class="section-hd-title">Personal Information</div>
        </div>
        <div class="section-body">

          <!-- Full name -->
          <div class="form-group">
            <label class="form-label">Full Name <span class="req">*</span></label>
            <input type="text" name="full_name" class="form-control"
                   placeholder="Student's full name (as in birth certificate)"
                   maxlength="120" value="<?= h($formData['fullName'] ?? '') ?>"
                   oninput="updateSummary()" required>
          </div>

          <div class="form-2">

            <!-- Date of Birth -->
            <div class="form-group mb-0">
              <label class="form-label">Date of Birth</label>
              <input type="date" name="date_of_birth" class="form-control"
                     value="<?= h($formData['dob'] ?? '') ?>">
            </div>

            <!-- Gender -->
            <div class="form-group mb-0">
              <label class="form-label">Gender <span class="req">*</span></label>
              <div class="gender-opts" id="genderOpts">
                <?php
                $genderVal = $formData['gender'] ?? 'male';
                foreach ([
                    ['male',   'fa-mars',   'Male',   'selected-male'],
                    ['female', 'fa-venus',  'Female', 'selected-female'],
                    ['other',  'fa-genderless','Other','selected-other'],
                ] as [$val, $icon, $lbl, $selCls]):
                ?>
                <label class="gender-opt <?= $genderVal === $val ? $selCls : '' ?>"
                       onclick="selectGender('<?= $val ?>')">
                  <input type="radio" name="gender" value="<?= $val ?>"
                         <?= $genderVal === $val ? 'checked' : '' ?>>
                  <i class="fas <?= $icon ?> gender-icon" style="color:<?= $val==='male'?'var(--sky)':($val==='female'?'#ec4899':'var(--purple)') ?>"></i>
                  <span class="gender-label"><?= $lbl ?></span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

          </div>
        </div>
      </div>

      <!-- ── Section 3: Family & Contact ───────────────────────── -->
      <div class="adm-section">
        <div class="section-hd">
          <div class="section-hd-icon si-amber"><i class="fas fa-people-roof"></i></div>
          <div class="section-hd-title">Family &amp; Contact</div>
        </div>
        <div class="section-body">
          <div class="form-2">

            <div class="form-group mb-0">
              <label class="form-label">Father's Name</label>
              <input type="text" name="father_name" class="form-control"
                     placeholder="Father's full name" maxlength="120"
                     value="<?= h($formData['fatherName'] ?? '') ?>">
            </div>

            <div class="form-group mb-0">
              <label class="form-label">Mother's Name</label>
              <input type="text" name="mother_name" class="form-control"
                     placeholder="Mother's full name" maxlength="120"
                     value="<?= h($formData['motherName'] ?? '') ?>">
            </div>

            <div class="form-group mb-0">
              <label class="form-label">Phone <span class="req">*</span></label>
              <input type="tel" name="phone" class="form-control"
                     placeholder="01XXXXXXXXX" maxlength="20"
                     value="<?= h($formData['phone'] ?? '') ?>"
                     oninput="updateSummary()" required>
            </div>

            <div class="form-group mb-0">
              <label class="form-label">Phone 2
                <span style="font-weight:400;font-size:11.5px;color:var(--text-muted)">(optional)</span>
              </label>
              <input type="tel" name="phone2" class="form-control"
                     placeholder="Alternate number" maxlength="20"
                     value="<?= h($formData['phone2'] ?? '') ?>">
            </div>

            <div class="form-group mb-0">
              <label class="form-label">Email
                <span style="font-weight:400;font-size:11.5px;color:var(--text-muted)">(optional)</span>
              </label>
              <input type="email" name="email" class="form-control"
                     placeholder="student.name@kkhs.edu.bd" maxlength="120"
                     value="<?= h($formData['email'] ?? '') ?>">
            </div>

            <div class="form-group mb-0 form-full">
              <label class="form-label">Address</label>
              <input type="text" name="address" class="form-control"
                     placeholder="Village, Union, Upazila, District"
                     value="<?= h($formData['address'] ?? '') ?>">
            </div>

          </div>
        </div>
      </div>

      <!-- ── Section 4: Login Credentials ──────────────────────── -->
      <div class="adm-section">
        <div class="section-hd">
          <div class="section-hd-icon si-purple"><i class="fas fa-key"></i></div>
          <div class="section-hd-title">Login Credentials</div>
        </div>
        <div class="section-body">
          <div class="alert alert-info" style="margin-bottom:16px;padding:10px 14px;font-size:13px">
            <i class="fas fa-circle-info" style="margin-top:3px;flex-shrink:0"></i>
            <div>The student will log in with their <strong>Student ID</strong> (auto-generated above) and this password.</div>
          </div>
          <div class="form-group mb-0">
            <label class="form-label">Initial Password <span class="req">*</span></label>
            <div class="pwd-input-wrap">
              <input type="text" name="password" id="pwdField" class="form-control"
                     placeholder="Min 6 characters"
                     value="<?= h($formData['password'] ?? '123456') ?>"
                     oninput="checkAdmPwdStrength(this.value)" autocomplete="new-password">
              <div class="pwd-actions">
                <button type="button" class="pwd-action-btn pwd-gen-btn" onclick="genAdmPwd()">
                  <i class="fas fa-dice"></i> Auto
                </button>
              </div>
            </div>
            <div class="pwd-strength-bar-wrap">
              <div class="pwd-strength-bar" id="admPwdBar" style="width:0%"></div>
            </div>
            <div class="form-hint" id="admPwdHint">
              <i class="fas fa-circle-info"></i> Note this password — share it with the student
            </div>
          </div>
        </div>
        <!-- Submit bar inside form -->
        <div class="submit-bar">

  <span style="font-size:12.5px;color:var(--text-muted);margin-right:auto">
    <i class="fas fa-shield-halved"></i> All data is saved securely
  </span>

  <a href="<?= BASE_URL ?>/admin/students.php" class="btn btn-outline btn-lg">
    Cancel
  </a>

  <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
    <i class="fas fa-user-plus"></i> Complete Admission
  </button>

</div>
      </div>

    </div><!-- /.adm-main -->

    <!-- ════════════════════════════════════════
         RIGHT COLUMN — Sidebar
         ════════════════════════════════════════ -->
    <div class="adm-sidebar">

      <!-- Student ID Preview -->
      <div class="id-preview-card">
        <div class="section-hd" style="border-bottom:1px solid var(--border-light)">
          <div class="section-hd-icon si-blue"><i class="fas fa-id-card"></i></div>
          <div class="section-hd-title">Student ID Preview</div>
        </div>
        <div class="id-preview-inner">
          <div class="id-preview-label">Auto-Generated Student ID</div>
          <div class="id-preview-num placeholder" id="idPreviewNum">
            Select class &amp; session
          </div>
          <div class="id-breakdown" id="idBreakdown" style="display:none">
            <span class="id-part id-part-ssc"    id="bpSsc">—</span>
            <span style="color:var(--text-light);padding:3px 2px;font-size:11px">SSC</span>
            <span class="id-part id-part-class"  id="bpClass">—</span>
            <span style="color:var(--text-light);padding:3px 2px;font-size:11px">Class</span>
            <span class="id-part id-part-serial" id="bpSerial">—</span>
            <span style="color:var(--text-light);padding:3px 2px;font-size:11px">Serial</span>
          </div>
          <div class="id-preview-meta" id="idPreviewMeta"></div>
        </div>
      </div>

      <!-- Photo Upload -->
      <div class="adm-section">
        <div class="section-hd">
          <div class="section-hd-icon si-teal"><i class="fas fa-camera"></i></div>
          <div class="section-hd-title">Student Photo</div>
          <span style="font-size:11px;color:var(--text-muted)">Optional</span>
        </div>
        <div class="section-body">
          <div class="photo-upload-area">
            <div class="photo-preview-ring" onclick="document.getElementById('photoInput').click()">
              <img src="<?= BASE_URL ?>/assets/images/student-avatar-m.jpg"
                   id="photoPreview" alt="Photo preview">
              <div class="photo-overlay">
                <i class="fas fa-camera"></i>
                <span>Click to upload</span>
              </div>
            </div>
            <input type="file" name="photo" id="photoInput" accept="image/*"
                   style="display:none" onchange="previewPhoto(this)">
            <div class="photo-hint">
              JPG, PNG or WebP · Max 3MB<br>
              <button type="button" class="btn btn-sm btn-ghost" style="margin-top:4px"
                      onclick="document.getElementById('photoInput').click()">
                <i class="fas fa-upload"></i> Choose Photo
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Live Summary -->
      <div class="summary-card">
        <div class="section-hd" style="border-bottom:1px solid var(--border-light)">
          <div class="section-hd-icon si-gray"><i class="fas fa-clipboard-check"></i></div>
          <div class="section-hd-title">Admission Summary</div>
        </div>
        <div class="summary-item">
          <i class="fas fa-user"></i>
          <span class="summary-key">Name</span>
          <span class="summary-val" id="sumName" style="color:var(--text-light)">—</span>
        </div>
        <div class="summary-item">
          <i class="fas fa-venus-mars"></i>
          <span class="summary-key">Gender</span>
          <span class="summary-val" id="sumGender">Male</span>
        </div>
        <div class="summary-item">
          <i class="fas fa-school"></i>
          <span class="summary-key">Class</span>
          <span class="summary-val" id="sumClass" style="color:var(--text-light)">—</span>
        </div>
        <div class="summary-item">
          <i class="fas fa-calendar-alt"></i>
          <span class="summary-key">Session</span>
          <span class="summary-val" id="sumSession" style="color:var(--text-light)">—</span>
        </div>
        <div class="summary-item">
          <i class="fas fa-phone"></i>
          <span class="summary-key">Phone</span>
          <span class="summary-val" id="sumPhone" style="color:var(--text-light)">—</span>
        </div>
        <div class="summary-item">
          <i class="fas fa-graduation-cap"></i>
          <span class="summary-key">SSC Batch</span>
          <span class="summary-val" id="sumSsc" style="color:var(--text-light)">—</span>
        </div>
      </div>

    </div><!-- /.adm-sidebar -->
  </div><!-- /.adm-layout -->
</form>

<script>
/* ═══════════════════════════════════════════════════════════════
   ADMISSION PAGE JS
   ═══════════════════════════════════════════════════════════════ */

// ── Reference data from PHP ──────────────────────────────────────
const AJAX_URL   = '<?= BASE_URL ?>/admin/students-ajax.php';
const AVT_MALE   = '<?= BASE_URL ?>/assets/images/student-avatar-m.jpg';
const AVT_FEMALE = '<?= BASE_URL ?>/assets/images/student-avatar-f.jpg';

// ── Class change: show/hide group selector ───────────────────────
function onClassChange() {
  const sel = document.getElementById('selClass');
  const num = parseInt(sel.options[sel.selectedIndex]?.dataset.num || '0');
  const gw  = document.getElementById('groupWrap');
  if (num >= 9) { gw.style.display = ''; }
  else          { gw.style.display = 'none'; document.getElementById('selGroup').value = ''; }
  // Class changed — subject choices depend on class+group, so reset and re-check.
  onGroupChange();
  updateSummary();
}

// ── Subject choice data (own group + other groups' choice subjects) ──
let _choiceData = { ownGroup: [], otherGroups: [] };

async function onGroupChange() {
  const classSel = document.getElementById('selClass');
  const groupSel = document.getElementById('selGroup');
  const wrap     = document.getElementById('subjectChoiceWrap');
  const mainSel  = document.getElementById('selMainSubject');
  const fourthSel= document.getElementById('selFourthSubject');
  const elimWrap = document.getElementById('elimSubjectWrap');

  const classId = classSel.value;
  const groupId = groupSel.value;

  if (!classId || !groupId) {
    wrap.style.display = 'none';
    elimWrap.style.display = 'none';
    mainSel.innerHTML = '<option value="">— Select —</option>';
    fourthSel.innerHTML = '<option value="">— Select —</option>';
    document.getElementById('selElimSubject').innerHTML = '<option value="">— Select —</option>';
    _choiceData = { ownGroup: [], otherGroups: [] };
    return;
  }

  wrap.style.display = '';
  mainSel.innerHTML   = '<option value="">Loading…</option>';
  fourthSel.innerHTML = '<option value="">Loading…</option>';

  try {
    const fd = new FormData();
    fd.append('action',     'get_choice_subjects');
    fd.append('class_id',   classId);
    fd.append('group_id',   groupId);
    fd.append('csrf_token', document.getElementById('csrfToken').value);

    const resp = await fetch(AJAX_URL, { method: 'POST', body: fd });
    const json = await resp.json();
    if (json.csrf) document.getElementById('csrfToken').value = json.csrf;

    if (!json.ok) {
      mainSel.innerHTML   = '<option value="">— No choice subjects found —</option>';
      fourthSel.innerHTML = '<option value="">— No choice subjects found —</option>';
      _choiceData = { ownGroup: [], otherGroups: [] };
      return;
    }

    _choiceData = { ownGroup: json.own_group || [], otherGroups: json.other_groups || [] };

    // Restore previous selections after a validation-error redisplay (one-time use).
    const prevMain   = wrap.dataset.prevMain   || '';
    const prevFourth = wrap.dataset.prevFourth || '';
    const prevElim   = wrap.dataset.prevElim   || '';
    delete wrap.dataset.prevMain;
    delete wrap.dataset.prevFourth;
    delete wrap.dataset.prevElim;

    renderSubjectOptions(prevMain, prevFourth, prevElim);
  } catch (e) {
    mainSel.innerHTML   = '<option value="">— Failed to load —</option>';
    fourthSel.innerHTML = '<option value="">— Failed to load —</option>';
  }
}

// Rebuilds Main/4th/Eliminate <option> lists based on _choiceData and current selections,
// so a subject picked in one field can't be picked again in another.
// Optional override*Val params are used once, right after AJAX load, to restore a
// previous selection (validation-error redisplay) since .value can't be set on a
// <select> before its <option>s exist.
function renderSubjectOptions(overrideMainVal, overrideFourthVal, overrideElimVal) {
  const mainSel   = document.getElementById('selMainSubject');
  const fourthSel = document.getElementById('selFourthSubject');
  const elimSel   = document.getElementById('selElimSubject');
  const elimWrap  = document.getElementById('elimSubjectWrap');

  const mainVal   = overrideMainVal   !== undefined ? overrideMainVal   : mainSel.value;
  const fourthVal = overrideFourthVal !== undefined ? overrideFourthVal : fourthSel.value;
  const elimVal   = overrideElimVal   !== undefined ? overrideElimVal   : elimSel.value;

  const ownGroup    = _choiceData.ownGroup;
  const otherGroups = _choiceData.otherGroups;

  // Main Subject: own-group choice subjects only, excluding whatever is picked as 4th.
  mainSel.innerHTML = '<option value="">— Select —</option>' +
    ownGroup
      .filter(s => String(s.subject_id) !== String(fourthVal))
      .map(s => '<option value="' + s.subject_id + '"' + (String(s.subject_id) === String(mainVal) ? ' selected' : '') + '>' +
        escAdm(s.subject_name) + '</option>').join('');

  // 4th Subject: own-group choice subjects (excluding Main) + every other group's choice subjects.
  let fourthOpts = '<option value="">— Select —</option>';
  if (ownGroup.length) {
    fourthOpts += '<optgroup label="Own Group">' +
      ownGroup
        .filter(s => String(s.subject_id) !== String(mainVal))
        .map(s => '<option value="' + s.subject_id + '"' + (String(s.subject_id) === String(fourthVal) ? ' selected' : '') + '>' +
          escAdm(s.subject_name) + '</option>').join('') +
      '</optgroup>';
  }
  if (otherGroups.length) {
    // Group the "other groups" subjects by group_name for clearer optgroups.
    const byGroup = {};
    otherGroups.forEach(s => { (byGroup[s.group_name] = byGroup[s.group_name] || []).push(s); });
    Object.keys(byGroup).forEach(gName => {
      fourthOpts += '<optgroup label="' + escAdm(gName) + '">' +
        byGroup[gName].map(s => '<option value="' + s.subject_id + '"' + (String(s.subject_id) === String(fourthVal) ? ' selected' : '') + '>' +
          escAdm(s.subject_name) + '</option>').join('') +
        '</optgroup>';
    });
  }
  fourthSel.innerHTML = fourthOpts;

  // Determine whether the currently-picked 4th subject is from the own group or not.
  const fourthIsOwnGroup = fourthVal && ownGroup.some(s => String(s.subject_id) === String(fourthVal));
  const fourthIsOutside  = fourthVal && !fourthIsOwnGroup;

  if (fourthIsOutside) {
    elimWrap.style.display = '';
    // Eliminated Subject: own-group choice subjects only, excluding whatever is picked as Main.
    elimSel.innerHTML = '<option value="">— Select —</option>' +
      ownGroup
        .filter(s => String(s.subject_id) !== String(mainVal))
        .map(s => '<option value="' + s.subject_id + '"' + (String(s.subject_id) === String(elimVal) ? ' selected' : '') + '>' +
          escAdm(s.subject_name) + '</option>').join('');
  } else {
    elimWrap.style.display = 'none';
    elimSel.value = '';
  }
}

function onSubjectChoiceChange() {
  renderSubjectOptions();
}

function escAdm(str) {
  const d = document.createElement('div');
  d.textContent = str == null ? '' : String(str);
  return d.innerHTML;
}

// ── Student ID preview via AJAX ──────────────────────────────────
let _idTimer = null;
function updateIdPreview() {
  clearTimeout(_idTimer);
  _idTimer = setTimeout(_doIdPreview, 300);
}

async function _doIdPreview() {
  const sessionSel = document.getElementById('selSession');
  const classSel   = document.getElementById('selClass');
  const numEl      = document.getElementById('idPreviewNum');
  const bdEl       = document.getElementById('idBreakdown');
  const metaEl     = document.getElementById('idPreviewMeta');

  const sessionId   = sessionSel.value;
  const classId     = classSel.value;
  const sessionYear = sessionSel.options[sessionSel.selectedIndex]?.dataset.year;
  const classNum    = parseInt(classSel.options[classSel.selectedIndex]?.dataset.num || '0');

  if (!sessionId || !classId) {
    numEl.textContent = 'Select class & session';
    numEl.className   = 'id-preview-num placeholder';
    bdEl.style.display  = 'none';
    metaEl.innerHTML    = '';
    updateSummary();
    return;
  }

  // Calculate locally (same formula as PHP)
  const sYear    = parseInt(sessionYear || '0');
  const sscBatch = sYear + (10 - classNum) + 1;
  const sscPart  = String(sscBatch % 100).padStart(2, '0');
  const clsPart  = String(classNum).padStart(2, '0');

  numEl.textContent = '…';
  numEl.className   = 'id-preview-num';

  // Fetch serial count from server
  try {
    const fd = new FormData();
    fd.append('action',     'count_serial');
    fd.append('class_id',   classId);
    fd.append('session_id', sessionId);
    fd.append('csrf_token', document.getElementById('csrfToken').value);

    const resp = await fetch(AJAX_URL, { method: 'POST', body: fd });
    const json = await resp.json();
    if (json.csrf) document.getElementById('csrfToken').value = json.csrf;

    const serial    = (json.serial || 0) + 1;
    const serialPart= String(serial).padStart(3, '0');
    const studentId = sscPart + clsPart + serialPart;

    // Show it big
    numEl.textContent = studentId;
    numEl.className   = 'id-preview-num';

    // Breakdown
    document.getElementById('bpSsc').textContent    = sscPart;
    document.getElementById('bpClass').textContent  = clsPart;
    document.getElementById('bpSerial').textContent = serialPart;
    bdEl.style.display = 'flex';

    metaEl.innerHTML =
      '<div style="color:var(--text-muted);font-size:12px;margin-top:4px">SSC Batch: <strong>' + sscBatch + '</strong></div>' +
      '<div style="color:var(--text-muted);font-size:11.5px">Serial #' + serial + ' in this class &amp; session</div>';

    updateSummary();
  } catch(e) {
    numEl.textContent = 'Error';
    numEl.className   = 'id-preview-num placeholder';
  }
}

// ── Gender selector ──────────────────────────────────────────────
function selectGender(val) {
  document.querySelectorAll('.gender-opt').forEach(el => {
    el.className = 'gender-opt';
  });
  const map = { male:'selected-male', female:'selected-female', other:'selected-other' };
  const selected = document.querySelector('.gender-opt input[value="' + val + '"]');
  if (selected) {
    selected.checked = true;
    selected.closest('.gender-opt').classList.add(map[val] || '');
  }
  // Update photo placeholder
  const preview = document.getElementById('photoPreview');
  if (preview && preview.dataset.custom !== '1') {
    preview.src = val === 'female' ? AVT_FEMALE : AVT_MALE;
  }
  updateSummary();
}

// ── Photo preview ────────────────────────────────────────────────
function previewPhoto(input) {
  if (!input.files || !input.files[0]) return;
  const file   = input.files[0];
  const reader = new FileReader();
  reader.onload = function(e) {
    const img = document.getElementById('photoPreview');
    img.src = e.target.result;
    img.dataset.custom = '1';
  };
  reader.readAsDataURL(file);
}

// ── Live summary update ──────────────────────────────────────────
function updateSummary() {
  const name    = (document.querySelector('input[name="full_name"]')?.value || '').trim();
  const genderR = document.querySelector('input[name="gender"]:checked');
  const gender  = genderR ? genderR.value : '—';
  const classSel= document.getElementById('selClass');
  const sessSel = document.getElementById('selSession');
  const phone   = (document.querySelector('input[name="phone"]')?.value || '').trim();

  const classLabel = classSel.value
    ? (classSel.options[classSel.selectedIndex]?.text || '—') : '—';
  const sessLabel  = sessSel.value
    ? (sessSel.options[sessSel.selectedIndex]?.text  || '—') : '—';

  const classNum  = parseInt(classSel.options[classSel.selectedIndex]?.dataset.num || '0');
  const sessYear  = parseInt(sessSel.options[sessSel.selectedIndex]?.dataset.year  || '0');
  const sscBatch  = (classNum && sessYear) ? (sessYear + (10 - classNum) + 1) : null;

  document.getElementById('sumName').textContent    = name    || '—';
  document.getElementById('sumGender').textContent  = gender.charAt(0).toUpperCase() + gender.slice(1);
  document.getElementById('sumClass').textContent   = classLabel;
  document.getElementById('sumSession').textContent = sessLabel;
  document.getElementById('sumPhone').textContent   = phone   || '—';
  document.getElementById('sumSsc').textContent     = sscBatch ? sscBatch : '—';

  // Style empty values
  ['sumName','sumClass','sumSession','sumPhone','sumSsc'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.color = el.textContent === '—' ? 'var(--text-light)' : 'var(--text)';
  });
}

// ── Password strength ────────────────────────────────────────────
function checkAdmPwdStrength(val) {
  const bar  = document.getElementById('admPwdBar');
  const hint = document.getElementById('admPwdHint');
  const len  = val.length;
  let pct = 0, color = 'var(--border)', label = '';
  if      (len >= 12){ pct=100; color='var(--green)';  label='Strong'; }
  else if (len >=  8){ pct=66;  color='var(--amber)';  label='Medium'; }
  else if (len >=  6){ pct=33;  color='var(--red)';    label='Weak'; }
  else if (len >   0){ pct=10;  color='var(--red)';    label='Too short'; }
  bar.style.width      = pct + '%';
  bar.style.background = color;
  if (hint) hint.innerHTML = label
    ? '<i class="fas fa-circle-info"></i> Strength: <strong style="color:' + color + '">' + label + '</strong> — share this password with the student'
    : '<i class="fas fa-circle-info"></i> Note this password — share it with the student';
}

function genAdmPwd() {
  const chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#!';
  let pwd = '';
  for (let i = 0; i < 10; i++) pwd += chars[Math.floor(Math.random() * chars.length)];
  const inp = document.getElementById('pwdField');
  if (inp) { inp.value = pwd; checkAdmPwdStrength(pwd); }
}

// ── Submit loading state ─────────────────────────────────────────
document.getElementById('admForm').addEventListener('submit', function() {
  const btn = document.getElementById('submitBtn');
  btn.innerHTML = '<i class="fas fa-spinner" style="animation:spin .7s linear infinite;display:inline-block"></i> Processing…';
  btn.disabled = true;
});

// ── Init ─────────────────────────────────────────────────────────
onClassChange();
updateIdPreview();
updateSummary();
checkAdmPwdStrength(document.getElementById('pwdField').value);
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
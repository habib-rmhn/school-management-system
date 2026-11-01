<?php
/**
 * EMS Student — Dashboard  v1.0
 * Friendly welcome banner, simple quick-glance stats, big tappable
 * action tiles. Designed for Class 6–10 students in a rural school —
 * minimal text, large icons, no jargon.
 */
require_once __DIR__ . '/../includes/student-session.php';

$pageTitle = 'Home';
$activeNav = 'dashboard';

// ── Helpers ───────────────────────────────────────────────────────
function ssafeVal(string $sql, array $p = []): mixed {
    try { $v = dbQuery($sql, $p)->fetchColumn(); return $v !== false ? $v : null; }
    catch (Throwable $e) { return null; }
}
function ssafeRow(string $sql, array $p = []): ?array {
    try { $r = dbQuery($sql, $p)->fetch(); return $r ?: null; }
    catch (Throwable $e) { return null; }
}
function ssafeAll(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); }
    catch (Throwable $e) { return []; }
}

$uid = $_SESSION['ems_uid'];

// ── Current active enrollment ──────────────────────────────────────
$enroll = ssafeRow(
    "SELECT e.enrollment_id, e.class_id, e.session_id, c.label AS class_label,
            sess.session_year, g.group_name
     FROM enrollments e
     JOIN classes c ON c.class_id = e.class_id
     JOIN sessions sess ON sess.session_id = e.session_id
     LEFT JOIN groups g ON g.group_id = e.group_id
     WHERE e.sys_uid = ? AND e.status = 'active'
     ORDER BY e.enrollment_id DESC LIMIT 1",
    [$uid]
);
$enrollmentId = $enroll['enrollment_id'] ?? null;

// ── Attendance this month (%) ──────────────────────────────────────
$attTotal = $attPresent = 0;
$attPct = null;
if ($enrollmentId) {
    $attTotal = (int)ssafeVal(
        "SELECT COUNT(*) FROM attendance WHERE enrollment_id = ? AND att_date >= DATE_FORMAT(NOW(),'%Y-%m-01')",
        [$enrollmentId]
    );
    $attPresent = (int)ssafeVal(
        "SELECT COUNT(*) FROM attendance WHERE enrollment_id = ? AND status = 'present' AND att_date >= DATE_FORMAT(NOW(),'%Y-%m-01')",
        [$enrollmentId]
    );
    if ($attTotal > 0) $attPct = round($attPresent / $attTotal * 100);
}

// ── Last published result (GPA) ────────────────────────────────────
$lastResult = null;
if ($enrollmentId) {
    $lastResult = ssafeRow(
        "SELECT ers.gpa, ers.overall_status, ex.exam_name
         FROM exam_results_summary ers
         JOIN exams ex ON ex.exam_id = ers.exam_id
         WHERE ers.enrollment_id = ? AND ex.status = 'published'
         ORDER BY ex.published_at DESC LIMIT 1",
        [$enrollmentId]
    );
}

// ── Pending dues ────────────────────────────────────────────────────
$dueAmount = 0;
$dueCount  = 0;
if ($enrollmentId) {
    $dueAmount = (float)(ssafeVal(
        "SELECT COALESCE(SUM(pd.amount_due - COALESCE(pr.paid,0)),0)
         FROM payment_dues pd
         LEFT JOIN (SELECT due_id, SUM(amount_paid) AS paid FROM payment_records GROUP BY due_id) pr
                ON pr.due_id = pd.due_id
         WHERE pd.enrollment_id = ? AND pd.status != 'paid'",
        [$enrollmentId]
    ) ?? 0);
    $dueCount = (int)ssafeVal(
        "SELECT COUNT(*) FROM payment_dues WHERE enrollment_id = ? AND status != 'paid'",
        [$enrollmentId]
    );
}

// ── Next exam (upcoming, this class+session) ───────────────────────
$nextExam = null;
if ($enroll) {
    $nextExam = ssafeRow(
        "SELECT ex.exam_name, MIN(es.exam_date) AS first_date
         FROM exams ex
         JOIN exam_subjects es ON es.exam_id = ex.exam_id
         WHERE ex.class_id = ? AND ex.session_id = ? AND es.exam_date >= CURDATE()
         GROUP BY ex.exam_id
         ORDER BY first_date ASC LIMIT 1",
        [$enroll['class_id'], $enroll['session_id']]
    );
}
$daysToExam = null;
if ($nextExam && $nextExam['first_date']) {
    $daysToExam = (int)ceil((strtotime($nextExam['first_date']) - strtotime(date('Y-m-d'))) / 86400);
}

// ── Today's classes (from routine) ─────────────────────────────────
$todayClasses = [];
if ($enroll) {
    $dow = (int)date('w');               // 0=Sun in PHP
    $dowMap = [0=>1, 1=>2, 2=>3, 3=>4, 4=>5, 5=>0, 6=>0]; // map PHP Sun-Sat to school's Sat=0..Thu=5; Fri has none
    $schoolDow = $dowMap[$dow] ?? null;
    if ($schoolDow !== null && $dow != 5) { // skip Friday (weekend)
        $todayClasses = ssafeAll(
            "SELECT cr.period_number, cr.start_time, cr.end_time, sub.subject_name, t.full_name AS teacher_name
             FROM class_routine cr
             LEFT JOIN subjects sub ON sub.subject_id = cr.subject_id
             LEFT JOIN teachers t ON t.sys_uid = cr.teacher_uid
             WHERE cr.class_id = ? AND cr.session_id = ? AND cr.day_of_week = ?
             ORDER BY cr.period_number ASC",
            [$enroll['class_id'], $enroll['session_id'], $schoolDow]
        );
    }
}

require_once __DIR__ . '/includes/student-sidebar.php';

$fmtTk = fn($n) => '৳ ' . number_format((float)($n ?? 0), 0);
?>

<?php if (!$enroll): ?>
<div class="alert alert-warning mb-4">
  <i class="fas fa-triangle-exclamation"></i>
  <span>You don't have an active class enrollment right now. Please contact your school admin office.</span>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     QUICK STATS — simple, icon + number + label
     ═══════════════════════════════════════════════════════════════ -->
<div class="stats-grid">

  <a href="<?= BASE_URL ?>/student/attendance.php" class="stat-card">
    <div class="stat-icon si-blue"><i class="fas fa-calendar-check"></i></div>
    <div class="stat-text">
      <div class="stat-value"><?= $attPct !== null ? $attPct . '%' : '—' ?></div>
      <div class="stat-label">Attendance this month</div>
    </div>
  </a>

  <a href="<?= BASE_URL ?>/student/results.php" class="stat-card">
    <div class="stat-icon si-green"><i class="fas fa-star"></i></div>
    <div class="stat-text">
      <div class="stat-value"><?= $lastResult ? number_format((float)$lastResult['gpa'], 2) : '—' ?></div>
      <div class="stat-label">Last GPA</div>
    </div>
  </a>

  <a href="<?= BASE_URL ?>/student/payments.php" class="stat-card">
    <div class="stat-icon si-amber"><i class="fas fa-money-bill-wave"></i></div>
    <div class="stat-text">
      <div class="stat-value"><?= $dueCount > 0 ? $fmtTk($dueAmount) : '৳ 0' ?></div>
      <div class="stat-label"><?= $dueCount > 0 ? 'Fees due' : 'No fees due' ?></div>
    </div>
  </a>

  <a href="<?= BASE_URL ?>/student/admit-card.php" class="stat-card">
    <div class="stat-icon si-purple"><i class="fas fa-file-pen"></i></div>
    <div class="stat-text">
      <div class="stat-value"><?= $daysToExam !== null ? $daysToExam : '—' ?></div>
      <div class="stat-label"><?= $daysToExam !== null ? 'Days to next exam' : 'No upcoming exam' ?></div>
    </div>
  </a>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     TODAY'S CLASSES
     ═══════════════════════════════════════════════════════════════ -->
<div class="card">
  <div class="card-header">
    <div class="card-title-group">
      <div class="card-title"><i class="fas fa-sun"></i> Today's Classes</div>
      <div class="card-subtitle"><?= date('l, d F') ?></div>
    </div>
    <a href="<?= BASE_URL ?>/student/routine.php" class="btn btn-sm btn-outline">Full Routine</a>
  </div>

  <?php if (date('w') == 5): ?>
    <div class="empty-state">
      <i class="fas fa-mug-hot"></i>
      <p>It's Friday — no classes today. Enjoy your day off!</p>
    </div>
  <?php elseif (empty($todayClasses)): ?>
    <div class="empty-state">
      <i class="fas fa-calendar-xmark"></i>
      <p>No classes have been added for today yet.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="ems-table">
        <thead><tr><th>Period</th><th>Time</th><th>Subject</th><th>Teacher</th></tr></thead>
        <tbody>
        <?php foreach ($todayClasses as $cls): ?>
          <tr>
            <td><span class="badge badge-blue">P<?= (int)$cls['period_number'] ?></span></td>
            <td style="white-space:nowrap">
              <?= $cls['start_time'] ? date('h:i A', strtotime($cls['start_time'])) : '—' ?>
              <?= $cls['end_time'] ? ' – ' . date('h:i A', strtotime($cls['end_time'])) : '' ?>
            </td>
            <td style="font-weight:700"><?= h($cls['subject_name'] ?? '—') ?></td>
            <td><?= h($cls['teacher_name'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     BIG ACTION TILES — most-used pages, one tap away
     ═══════════════════════════════════════════════════════════════ -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-bolt"></i> Quick Access</div>
  </div>
  <div class="card-body">
    <div class="action-grid">
      <a href="<?= BASE_URL ?>/student/routine.php" class="action-tile">
        <div class="at-icon si-blue"><i class="fas fa-calendar-week"></i></div>
        <div class="at-label">Class Routine</div>
      </a>
      <a href="<?= BASE_URL ?>/student/attendance.php" class="action-tile">
        <div class="at-icon si-green"><i class="fas fa-calendar-check"></i></div>
        <div class="at-label">My Attendance</div>
      </a>
      <a href="<?= BASE_URL ?>/student/results.php" class="action-tile">
        <div class="at-icon si-purple"><i class="fas fa-chart-simple"></i></div>
        <div class="at-label">My Results</div>
      </a>
      <a href="<?= BASE_URL ?>/student/admit-card.php" class="action-tile">
        <div class="at-icon si-amber"><i class="fas fa-id-card"></i></div>
        <div class="at-label">Admit Card</div>
      </a>
      <a href="<?= BASE_URL ?>/student/seat-plan.php" class="action-tile">
        <div class="at-icon si-sky"><i class="fas fa-chair"></i></div>
        <div class="at-label">Seat Plan</div>
      </a>
      <a href="<?= BASE_URL ?>/student/payments.php" class="action-tile">
        <div class="at-icon si-red"><i class="fas fa-money-bill-wave"></i></div>
        <div class="at-label">My Payments</div>
      </a>
      <a href="<?= BASE_URL ?>/student/profile.php" class="action-tile">
        <div class="at-icon si-blue"><i class="fas fa-user"></i></div>
        <div class="at-label">My Profile</div>
      </a>
      <a href="<?= BASE_URL ?>/student/dashboard.php" class="action-tile">
        <div class="at-icon si-green"><i class="fas fa-house"></i></div>
        <div class="at-label">Home</div>
      </a>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     NEXT EXAM REMINDER (if any)
     ═══════════════════════════════════════════════════════════════ -->
<?php if ($nextExam): ?>
<div class="alert alert-info">
  <i class="fas fa-bell"></i>
  <span>
    Your next exam <strong><?= h($nextExam['exam_name']) ?></strong> starts on
    <strong><?= date('d F Y', strtotime($nextExam['first_date'])) ?></strong>
    (<?= $daysToExam ?> day<?= $daysToExam == 1 ? '' : 's' ?> left).
    <a href="<?= BASE_URL ?>/student/admit-card.php" style="color:#0c4a6e;font-weight:700;text-decoration:underline">Download your admit card →</a>
  </span>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/student-footer.php'; ?>
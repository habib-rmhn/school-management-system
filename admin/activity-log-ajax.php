<?php
/**
 * EMS Admin — Activity Log AJAX Handler  (activity-log-ajax.php)
 *
 * Actions:
 *   fetch_logs        — paginated, filtered activity history (full AJAX, no GET params)
 *   fetch_filter_meta — action types actually present in data + teacher/class/session lists
 */
require_once __DIR__ . '/../includes/admin-session.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed.']); exit;
}

// ── Action type → [icon, colour, human label] ──────────────────────
// Falls back to a generic "circle-dot / gray / Title Cased" entry for
// anything new that gets logged elsewhere but isn't listed here yet.
//
// NOTE: this const (and COL_MAP below) is deliberately declared up here,
// before $action is even read, rather than further down near
// actionMeta()/describeAction() where it conceptually belongs. Unlike
// function declarations, PHP does NOT hoist top-level `const` — it only
// becomes available once execution actually reaches this line. The
// notif_count/notif_fetch branch below runs (and returns) before the
// script would otherwise get down to where this used to live, so
// actionMeta() was throwing "Undefined constant ACTION_META" on every
// call. Moving both consts here fixes that for good.
const ACTION_META = [
    'login'                 => ['fa-right-to-bracket',  'blue',   'Logged In'],
    'logout'                => ['fa-right-from-bracket', 'gray',   'Logged Out'],
    'change_password'       => ['fa-key',                'sky',    'Changed Password'],
    'reset_password'        => ['fa-key',                'red',    'Reset Password'],

    'mark_attendance'       => ['fa-calendar-check',     'green',  'Marked Attendance'],
    'save_attendance'       => ['fa-calendar-check',     'green',  'Saved Attendance'],
    'update_attendance'     => ['fa-calendar-pen',       'sky',    'Updated Attendance'],

    'upload_marks'          => ['fa-pencil',             'amber',  'Uploaded Marks'],
    'edit_marks'            => ['fa-pen-to-square',      'amber',  'Edited Marks'],
    'clear_marks'           => ['fa-eraser',             'red',    'Cleared Marks'],

    'publish_result'        => ['fa-poll',               'purple', 'Published Results'],
    'unpublish_result'      => ['fa-rotate-left',        'gray',   'Unpublished Results'],
    'publish_exam'          => ['fa-bullhorn',           'purple', 'Published Exam'],

    'create_exam'           => ['fa-file-circle-plus',   'blue',   'Created Exam'],
    'edit_exam'             => ['fa-file-pen',           'sky',    'Edited Exam'],
    'delete_exam'           => ['fa-file-circle-xmark',  'red',    'Deleted Exam'],
    'add_exam_subject'      => ['fa-square-plus',        'blue',   'Added Exam Subject'],
    'update_exam_subject'   => ['fa-pen-to-square',      'sky',    'Updated Exam Subject'],
    'remove_exam_subject'   => ['fa-square-minus',       'red',    'Removed Exam Subject'],
    'toggle_participation'  => ['fa-user-slash',         'amber',  'Toggled Exam Participation'],

    'auto_assign_seats'     => ['fa-chair',              'teal',   'Auto-Assigned Seats'],
    'save_rooms'            => ['fa-door-open',           'teal',   'Saved Exam Rooms'],
    'clear_seat_allocations'=> ['fa-eraser',             'red',    'Cleared Seat Allocations'],

    'admit_student'         => ['fa-user-plus',          'green',  'Admitted Student'],
    'edit_student'          => ['fa-user-pen',           'sky',    'Edited Student'],
    'toggle_student'        => ['fa-user-check',         'amber',  'Toggled Student Status'],
    'deactivate_student'    => ['fa-user-slash',         'red',    'Deactivated Student'],
    'activate_student'      => ['fa-user-check',         'green',  'Activated Student'],
    'promote_students'      => ['fa-arrow-trend-up',     'purple', 'Promoted Class'],

    'add_teacher'           => ['fa-chalkboard-user',    'green',  'Added Teacher'],
    'edit_teacher'          => ['fa-user-pen',           'sky',    'Edited Teacher'],
    'toggle_teacher'        => ['fa-user-check',         'amber',  'Toggled Teacher Status'],
    'deactivate_teacher'    => ['fa-user-slash',         'red',    'Deactivated Teacher'],
    'delete_teacher'        => ['fa-user-xmark',         'red',    'Deleted Teacher'],
    'assign_teachers'       => ['fa-link',               'blue',   'Assigned Teachers'],
    'remove_assignment'     => ['fa-link-slash',         'red',    'Removed Assignment'],

    'record_payment'        => ['fa-money-bill-wave',    'green',  'Recorded Payment'],
    'add_payment_due'       => ['fa-file-invoice-dollar','amber',  'Added Fee Due'],
    'delete_payment_record' => ['fa-trash',              'red',    'Deleted Payment Record'],

    'save_routine'          => ['fa-calendar-week',      'teal',   'Saved Class Routine'],
    'save_routine_slot'     => ['fa-calendar-week',      'teal',   'Saved Routine Slot'],
    'delete_routine_slot'   => ['fa-calendar-xmark',     'red',    'Deleted Routine Slot'],
    'add_period_row'        => ['fa-square-plus',        'blue',   'Added Period Row'],
    'delete_period_row'     => ['fa-square-minus',       'red',    'Deleted Period Row'],

    'create_subject'        => ['fa-book',               'purple', 'Added Subject'],
    'edit_subject'          => ['fa-book-open',          'sky',    'Edited Subject'],
    'delete_subject'        => ['fa-book-circle-xmark',  'red',    'Deleted Subject'],
    'toggle_subject'        => ['fa-toggle-on',           'amber',  'Toggled Subject Status'],

    'create_session'        => ['fa-rotate',             'blue',   'Created Session'],
    'edit_session'          => ['fa-pen-to-square',      'sky',    'Edited Session'],
    'delete_session'        => ['fa-rotate-right',       'red',    'Deleted Session'],
    'set_current_session'   => ['fa-star',               'purple', 'Set Current Session'],

    'app_settings'          => ['fa-gear',               'gray',   'Updated Settings'],
    'update_settings'       => ['fa-gear',               'gray',   'Updated Settings'],
];

const COL_MAP = [
    'blue'   => ['#eff6ff', '#2563eb'],
    'green'  => ['#ecfdf5', '#059669'],
    'amber'  => ['#fffbeb', '#d97706'],
    'red'    => ['#fef2f2', '#dc2626'],
    'purple' => ['#f5f3ff', '#7c3aed'],
    'sky'    => ['#f0f9ff', '#0284c7'],
    'teal'   => ['#f0fdfa', '#0f766e'],
    'gray'   => ['#f8fafc', '#64748b'],
];

$action = trim($_POST['action'] ?? '');

// ═══════════════════════════════════════════════════════════════════
// Sidebar notification-bell actions (notif_count / notif_fetch) are
// handled here, BEFORE the CSRF gate below, and deliberately never
// touch $_SESSION['csrf_token'].
//
// WHY: every other action on this endpoint (and on students-ajax.php,
// attendance-ajax.php, etc.) shares ONE session-wide CSRF token that
// rotates on every successful request. The bell widget runs in the
// background on every admin page — on load, every 45s, and on click —
// completely independent of whatever page-specific AJAX flow (e.g.
// attendance.php saving, students.php fetching its list) is also using
// that same token. A background bell request landing between a page's
// own "read the token" and "submit the token" moments rotates the
// token out from under that page, producing a bogus "Session expired"
// response on an otherwise unrelated action. That's exactly what was
// happening. These two actions are read-only / low-stakes (viewing
// notifications, marking them read) so they're exempt from CSRF
// entirely — they still require a valid logged-in admin session via
// admin-session.php above, just not the rotating token.
// ═══════════════════════════════════════════════════════════════════
if ($action === 'notif_count' || $action === 'notif_fetch') {
    handle_notif_action($action);
    exit; // handle_notif_action() always echoes + exits; this is just a safety net
}

if (!csrf_verify()) {
    echo json_encode(['ok' => false, 'msg' => 'Session expired. Please refresh the page.']); exit;
}

csrf_rotate();
$newToken = csrf_token();

function ok(array $data = []): void {
    global $newToken;
    echo json_encode(array_merge(['ok' => true, 'csrf' => $newToken], $data)); exit;
}
function fail(string $msg): void {
    global $newToken;
    echo json_encode(['ok' => false, 'msg' => $msg, 'csrf' => $newToken]); exit;
}
function rows(string $sql, array $p = []): array {
    try { return dbQuery($sql, $p)->fetchAll(); } catch (Throwable $e) { return []; }
}
function sv(string $sql, array $p = []): mixed {
    try { $v = dbQuery($sql, $p)->fetchColumn(); return $v !== false ? $v : null; }
    catch (Throwable $e) { return null; }
}

// ── Action type → [icon, colour, human label] ──────────────────────
// Falls back to a generic "circle-dot / gray / Title Cased" entry for
// anything new that gets logged elsewhere but isn't listed here yet.
// (ACTION_META and COL_MAP themselves now live at the top of the file —
// see the note up there for why.)
function actionMeta(string $type): array {
    return ACTION_META[$type] ?? ['fa-circle-dot', 'gray', ucwords(str_replace('_', ' ', $type))];
}

/**
 * Short, plain-text (no HTML) context suffix for the notification
 * dropdown — much shorter than describeAction()'s full feed sentence.
 * Best-effort: grabs whichever of these fields is present in meta_json.
 */
function notifContext(array $meta): string {
    foreach (['class', 'subject', 'name', 'label', 'fee_type'] as $k) {
        if (!empty($meta[$k]) && is_scalar($meta[$k])) return ' for ' . $meta[$k];
    }
    return '';
}

/**
 * Builds the verb phrase for a notification row, e.g. "Updated Marks for
 * Class 6". The actor's name/ID is prepended separately by the caller.
 * Password actions get dedicated phrasing (gender-aware pronoun) to match
 * the requested tone; everything else falls back to the existing
 * ACTION_META label + notifContext().
 */
function notifText(string $actionType, array $meta, ?string $gender): string {
    if ($actionType === 'change_password') {
        $pronoun = $gender === 'female' ? 'her' : ($gender === 'male' ? 'his' : 'their');
        return !empty($meta['self_service']) ? "Changed {$pronoun} password" : 'Changed a password';
    }
    if ($actionType === 'reset_password') {
        return 'Reset password for ' . ($meta['name'] ?? 'a user');
    }
    [, , $label] = actionMeta($actionType);
    return $label . notifContext($meta);
}

/**
 * Build a human-readable one-line description of the action from
 * action_type + meta_json, e.g.:
 *   "Uploaded Agriculture Studies marks for Final Exam 2026 — for 3106001, 3106006, 3106041"
 *   "Marked attendance for Class 7 on 09 Jun 2026 — Present: 28, Absent: 3, Late: 1"
 * Falls back to a generic label + raw context chips when the shape is unknown.
 */
function describeAction(string $actionType, array $meta, array $studentIdMap): string {
    $sids = function (array $uids) use ($studentIdMap): array {
        $out = [];
        foreach ($uids as $u) $out[] = $studentIdMap[$u] ?? $u;
        return $out;
    };
    $joinIds = fn(array $ids) => implode(', ', array_slice($ids, 0, 12)) . (count($ids) > 12 ? ' +' . (count($ids) - 12) . ' more' : '');

    switch ($actionType) {
        case 'upload_marks':
            $subj = h((string)($meta['subject'] ?? 'marks'));
            $ids  = !empty($meta['student_uids']) ? $sids($meta['student_uids']) : [];
            $base = "Uploaded <strong>{$subj}</strong> marks";
            if (!empty($meta['count'])) $base .= " for " . (int)$meta['count'] . " student" . ((int)$meta['count'] !== 1 ? 's' : '');
            if ($ids) $base .= '<br><span class="al-sub">Students: ' . h($joinIds($ids)) . '</span>';
            return $base;

        case 'clear_marks':
            $subj = h((string)($meta['subject'] ?? 'marks'));
            $ids  = !empty($meta['student_uids']) ? $sids($meta['student_uids']) : [];
            $base = "Cleared <strong>{$subj}</strong> marks" . (isset($meta['deleted']) ? " (" . (int)$meta['deleted'] . " record" . ((int)$meta['deleted'] !== 1 ? 's' : '') . ")" : '');
            if ($ids) $base .= '<br><span class="al-sub">Students: ' . h($joinIds($ids)) . '</span>';
            return $base;

        case 'mark_attendance':
        case 'save_attendance':
            $cls  = isset($meta['class']) ? h((string)$meta['class']) : null;
            $date = $meta['date'] ?? null;
            $base = "Marked attendance" . ($cls ? " for <strong>{$cls}</strong>" : '') . ($date ? " on " . h(date('d M Y', strtotime($date))) : '');
            $parts = [];
            if (isset($meta['present'])) $parts[] = (int)$meta['present'] . ' present';
            if (isset($meta['absent']))  $parts[] = (int)$meta['absent']  . ' absent';
            if (isset($meta['late']))    $parts[] = (int)$meta['late']    . ' late';
            if ($parts) $base .= ' — ' . h(implode(', ', $parts));
            // Show absent/late student IDs specifically — that's the actionable info
            $byStatus = $meta['student_uids_by_status'] ?? null;
            if (is_array($byStatus)) {
                $flag = [];
                if (!empty($byStatus['absent'])) $flag[] = 'Absent: ' . h($joinIds($sids($byStatus['absent'])));
                if (!empty($byStatus['late']))   $flag[] = 'Late: '   . h($joinIds($sids($byStatus['late'])));
                if ($flag) $base .= '<br><span class="al-sub">' . implode(' &nbsp;·&nbsp; ', $flag) . '</span>';
            }
            return $base;

        case 'admit_student':
            return "Admitted <strong>" . h($meta['name'] ?? 'a student') . "</strong>" . (!empty($meta['student_id']) ? ' (ID: ' . h($meta['student_id']) . ')' : '') . (!empty($meta['class']) ? ' to ' . h($meta['class']) : '');

        case 'edit_student':
            $base = "Edited student <strong>" . h($meta['name'] ?? '') . "</strong>";
            if (!empty($meta['changed']) && is_array($meta['changed'])) $base .= ' — changed ' . h(implode(', ', $meta['changed']));
            return $base;

        case 'edit_teacher':
            $base = "Edited teacher <strong>" . h($meta['name'] ?? '') . "</strong>";
            if (!empty($meta['changed']) && is_array($meta['changed'])) $base .= ' — changed ' . h(implode(', ', $meta['changed']));
            return $base;

        case 'toggle_student':
            return (!empty($meta['active']) ? 'Activated' : 'Deactivated') . ' student <strong>' . h($meta['name'] ?? '') . '</strong>';

        case 'toggle_teacher':
            return (!empty($meta['active']) ? 'Activated' : 'Deactivated') . ' teacher <strong>' . h($meta['name'] ?? '') . '</strong>';

        case 'toggle_subject':
            return (!empty($meta['active']) ? 'Activated' : 'Deactivated') . ' subject <strong>' . h($meta['name'] ?? '') . '</strong>';

        case 'add_teacher':
            return 'Added teacher <strong>' . h($meta['name'] ?? '') . '</strong>' . (!empty($meta['username']) ? ' (username: ' . h($meta['username']) . ')' : '');

        case 'delete_teacher':
            return 'Deleted teacher <strong>' . h($meta['name'] ?? '') . '</strong>';

        case 'reset_password':
            return 'Reset password for <strong>' . h($meta['name'] ?? 'a user') . '</strong>';

        case 'change_password':
            return !empty($meta['self_service']) ? 'Changed their own password' : 'Changed a password';

        case 'create_exam':
            return 'Created exam <strong>' . h($meta['name'] ?? '') . '</strong>';

        case 'publish_exam':
            return 'Published exam <strong>' . h($meta['name'] ?? '') . '</strong>';

        case 'publish_result':
            return 'Published results for <strong>' . h($meta['name'] ?? '') . '</strong>';

        case 'unpublish_result':
            return 'Unpublished results for <strong>' . h($meta['name'] ?? '') . '</strong>';

        case 'create_subject':
            return 'Added subject <strong>' . h($meta['name'] ?? '') . '</strong>';

        case 'edit_subject':
            return 'Edited subject <strong>' . h($meta['name'] ?? '') . '</strong>';

        case 'delete_subject':
            return 'Deleted subject <strong>' . h($meta['name'] ?? '') . '</strong>';

        case 'create_session':
            return 'Created session <strong>' . h($meta['label'] ?? '') . '</strong>';

        case 'edit_session':
            return 'Edited session <strong>' . h($meta['label'] ?? '') . '</strong>';

        case 'delete_session':
            return 'Deleted session <strong>' . h($meta['label'] ?? '') . '</strong>';

        case 'set_current_session':
            return 'Set <strong>' . h($meta['label'] ?? '') . '</strong> as the current session';

        case 'record_payment':
            $base = 'Recorded a payment';
            if (!empty($meta['fee_type'])) $base .= ' for <strong>' . h($meta['fee_type']) . '</strong>';
            if (isset($meta['amount']))    $base .= ' (৳' . h((string)$meta['amount']) . ')';
            return $base;

        case 'add_payment_due':
            $base = 'Added a fee due';
            if (!empty($meta['fee_type'])) $base .= ' — <strong>' . h($meta['fee_type']) . '</strong>';
            if (isset($meta['amount']))    $base .= ' (৳' . h((string)$meta['amount']) . ')';
            return $base;

        case 'delete_payment_record':
            return 'Deleted a payment record' . (isset($meta['amount']) ? ' (৳' . h((string)$meta['amount']) . ')' : '');

        case 'assign_teachers':
            return 'Assigned ' . (isset($meta['count']) ? (int)$meta['count'] . ' teacher' . ((int)$meta['count'] !== 1 ? 's' : '') : 'teachers') . ' to a class';

        case 'remove_assignment':
            return 'Removed teaching assignment' . (!empty($meta['subject']) ? ' for <strong>' . h($meta['subject']) . '</strong>' : '');

        case 'auto_assign_seats':
            return 'Auto-assigned ' . (isset($meta['assigned']) ? (int)$meta['assigned'] . ' ' : '') . 'exam seats' . (!empty($meta['strategy']) ? ' (' . h($meta['strategy']) . ')' : '');

        case 'clear_seat_allocations':
            return 'Cleared ' . (isset($meta['deleted']) ? (int)$meta['deleted'] . ' ' : '') . 'seat allocations';

        case 'save_rooms':
            return 'Saved exam room setup' . (isset($meta['count']) ? ' (' . (int)$meta['count'] . ' rooms)' : '');

        case 'login':
            return 'Logged in' . (!empty($meta['ip']) ? ' from <span class="al-mono">' . h($meta['ip']) . '</span>' : '');

        case 'logout':
            return 'Logged out';

        case 'update_settings':
        case 'app_settings':
            return 'Updated school settings' . (!empty($meta['name']) ? ' (school name: ' . h($meta['name']) . ')' : '');

        case 'save_routine_slot':
            return 'Saved a class routine slot';

        default:
            // Generic fallback: show the most informative scalar fields we can find
            $bits = [];
            foreach (['name', 'label', 'subject', 'class'] as $k) {
                if (!empty($meta[$k]) && is_scalar($meta[$k])) { $bits[] = h((string)$meta[$k]); break; }
            }
            [, , $label] = actionMeta($actionType);
            return h($label) . ($bits ? ' — ' . implode(', ', $bits) : '');
    }
}

// ═══════════════════════════════════════════════════════════════
// ACTION: fetch_logs
// ═══════════════════════════════════════════════════════════════
if ($action === 'fetch_logs') {
    $role      = trim($_POST['role']      ?? ''); // '', student, teacher, admin
    $actorUid  = trim($_POST['actor']     ?? ''); // specific sys_uid (teacher/admin picker)
    $actionTy  = trim($_POST['action_type'] ?? '');
    $classId   = (int)($_POST['class_id'] ?? 0);
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $dateFrom  = trim($_POST['date_from'] ?? '');
    $dateTo    = trim($_POST['date_to']   ?? '');
    $search    = trim($_POST['search']    ?? '');
    $page      = max(1, (int)($_POST['page'] ?? 1));
    $perPage   = 25;

    if ($dateFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) fail('Invalid from-date.');
    if ($dateTo   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   fail('Invalid to-date.');

    try {
        $where  = ['1=1'];
        $params = [];

        if (in_array($role, ['student', 'teacher', 'admin'], true)) {
            $where[] = 'su.role = ?'; $params[] = $role;
        }
        if ($actorUid !== '') { $where[] = 'al.actor_uid = ?'; $params[] = $actorUid; }
        if ($actionTy !== '') { $where[] = 'al.action_type = ?'; $params[] = $actionTy; }

        if ($classId) {
            $where[] = "(JSON_UNQUOTE(JSON_EXTRACT(al.meta_json,'$.class_id')) = ?)";
            $params[] = (string)$classId;
        }
        if ($sessionId) {
            $where[] = "(JSON_UNQUOTE(JSON_EXTRACT(al.meta_json,'$.session_id')) = ?)";
            $params[] = (string)$sessionId;
        }
        if ($dateFrom !== '') { $where[] = 'al.logged_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
        if ($dateTo   !== '') { $where[] = 'al.logged_at <= ?'; $params[] = $dateTo   . ' 23:59:59'; }

        if ($search !== '') {
            $where[] = "(s.full_name LIKE ? OR t.full_name LIKE ? OR su.username LIKE ?
                         OR al.action_type LIKE ? OR al.meta_json LIKE ? OR al.target_uid LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $baseSql = "FROM activity_logs al
                    JOIN system_users su ON su.sys_uid = al.actor_uid
                    LEFT JOIN students s ON s.sys_uid = al.actor_uid
                    LEFT JOIN teachers t ON t.sys_uid = al.actor_uid
                    WHERE $whereSql";

        $total      = (int)(sv("SELECT COUNT(*) $baseSql", $params) ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;

        // teachers.gender may not exist on older schema copies — detect it once
        // so we can request it when available without breaking older databases.
        $teacherHasGender = (bool)(sv(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'teachers' AND column_name = 'gender'"
        ) ?? 0);
        $tGenderSelect = $teacherHasGender ? "t.gender AS t_gender" : "NULL AS t_gender";

        $logs = rows(
            "SELECT al.log_id, al.actor_uid, al.action_type, al.target_type,
                    al.target_uid, al.meta_json, al.logged_at,
                    su.role AS actor_role, su.username,
                    s.full_name AS s_name, s.photo_path AS s_photo, s.gender AS s_gender,
                    t.full_name AS t_name, t.photo_path AS t_photo, $tGenderSelect
             $baseSql
             ORDER BY al.logged_at DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        // Collect every student sys_uid referenced in this page's meta_json
        // (student_uids / student_uids_by_status) so we can resolve them to
        // human-friendly student_id (roll/admission numbers) in one query.
        $allStudentUids = [];
        $decodedMetas   = [];
        foreach ($logs as $log) {
            $m = $log['meta_json'] ? (json_decode($log['meta_json'], true) ?? []) : [];
            if (!is_array($m)) $m = [];
            $decodedMetas[$log['log_id']] = $m;
            if (!empty($m['student_uids']) && is_array($m['student_uids'])) {
                foreach ($m['student_uids'] as $u) $allStudentUids[$u] = true;
            }
            if (!empty($m['student_uids_by_status']) && is_array($m['student_uids_by_status'])) {
                foreach ($m['student_uids_by_status'] as $list) {
                    if (is_array($list)) foreach ($list as $u) $allStudentUids[$u] = true;
                }
            }
        }
        $studentIdMap = [];
        if ($allStudentUids) {
            $uids = array_keys($allStudentUids);
            $ph   = implode(',', array_fill(0, count($uids), '?'));
            $sRows = rows("SELECT sys_uid, student_id FROM students WHERE sys_uid IN ($ph)", $uids);
            foreach ($sRows as $sr) $studentIdMap[$sr['sys_uid']] = $sr['student_id'];
        }

        // Build display-ready rows
        $out = [];
        foreach ($logs as $log) {
            $meta = $decodedMetas[$log['log_id']];
            [$icon, $col, $label] = actionMeta($log['action_type']);
            [$iconBg, $iconColor] = COL_MAP[$col] ?? COL_MAP['gray'];

            $role = $log['actor_role'];
            $name = match ($role) {
                'student' => $log['s_name'] ?? $log['username'],
                'teacher' => $log['t_name'] ?? $log['username'],
                'admin'   => 'Administrator',
                default   => $log['username'],
            };
            $photo  = $role === 'student' ? $log['s_photo'] : ($role === 'teacher' ? $log['t_photo'] : null);
            $gender = match ($role) {
                'student' => $log['s_gender'] ?? 'male',
                'teacher' => $log['t_gender'] ?? 'male',
                default   => null,
            };

            $out[] = [
                'log_id'        => (int)$log['log_id'],
                'action_type'   => $log['action_type'],
                'action_label'  => $label,
                'icon'          => $icon,
                'icon_bg'       => $iconBg,
                'icon_color'    => $iconColor,
                'actor_name'    => $name,
                'actor_role'    => $role,
                'actor_photo'   => $photo,
                'actor_gender'  => $gender,
                'description'   => describeAction($log['action_type'], $meta, $studentIdMap),
                'logged_at'     => $log['logged_at'],
            ];
        }

        // ── Summary stats for the stat strip ──────────────────────
        $statsToday   = (int)(sv("SELECT COUNT(*) FROM activity_logs WHERE DATE(logged_at) = CURDATE()") ?? 0);
        $statsWeek    = (int)(sv("SELECT COUNT(*) FROM activity_logs WHERE logged_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)") ?? 0);
        $statsStudent = (int)(sv("SELECT COUNT(*) FROM activity_logs al JOIN system_users su ON su.sys_uid=al.actor_uid WHERE su.role='student'") ?? 0);
        $statsTeacher = (int)(sv("SELECT COUNT(*) FROM activity_logs al JOIN system_users su ON su.sys_uid=al.actor_uid WHERE su.role='teacher'") ?? 0);
        $statsAdmin   = (int)(sv("SELECT COUNT(*) FROM activity_logs al JOIN system_users su ON su.sys_uid=al.actor_uid WHERE su.role='admin'") ?? 0);

        ok([
            'logs'        => $out,
            'page'        => $page,
            'total_pages' => $totalPages,
            'total'       => $total,
            'per_page'    => $perPage,
            'stats' => [
                'today'   => $statsToday,
                'week'    => $statsWeek,
                'student' => $statsStudent,
                'teacher' => $statsTeacher,
                'admin'   => $statsAdmin,
            ],
        ]);
    } catch (Throwable $e) {
        error_log('EMS fetch_logs(activity): ' . $e->getMessage());
        fail('Failed to load activity log.');
    }
}

// ═══════════════════════════════════════════════════════════════
// ACTION: fetch_filter_meta
// ═══════════════════════════════════════════════════════════════
if ($action === 'fetch_filter_meta') {
    try {
        $teachers = rows(
            "SELECT t.sys_uid, t.full_name FROM teachers t
             JOIN system_users su ON su.sys_uid = t.sys_uid
             WHERE su.is_active = 1
             ORDER BY t.full_name ASC"
        );
        $classes  = rows("SELECT class_id, label FROM classes ORDER BY class_num ASC");
        $sessions = rows("SELECT session_id, label FROM sessions ORDER BY session_year DESC");

        // Only offer action types that actually appear in the data, so the
        // dropdown never shows empty/irrelevant options.
        $present = rows("SELECT DISTINCT action_type FROM activity_logs");
        $actionTypes = [];
        foreach ($present as $p) {
            $type = $p['action_type'];
            [, , $label] = actionMeta($type);
            $actionTypes[] = ['value' => $type, 'label' => $label];
        }
        usort($actionTypes, fn($a, $b) => strcmp($a['label'], $b['label']));

        ok([
            'teachers'     => $teachers,
            'classes'      => $classes,
            'sessions'     => $sessions,
            'action_types' => $actionTypes,
        ]);
    } catch (Throwable $e) {
        fail('Failed to load filter options.');
    }
}

// ═══════════════════════════════════════════════════════════════════
// Sidebar notification-bell actions — see the big comment near the top
// of the file for why this is a separate, CSRF-exempt code path.
//
//   notif_count — unread badge count. Excludes login/logout noise and
//                 the current admin's own actions (the dropdown is
//                 meant to surface OTHER users' activity, not self).
//   notif_fetch — latest 10 notifications (read + unread) for the
//                 dropdown, so the list stays populated instead of
//                 going blank once everything's been seen. Each item
//                 carries `unread` reflecting its state BEFORE this
//                 call, so the frontend can highlight what's new.
//                 Same exclusions as notif_count. Whatever was unread
//                 in this batch gets marked read ("click the icon
//                 marks those read").
// ═══════════════════════════════════════════════════════════════════
function handle_notif_action(string $action): void {
    $myUid = $_SESSION['ems_uid'] ?? '';

    if ($action === 'notif_count') {
        try {
            $count = (int)(sv(
                "SELECT COUNT(*) FROM activity_logs al
                 WHERE al.is_read = 0
                   AND al.action_type NOT IN ('login','logout')
                   AND al.actor_uid != ?",
                [$myUid]
            ) ?? 0);
            echo json_encode(['ok' => true, 'count' => $count]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'Failed to load notification count.']);
        }
        return;
    }

    // notif_fetch
    try {
        // teachers.gender may not exist on older schema copies — same
        // detection already used by fetch_logs above.
        $teacherHasGender = (bool)(sv(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'teachers' AND column_name = 'gender'"
        ) ?? 0);
        $tGenderSelect = $teacherHasGender ? "t.gender AS t_gender" : "NULL AS t_gender";

        $logs = rows(
            "SELECT al.log_id, al.actor_uid, al.action_type, al.meta_json, al.logged_at, al.is_read,
                    su.role AS actor_role, su.username,
                    s.full_name AS s_name, s.student_id AS s_sid, s.gender AS s_gender,
                    t.full_name AS t_name, $tGenderSelect
             FROM activity_logs al
             JOIN system_users su ON su.sys_uid = al.actor_uid
             LEFT JOIN students s ON s.sys_uid = al.actor_uid
             LEFT JOIN teachers t ON t.sys_uid = al.actor_uid
             WHERE al.action_type NOT IN ('login','logout')
               AND al.actor_uid != ?
             ORDER BY al.logged_at DESC
             LIMIT 10",
            [$myUid]
        );

        $out = [];
        $ids = []; // only the ones that were unread — these get marked read below
        foreach ($logs as $log) {
            $wasUnread = !(bool)$log['is_read'];
            if ($wasUnread) $ids[] = (int)$log['log_id'];

            $meta = $log['meta_json'] ? (json_decode($log['meta_json'], true) ?? []) : [];
            if (!is_array($meta)) $meta = [];

            $role = $log['actor_role'];
            $name = match ($role) {
                'student' => $log['s_name'] ?? $log['username'],
                'teacher' => $log['t_name'] ?? $log['username'],
                'admin'   => 'Administrator',
                default   => $log['username'],
            };
            $gender = match ($role) {
                'student' => $log['s_gender'] ?? null,
                'teacher' => $log['t_gender'] ?? null,
                default   => null,
            };
            $actorLabel = ($role === 'student' && !empty($log['s_sid']))
                ? $name . ' (ID: ' . $log['s_sid'] . ')'
                : $name;

            [$icon, $col] = actionMeta($log['action_type']);
            [$iconBg, $iconColor] = COL_MAP[$col] ?? COL_MAP['gray'];

            $out[] = [
                'log_id'     => (int)$log['log_id'],
                'icon'       => $icon,
                'icon_bg'    => $iconBg,
                'icon_color' => $iconColor,
                'text'       => h($actorLabel) . ' ' . h(notifText($log['action_type'], $meta, $gender)),
                'logged_at'  => $log['logged_at'],
                'unread'     => $wasUnread, // drives the "new" highlight on the frontend
            ];
        }

        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            dbQuery("UPDATE activity_logs SET is_read = 1 WHERE log_id IN ($ph)", $ids);
        }

        echo json_encode(['ok' => true, 'notifications' => $out]);
    } catch (Throwable $e) {
        error_log('EMS notif_fetch: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Failed to load notifications.']);
    }
}

fail('Unknown action.');
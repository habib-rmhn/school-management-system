<?php
/**
 * EMS Admin — Login Logs AJAX Handler  (login-logs-ajax.php)
 *
 * Actions:
 *   fetch_logs        — paginated, filtered login history
 *   fetch_filter_meta — distinct device types + role list for filter dropdowns
 *   get_uid_history   — all login attempts for one specific sys_uid (drill-in)
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

$action = trim($_POST['action'] ?? '');

/**
 * Resolve a sys_uid (which may carry the UNKNOWN_ prefix for failed
 * attempts against non-existent accounts) into a display-safe identity.
 * Returns: ['display_name', 'role', 'identifier', 'is_known']
 */
function resolveIdentity(string $sysUid): array {
    if (str_starts_with($sysUid, 'UNKNOWN_')) {
        $typed = substr($sysUid, 8); // whatever the person typed in the login box
        return [
            'display_name' => $typed !== '' ? $typed : '(blank)',
            'role'         => null,
            'identifier'   => $typed,
            'is_known'     => false,
        ];
    }

    $row = dbQuery(
        "SELECT su.role, su.username,
                s.full_name AS s_name, s.student_id,
                t.full_name AS t_name
         FROM system_users su
         LEFT JOIN students s ON s.sys_uid = su.sys_uid
         LEFT JOIN teachers t ON t.sys_uid = su.sys_uid
         WHERE su.sys_uid = ?", [$sysUid]
    )->fetch();

    if (!$row) {
        return [
            'display_name' => $sysUid,
            'role'         => null,
            'identifier'   => $sysUid,
            'is_known'     => false,
        ];
    }

    $name = match ($row['role']) {
        'student' => $row['s_name'] ?? $row['username'],
        'teacher' => $row['t_name'] ?? $row['username'],
        'admin'   => 'Administrator (' . $row['username'] . ')',
        default   => $row['username'],
    };
    $identifier = $row['role'] === 'student' ? ($row['student_id'] ?? $row['username']) : $row['username'];

    return [
        'display_name' => $name,
        'role'         => $row['role'],
        'identifier'   => $identifier,
        'is_known'     => true,
    ];
}

// ═══════════════════════════════════════════════════════════════
// ACTION: fetch_logs
// Paginated + filtered login history. All filters are optional.
// ═══════════════════════════════════════════════════════════════
if ($action === 'fetch_logs') {
    $role       = trim($_POST['role']       ?? '');           // '', student, teacher, admin, unknown
    $status     = trim($_POST['status']     ?? '');           // '', success, failed
    $device     = trim($_POST['device']     ?? '');           // device_type exact match
    $dateFrom   = trim($_POST['date_from']  ?? '');
    $dateTo     = trim($_POST['date_to']    ?? '');
    $search     = trim($_POST['search']     ?? '');            // name / username / student id / IP
    $page       = max(1, (int)($_POST['page']     ?? 1));
    $perPage    = 25;

    if ($dateFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) fail('Invalid from-date.');
    if ($dateTo   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   fail('Invalid to-date.');

    try {
        $where  = ['1=1'];
        $params = [];

        if ($status === 'success') { $where[] = 'll.success = 1'; }
        if ($status === 'failed')  { $where[] = 'll.success = 0'; }

        if ($device !== '') { $where[] = 'll.device_type = ?'; $params[] = $device; }

        if ($dateFrom !== '') { $where[] = 'll.login_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
        if ($dateTo   !== '') { $where[] = 'll.login_at <= ?'; $params[] = $dateTo   . ' 23:59:59'; }

        if ($role === 'unknown') {
            $where[] = "(su.role IS NULL OR ll.sys_uid LIKE 'UNKNOWN\\_%')";
        } elseif (in_array($role, ['student','teacher','admin'], true)) {
            $where[] = 'su.role = ?'; $params[] = $role;
        }

        if ($search !== '') {
            $where[] = "(s.full_name LIKE ? OR t.full_name LIKE ? OR su.username LIKE ?
                         OR s.student_id LIKE ? OR ll.ip_address LIKE ? OR ll.sys_uid LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);

        $total = (int)(sv(
            "SELECT COUNT(*) FROM login_logs ll
             LEFT JOIN system_users su ON su.sys_uid = ll.sys_uid
             LEFT JOIN students s ON s.sys_uid = ll.sys_uid
             LEFT JOIN teachers t ON t.sys_uid = ll.sys_uid
             WHERE $whereSql", $params
        ) ?? 0);

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;

        // teachers.gender may not exist on older schema copies — detect it
        // once so we can request it when available without breaking older
        // databases that haven't been migrated yet.
        $teacherHasGender = (bool)(sv(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'teachers' AND column_name = 'gender'"
        ) ?? 0);
        $tGenderSelect = $teacherHasGender ? "t.gender AS t_gender" : "NULL AS t_gender";

        $logs = rows(
            "SELECT ll.log_id, ll.sys_uid, ll.login_at, ll.ip_address, ll.isp, ll.location,
                    ll.device_type, ll.browser, ll.os, ll.success,
                    su.role, su.username,
                    s.full_name AS s_name, s.student_id, s.photo_path AS s_photo, s.gender AS s_gender,
                    t.full_name AS t_name, t.photo_path AS t_photo, $tGenderSelect
             FROM login_logs ll
             LEFT JOIN system_users su ON su.sys_uid = ll.sys_uid
             LEFT JOIN students s ON s.sys_uid = ll.sys_uid
             LEFT JOIN teachers t ON t.sys_uid = ll.sys_uid
             WHERE $whereSql
             ORDER BY ll.login_at DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        // Resolve display identity for each row (no raw sys_uid ever sent to browser
        // unless it's the safe UNKNOWN_ display text)
        foreach ($logs as &$row) {
            $isUnknownPrefix = str_starts_with($row['sys_uid'], 'UNKNOWN_');
            if ($isUnknownPrefix) {
                $typed = substr($row['sys_uid'], 8);
                $row['display_name'] = $typed !== '' ? $typed : '(blank)';
                $row['display_role'] = null;
                $row['display_photo'] = null;
                $row['display_gender'] = null;
                $row['is_known'] = false;
            } elseif ($row['role'] === 'student') {
                $row['display_name'] = $row['s_name'] ?? $row['username'];
                $row['display_role'] = 'student';
                $row['display_photo'] = $row['s_photo'];
                $row['display_gender'] = $row['s_gender'] ?? 'male';
                $row['display_id']   = $row['student_id'];
                $row['is_known'] = true;
            } elseif ($row['role'] === 'teacher') {
                $row['display_name'] = $row['t_name'] ?? $row['username'];
                $row['display_role'] = 'teacher';
                $row['display_photo'] = $row['t_photo'];
                $row['display_gender'] = $row['t_gender'] ?? 'male';
                $row['is_known'] = true;
            } elseif ($row['role'] === 'admin') {
                $row['display_name'] = 'Administrator';
                $row['display_role'] = 'admin';
                $row['display_photo'] = null;
                $row['display_gender'] = null;
                $row['is_known'] = true;
            } else {
                $row['display_name'] = '(deleted account)';
                $row['display_role'] = null;
                $row['display_photo'] = null;
                $row['display_gender'] = null;
                $row['is_known'] = false;
            }
            // Strip raw uid + internal join columns from response
            unset($row['s_name'], $row['t_name'], $row['s_photo'], $row['t_photo'], $row['s_gender'], $row['t_gender'], $row['username']);
            if ($isUnknownPrefix) unset($row['sys_uid']); // never leak even the literal prefix string structure
        }
        unset($row);

        // ── Summary stats for the current filter context ──────────
        $statsToday = (int)(sv(
            "SELECT COUNT(*) FROM login_logs WHERE DATE(login_at) = CURDATE()"
        ) ?? 0);
        $statsFailedToday = (int)(sv(
            "SELECT COUNT(*) FROM login_logs WHERE DATE(login_at) = CURDATE() AND success = 0"
        ) ?? 0);
        $statsWeek = (int)(sv(
            "SELECT COUNT(*) FROM login_logs WHERE login_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        ) ?? 0);
        $statsUniqueIpsWeek = (int)(sv(
            "SELECT COUNT(DISTINCT ip_address) FROM login_logs WHERE login_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        ) ?? 0);

        ok([
            'logs'        => $logs,
            'page'        => $page,
            'total_pages' => $totalPages,
            'total'       => $total,
            'per_page'    => $perPage,
            'stats'       => [
                'today'           => $statsToday,
                'failed_today'    => $statsFailedToday,
                'week'            => $statsWeek,
                'unique_ips_week' => $statsUniqueIpsWeek,
            ],
        ]);
    } catch (Throwable $e) {
        error_log('EMS fetch_logs(login): ' . $e->getMessage());
        fail('Failed to load login logs.');
    }
}

// ═══════════════════════════════════════════════════════════════
// ACTION: fetch_filter_meta
// Distinct device types currently present in the log, for the filter dropdown
// ═══════════════════════════════════════════════════════════════
if ($action === 'fetch_filter_meta') {
    try {
        $devices = rows(
            "SELECT DISTINCT device_type FROM login_logs
             WHERE device_type IS NOT NULL AND device_type != '' AND device_type != 'Unknown'
             ORDER BY device_type"
        );
        ok(['devices' => array_column($devices, 'device_type')]);
    } catch (Throwable $e) {
        fail('Failed to load filter options.');
    }
}

// ═══════════════════════════════════════════════════════════════
// ACTION: get_history_by_log
// Drill-in: every login attempt tied to the account behind one specific
// log row. The browser never receives or sends a raw sys_uid — it only
// ever has log_id (which is already public per-row), and the server
// resolves log_id -> sys_uid internally before querying history.
// (used when admin clicks "View all logins for this user")
// ═══════════════════════════════════════════════════════════════
if ($action === 'get_history_by_log') {
    $logId = (int)($_POST['log_id'] ?? 0);
    if ($logId < 1) fail('Invalid request.');

    try {
        $sysUid = sv("SELECT sys_uid FROM login_logs WHERE log_id = ?", [$logId]);
        if (!$sysUid) fail('Record not found.');

        // Only allow drilling into KNOWN accounts — never allow probing UNKNOWN_ identifiers
        if (str_starts_with($sysUid, 'UNKNOWN_')) fail('Cannot view history for unknown identifiers.');

        $identity = resolveIdentity($sysUid);
        if (!$identity['is_known']) fail('Account not found.');

        $logs = rows(
            "SELECT log_id, login_at, ip_address, isp, location, device_type, browser, os, success
             FROM login_logs WHERE sys_uid = ?
             ORDER BY login_at DESC LIMIT 50",
            [$sysUid]
        );

        $totalAttempts  = (int)(sv("SELECT COUNT(*) FROM login_logs WHERE sys_uid=?", [$sysUid]) ?? 0);
        $failedAttempts = (int)(sv("SELECT COUNT(*) FROM login_logs WHERE sys_uid=? AND success=0", [$sysUid]) ?? 0);
        $lastSuccess    = sv("SELECT MAX(login_at) FROM login_logs WHERE sys_uid=? AND success=1", [$sysUid]);

        ok([
            'identity' => $identity,
            'logs'     => $logs,
            'stats'    => [
                'total_attempts'  => $totalAttempts,
                'failed_attempts' => $failedAttempts,
                'last_success'    => $lastSuccess,
                'showing'         => count($logs),
            ],
        ]);
    } catch (Throwable $e) {
        error_log('EMS get_history_by_log: ' . $e->getMessage());
        fail('Failed to load account history.');
    }
}

// ═══════════════════════════════════════════════════════════════
// ACTION: get_uid_history
// Drill-in: every login attempt tied to one specific account
// (used when admin clicks "View all logins for this user")
// ═══════════════════════════════════════════════════════════════
if ($action === 'get_uid_history') {
    $sysUid = trim($_POST['sys_uid'] ?? '');
    if ($sysUid === '') fail('Invalid request.');
    // Only allow drilling into KNOWN accounts — never allow probing UNKNOWN_ identifiers
    if (str_starts_with($sysUid, 'UNKNOWN_')) fail('Cannot view history for unknown identifiers.');

    try {
        $identity = resolveIdentity($sysUid);
        if (!$identity['is_known']) fail('Account not found.');

        $logs = rows(
            "SELECT log_id, login_at, ip_address, isp, location, device_type, browser, os, success
             FROM login_logs WHERE sys_uid = ?
             ORDER BY login_at DESC LIMIT 50",
            [$sysUid]
        );

        $totalAttempts = (int)(sv("SELECT COUNT(*) FROM login_logs WHERE sys_uid=?", [$sysUid]) ?? 0);
        $failedAttempts = (int)(sv("SELECT COUNT(*) FROM login_logs WHERE sys_uid=? AND success=0", [$sysUid]) ?? 0);
        $lastSuccess = sv("SELECT MAX(login_at) FROM login_logs WHERE sys_uid=? AND success=1", [$sysUid]);

        ok([
            'identity' => $identity,
            'logs'     => $logs,
            'stats'    => [
                'total_attempts'  => $totalAttempts,
                'failed_attempts' => $failedAttempts,
                'last_success'    => $lastSuccess,
                'showing'         => count($logs),
            ],
        ]);
    } catch (Throwable $e) {
        error_log('EMS get_uid_history: ' . $e->getMessage());
        fail('Failed to load account history.');
    }
}

fail('Unknown action.');
<?php
/**
 * EMS — Database Connection & Core Configuration
 * Uses PDO with prepared statements for security
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'ems');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('EMS_VERSION', '1.0.0');
define('EMS_NAME', 'KKHS EMS');
define('BASE_URL', 'http://localhost');
// ── School profile DEFAULTS ──────────────────────────────────────────────
define('EMS_SCHOOL_DEFAULT', 'Khajura Kathaltala High School');
define('EMS_SCHOOL_ADDRESS_DEFAULT', 'Parkhajurs, Manirampur, Jashore');
define('EMS_SCHOOL_PHONE_DEFAULT', '017XXXXXXXX');
define('EMS_SCHOOL_EMAIL_DEFAULT', 'info@example.edu.bd');
define('EMS_LOGO', 'http://localhost/assets/images/ems-logo.jpg');
define('EMS_SCHOOL_LOGO_DEFAULT', 'http://localhost/assets/images/ems-school-logo.png');
define('EMS_FAVICONS', 'http://localhost/assets/images/favicon');

//date_default_timezone_set('Asia/Dhaka');

// Session config — must be before session_start()
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
   // ini_set('session.cookie_secure', 1); // Uncomment on HTTPS
    ini_set('session.gc_maxlifetime', 7200); // 2 hours
    session_start();
}

// PDO connection
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
          // $pdo->exec("SET time_zone = '+06:00'");
        } catch (PDOException $e) {
            error_log('EMS DB Connection Error: ' . $e->getMessage());
            http_response_code(503);
            die('<div style="font-family:sans-serif;padding:40px;text-align:center;color:#c0392b"><h2>Service Unavailable</h2><p>Database connection failed. Please contact the administrator.</p></div>');
        }
    }
    return $pdo;
}

function dbQuery(string $sql, array $params = []): PDOStatement {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
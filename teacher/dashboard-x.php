<?php
require_once __DIR__ . '/../includes/teacher-session.php';

$name        = h($_SESSION['ems_display_name'] ?? 'Teacher');
$designation = h($_SESSION['ems_designation']  ?? 'Teacher');
$uid         = $_SESSION['ems_uid'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard — EMS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --primary: #059669; --bg: #f0fdf4; --bg-card: #fff; --border: #d1fae5; --text: #0f172a; --text-muted: #64748b; --radius: 12px; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
        .topbar {
            background: linear-gradient(135deg, #047857 0%, #059669 60%, #10b981 100%);
            padding: 0 28px; height: 64px;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 2px 12px rgba(5,150,105,.25);
        }
        .topbar-brand { font-size: 18px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 10px; }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .user-pill { display: flex; align-items: center; gap: 10px; background: rgba(255,255,255,.15); border-radius: 50px; padding: 6px 14px 6px 8px; }
        .user-avatar { width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,.25); display: flex; align-items: center; justify-content: center; font-size: 13px; color: #fff; font-weight: 600; }
        .user-name { font-size: 13px; font-weight: 500; color: #fff; }
        .user-role { font-size: 11px; color: rgba(255,255,255,.75); }
        .btn-logout { background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.25); color: #fff; padding: 7px 14px; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; display: flex; align-items: center; gap: 6px; transition: background .2s; }
        .btn-logout:hover { background: rgba(255,255,255,.25); }
        .main { padding: 32px 28px; max-width: 1100px; margin: 0 auto; }
        .session-badge { background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 16px 20px; margin-bottom: 28px; font-size: 13.5px; color: #065f46; display: flex; align-items: center; gap: 10px; }
        .welcome-banner { background: var(--bg-card); border-radius: var(--radius); border: 1px solid var(--border); padding: 28px 32px; margin-bottom: 28px; display: flex; align-items: center; gap: 20px; }
        .welcome-icon { width: 56px; height: 56px; border-radius: 14px; background: #ecfdf5; display: flex; align-items: center; justify-content: center; font-size: 24px; color: var(--primary); flex-shrink: 0; }
        .welcome-title { font-size: 20px; font-weight: 700; }
        .welcome-sub { font-size: 14px; color: var(--text-muted); margin-top: 4px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px,1fr)); gap: 16px; margin-bottom: 28px; }
        .stat-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 22px 20px; display: flex; align-items: flex-start; gap: 14px; }
        .stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .stat-label { font-size: 12px; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: .04em; }
        .stat-value { font-size: 22px; font-weight: 700; margin-top: 4px; }
        .nav-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px,1fr)); gap: 14px; }
        .nav-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px 16px; text-align: center; cursor: pointer; transition: border-color .2s, box-shadow .2s, transform .15s; text-decoration: none; color: var(--text); }
        .nav-card:hover { border-color: var(--primary); box-shadow: 0 4px 16px rgba(5,150,105,.1); transform: translateY(-2px); }
        .nav-card i { font-size: 26px; color: var(--primary); display: block; margin-bottom: 10px; }
        .nav-card span { font-size: 13px; font-weight: 600; }
        @media (max-width: 600px) { .topbar { padding: 0 16px; } .main { padding: 20px 16px; } .welcome-banner { flex-direction: column; text-align: center; padding: 20px; } .user-name, .user-role { display: none; } }
    </style>
</head>
<body>
<nav class="topbar">
    <div class="topbar-brand"><i class="fas fa-school"></i> School EMS</div>
    <div class="topbar-right">
        <div class="user-pill">
            <div class="user-avatar"><?= strtoupper(substr($_SESSION['ems_display_name'] ?? 'T', 0, 1)) ?></div>
            <div>
                <div class="user-name"><?= $name ?></div>
                <div class="user-role"><?= $designation ?></div>
            </div>
        </div>
        <a href="<?= BASE_URL ?>/logout.php" class="btn-logout"><i class="fas fa-right-from-bracket"></i> Logout</a>
    </div>
</nav>
<div class="main">
    <div class="session-badge">
        <i class="fas fa-circle-info"></i>
        Logged in as Teacher &bull; UID: <?= h($uid) ?> &bull; Login time: <?= date('d M Y, h:i A', $_SESSION['ems_login_time'] ?? time()) ?>
    </div>
    <div class="welcome-banner">
        <div class="welcome-icon"><i class="fas fa-chalkboard-teacher"></i></div>
        <div>
            <div class="welcome-title">Hello, <?= $name ?>! 👋</div>
            <div class="welcome-sub"><?= $designation ?> &bull; Welcome to your teacher portal</div>
        </div>
    </div>
    <div class="stats">
        <div class="stat-card"><div class="stat-icon" style="background:#ecfdf5;color:#059669"><i class="fas fa-calendar-day"></i></div><div><div class="stat-label">Today's Classes</div><div class="stat-value">—</div></div></div>
        <div class="stat-card"><div class="stat-icon" style="background:#eff6ff;color:#1a56db"><i class="fas fa-clock"></i></div><div><div class="stat-label">Pending Marks</div><div class="stat-value">—</div></div></div>
        <div class="stat-card"><div class="stat-icon" style="background:#fefce8;color:#d97706"><i class="fas fa-users"></i></div><div><div class="stat-label">My Students</div><div class="stat-value">—</div></div></div>
    </div>
    <div class="nav-grid">
        <a href="#" class="nav-card"><i class="fas fa-calendar-week"></i><span>My Routine</span></a>
        <a href="#" class="nav-card"><i class="fas fa-sun"></i><span>Today's Classes</span></a>
        <a href="#" class="nav-card"><i class="fas fa-calendar-check"></i><span>Attendance</span></a>
        <a href="#" class="nav-card"><i class="fas fa-pencil"></i><span>Upload Marks</span></a>
        <a href="#" class="nav-card"><i class="fas fa-users"></i><span>Student List</span></a>
        <a href="#" class="nav-card"><i class="fas fa-user"></i><span>My Profile</span></a>
    </div>
</div>
</body>
</html>
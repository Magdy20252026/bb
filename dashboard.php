<?php
session_start();

// منع الدخول بدون تسجيل
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// منع التخزين المؤقت حتى لا تُعرض نسخة قديمة من الصفحة
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'config.php';

// جلب اسم الموقع وعدد المستخدمين وعدد الاشتراكات وسعر التمرينة الواحدة وعدد المشتركين الجدد
$siteName           = "Gym System";
$logoPath           = null;
$userCount          = 0;
$subscriptionCount  = 0;
$singleSessionPrice = 0.00;
$newMembersCount    = 0; // اليوم
$newMembersWeek     = 0; // خلال أسبوع
$newMembersMonth    = 0; // خلال الشهر

try {
    $stmt = $pdo->query("SELECT site_name, logo_path FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
        $logoPath = $row['logo_path'];
    }

    $stmt = $pdo->query("SELECT COUNT(*) AS c FROM users");
    $userCount = (int)$stmt->fetch()['c'];

    $stmt = $pdo->query("SELECT COUNT(*) AS c FROM subscriptions");
    $subscriptionCount = (int)$stmt->fetch()['c'];

    // سعر التمرينة الواحدة
    $stmt = $pdo->query("SELECT price FROM single_session_price ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $singleSessionPrice = (float)$row['price'];
    }

    // عدد المشتركين الجدد (اليوم)
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM members WHERE DATE(created_at) = :today");
    $stmt->execute([':today' => $today]);
    $newMembersCount = (int)$stmt->fetch()['c'];

    // عدد المشتركين الجدد خلال آخر 7 أيام (بما فيهم اليوم)
    $weekAgo = date('Y-m-d', strtotime('-6 days')); // من 6 أيام + اليوم = 7 أيام
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM members WHERE DATE(created_at) BETWEEN :weekAgo AND :today");
    $stmt->execute([':weekAgo' => $weekAgo, ':today' => $today]);
    $newMembersWeek = (int)$stmt->fetch()['c'];

    // عدد المشتركين الجدد خلال الشهر الحالي
    $monthStart = date('Y-m-01'); // أول يوم في الشهر الحالي
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM members WHERE DATE(created_at) BETWEEN :monthStart AND :today");
    $stmt->execute([':monthStart' => $monthStart, ':today' => $today]);
    $newMembersMonth = (int)$stmt->fetch()['c'];

} catch (Exception $e) {}

$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role'] ?? '';
$userId   = $_SESSION['user_id'] ?? 0;

// مصفوفة صلاحيات افتراضية (المدير يرى كل شيء)
$perms = [
    'can_view_members'        => 1,
    'can_view_renew_members'  => 1,
    'can_view_attendance'     => 1,
    'can_view_expenses'       => 1,
    'can_view_stats'          => 1,
    'can_view_settings'       => 1,
    'can_view_closing'        => 1,
];

// لو المستخدم مشرف نقرأ صلاحياته من جدول user_permissions
if ($role === 'مشرف' && $userId) {
    try {
        $stmtPerm = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            $perms['can_view_members']       = (int)$rowPerm['can_view_members'];
            $perms['can_view_renew_members'] = (int)$rowPerm['can_view_renew_members'];
            $perms['can_view_attendance']    = (int)$rowPerm['can_view_attendance'];
            $perms['can_view_expenses']      = (int)$rowPerm['can_view_expenses'];
            $perms['can_view_stats']         = (int)$rowPerm['can_view_stats'];
            $perms['can_view_settings']      = (int)$rowPerm['can_view_settings'];
            $perms['can_view_closing']       = (int)$rowPerm['can_view_closing'];
        }
    } catch (Exception $e) {
        // في حالة الخطأ نترك القيم الافتراضية (كلها 1)
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>لوحة التحكم - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }

        :root {
            --bg: #e5e7eb;
            --sidebar-bg: #f9fafb;
            --sidebar-border: #d1d5db;
            --main-bg: radial-gradient(circle at top, #e5e7eb, #e0f2fe);
            --card-bg: #ffffff;
            --text-main: #111827;
            --text-muted: #4b5563;
            --primary: #22c55e;
            --primary-soft: rgba(34, 197, 94, 0.10);
            --accent-blue: #2563eb;
            --danger: #ef4444;
            --shadow-soft: 0 20px 45px rgba(15,23,42,0.15);
        }

        body.dark {
            --bg: #020617;
            --sidebar-bg: #020617;
            --sidebar-border: #1f2937;
            --main-bg: radial-gradient(circle at top, #020617, #020617);
            --card-bg: #020617;
            --text-main: #ffffff;
            --text-muted: #e5e7eb;
            --primary: #22c55e;
            --primary-soft: rgba(34, 197, 94, 0.18);
            --accent-blue: #38bdf8;
            --danger: #fb7185;
            --shadow-soft: 0 20px 45px rgba(0,0,0,0.75);
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text-main);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-weight: 600;
            display: flex;
            transition: background 0.4s ease, color 0.3s ease;
        }

        .layout { display: flex; width: 100%; min-height: 100vh; }

        /* سويتش الثيم */
        .theme-toggle { display: flex; align-items: center; }
        .theme-switch {
            position: relative;
            width: 64px;
            height: 30px;
            border-radius: 999px;
            background: #e5e7eb;
            box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.8);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 7px;
            font-size: 14px;
            color: #6b7280;
            font-weight: 600;
        }
        .theme-switch span { z-index: 2; user-select: none; }
        .theme-thumb {
            position: absolute;
            top: 3px;
            right: 3px;
            width: 24px;
            height: 24px;
            border-radius: 999px;
            background: #facc15;
            box-shadow: 0 4px 10px rgba(250, 204, 21, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: transform 0.25s ease, background 0.25s ease, box-shadow 0.25s ease;
        }
        body.dark .theme-switch {
            background: #020617;
            box-shadow: inset 0 0 0 1px rgba(30, 64, 175, 0.9);
            color: #e5e7eb;
        }
        body.dark .theme-thumb {
            transform: translateX(-32px);
            background: #0f172a;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.9);
        }

        /* الشريط الجانبي */
        .sidebar {
            width: 270px;
            background: var(--sidebar-bg);
            border-left: 1px solid var(--sidebar-border);
            padding: 20px 18px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            box-shadow: var(--shadow-soft);
            z-index: 2;
        }

        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }

        .brand-logo {
            width: 68px;
            height: 68px;
            border-radius: 22px;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            box-shadow: 0 14px 30px rgba(15,23,42,0.25);
            overflow: hidden;
        }
        .brand-logo img {
            width: 90%;
            height: 90%;
            object-fit: contain;
        }

        .brand-text-main { font-size: 18px; font-weight: 800; }
        .brand-text-sub  { font-size: 12px; color: var(--text-muted); font-weight: 600; }

        .user-info { margin-top: 8px; font-size: 13px; color: var(--text-muted); }
        .user-info strong { color: var(--text-main); }

        .sidebar-section-title {
            font-size: 11px;
            color: var(--text-muted);
            margin: 12px 4px 8px;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-weight: 800;
        }

        .menu { display: flex; flex-direction: column; gap: 10px; }

        .menu-button {
            width: 100%;
            border-radius: 14px;
            padding: 12px 12px;
            border: none;
            background: var(--card-bg);
            color: var(--text-main);
            font-size: 14px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            box-shadow:
                0 0 0 1px var(--sidebar-border),
                0 5px 14px rgba(15,23,42,0.18);
            transition: background 0.15s, transform 0.08s, box-shadow 0.15s;
        }
        .menu-button:hover {
            transform: translateY(-1px);
            box-shadow:
                0 0 0 1px rgba(148,163,184,0.9),
                0 8px 18px rgba(15,23,42,0.25);
        }
        .menu-button.active {
            background: linear-gradient(90deg, rgba(34,197,94,0.22), rgba(34,197,94,0.10));
            box-shadow:
                0 0 0 1px rgba(34,197,94,0.95),
                0 10px 22px rgba(34,197,94,0.38);
        }

        .menu-left { display: flex; align-items: center; gap: 10px; }
        .menu-icon {
            width: 30px; height: 30px; border-radius: 999px;
            background: rgba(15,23,42,0.95);
            display:flex;align-items:center;justify-content:center;
            font-size:18px;color:#f9fafb;
        }
        .menu-label { white-space: nowrap; }

        .badge {
            font-size: 12px;
            padding: 3px 9px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: #166534;
            font-weight: 800;
        }
        body.dark .badge { color: #bbf7d0; }

        .logout-btn {
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid var(--sidebar-border);
        }
        .logout-btn button {
            width: 100%;
            border-radius: 12px;
            padding: 10px 12px;
            border: none;
            background: rgba(239,68,68,0.12);
            color: var(--danger);
            font-size: 13px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            cursor: pointer;
            box-shadow: 0 0 0 1px rgba(239,68,68,0.65);
        }
        .logout-btn button:hover { background: rgba(239,68,68,0.2); }

        /* المنطقة الرئيسية */
        .main {
            flex: 1;
            background: var(--main-bg);
            padding: 20px 22px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }

        .top-title { font-size: 20px; font-weight: 900; }
        .breadcrumbs { font-size: 12px; color: var(--text-muted); }

        .stat-cards {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 16px 18px;
            min-width: 260px;
            min-height: 110px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-soft);
        }
        .stat-main {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 6px;
            font-weight: 700;
        }
        .stat-number { font-size: 30px; font-weight: 900; }
        .stat-icon {
            width: 52px; height: 52px; border-radius: 18px;
            background: radial-gradient(circle at 30% 0, #22c55e, #16a34a);
            display:flex;align-items:center;justify-content:center;
            font-size:26px;color:#f9fafb;
        }

        .content {
            flex: 1;
            margin-top: 8px;
            background: transparent;
            border-radius: 0;
            padding: 0;
            box-shadow: none;
            color: var(--text-main);
            font-size: 14px;
        }

        /* مجموعات القوائم المنسدلة (التقفيل + صلاحيات المستخدمين) */
        .closing-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .closing-subbuttons {
            display: none; /* مخفي افتراضياً */
            flex-direction: column;
            gap: 6px;
            margin-top: 4px;
            padding-right: 38px;
        }
        .closing-subbtn {
            width: 100%;
            border-radius: 12px;
            padding: 8px 10px;
            border: none;
            background: rgba(15,23,42,0.03);
            color: var(--text-main);
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
        }
        .closing-subbtn span:first-child {
            display: flex;
            align-items: center;
            gap: 6px;
        }
    </style>
</head>
<body>
<div class="layout">
    <!-- الشريط الجانبي -->
    <aside class="sidebar">
        <div>
            <div class="brand">
                <div class="brand-logo">
                    <?php if ($logoPath): ?>
                        <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="شعار">
                    <?php else: ?>
                        <span>🏋️‍♂️</span>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="brand-text-main"><?php echo htmlspecialchars($siteName); ?></div>
                    <div class="brand-text-sub">لوحة إدارة نظام الجيم</div>
                </div>
            </div>

            <div class="user-info">
                مستخدم مسجّل: <strong><?php echo htmlspecialchars($username); ?></strong>
                — صلاحية: <strong><?php echo htmlspecialchars($role); ?></strong>
            </div>
        </div>

        <div>
            <div class="sidebar-section-title">القائمة الرئيسية</div>
            <div class="menu">
                <!-- اللوحة الرئيسية -->
                <button class="menu-button active" type="button" onclick="location.href='dashboard.php'">
                    <div class="menu-left">
                        <div class="menu-icon">📊</div>
                        <div class="menu-label">اللوحة الرئيسية</div>
                    </div>
                    <span class="badge">الآن</span>
                </button>

                <!-- إدارة المستخدمين -->
                <?php if ($role === 'مدير'): ?>
                    <button class="menu-button" type="button" onclick="location.href='users.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(37,99,235,0.95);">👥</div>
                            <div class="menu-label">اسم المستخدمين</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- الاشتراكات -->
                <button class="menu-button" type="button" onclick="location.href='subscriptions.php'">
                    <div class="menu-left">
                        <div class="menu-icon" style="background:rgba(59,130,246,0.95);">📅</div>
                        <div class="menu-label">الاشتراكات</div>
                    </div>
                </button>

                <!-- سعر التمرينة الواحدة -->
                <button class="menu-button" type="button" onclick="location.href='single_session.php'">
                    <div class="menu-left">
                        <div class="menu-icon" style="background:rgba(34,197,94,0.95);">💪</div>
                        <div class="menu-label">سعر التمرينة الواحدة</div>
                    </div>
                </button>

                <!-- المشتركين -->
                <?php if ($role === 'مدير' || $perms['can_view_members']): ?>
                    <button class="menu-button" type="button" onclick="location.href='members.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(236,72,153,0.95);">🧍</div>
                            <div class="menu-label">المشتركين</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- تجديد الاشتراكات -->
                <?php if ($role === 'مدير' || $perms['can_view_renew_members']): ?>
                    <button class="menu-button" type="button" onclick="location.href='renew_members.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(14,116,144,0.95);">🔁</div>
                            <div class="menu-label">تجديد الاشتراكات</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- حضور المشتركين -->
                <?php if ($role === 'مدير' || $perms['can_view_attendance']): ?>
                    <button class="menu-button" type="button" onclick="location.href='attendance.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(16,185,129,0.95);">✅</div>
                            <div class="menu-label">حضور المشتركين</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- صفحة الفريز الجديدة -->
                <?php if ($role === 'مدير' || $perms['can_view_members']): ?>
                    <button class="menu-button" type="button" onclick="location.href='freeze.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(107,114,128,0.95);">⏸️</div>
                            <div class="menu-label">إيقاف مؤقت (Freeze)</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- المصروفات -->
                <?php if ($role === 'مدير' || $perms['can_view_expenses']): ?>
                    <button class="menu-button" type="button" onclick="location.href='expenses.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(239,68,68,0.95);">💸</div>
                            <div class="menu-label">المصروفات</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- الإحصائيات -->
                <?php if ($role === 'مدير' || $perms['can_view_stats']): ?>
                    <button class="menu-button" type="button" onclick="location.href='stats.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(56,189,248,0.95);">📈</div>
                            <div class="menu-label">الإحصائيات</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- إعدادات الموقع -->
                <?php if ($role === 'مدير' || $perms['can_view_settings']): ?>
                    <button class="menu-button" type="button" onclick="location.href='settings.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(234,179,8,0.95);">⚙️</div>
                            <div class="menu-label">إعدادات الموقع</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- زر التقفيل -->
                <?php if ($role === 'مدير' || $perms['can_view_closing']): ?>
                    <div class="closing-group">
                        <button class="menu-button" type="button" id="btnClosingToggle">
                            <div class="menu-left">
                                <div class="menu-icon" style="background:rgba(55,65,81,0.95);">🔒</div>
                                <div class="menu-label">زر التقفيل</div>
                            </div>
                            <span id="closingArrow">＋</span>
                        </button>
                        <div class="closing-subbuttons" id="closingSubButtons">
                            <button class="closing-subbtn" type="button" onclick="location.href='close_day.php'">
                                <span>
                                    <span>📅</span>
                                    <span>تقفيل يومي</span>
                                </span>
                                <span>›</span>
                            </button>
                            <button class="closing-subbtn" type="button" onclick="location.href='close_month.php'">
                                <span>
                                    <span>🗓️</span>
                                    <span>تقفيل شهر</span>
                                </span>
                                <span>›</span>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- زر صلاحيات المستخدمين (للمدير فقط) -->
                <?php if ($role === 'مدير'): ?>
                    <div class="closing-group">
                        <button class="menu-button" type="button" id="btnPermissionsToggle">
                            <div class="menu-left">
                                <div class="menu-icon" style="background:rgba(147,51,234,0.95);">🛡️</div>
                                <div class="menu-label">صلاحيات المستخدمين</div>
                            </div>
                            <span id="permissionsArrow">＋</span>
                        </button>
                        <div class="closing-subbuttons" id="permissionsSubButtons" style="padding-right: 18px;">
                            <form method="get" action="user_permissions.php" style="width:100%;display:flex;flex-direction:column;gap:6px;">
                                <select name="user_id" required
                                        style="width:100%;padding:8px 10px;border-radius:12px;border:1px solid var(--sidebar-border);font-size:13px;">
                                    <option value="">اختر مستخدم مشرف...</option>
                                    <?php
                                    try {
                                        $stmtMods = $pdo->query("SELECT id, username FROM users WHERE role = 'مشرف' ORDER BY username ASC");
                                        while ($mod = $stmtMods->fetch(PDO::FETCH_ASSOC)) {
                                            echo '<option value="'.(int)$mod['id'].'">'.htmlspecialchars($mod['username']).'</option>';
                                        }
                                    } catch (Exception $e) {}
                                    ?>
                                </select>
                                <button type="submit" class="closing-subbtn">
                                    <span>
                                        <span>⚙️</span>
                                        <span>إدارة صلاحيات هذا الحساب</span>
                                    </span>
                                    <span>›</span>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <div class="logout-btn">
            <form method="post" action="logout.php">
                <button type="submit">
                    <span>🚪</span>
                    <span>تسجيل الخروج</span>
                </button>
            </form>
        </div>
    </aside>

    <!-- المنطقة الرئيسية -->
    <main class="main">
        <div class="top-bar">
            <div>
                <div class="top-title">اللوحة الرئيسية</div>
                <div class="breadcrumbs">نظام إدارة الجيم › لوحة التحكم</div>
            </div>

            <div class="theme-toggle">
                <div class="theme-switch" id="themeSwitch">
                    <span>🌙</span>
                    <span>☀️</span>
                    <div class="theme-thumb" id="themeThumb">☀️</div>
                </div>
            </div>
        </div>

        <!-- كروت الإحصائيات -->
        <div class="stat-cards">
            <div class="stat-card">
                <div>
                    <div class="stat-main">عدد المستخدمين</div>
                    <div class="stat-number"><?php echo $userCount; ?></div>
                </div>
                <div class="stat-icon">👥</div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-main">عدد الاشتراكات</div>
                    <div class="stat-number"><?php echo $subscriptionCount; ?></div>
                </div>
                <div class="stat-icon" style="background:radial-gradient(circle at 30% 0,#2563eb,#1d4ed8);">
                    📅
                </div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-main">سعر التمرينة الواحدة</div>
                    <div class="stat-number"><?php echo number_format($singleSessionPrice, 2); ?></div>
                </div>
                <div class="stat-icon" style="background:radial-gradient(circle at 30% 0,#f97316,#ea580c);">
                    💪
                </div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-main">المشتركين الجدد اليوم</div>
                    <div class="stat-number"><?php echo $newMembersCount; ?></div>
                </div>
                <div class="stat-icon" style="background:radial-gradient(circle at 30% 0,#ec4899,#db2777);">
                    🧍
                </div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-main">المشتركين الجدد خلال أسبوع</div>
                    <div class="stat-number"><?php echo $newMembersWeek; ?></div>
                </div>
                <div class="stat-icon" style="background:radial-gradient(circle at 30% 0,#22c55e,#16a34a);">
                    📆
                </div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-main">المشتركين الجدد خلال الشهر</div>
                    <div class="stat-number"><?php echo $newMembersMonth; ?></div>
                </div>
                <div class="stat-icon" style="background:radial-gradient(circle at 30% 0,#0ea5e9,#0369a1);">
                    📅
                </div>
            </div>
        </div>

        <div class="content"></div>
    </main>
</div>

<script>
    const body   = document.body;
    const switchEl = document.getElementById('themeSwitch');
    const savedTheme = localStorage.getItem('gymDashboardTheme') || 'light';

    function applyTheme(mode) {
        if (mode === 'dark') {
            body.classList.add('dark');
        } else {
            body.classList.remove('dark');
        }
        localStorage.setItem('gymDashboardTheme', mode);
    }

    applyTheme(savedTheme);

    if (switchEl) {
        switchEl.addEventListener('click', () => {
            const isDark = body.classList.contains('dark');
            applyTheme(isDark ? 'light' : 'dark');
        });
    }

    // فتح/غلق أزرار التقفيل اليومي والشهري
    const btnClosingToggle   = document.getElementById('btnClosingToggle');
    const closingSubButtons  = document.getElementById('closingSubButtons');
    const closingArrow       = document.getElementById('closingArrow');

    if (btnClosingToggle && closingSubButtons && closingArrow) {
        btnClosingToggle.addEventListener('click', () => {
            const isVisible = closingSubButtons.style.display === 'flex';
            if (isVisible) {
                closingSubButtons.style.display = 'none';
                closingArrow.textContent = '＋';
            } else {
                closingSubButtons.style.display = 'flex';
                closingArrow.textContent = '－';
            }
        });
    }

    // فتح/غلق زر صلاحيات المستخدمين
    const btnPermissionsToggle  = document.getElementById('btnPermissionsToggle');
    const permissionsSubButtons = document.getElementById('permissionsSubButtons');
    const permissionsArrow      = document.getElementById('permissionsArrow');

    if (btnPermissionsToggle && permissionsSubButtons && permissionsArrow) {
        btnPermissionsToggle.addEventListener('click', () => {
            const isVisible = permissionsSubButtons.style.display === 'flex';
            if (isVisible) {
                permissionsSubButtons.style.display = 'none';
                permissionsArrow.textContent = '＋';
            } else {
                permissionsSubButtons.style.display = 'flex';
                permissionsArrow.textContent = '－';
            }
        });
    }

    // منع الرجوع للخلف
    history.pushState(null, document.title, location.href);
    window.addEventListener('popstate', function () {
        history.pushState(null, document.title, location.href);
    });
</script>
</body>
</html>
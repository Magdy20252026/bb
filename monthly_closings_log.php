<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

// منع التخزين المؤقت
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$siteName = "Gym System";
try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {}

$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role'] ?? '';
$userId   = (int)($_SESSION['user_id'] ?? 0);

// نسمح فقط للمدير
if ($role !== 'مدير') {
    header("Location: dashboard.php");
    exit;
}

$errors  = [];
$records = [];

try {
    $stmt = $pdo->query("
        SELECT
            w.*,
            u.username AS closed_by_username
        FROM weekly_closings w
        LEFT JOIN users u ON u.id = w.closed_by_user_id
        ORDER BY w.closed_at DESC
    ");
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errors[] = 'حدث خطأ أثناء جلب سجل التقفيلات الشهرية.';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>سجل التقفيلات الشهرية - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }
        :root {
            --bg: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #6b7280;
            --primary: #2563eb;
            --danger: #ef4444;
            --border: #e5e7eb;
        }
        body.dark {
            --bg: #020617;
            --card-bg: #020617;
            --text-main: #ffffff;
            --text-muted: #e5e7eb;
            --primary: #38bdf8;
            --danger: #fb7185;
            --border: #1f2937;
        }
        body {
            margin:0;
            min-height:100vh;
            background:var(--bg);
            color:var(--text-main);
            font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            font-weight:900;
            font-size:16px;
        }
        .page {
            max-width:1100px;
            margin:30px auto 50px;
            padding:0 20px;
        }
        .header-bar {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:22px;
        }
        .title-main { font-size:24px;font-weight:900; }
        .title-sub { margin-top:6px;font-size:14px;color:var(--text-muted);font-weight:800; }
        .back-button {
            display:inline-flex;align-items:center;justify-content:center;gap:8px;
            padding:9px 18px;border-radius:999px;border:none;cursor:pointer;
            font-size:14px;font-weight:900;
            background:linear-gradient(90deg,#6366f1,#22c55e);color:#f9fafb;
            box-shadow:0 10px 25px rgba(79,70,229,0.45);
            text-decoration:none;
        }
        .card {
            background:var(--card-bg);
            border-radius:20px;
            padding:18px 18px 20px;
            box-shadow:0 18px 50px rgba(15,23,42,0.2),
                       0 0 0 1px rgba(255,255,255,0.6);
        }
        .alert {
            padding:10px 12px;
            border-radius:12px;
            font-size:14px;
            margin-bottom:12px;
            font-weight:900;
        }
        .alert-error {
            background:rgba(239,68,68,0.08);
            border:1px solid rgba(239,68,68,0.8);
            color:var(--danger);
        }
        table {
            width:100%;
            border-collapse:collapse;
            margin-top:10px;
            font-size:14px;
        }
        th, td {
            padding:8px 6px;
            border-bottom:1px solid var(--border);
            text-align:center;
        }
        th {
            background:rgba(15,23,42,0.03);
            font-weight:900;
            font-size:13px;
        }
        body.dark th {
            background:rgba(15,23,42,0.4);
        }
        tbody tr:hover {
            background:rgba(15,23,42,0.02);
        }
        body.dark tbody tr:hover {
            background:rgba(15,23,42,0.5);
        }
        .badge-user {
            display:inline-block;
            padding:3px 8px;
            border-radius:999px;
            background:rgba(37,99,235,0.1);
            color:#1d4ed8;
            font-size:12px;
        }
        body.dark .badge-user {
            background:rgba(59,130,246,0.2);
            color:#bfdbfe;
        }
        .theme-toggle {
            display:flex;
            justify-content:flex-end;
            margin-bottom:10px;
        }
        .theme-switch {
            position:relative;width:64px;height:30px;border-radius:999px;
            background:#e5e7eb;box-shadow:inset 0 0 0 1px rgba(148,163,184,0.9);
            cursor:pointer;display:flex;align-items:center;justify-content:space-between;
            padding:0 6px;font-size:14px;color:#6b7280;font-weight:900;
        }
        .theme-switch span { z-index:2;user-select:none; }
        .theme-thumb {
            position:absolute;top:3px;right:3px;width:24px;height:24px;border-radius:999px;
            background:#facc15;box-shadow:0 4px 10px rgba(250,204,21,0.7);
            display:flex;align-items:center;justify-content:center;font-size:14px;
            transition:transform .25s ease,background .25s ease,box-shadow .25s.ease;
        }
        body.dark .theme-switch {
            background:#020617;
            box-shadow:inset 0 0 0 1px rgba(30,64,175,0.9);
            color:#e5e7eb;
        }
        body.dark .theme-thumb {
            transform:translateX(-32px);
            background:#0f172a;
            box-shadow:0 4px 12px rgba(15,23,42,0.9);
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">سجل التقفيلات الشهرية</div>
            <div class="title-sub">
                هنا يمكنك مراجعة كل عملية تقفيل شهرية تمت، مع الأرقام التي تم تسجيلها في وقتها.
            </div>
        </div>
        <div>
            <a href="close_month.php" class="back-button">
                <span>🔙</span>
                <span>العودة لصفحة التقفيل الشهري</span>
            </a>
        </div>
    </div>

    <div class="card">
        <div class="theme-toggle">
            <div class="theme-switch" id="themeSwitch">
                <span>🌙</span>
                <span>☀️</span>
                <div class="theme-thumb" id="themeThumb">☀️</div>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e): ?>
                    <div>• <?php echo htmlspecialchars($e); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$errors && empty($records)): ?>
            <div class="alert">
                لا توجد أي تقفيلات شهرية مسجلة حتى الآن.
            </div>
        <?php endif; ?>

        <?php if (!empty($records)): ?>
            <table>
                <thead>
                <tr>
                    <th>وقت التقفيل الفعلي</th>
                    <th>المستخدم</th>
                    <th>اشتراكات جديدة (عدد / إجمالي)</th>
                    <th>سداد البواقي (عدد / إجمالي)</th>
                    <th>تجديدات (عدد / إجمالي)</th>
                    <th>حصص واحدة (عدد / إجمالي)</th>
                    <th>إجمالي المصروفات</th>
                    <th>صافي الشهر</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $c): ?>
                    <?php
                    // لو net_total = 0 نحسبه ديناميكياً (للتقفيلات القديمة)
                    $rowNet = (float)$c['net_total'];
                    if ($rowNet == 0.0) {
                        $rowNet = (
                            (float)$c['total_paid_for_new_subs'] +
                            (float)$c['total_renewals_amount'] +
                            (float)$c['total_single_sessions_amount'] +
                            (float)$c['total_partial_payments']
                        ) - (float)$c['total_expenses'];
                    }
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($c['closed_at']); ?></td>
                        <td>
                            <?php if (!empty($c['closed_by_username'])): ?>
                                <span class="badge-user"><?php echo htmlspecialchars($c['closed_by_username']); ?></span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            echo (int)$c['new_subscriptions_count'] . ' / ' .
                                 number_format((float)$c['total_paid_for_new_subs'], 2);
                            ?>
                        </td>
                        <td>
                            <?php
                            echo (int)$c['partial_payments_count'] . ' / ' .
                                 number_format((float)$c['total_partial_payments'], 2);
                            ?>
                        </td>
                        <td>
                            <?php
                            echo (int)$c['renewals_count'] . ' / ' .
                                 number_format((float)$c['total_renewals_amount'], 2);
                            ?>
                        </td>
                        <td>
                            <?php
                            echo (int)$c['single_sessions_count'] . ' / ' .
                                 number_format((float)$c['total_single_sessions_amount'], 2);
                            ?>
                        </td>
                        <td>
                            <?php echo number_format((float)$c['total_expenses'], 2); ?>
                        </td>
                        <td>
                            <?php echo number_format($rowNet, 2); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
    const body = document.body;
    const switchEl = document.getElementById('themeSwitch');
    const savedTheme = localStorage.getItem('gymDashboardTheme') || 'light';

    function applyTheme(mode) {
        if (mode === 'dark') body.classList.add('dark');
        else body.classList.remove('dark');
        localStorage.setItem('gymDashboardTheme', mode);
    }
    applyTheme(savedTheme);

    if (switchEl) {
        switchEl.addEventListener('click', () => {
            const isDark = body.classList.contains('dark');
            applyTheme(isDark ? 'light' : 'dark');
        });
    }
</script>
</body>
</html>
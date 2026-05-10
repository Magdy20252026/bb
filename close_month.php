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

// هنا نسمح فقط للمدير مثلاً
if ($role !== 'مدير') {
    header("Location: dashboard.php");
    exit;
}

$errors  = [];
$success = "";

/*
 * منطق التقفيل الشهري:
 * - نعتمد على آخر وقت تقفيل فعلي closed_at من جدول weekly_closings
 * - الإحصائيات تُحسب من بعد هذا الوقت حتى الآن
 * - المصروفات تعتمد على expenses.created_at مثل باقي الحركات
 */

// جلب آخر وقت تقفيل (closed_at) من جدول weekly_closings
$lastMonthCloseDateTime = null;
try {
    $stmt = $pdo->query("SELECT MAX(closed_at) AS last_close FROM weekly_closings");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['last_close']) {
        $lastMonthCloseDateTime = $row['last_close']; // مثال: 2026-02-01 23:59:59
    }
} catch (Exception $e) {
    $errors[] = "حدث خطأ أثناء جلب آخر وقت تقفيل شهر.";
}

$nowDateTime = date('Y-m-d H:i:s');
$today       = date('Y-m-d');

// تحديد وقت بداية الإحصائيات للفترة الحالية
if ($lastMonthCloseDateTime) {
    $periodStartDateTime = $lastMonthCloseDateTime;
} else {
    $periodStartDateTime = '1970-01-01 00:00:00';
}

// متغيرات الإحصاء
$newSubsCount  = 0;
$totalPaidNew  = 0.00;
$partialCount  = 0;
$partialTotal  = 0.00;
$renewCount    = 0;
$renewTotal    = 0.00;
$totalExpenses = 0.00;
$singleCount   = 0;
$singleTotal   = 0.00;

// حساب الإحصائيات للفترة
try {
    // اشتراكات جديدة
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS new_subscriptions_count,
            COALESCE(SUM(initial_paid_amount), 0) AS total_paid_for_new_subs
        FROM members
        WHERE created_at > :start
          AND created_at <= :end
    ");
    $stmt->execute([
        ':start' => $periodStartDateTime,
        ':end'   => $nowDateTime,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $newSubsCount = (int)$row['new_subscriptions_count'];
        $totalPaidNew = (float)$row['total_paid_for_new_subs'];
    }

    // سداد البواقي
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS partial_payments_count,
            COALESCE(SUM(paid_amount), 0) AS total_partial_payments
        FROM partial_payments
        WHERE paid_at > :start
          AND paid_at <= :end
    ");
    $stmt->execute([
        ':start' => $periodStartDateTime,
        ':end'   => $nowDateTime,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $partialCount = (int)$row['partial_payments_count'];
        $partialTotal = (float)$row['total_partial_payments'];
    }

    // التجديدات
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS renewals_count,
            COALESCE(SUM(new_subscription_amount), 0) AS total_renewals_amount
        FROM renewals_log
        WHERE renewed_at > :start
          AND renewed_at <= :end
    ");
    $stmt->execute([
        ':start' => $periodStartDateTime,
        ':end'   => $nowDateTime,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $renewCount = (int)$row['renewals_count'];
        $renewTotal = (float)$row['total_renewals_amount'];
    }

    // المصروفات من expenses (باستخدام created_at)
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(amount), 0) AS total_expenses
        FROM expenses
        WHERE created_at > :start
          AND created_at <= :end
    ");
    $stmt->execute([
        ':start' => $periodStartDateTime,
        ':end'   => $nowDateTime,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $totalExpenses = (float)$row['total_expenses'];
    }

    // حصص التمرينة الواحدة
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS single_sessions_count,
            COALESCE(SUM(single_paid), 0) AS total_single_paid
        FROM attendance
        WHERE type = 'حصة_واحدة'
          AND created_at > :start
          AND created_at <= :end
    ");
    $stmt->execute([
        ':start' => $periodStartDateTime,
        ':end'   => $nowDateTime,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $singleCount = (int)$row['single_sessions_count'];
        $singleTotal = (float)$row['total_single_paid'];
    }

    // إجمالي صافي الشهر = (اجمالي الاشتراكات + اجمالي التجديدات + اجمالي تمرينة واحدة + اجمالي سداد البواقي) - المصروفات
    $netTotal = ($totalPaidNew + $renewTotal + $singleTotal + $partialTotal) - $totalExpenses;

} catch (Exception $e) {
    $errors[] = "حدث خطأ أثناء حساب إحصائيات الفترة الحالية.";
}

// عند الضغط على "تأكيد تقفيل الشهر"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['confirm'] === '1') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO weekly_closings
                (week_start, week_end,
                 new_subscriptions_count, total_paid_for_new_subs,
                 partial_payments_count, total_partial_payments,
                 renewals_count, total_renewals_amount,
                 single_sessions_count, total_single_sessions_amount,
                 total_expenses, net_total, closed_by_user_id)
            VALUES
                (:ws, :we,
                 :new_subs, :total_new,
                 :partial_count, :partial_total,
                 :renew_count, :renew_total,
                 :single_count, :single_total,
                 :expenses, :net_total, :uid)
        ");
        $stmt->execute([
            ':ws'            => date('Y-m-d', strtotime($periodStartDateTime)),
            ':we'            => $today,
            ':new_subs'      => $newSubsCount,
            ':total_new'     => $totalPaidNew,
            ':partial_count' => $partialCount,
            ':partial_total' => $partialTotal,
            ':renew_count'   => $renewCount,
            ':renew_total'   => $renewTotal,
            ':single_count'  => $singleCount,
            ':single_total'  => $singleTotal,
            ':expenses'      => $totalExpenses,
            ':net_total'     => $netTotal,
            ':uid'           => $userId,
        ]);

        $success = "تم تقفيل الشهر بنجاح، وستبدأ الإحصائيات من جديد من الآن.";
        header("Location: close_month.php?done=1");
        exit;
    } catch (Exception $e) {
        $errors[] = "حدث خطأ أثناء حفظ بيانات تقفيل الشهر.";
    }
}

if (isset($_GET['done']) && !$success) {
    $success = "تم تقفيل الشهر بنجاح، وستبدأ الإحصائيات من جديد من الآن.";
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تقفيل شهر - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }
        :root {
            --bg: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #6b7280;
            --primary: #2563eb;
            --primary-soft: rgba(37,99,235,0.15);
            --danger: #ef4444;
            --border: #e5e7eb;
        }
        body.dark {
            --bg: #020617;
            --card-bg: #020617;
            --text-main: #ffffff;
            --text-muted: #e5e7eb;
            --primary: #38bdf8;
            --primary-soft: rgba(56,189,248,0.3);
            --danger: #fb7185;
            --border: #1f2937;
        }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text-main);
            font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            font-weight: 900;
            font-size: 18px;
        }
        .page {
            max-width: 900px;
            margin: 30px auto 50px;
            padding: 0 20px;
        }
        .header-bar {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:22px;
        }
        .title-main { font-size:26px;font-weight:900; }
        .title-sub { margin-top:6px;font-size:16px;color:var(--text-muted);font-weight:800; }
        .back-button {
            display:inline-flex;align-items:center;justify-content:center;gap:8px;
            padding:11px 22px;border-radius:999px;border:none;cursor:pointer;
            font-size:16px;font-weight:900;
            background:linear-gradient(90deg,#6366f1,#22c55e);color:#f9fafb;
            box-shadow:0 16px 38px rgba(79,70,229,0.55);
            text-decoration:none;
        }
        .back-button:hover { filter:brightness(1.05); }
        .card {
            background:var(--card-bg);
            border-radius:24px;
            padding:20px 20px 24px;
            box-shadow:0 22px 60px rgba(15,23,42,0.22),
                       0 0 0 1px rgba(255,255,255,0.65);
        }
        .theme-toggle {
            display:flex;
            justify-content:flex-end;
            margin-bottom:14px;
        }
        .theme-switch {
            position:relative;width:72px;height:34px;border-radius:999px;
            background:#e5e7eb;box-shadow:inset 0 0 0 1px rgba(148,163,184,0.9);
            cursor:pointer;display:flex;align-items:center;justify-content:space-between;
            padding:0 8px;font-size:16px;color:#6b7280;font-weight:900;
        }
        .theme-switch span { z-index:2;user-select:none; }
        .theme-thumb {
            position:absolute;top:3px;right:3px;width:26px;height:26px;border-radius:999px;
            background:#facc15;box-shadow:0 4px 10px rgba(250,204,21,0.7);
            display:flex;align-items:center;justify-content:center;font-size:16px;
            transition:transform .25s ease,background .25s.ease,box-shadow .25s.ease;
        }
        body.dark .theme-switch {
            background:#020617;
            box-shadow:inset 0 0 0 1px rgba(30,64,175,0.9);
            color:#e5e7eb;
        }
        body.dark .theme-thumb {
            transform:translateX(-36px);
            background:#0f172a;
            box-shadow:0 4px 12px rgba(15,23,42,0.9);
        }
        .alert {
            padding:11px 13px;
            border-radius:12px;
            font-size:16px;
            margin-bottom:12px;
            font-weight:900;
        }
        .alert-error {
            background:rgba(239,68,68,0.08);
            border:1px solid rgba(239,68,68,0.8);
            color:var(--danger);
        }
        .alert-success {
            background:rgba(37,197,94,0.08);
            border:1px solid rgba(37,197,94,0.8);
            color:var(--primary);
        }
        .stats-grid {
            display:grid;
            grid-template-columns: repeat(auto-fit,minmax(220px,1fr));
            gap:12px;
            margin:14px 0 18px;
        }
        .stat-card {
            border-radius:18px;
            border:1px solid var(--border);
            padding:12px 14px;
            background:rgba(15,23,42,0.01);
        }
        body.dark .stat-card {
            background:rgba(15,23,42,0.3);
        }
        .stat-label {
            font-size:14px;
            color:var(--text-muted);
            margin-bottom:4px;
        }
        .stat-value {
            font-size:22px;
            font-weight:900;
        }
        .muted { font-size:14px;color:var(--text-muted);font-weight:700;margin-top:4px; }
        .btn-confirm {
            margin-top:8px;
            border-radius:999px;
            padding:11px 22px;
            border:none;
            cursor:pointer;
            font-size:18px;
            font-weight:900;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            background:linear-gradient(90deg,#2563eb,#22c55e);
            color:#f9fafb;
            box-shadow:0 18px 40px rgba(37,99,235,0.7);
        }
        .btn-confirm:hover { filter:brightness(1.05); }
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">تقفيل شهر</div>
            <div class="title-sub">
            </div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="dashboard.php" class="back-button">
                <span>📊</span>
                <span>العودة للوحة التحكم</span>
            </a>
            <a href="monthly_closings_log.php"
               class="back-button"
               style="background:linear-gradient(90deg,#0ea5e9,#6366f1);box-shadow:0 16px 38px rgba(14,165,233,0.55);">
                <span>📜</span>
                <span>سجل التقفيلات الشهرية</span>
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

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <div class="muted">
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">عدد الاشتراكات الجديدة</div>
                <div class="stat-value"><?php echo $newSubsCount; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">إجمالي المدفوع في الاشتراكات الجديدة</div>
                <div class="stat-value"><?php echo number_format($totalPaidNew, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">عدد عمليات سداد البواقي</div>
                <div class="stat-value"><?php echo $partialCount; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">إجمالي سداد البواقي</div>
                <div class="stat-value"><?php echo number_format($partialTotal, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">عدد التجديدات</div>
                <div class="stat-value"><?php echo $renewCount; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">إجمالي مبالغ التجديدات</div>
                <div class="stat-value"><?php echo number_format($renewTotal, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">عدد حصص التمرينة الواحدة</div>
                <div class="stat-value"><?php echo $singleCount; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">إجمالي المبلغ من حصص التمرينة الواحدة</div>
                <div class="stat-value"><?php echo number_format($singleTotal, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">إجمالي المصروفات (الفترة)</div>
                <div class="stat-value"><?php echo number_format($totalExpenses, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">إجمالي صافي الشهر</div>
                <div class="stat-value"><?php echo number_format($netTotal, 2); ?></div>
            </div>
        </div>

        <div class="muted">
        </div>

        <form method="post" action="">
            <input type="hidden" name="confirm" value="1">
            <button type="submit" class="btn-confirm">
                <span>🔒</span>
                <span>تأكيد تقفيل الشهر</span>
            </button>
        </form>
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
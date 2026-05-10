<?php
session_start();

// منع الدخول بدون تسجيل
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

// جلب اسم الموقع
$siteName = "Gym System";
try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {}

$username  = $_SESSION['username'] ?? '';
$role      = $_SESSION['role'] ?? '';
$isManager = ($role === 'مدير'); // تعديل السعر فقط للمدير

$errors = [];
$success = "";

// جلب السعر الحالي (صف واحد فقط)
$currentPrice = 0.00;
try {
    $stmt = $pdo->query("SELECT id, price FROM single_session_price ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch();
    if ($row) {
        $currentId    = (int)$row['id'];
        $currentPrice = (float)$row['price'];
    } else {
        $currentId    = null;
    }
} catch (Exception $e) {
    $currentId = null;
}

// حفظ السعر الجديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isManager) {
    $price = (float)($_POST['price'] ?? 0);

    if ($price <= 0) {
        $errors[] = "من فضلك أدخل سعرًا صحيحًا للتمرينة الواحدة.";
    } else {
        try {
            if ($currentId !== null) {
                $stmt = $pdo->prepare("UPDATE single_session_price SET price = :p WHERE id = :id");
                $stmt->execute([':p' => $price, ':id' => $currentId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO single_session_price (price) VALUES (:p)");
                $stmt->execute([':p' => $price]);
            }
            $currentPrice = $price;
            $success = "تم حفظ سعر التمرينة الواحدة بنجاح.";
        } catch (Exception $e) {
            $errors[] = "حدث خطأ أثناء حفظ السعر.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>سعر التمرينة الواحدة - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }

        :root {
            --bg: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #6b7280;
            --primary: #2563eb;
            --primary-soft: rgba(37,99,235,0.12);
            --accent-green: #22c55e;
            --border: #e5e7eb;
            --input-bg: #f9fafb;
        }

        body.dark {
            --bg: #020617;
            --card-bg: #020617;
            --text-main: #ffffff;
            --text-muted: #e5e7eb;
            --primary: #38bdf8;
            --primary-soft: rgba(56,189,248,0.25);
            --accent-green: #22c55e;
            --border: #1f2937;
            --input-bg: #020617;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text-main);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-weight: 900;
            font-size: 18px;
        }

        .page {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
        }

        .title-main {
            font-size: 26px;
            font-weight: 900;
        }

        .title-sub {
            margin-top: 6px;
            font-size: 16px;
            color: var(--text-muted);
            font-weight: 800;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 22px;
            border-radius: 999px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 900;
            background: linear-gradient(90deg, #6366f1, #22c55e);
            color: #f9fafb;
            box-shadow: 0 16px 38px rgba(79,70,229,0.55);
            text-decoration: none;
        }
        .back-button:hover { filter: brightness(1.05); }

        .card {
            background: var(--card-bg);
            border-radius: 24px;
            padding: 22px 22px 24px;
            box-shadow:
                0 22px 60px rgba(15,23,42,0.22),
                0 0 0 1px rgba(255,255,255,0.65);
        }

        .theme-toggle {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 16px;
        }
        .theme-switch {
            position: relative;
            width: 72px;
            height: 34px;
            border-radius: 999px;
            background: #e5e7eb;
            box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.9);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 8px;
            font-size: 16px;
            color: #6b7280;
            font-weight: 900;
        }
        .theme-switch span { z-index: 2; user-select: none; }
        .theme-thumb {
            position: absolute;
            top: 3px;
            right: 3px;
            width: 26px;
            height: 26px;
            border-radius: 999px;
            background: #facc15;
            box-shadow: 0 4px 10px rgba(250, 204, 21, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: transform .25s ease, background .25s ease, box-shadow .25s ease;
        }
        body.dark .theme-switch {
            background: #020617;
            box-shadow: inset 0 0 0 1px rgba(30, 64, 175, 0.9);
            color: #e5e7eb;
        }
        body.dark .theme-thumb {
            transform: translateX(-36px);
            background: #0f172a;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.9);
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 14px;
        }

        .field label {
            font-size: 16px;
            color: var(--text-muted);
            font-weight: 900;
        }

        input[type="number"] {
            width: 100%;
            padding: 11px 13px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: var(--input-bg);
            font-size: 18px;
            font-weight: 800;
            color: var(--text-main);
        }

        input[type="number"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px var(--primary-soft);
        }

        .current-price {
            margin-bottom: 12px;
            font-size: 18px;
            font-weight: 900;
        }

        .alert {
            padding: 11px 13px;
            border-radius: 12px;
            font-size: 16px;
            margin-bottom: 12px;
            font-weight: 900;
        }

        .alert-error {
            background: rgba(239,68,68,0.08);
            border: 1px solid rgba(239,68,68,0.8);
            color: #ef4444;
        }

        .alert-success {
            background: rgba(34,197,94,0.08);
            border: 1px solid rgba(34,197,94,0.8);
            color: var(--accent-green);
        }

        .btn-save-main {
            margin-top: 4px;
            border-radius: 999px;
            padding: 11px 22px;
            border: none;
            cursor: pointer;
            font-size: 18px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: linear-gradient(90deg, #f97316, #ea580c);
            color: #f9fafb;
            box-shadow: 0 18px 40px rgba(234,88,12,0.7);
        }
        .btn-save-main:hover { filter: brightness(1.05); }

        .muted {
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 700;
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">سعر التمرينة الواحدة</div>
            <div class="title-sub">حدد سعر التمرينة الواحدة، ولن يتغير إلا عند تعديلك له من هذه الصفحة.</div>
        </div>
        <div>
            <a href="dashboard.php" class="back-button">
                <span>📊</span>
                <span>العودة إلى لوحة التحكم</span>
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

        <div class="current-price">
            السعر الحالي للتمرينة الواحدة:
            <strong><?php echo number_format($currentPrice, 2); ?></strong>
        </div>

        <?php if (!$isManager): ?>
            <div class="alert alert-error">
                لا تملك صلاحية تعديل سعر التمرينة (الصلاحية المطلوبة: مدير).
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="field">
                <label for="price">سعر التمرينة الواحدة</label>
                <input type="number" step="0.01" min="0" id="price" name="price"
                       value="<?php echo htmlspecialchars($currentPrice); ?>"
                       <?php echo !$isManager ? 'disabled' : ''; ?>>
                <div class="muted">يمكنك كتابة السعر بأي عملة تستخدمها في النظام.</div>
            </div>

            <?php if ($isManager): ?>
                <button type="submit" class="btn-save-main">
                    <span>💾</span>
                    <span>حفظ السعر</span>
                </button>
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
    const body = document.body;
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
</script>
</body>
</html>
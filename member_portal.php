<?php
session_start();
require_once 'config.php';

// جلب اسم الجيم واللوجو من site_settings
$siteName = "Gym System";
$logoPath = null;

try {
    $stmt = $pdo->query("SELECT site_name, logo_path FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $siteName = $row['site_name'];
        $logoPath = $row['logo_path'];
    }
} catch (Exception $e) {}

// معالجة البحث برقم الهاتف
$phoneInput = trim($_GET['phone'] ?? '');
$memberData = null;
$errorMsg   = '';

if ($phoneInput !== '') {
    try {
        $stmt = $pdo->prepare("
            SELECT
                m.*,
                s.name AS subscription_name
            FROM members m
            JOIN subscriptions s ON s.id = m.subscription_id
            WHERE m.phone = :ph
            LIMIT 1
        ");
        $stmt->execute([':ph' => $phoneInput]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            $errorMsg = 'لا يوجد مشترك بهذا رقم الهاتف.';
        } else {
            // حساب الأيام المتبقية
            $today     = new DateTime(date('Y-m-d'));
            $endDate   = $member['end_date'] ? new DateTime($member['end_date']) : null;
            $daysLeft  = null;

            if ($endDate) {
                $diff = $today->diff($endDate);
                $daysLeft = ($endDate >= $today) ? $diff->days : 0;
            }

            // بيانات الفريز من جدول members
            $freezeAllowed = (int)($member['freeze_days'] ?? 0);
            $freezeUsed    = (int)($member['used_freeze_days'] ?? 0);
            $freezeRemain  = max(0, $freezeAllowed - $freezeUsed);

            $memberData = [
                'name'               => $member['name'],
                'phone'              => $member['phone'],
                'barcode'            => $member['barcode'],
                'subscription_name'  => $member['subscription_name'],
                'start_date'         => $member['start_date'],
                'end_date'           => $member['end_date'],
                'days_left'          => $daysLeft,
                'sessions_remaining' => (int)$member['sessions_remaining'],
                'freeze_days'        => $freezeAllowed,
                'freeze_used_days'   => $freezeUsed,
                'freeze_left_days'   => $freezeRemain,
                'paid_amount'        => (float)$member['paid_amount'],
                'remaining_amount'   => (float)$member['remaining_amount'],
                'status'             => $member['status'],
            ];
        }
    } catch (Exception $e) {
        $errorMsg = 'حدث خطأ أثناء جلب بيانات المشترك.';
    }
}

// دالة توليد رابط صورة باركود (يمكن تغييرها لاحقًا لأي خدمة أخرى)
function barcodeImgUrl($text) {
    $encoded = urlencode($text);
    return "https://barcode.tec-it.com/barcode.ashx?data={$encoded}&code=Code128&dpi=96";
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>استعلام عن اشتراك - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }

        :root {
            --bg: #e5e7eb;
            --card-bg: #f9fafb;
            --text-main: #0f172a;
            --text-muted: #6b7280;
            --primary: #22c55e;
            --primary-soft: rgba(34, 197, 94, 0.12);
            --danger: #ef4444;
            --border: #d1d5db;
            --input-bg: #ffffff;
        }
        body.dark {
            --bg: #020617;
            --card-bg: #020617;
            --text-main: #f9fafb;
            --text-muted: #e5e7eb;
            --primary: #38bdf8;
            --primary-soft: rgba(56,189,248,0.25);
            --danger: #fb7185;
            --border: #1f2937;
            --input-bg: #020617;
        }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text-main);
            font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
        }

        .page {
            width: 100%;
            max-width: 960px;
            margin: 0 auto;
            padding: 16px;
        }

        .card {
            background: var(--card-bg);
            border-radius: 18px;
            padding: 14px 14px 18px;
            box-shadow: 0 10px 30px rgba(15,23,42,0.15),
                        0 0 0 1px rgba(255,255,255,0.7);
        }

        .header-bar {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:12px;
            gap:10px;
        }
        .brand {
            display:flex;
            align-items:center;
            gap:10px;
        }
        .logo {
            width:52px;height:52px;border-radius:16px;
            background:#ffffff;
            display:flex;align-items:center;justify-content:center;
            overflow:hidden;
            box-shadow:0 6px 16px rgba(15,23,42,0.25);
            flex-shrink:0;
        }
        .logo img {
            width:100%;height:100%;object-fit:contain;
        }
        .gym-name {
            font-size:18px;font-weight:900;
        }
        .gym-subtitle {
            font-size:12px;color:var(--text-muted);font-weight:700;
        }

        .theme-toggle {
            display:flex;
            justify-content:flex-end;
            flex-shrink:0;
        }
        .theme-switch {
            position:relative;
            width:60px;
            height:28px;
            border-radius:999px;
            background:#e5e7eb;
            box-shadow:inset 0 0 0 1px rgba(148,163,184,0.9);
            cursor:pointer;
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:0 6px;
            font-size:12px;
            color:#6b7280;
            font-weight:800;
        }
        .theme-switch span { z-index:2; user-select:none; }
        .theme-thumb {
            position:absolute;
            top:3px;
            right:3px;
            width:22px;
            height:22px;
            border-radius:999px;
            background:#facc15;
            box-shadow:0 4px 10px rgba(250,204,21,0.7);
            display:flex;align-items:center;justify-content:center;
            font-size:12px;
            transition:transform .25s ease,background .25s ease,box-shadow .25s ease;
        }
        body.dark .theme-switch {
            background:#020617;
            box-shadow:inset 0 0 0 1px rgba(30,64,175,0.9);
            color:#e5e7eb;
        }
        body.dark .theme-thumb {
            transform:translateX(-28px);
            background:#0f172a;
            box-shadow:0 4px 12px rgba(15,23,42,0.9);
        }

        .search-box {
            margin-top:8px;
            display:flex;
            gap:8px;
            flex-wrap:wrap;
            align-items:flex-end;
        }
        .field {
            display:flex;
            flex-direction:column;
            gap:4px;
        }
        .field label {
            font-size:13px;
            font-weight:800;
            color:var(--text-muted);
        }
        input[type="text"] {
            padding:9px 12px;
            border-radius:999px;
            border:1px solid var(--border);
            background:var(--input-bg);
            font-size:15px;
            font-weight:800;
            color:var(--text-main);
            min-width:220px;
        }
        input[type="text"]:focus {
            outline:none;
            border-color:var(--primary);
            box-shadow:0 0 0 2px var(--primary-soft);
        }

        .btn-search {
            border-radius:999px;
            padding:9px 18px;
            border:none;
            cursor:pointer;
            font-size:14px;
            font-weight:900;
            background:linear-gradient(90deg,#22c55e,#16a34a);
            color:#f9fafb;
            box-shadow:0 10px 24px rgba(22,163,74,0.7);
            white-space:nowrap;
        }
        .btn-search:hover { filter:brightness(1.06); }

        .alert {
            margin-top:10px;
            padding:9px 11px;
            border-radius:10px;
            font-size:13px;
            font-weight:800;
        }
        .alert-error {
            background:rgba(239,68,68,0.08);
            border:1px solid rgba(239,68,68,0.9);
            color:var(--danger);
        }

        .member-card {
            margin-top:12px;
            border-radius:16px;
            border:1px solid var(--border);
            padding:10px 12px;
            background:rgba(15,23,42,0.01);
        }
        body.dark .member-card {
            background:rgba(15,23,42,0.7);
        }

        .member-header {
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            flex-wrap:wrap;
            gap:8px;
            margin-bottom:8px;
        }
        .member-main {
            font-size:16px;
            font-weight:900;
        }
        .member-sub {
            font-size:13px;
            color:var(--text-muted);
            font-weight:700;
        }

        .status-badge {
            border-radius:999px;
            padding:4px 10px;
            font-size:12px;
            font-weight:900;
        }
        .status-active {
            background:rgba(22,163,74,0.15);
            color:#166534;
        }
        .status-ended {
            background:rgba(239,68,68,0.15);
            color:#b91c1c;
        }
        .status-frozen {
            background:rgba(59,130,246,0.18);
            color:#1d4ed8;
        }

        .grid {
            display:grid;
            grid-template-columns: repeat(auto-fit,minmax(160px,1fr));
            gap:8px;
        }
        .info-item {
            border-radius:12px;
            border:1px solid var(--border);
            padding:7px 9px;
            font-size:13px;
        }
        .info-label {
            color:var(--text-muted);
            font-weight:700;
            margin-bottom:2px;
        }
        .info-value {
            font-weight:900;
        }

        .barcode-box {
            display:flex;
            flex-direction:column;
            align-items:center;
            justify-content:center;
            gap:4px;
        }
        .barcode-box img {
            width:100%;
            max-width:210px;
            background:#ffffff;
            padding:5px;
            border-radius:8px;
            box-shadow:0 5px 16px rgba(15,23,42,0.3);
        }

        @media (max-width: 480px) {
            .card { padding:12px 10px 16px; }
            .gym-name { font-size:16px; }
            .search-box { flex-direction:column; align-items:stretch; }
            .btn-search { width:100%; justify-content:center; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="card">
        <div class="header-bar">
            <div class="brand">
                <div class="logo">
                    <?php if ($logoPath): ?>
                        <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="شعار الجيم">
                    <?php else: ?>
                        <span style="font-size:30px;">🏋️‍♂️</span>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="gym-name"><?php echo htmlspecialchars($siteName); ?></div>
                    <div class="gym-subtitle">استعلام حالة الاشتراك للمشتركين</div>
                </div>
            </div>
            <div class="theme-toggle">
                <div class="theme-switch" id="themeSwitch">
                    <span>🌙</span>
                    <span>☀️</span>
                    <div class="theme-thumb" id="themeThumb">☀️</div>
                </div>
            </div>
        </div>

        <!-- نموذج البحث -->
        <form method="get" action="" class="search-box">
            <div class="field">
                <label for="phone">رقم الهاتف</label>
                <input type="text" id="phone" name="phone"
                       placeholder="اكتب رقم الهاتف واضغط بحث"
                       value="<?php echo htmlspecialchars($phoneInput); ?>">
            </div>
            <button type="submit" class="btn-search">🔍 بحث</button>
        </form>

        <?php if ($errorMsg): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($errorMsg); ?>
            </div>
        <?php endif; ?>

        <?php if ($memberData): ?>
            <div class="member-card">
                <div class="member-header">
                    <div>
                        <div class="member-main">
                            <?php echo htmlspecialchars($memberData['name']); ?>
                        </div>
                        <div class="member-sub">
                            هاتف: <?php echo htmlspecialchars($memberData['phone']); ?>
                        </div>
                    </div>
                    <div>
                        <?php
                        $status = $memberData['status'];
                        $statusClass = 'status-ended';
                        $statusText  = $status;
                        if ($status === 'مستمر') {
                            $statusClass = 'status-active';
                        } elseif ($status === 'مجمد') {
                            $statusClass = 'status-frozen';
                        }
                        ?>
                        <span class="status-badge <?php echo $statusClass; ?>">
                            حالة الاشتراك: <?php echo htmlspecialchars($statusText); ?>
                        </span>
                    </div>
                </div>

                <div class="grid">
                    <div class="info-item">
                        <div class="info-label">نوع الاشتراك</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($memberData['subscription_name']); ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">تاريخ بداية الاشتراك</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($memberData['start_date']); ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">تاريخ نهاية الاشتراك</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($memberData['end_date']); ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">عدد الأيام المتبقية</div>
                        <div class="info-value">
                            <?php echo ($memberData['days_left'] !== null)
                                ? (int)$memberData['days_left']
                                : '—';
                            ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">عدد التمارين المتبقية</div>
                        <div class="info-value">
                            <?php echo (int)$memberData['sessions_remaining']; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">أيام الـ Freeze المسموحة</div>
                        <div class="info-value">
                            <?php echo (int)$memberData['freeze_days']; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">أيام الـ Freeze المستخدمة</div>
                        <div class="info-value">
                            <?php echo (int)$memberData['freeze_used_days']; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">أيام الـ Freeze المتبقية</div>
                        <div class="info-value">
                            <?php echo (int)$memberData['freeze_left_days']; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">المدفوع</div>
                        <div class="info-value">
                            <?php echo number_format($memberData['paid_amount'], 2); ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">المتبقي</div>
                        <div class="info-value">
                            <?php echo number_format($memberData['remaining_amount'], 2); ?>
                        </div>
                    </div>

                    <div class="info-item barcode-box">
                        <div class="info-label">الباركود</div>
                        <?php if (!empty($memberData['barcode'])): ?>
                            <div class="info-value">
                                <?php echo htmlspecialchars($memberData['barcode']); ?>
                            </div>
                            <img src="<?php echo htmlspecialchars(barcodeImgUrl($memberData['barcode'])); ?>"
                                 alt="باركود المشترك">
                        <?php else: ?>
                            <div class="info-value">لا يوجد باركود مسجل لهذا المشترك.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // تبديل الثيم
    const body      = document.body;
    const switchEl  = document.getElementById('themeSwitch');
    const savedTheme = localStorage.getItem('gymPublicTheme') || 'light';

    function applyTheme(mode) {
        if (mode === 'dark') body.classList.add('dark');
        else body.classList.remove('dark');
        localStorage.setItem('gymPublicTheme', mode);
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
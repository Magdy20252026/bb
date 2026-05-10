<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

$siteName = "Gym System";
try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {}

$username   = $_SESSION['username'] ?? '';
$role       = $_SESSION['role'] ?? '';
$userId     = (int)($_SESSION['user_id'] ?? 0);

// السماح للمدير أو المشرف (لو زر الصفحة ظاهر له من صلاحيات المستخدمين)
$isManager    = ($role === 'مدير');
$isSupervisor = ($role === 'مشرف');

// قراءة صلاحيات المشرف من جدول user_permissions (مثل ما يحصل في dashboard.php)
$perms = [
    'can_view_renew_members' => 0,
];

if ($isSupervisor && $userId) {
    try {
        $stmtPerm = $pdo->prepare("SELECT can_view_renew_members FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            $perms['can_view_renew_members'] = (int)$rowPerm['can_view_renew_members'];
        }
    } catch (Exception $e) {
        // في حالة الخطأ نعتبر أنه لا يملك صلاحية
        $perms['can_view_renew_members'] = 0;
    }
}

// الآن نسمح بالدخول إذا:
// - مدير، أو
// - مشرف ولديه صلاحية can_view_renew_members = 1 (يعني الزر ظاهر له)
$canAccessPage = $isManager || ($isSupervisor && $perms['can_view_renew_members'] == 1);

if (!$canAccessPage) {
    header("Location: dashboard.php");
    exit;
}

$errors  = [];
$success = "";

// جلب الاشتراكات المتاحة
$subscriptions = [];
try {
    $stmt = $pdo->query("
        SELECT id, name, days, sessions, invites, price_after_discount
        FROM subscriptions
        ORDER BY name ASC
    ");
    $subscriptions = $stmt->fetchAll();
} catch (Exception $e) {
    $errors[] = "حدث خطأ أثناء تحميل بيانات الاشتراكات.";
}

// معالجة طلب تجديد الاشتراك
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'renew'
) {
    $memberId    = (int)($_POST['member_id'] ?? 0);
    $newSubId    = (int)($_POST['subscription_id'] ?? 0);
    $confirmOld  = isset($_POST['confirm_old']) ? (int)$_POST['confirm_old'] : 0; // 0 = غير موافق, 1 = موافق
    $paidRenewal = (float)($_POST['paid_renewal'] ?? 0); // المبلغ المدفوع في عملية التجديد

    if ($memberId <= 0 || $newSubId <= 0) {
        $errors[] = "بيانات التجديد غير صحيحة.";
    } elseif ($paidRenewal < 0) {
        $errors[] = "قيمة المبلغ المدفوع في التجديد غير صحيحة.";
    } else {
        try {
            // جلب بيانات المشترك للتأكد من حالته والمتبقي القديم
            $stmt = $pdo->prepare("
                SELECT id, subscription_id, remaining_amount, subscription_amount,
                       start_date, end_date, status, paid_amount
                FROM members
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $memberId]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$member) {
                $errors[] = "المشترك المطلوب غير موجود.";
            } elseif ($member['status'] !== 'منتهي') {
                $errors[] = "حالة هذا المشترك ليست منتهية، لا يمكن تجديده من هذه الصفحة.";
            } else {
                $oldRemaining    = (float)$member['remaining_amount'];
                $oldSubId        = (int)$member['subscription_id'];
                $oldStartDate    = $member['start_date'];
                $oldEndDate      = $member['end_date'];

                // إن كان هناك متبقي قديم والمستخدم لم يؤكد، لا نكمل العملية
                if ($oldRemaining > 0 && $confirmOld !== 1) {
                    $errors[] = "لدى هذا المشترك مبلغ متبقي من الاشتراك السابق ("
                             . number_format($oldRemaining, 2)
                             . ")، من فضلك راجع المبلغ ثم أعد المحاولة واضغط على مربع التأكيد قبل التجديد.";
                } else {
                    // جلب بيانات الاشتراك الجديد
                    $subRow = null;
                    foreach ($subscriptions as $s) {
                        if ((int)$s['id'] === $newSubId) {
                            $subRow = $s;
                            break;
                        }
                    }

                    if (!$subRow) {
                        $errors[] = "الاشتراك الجديد المحدد غير موجود.";
                    } else {
                        $days     = (int)$subRow['days'];
                        $sessions = (int)$subRow['sessions'];
                        $invites  = (int)$subRow['invites'];
                        $amount   = (float)$subRow['price_after_discount'];

                        // منطق الاحتساب:
                        // المتبقي النهائي = المتبقي القديم + قيمة الاشتراك الجديد - المبلغ المدفوع عند التجديد
                        $startDate         = date('Y-m-d');
                        $endDate           = date('Y-m-d', strtotime($startDate . ' + ' . $days . ' days'));
                        $sessionsRemaining = $sessions;
                        $status            = 'مستمر';

                        $newTotalRemaining = $oldRemaining + $amount - $paidRenewal;
                        if ($newTotalRemaining < 0) {
                            $newTotalRemaining = 0;
                        }

                        // المبلغ المدفوع لهذه الدورة = مبلغ التجديد فقط
                        $paid = $paidRenewal;

                        $pdo->beginTransaction();

                        // تحديث بيانات المشترك
                        $stmt = $pdo->prepare("
                            UPDATE members
                            SET subscription_id     = :sid,
                                days                = :d,
                                sessions            = :s,
                                sessions_remaining  = :sr,
                                invites             = :i,
                                subscription_amount = :amt,
                                paid_amount         = :paid,
                                remaining_amount    = :rem,
                                start_date          = :sd,
                                end_date            = :ed,
                                status              = :st
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':sid'  => $newSubId,
                            ':d'    => $days,
                            ':s'    => $sessions,
                            ':sr'   => $sessionsRemaining,
                            ':i'    => $invites,
                            ':amt'  => $amount,
                            ':paid' => $paid,
                            ':rem'  => $newTotalRemaining,
                            ':sd'   => $startDate,
                            ':ed'   => $endDate,
                            ':st'   => $status,
                            ':id'   => $memberId,
                        ]);

                        // حفظ سجل التجديد في renewals_log
                        $stmt = $pdo->prepare("
                            INSERT INTO renewals_log
                                (member_id, old_subscription_id, new_subscription_id,
                                 old_remaining, new_subscription_amount, new_total_remaining,
                                 renewed_by_user_id)
                            VALUES
                                (:mid, :old_sid, :new_sid,
                                 :old_rem, :new_amt, :new_tot_rem,
                                 :uid)
                        ");
                        $stmt->execute([
                            ':mid'          => $memberId,
                            ':old_sid'      => $oldSubId,
                            ':new_sid'      => $newSubId,
                            ':old_rem'      => $oldRemaining,
                            ':new_amt'      => $amount,
                            ':new_tot_rem'  => $newTotalRemaining,
                            ':uid'          => $userId,
                        ]);

                        $pdo->commit();

                        $success = "تم تجديد اشتراك المشترك بنجاح. تم احتساب المبلغ المتبقي القديم مع الاشتراك الجديد وتسجيل مبلغ التجديد في سجل التجديدات.";
                    }
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = "حدث خطأ أثناء عملية التجديد.";
        }
    }
}

/*
 * بحث بالباركود أو الاسم للمشتركين المنتهين
 */
$searchTerm = '';
$endedMembers = [];
try {
    $baseSql = "
        SELECT
            m.id,
            m.name,
            m.phone,
            m.barcode,
            m.age,
            m.gender,
            m.address,
            s.name AS subscription_name,
            m.subscription_id,
            m.days,
            m.sessions,
            m.sessions_remaining,
            m.invites,
            m.subscription_amount,
            m.paid_amount,
            m.remaining_amount,
            m.start_date,
            m.end_date,
            m.status
        FROM members m
        JOIN subscriptions s ON s.id = m.subscription_id
        WHERE m.status = 'منتهي'
    ";

    $params = [];

    if (isset($_GET['search']) && $_GET['search'] !== '') {
        $searchTerm = trim($_GET['search']);
        // البحث بالباركود أو الاسم (جزئي)
        $baseSql .= " AND (m.barcode = :exact_barcode OR m.name LIKE :name_like)";
        $params[':exact_barcode'] = $searchTerm;
        $params[':name_like']     = '%' . $searchTerm . '%';
    }

    $baseSql .= " ORDER BY m.end_date DESC, m.id DESC";

    $stmt = $pdo->prepare($baseSql);
    $stmt->execute($params);
    $endedMembers = $stmt->fetchAll();
} catch (Exception $e) {
    $errors[] = "حدث خطأ أثناء تحميل قائمة المشتركين المنتهين.";
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تجديد الاشتراكات - <?php echo htmlspecialchars($siteName); ?></title>
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
            --danger: #ef4444;
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
            font-weight: 900;
            font-size: 18px;
        }
        .page { max-width: 1300px; margin: 30px auto 50px; padding: 0 22px; }
        .header-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
        .title-main{font-size:28px;font-weight:900;}
        .back-button{
            display:inline-flex;align-items:center;justify-content:center;gap:10px;
            padding:12px 24px;border-radius:999px;border:none;cursor:pointer;
            font-size:18px;font-weight:900;
            background:linear-gradient(90deg,#6366f1,#22c55e);color:#f9fafb;
            box-shadow:0 18px 40px rgba(79,70,229,0.55);text-decoration:none;
        }
        .back-button:hover{filter:brightness(1.06);}
        .card{
            background:var(--card-bg);border-radius:28px;padding:22px 24px 24px;
            box-shadow:0 24px 60px rgba(15,23,42,0.24),0 0 0 1px rgba(255,255,255,0.7);
        }
        .theme-toggle{display:flex;justify-content:flex-end;margin-bottom:16px;}
        .theme-switch{
            position:relative;width:80px;height:38px;border-radius:999px;
            background:#e5e7eb;box-shadow:inset 0 0 0 1px rgba(148,163,184,0.95);
            cursor:pointer;display:flex;align-items:center;justify-content:space-between;
            padding:0 9px;font-size:18px;color:#6b7280;font-weight:900;
        }
        .theme-switch span{z-index:2;user-select:none;}
        .theme-thumb{
            position:absolute;top:4px;right:4px;width:30px;height:30px;border-radius:999px;
            background:#facc15;box-shadow:0 4px 12px rgba(250,204,21,0.8);
            display:flex;align-items:center;justify-content:center;font-size:18px;
            transition:transform .25s ease,background .25s ease,box-shadow .25s ease;
        }
        body.dark .theme-switch{background:#020617;box-shadow:inset 0 0 0 1px rgba(30,64,175,1);color:#e5e7eb;}
        body.dark .theme-thumb{transform:translateX(-40px);background:#0f172a;box-shadow:0 4px 14px rgba(15,23,42,1);}
        .table-wrapper{margin-top:12px;border-radius:22px;border:1px solid var(--border);overflow:auto;max-height:540px;}
        table{width:100%;border-collapse:collapse;font-size:16px;}
        thead{background:rgba(15,23,42,0.04);}
        body.dark thead{background:rgba(15,23,42,0.95);}
        th,td{padding:10px 12px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap;}
        th{font-weight:900;color:var(--text-muted);font-size:16px;}
        td{font-weight:800;font-size:16px;}
        .alert{padding:12px 14px;border-radius:14px;font-size:18px;margin-bottom:14px;font-weight:900;}
        .alert-error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.9);color:var(--danger);}
        .alert-success{background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.9);color:var(--accent-green);}
        .field{display:flex;flex-direction:column;gap:6px;margin-bottom:10px;}
        .field label{font-size:16px;color:var(--text-muted);font-weight:900;}
        input[type="number"],input[type="text"],select{
            width:100%;padding:10px 13px;border-radius:999px;border:1px solid var(--border);
            background:var(--input-bg);font-size:18px;font-weight:800;color:var(--text-main);
        }
        input[type="number"]:focus,input[type="text"]:focus,select:focus{
            outline:none;border-color:var(--primary);box-shadow:0 0 0 2px var(--primary-soft);
        }
        .btn-main{
            border-radius:999px;padding:10px 22px;border:none;cursor:pointer;font-size:18px;
            font-weight:900;display:inline-flex;align-items:center;justify-content:center;gap:8px;
            background:linear-gradient(90deg,#22c55e,#16a34a);color:#f9fafb;
            box-shadow:0 20px 44px rgba(22,163,74,0.7);text-decoration:none;
        }
        .btn-main:hover{filter:brightness(1.06);}
        .search-bar{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            align-items:center;
            margin-bottom:10px;
        }
        .search-input-wrapper{
            flex:1 1 260px;
        }
        .search-button{
            border-radius:999px;
            padding:10px 20px;
            border:none;
            cursor:pointer;
            font-size:16px;
            font-weight:900;
            background:linear-gradient(90deg,#2563eb,#6366f1);
            color:#f9fafb;
            box-shadow:0 14px 30px rgba(37,99,235,0.6);
        }
        .search-button:hover{
            filter:brightness(1.06);
        }
        .search-hint{
            font-size:14px;
            color:var(--text-muted);
            font-weight:800;
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div class="title-main">تجديد الاشتراكات</div>
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

        <!-- شريط البحث بالباركود أو اسم المشترك -->
        <form method="get" action="" class="search-bar">
            <div class="search-input-wrapper">
                <input
                    type="text"
                    name="search"
                    placeholder="ابحث بالباركود أو اسم المشترك..."
                    value="<?php echo htmlspecialchars($searchTerm); ?>"
                >
            </div>
            <div>
                <button type="submit" class="search-button">بحث</button>
            </div>
            <div class="search-hint">
                اترك خانة البحث فارغة لعرض جميع الاشتراكات المنتهية.
            </div>
        </form>

        <?php if (!$endedMembers): ?>
            <p>لا توجد اشتراكات منتهية تنطبق عليها شروط البحث حالياً.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>الاسم</th>
                        <th>الهاتف</th>
                        <th>الباركود</th>
                        <th>الاشتراك الحالي</th>
                        <th>تاريخ النهاية</th>
                        <th>المتبقي القديم</th>
                        <th>تجديد الاشتراك</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($endedMembers as $m): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($m['name']); ?></td>
                            <td><?php echo htmlspecialchars($m['phone']); ?></td>
                            <td><?php echo htmlspecialchars($m['barcode']); ?></td>
                            <td><?php echo htmlspecialchars($m['subscription_name']); ?></td>
                            <td><?php echo htmlspecialchars($m['end_date']); ?></td>
                            <td><?php echo number_format($m['remaining_amount'], 2); ?></td>
                            <td>
                                <form method="post" action="" style="display:flex;flex-direction:column;gap:6px;min-width:280px;">
                                    <input type="hidden" name="action" value="renew">
                                    <input type="hidden" name="member_id" value="<?php echo (int)$m['id']; ?>">

                                    <div class="field">
                                        <label>الاشتراك الجديد</label>
                                        <select name="subscription_id" required>
                                            <option value="">اختر اشتراكاً...</option>
                                            <?php foreach ($subscriptions as $s): ?>
                                                <option value="<?php echo (int)$s['id']; ?>">
                                                    <?php echo htmlspecialchars($s['name']); ?> — سعر: <?php echo number_format($s['price_after_discount'], 2); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="field">
                                        <label>المبلغ المدفوع الآن في التجديد</label>
                                        <input type="number" step="0.01" name="paid_renewal" min="0" value="0">
                                    </div>

                                    <?php if ($m['remaining_amount'] > 0): ?>
                                        <div class="field">
                                            <label>
                                                <input type="checkbox" name="confirm_old" value="1">
                                                أؤكد مراجعة المبلغ المتبقي القديم (<?php echo number_format($m['remaining_amount'], 2); ?>)
                                                وأوافق على إضافته مع قيمة الاشتراك الجديد.
                                            </label>
                                        </div>
                                    <?php else: ?>
                                        <input type="hidden" name="confirm_old" value="1">
                                    <?php endif; ?>

                                    <button type="submit" class="btn-main">تجديد الآن</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const body      = document.body;
    const switchEl  = document.getElementById('themeSwitch');
    const savedTheme = localStorage.getItem('gymDashboardTheme') || 'light';

    function applyTheme(mode) {
        if (mode === 'dark') body.classList.add('dark'); else body.classList.remove('dark');
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
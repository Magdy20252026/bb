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

$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role'] ?? '';
$userId   = (int)($_SESSION['user_id'] ?? 0);

$isManager    = ($role === 'مدير');
$isSupervisor = ($role === 'مشرف');

// صلاحية هذه الصفحة مرتبطة بـ can_view_members
$canViewMembers = false;

if ($isManager) {
    $canViewMembers = true;
} elseif ($isSupervisor && $userId > 0) {
    try {
        $stmtPerm = $pdo->prepare("SELECT can_view_members FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            $canViewMembers = (int)$rowPerm['can_view_members'] === 1;
        } else {
            $canViewMembers = false;
        }
    } catch (Exception $e) {
        $canViewMembers = false;
    }
}

// منع الدخول إذا لم يكن له صلاحية رؤية الصفحة
if (!$canViewMembers) {
    header("Location: dashboard.php");
    exit;
}

// من الآن فصاعداً:
// - المدير يمكنه الإضافة/التعديل/الحذف
// - المشرف الذي لديه can_view_members = 1 يمكنه أيضاً
$canManageMembers = ($isManager || ($isSupervisor && $canViewMembers));

$errors  = [];
$success = "";

// جلب قائمة الاشتراكات مرة واحدة (مع freeze_days)
$subscriptions = [];
try {
    $stmt = $pdo->query("
        SELECT
            id,
            name,
            days,
            sessions,
            invites,
            price,
            price_after_discount,
            freeze_days
        FROM subscriptions
        ORDER BY name ASC
    ");
    $subscriptions = $stmt->fetchAll();
} catch (Exception $e) {}

// رسائل الاستيراد من Excel
if (isset($_SESSION['import_error'])) {
    $errors[] = $_SESSION['import_error'];
    unset($_SESSION['import_error']);
}
if (isset($_SESSION['import_success'])) {
    $success .= ($success ? ' ' : '') . $_SESSION['import_success'];
    unset($_SESSION['import_success']);
}

// معالجة الإضافة / التعديل / السداد / الحذف
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageMembers) {
    $action = $_POST['action'] ?? '';

    // إضافة أو تعديل مشترك
    if ($action === 'add_member' || $action === 'edit_member') {
        $memberId = (int)($_POST['member_id'] ?? 0);

        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $barcode = trim($_POST['barcode'] ?? '');
        $age     = (int)($_POST['age'] ?? 0);
        $gender  = $_POST['gender'] ?? '';
        $addr    = trim($_POST['address'] ?? '');
        $subId   = (int)($_POST['subscription_id'] ?? 0);
        $paid    = (float)($_POST['paid_amount'] ?? 0);
        $startDateInput = trim($_POST['start_date'] ?? '');

        if ($name === '' || $phone === '' || $age <= 0 || $addr === '' || !in_array($gender, ['ذكر','أنثى'], true)) {
            $errors[] = "من فضلك أدخل بيانات المشترك الأساسية بشكل صحيح.";
        } elseif ($subId <= 0) {
            $errors[] = "من فضلك اختر اشتراكاً صالحاً.";
        } elseif ($startDateInput === '') {
            $errors[] = "من فضلك اختر تاريخ بداية الاشتراك.";
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDateInput)) {
            $errors[] = "تنسيق تاريخ البداية غير صحيح (يجب أن يكون YYYY-MM-DD).";
        } else {
            // التحقق من عدم تكرار الباركود (إن وُجد) لمشترك آخر
            if ($barcode !== '') {
                try {
                    if ($action === 'add_member') {
                        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM members WHERE barcode = :bc");
                        $stmt->execute([':bc' => $barcode]);
                    } else {
                        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM members WHERE barcode = :bc AND id <> :id");
                        $stmt->execute([':bc' => $barcode, ':id' => $memberId]);
                    }
                    $countBarcode = (int)$stmt->fetch()['c'];
                    if ($countBarcode > 0) {
                        $errors[] = "لا يمكن تسجيل مشترك بنفس الباركود الموجود بالفعل.";
                    }
                } catch (Exception $e) {
                    $errors[] = "حدث خطأ أثناء التحقق من الباركود.";
                }
            }

            if (empty($errors)) {
                $subRow = null;
                foreach ($subscriptions as $s) {
                    if ((int)$s['id'] === $subId) {
                        $subRow = $s;
                        break;
                    }
                }
                if (!$subRow) {
                    $errors[] = "الاشتراك المحدد غير موجود.";
                } else {
                    $days     = (int)$subRow['days'];
                    $sessions = (int)$subRow['sessions'];
                    $invites  = (int)$subRow['invites'];
                    $amount   = (float)$subRow['price_after_discount'];
                    $memberFreezeDays = (int)($subRow['freeze_days'] ?? 0); // عدد أيام الفريز للمشترك (من الاشتراك)

                    if ($paid < 0 || $paid > $amount) {
                        $errors[] = "مبلغ المدفوع يجب أن يكون بين 0 وقيمة الاشتراك.";
                    } else {
                        $remaining         = $amount - $paid;
                        $sessionsRemaining = $sessions;

                        // استخدام التاريخ الذي أدخله المستخدم كبداية للاشتراك
                        $startDate = $startDateInput;
                        // حساب تاريخ النهاية حسب عدد الأيام في الاشتراك
                        $endDate   = date('Y-m-d', strtotime($startDate . ' + ' . $days . ' days'));

                        $status = 'مستمر';

                        try {
                            if ($action === 'add_member') {
                                // عند الإضافة: نخزن initial_paid_amount = المبلغ المدفوع أول مرة
                                $stmt = $pdo->prepare("
                                    INSERT INTO members
                                    (name, phone, barcode, age, gender, address, subscription_id,
                                     days, sessions, sessions_remaining, invites, freeze_days,
                                     subscription_amount, initial_paid_amount, paid_amount, remaining_amount,
                                     start_date, end_date, status)
                                    VALUES
                                    (:n,:ph,:bc,:a,:g,:ad,:sid,
                                     :d,:s,:sr,:i,:fz,
                                     :amt,:init_paid,:paid,:rem,
                                     :sd,:ed,:st)
                                ");
                            } else { // edit_member
                                if ($memberId <= 0) {
                                    $errors[] = "معرّف المشترك غير صحيح.";
                                    goto skip_member_execute;
                                }
                                // في التعديل لا نغيّر initial_paid_amount (يبقى كما تم تسجيله أول مرة)
                                $stmt = $pdo->prepare("
                                    UPDATE members
                                    SET name = :n,
                                        phone = :ph,
                                        barcode = :bc,
                                        age = :a,
                                        gender = :g,
                                        address = :ad,
                                        subscription_id = :sid,
                                        days = :d,
                                        sessions = :s,
                                        sessions_remaining = :sr,
                                        invites = :i,
                                        freeze_days = :fz,
                                        subscription_amount = :amt,
                                        paid_amount = :paid,
                                        remaining_amount = :rem,
                                        start_date = :sd,
                                        end_date = :ed,
                                        status = :st
                                    WHERE id = :mid
                                ");
                                $stmt->bindValue(':mid', $memberId, PDO::PARAM_INT);
                            }

                            if (empty($errors)) {
                                $stmt->bindValue(':n',   $name);
                                $stmt->bindValue(':ph',  $phone);
                                $stmt->bindValue(':bc',  $barcode);
                                $stmt->bindValue(':a',   $age, PDO::PARAM_INT);
                                $stmt->bindValue(':g',   $gender);
                                $stmt->bindValue(':ad',  $addr);
                                $stmt->bindValue(':sid', $subId, PDO::PARAM_INT);
                                $stmt->bindValue(':d',   $days, PDO::PARAM_INT);
                                $stmt->bindValue(':s',   $sessions, PDO::PARAM_INT);
                                $stmt->bindValue(':sr',  $sessionsRemaining, PDO::PARAM_INT);
                                $stmt->bindValue(':i',   $invites, PDO::PARAM_INT);
                                $stmt->bindValue(':fz',  $memberFreezeDays, PDO::PARAM_INT);
                                $stmt->bindValue(':amt', $amount);
                                $stmt->bindValue(':paid',$paid);
                                $stmt->bindValue(':rem', $remaining);
                                $stmt->bindValue(':sd',  $startDate);
                                $stmt->bindValue(':ed',  $endDate);
                                $stmt->bindValue(':st',  $status);

                                if ($action === 'add_member') {
                                    // نمرر initial_paid_amount فقط في حالة الإضافة
                                    $stmt->bindValue(':init_paid', $paid);
                                }

                                $stmt->execute();

                                if ($action === 'add_member') {
                                    $success = "تم إضافة المشترك بنجاح.";
                                } else {
                                    $success = "تم تعديل بيانات المشترك بنجاح.";
                                }
                            }
                        } catch (Exception $e) {
                            $errors[] = "حدث خطأ أثناء حفظ بيانات المشترك.";
                        }
                    }
                }
            }
        }
        skip_member_execute:;
    }

    // سداد الباقي
    if ($action === 'pay_rest') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $payMore  = (float)($_POST['pay_amount'] ?? 0);

        if ($memberId <= 0 || $payMore <= 0) {
            $errors[] = "بيانات السداد غير صحيحة.";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    SELECT paid_amount, remaining_amount
                    FROM members
                    WHERE id = :id
                    FOR UPDATE
                ");
                $stmt->execute([':id' => $memberId]);
                $memberRow = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$memberRow) {
                    $pdo->rollBack();
                    $errors[] = "المشترك غير موجود.";
                } else {
                    $oldPaid      = (float)$memberRow['paid_amount'];
                    $oldRemaining = (float)$memberRow['remaining_amount'];

                    if ($payMore > $oldRemaining || $payMore <= 0) {
                        $pdo->rollBack();
                        $errors[] = "مبلغ السداد أكبر من المتبقي أو غير صالح.";
                    } else {
                        $newPaid      = $oldPaid + $payMore;
                        $newRemaining = $oldRemaining - $payMore;

                        $stmt = $pdo->prepare("
                            UPDATE members
                            SET paid_amount = :newPaid,
                                remaining_amount = :newRemaining
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':newPaid'      => $newPaid,
                            ':newRemaining' => $newRemaining,
                            ':id'           => $memberId,
                        ]);

                        if ($stmt->rowCount() === 0) {
                            $pdo->rollBack();
                            $errors[] = "فشل تحديث بيانات المشترك.";
                        } else {
                            $paidByUserId = (int)($_SESSION['user_id'] ?? 0);

                            $stmt = $pdo->prepare("
                                INSERT INTO partial_payments
                                    (member_id, paid_amount, old_remaining, new_remaining, paid_by_user_id)
                                VALUES
                                    (:member_id, :paid_amount, :old_remaining, :new_remaining, :paid_by_user_id)
                            ");
                            $stmt->execute([
                                ':member_id'       => $memberId,
                                ':paid_amount'     => $payMore,
                                ':old_remaining'   => $oldRemaining,
                                ':new_remaining'   => $newRemaining,
                                ':paid_by_user_id' => $paidByUserId,
                            ]);

                            $pdo->commit();
                            $success = "تم سداد جزء من المبلغ بنجاح.";
                        }
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = "حدث خطأ أثناء عملية السداد.";
            }
        }
    }

    // حذف مشترك
    if ($action === 'delete_member') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        if ($memberId <= 0) {
            $errors[] = "معرّف المشترك غير صحيح.";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM members WHERE id = :id");
                $stmt->execute([':id' => $memberId]);
                $success = "تم حذف المشترك بنجاح.";
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء حذف المشترك.";
            }
        }
    }
}

// تحديث حالة الاشتراك (منتهي إذ�� انتهى التاريخ أو استنفذ التمرينات)
try {
    $today = date('Y-m-d');
    $pdo->query("
        UPDATE members
        SET status = 'منتهي'
        WHERE (end_date IS NOT NULL AND end_date < '$today')
           OR sessions_remaining <= 0
    ");
} catch (Exception $e) {}

// =============================
// جلب جدول المشتركين + الفلاتر
// =============================
$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status_filter'] ?? '';
$debtsFilter  = $_GET['debts_filter']  ?? '';
$members      = [];

$totalMembers        = 0;   // عدد المشتركين الظاهرين في الجدول
$totalWithDebtsCount = 0;   // عدد من عليهم مبالغ متبقية ضمن النتيجة
$totalDebtsAmount    = 0.0; // مجموع المبالغ المتبقية ضمن النتيجة

try {
    $params = [];
    $where  = [];

    if ($q !== '') {
        $where[]      = "(m.name LIKE :q OR m.phone LIKE :q OR m.barcode LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    // فلتر الحالة
    if ($statusFilter === 'active') {
        $where[] = "m.status = 'مستمر'";
    } elseif ($statusFilter === 'ended') {
        $where[] = "m.status = 'منتهي'";
    } elseif ($statusFilter === 'frozen') {
        $where[] = "m.status = 'مجمد'";
    }

    // فلتر المدفوع/المتبقي
    if ($debtsFilter === 'with_debts') {
        $where[] = "m.remaining_amount > 0";
    } elseif ($debtsFilter === 'no_debts') {
        $where[] = "m.remaining_amount <= 0";
    }

    $sql = "
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
            m.freeze_days,
            m.subscription_amount,
            m.paid_amount,
            m.remaining_amount,
            m.start_date,
            m.end_date,
            m.status
        FROM members m
        JOIN subscriptions s ON s.id = m.subscription_id
    ";

    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY m.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // حساب الإحصاءات بناءً على النتيجة الحالية
    $totalMembers = count($members);
    foreach ($members as $row) {
        if ((float)$row['remaining_amount'] > 0) {
            $totalWithDebtsCount++;
            $totalDebtsAmount += (float)$row['remaining_amount'];
        }
    }
} catch (Exception $e) {
    // يمكن لاحقاً عرض رسالة خطأ إن أردت
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>المشتركين - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        /* نفس الـ CSS السابق */
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
        body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--text-main);
            font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; font-weight: 900; font-size: 20px; }
        .page { max-width: 1300px; margin: 30px auto 50px; padding: 0 22px; }
        .header-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
        .title-main{font-size:30px;font-weight:900;}
        .back-button{
            display:inline-flex;align-items:center;justify-content:center;gap:10px;
            padding:12px 24px;border-radius:999px;border:none;cursor:pointer;
            font-size:18px;font-weight:900;
            background:linear-gradient(90deg,#6366f1,#22c55e);color:#f9fafb;
            box-shadow:0 18px 40px rgba(79,70,229,0.55);text-decoration:none;
        }
        .back-button:hover{filter:brightness(1.06);}
        .card{background:var(--card-bg);border-radius:28px;padding:22px 24px 24px;
            box-shadow:0 24px 60px rgba(15,23,42,0.24),0 0 0 1px rgba(255,255,255,0.7);}
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
        .controls { display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:12px; }
        .btn-main{
            border-radius:999px;padding:12px 24px;border:none;cursor:pointer;font-size:18px;
            font-weight:900;display:inline-flex;align-items:center;justify-content:center;gap:8px;
            background:linear-gradient(90deg,#22c55e,#16a34a);color:#f9fafb;
            box-shadow:0 20px 44px rgba(22,163,74,0.8);text-decoration:none;
        }
        .btn-main:hover{filter:brightness(1.06);}
        .alert{padding:12px 14px;border-radius:14px;font-size:18px;margin-bottom:14px;font-weight:900;}
        .alert-error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.9);color:var(--danger);}
        .alert-success{background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.9);color:var(--accent-green);}
        .table-wrapper{margin-top:12px;border-radius:22px;border:1px solid var(--border);overflow:auto;max-height:540px;}
        table{width:100%;border-collapse:collapse;font-size:16px;}
        thead{background:rgba(15,23,42,0.04);}
        body.dark thead{background:rgba(15,23,42,0.95);}
        th,td{padding:10px 12px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap;}
        th{font-weight:900;color:var(--text-muted);font-size:16px;}
        td{font-weight:800;font-size:16px;}
        .btn-pay,.btn-small-danger,.btn-small-edit{
            border-radius:999px;padding:7px 14px;border:none;cursor:pointer;
            font-size:14px;font-weight:900;color:#f9fafb;
        }
        .btn-pay{background:#22c55e;}
        .btn-small-danger{background:#ef4444;}
        .btn-small-edit{background:#f59e0b;}
        .btn-pay:hover,.btn-small-danger:hover,.btn-small-edit:hover{filter:brightness(1.07);}
        .badge-remaining-positive{color:#b91c1c;font-weight:900;font-size:16px;}
        .badge-remaining-zero{color:#16a34a;font-weight:900;font-size:16px;}
        .modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,0.5);display:none;align-items:center;justify-content:center;z-index:30;}
        .modal{background:var(--card-bg);border-radius:26px;max-width:560px;width:100%;max-height:90vh;display:flex;flex-direction:column;
            box-shadow:0 26px 70px rgba(15,23,42,0.8);}
        .modal-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px 8px;}
        .modal-title{font-size:24px;font-weight:900;}
        .modal-close{border:none;background:transparent;font-size:24px;cursor:pointer;color:var(--text-muted);}
        .modal-body{padding:0 20px 16px;overflow-y:auto;}
        .field{display:flex;flex-direction:column;gap:6px;margin-bottom:10px;}
        .field label{font-size:16px;color:var(--text-muted);font-weight:900;}
        input[type="text"],input[type="number"],select,input[type="date"]{
            width:100%;padding:10px 13px;border-radius:999px;border:1px solid var(--border);
            background:var(--input-bg);font-size:18px;font-weight:800;color:var(--text-main);
        }
        input:focus,select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 2px var(--primary-soft);}
        .muted{font-size:14px;color:var(--text-muted);font-weight:700;}
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">إدارة المشتركين</div>
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

        <!-- فورم البحث -->
        <form method="get" action="" style="margin-bottom:12px; display:flex; gap:8px;">
            <input type="text" name="q"
                   placeholder="ابحث بالاسم أو الباركود أو رقم التليفون"
                   value="<?php echo htmlspecialchars($q ?? ''); ?>"
                   style="flex:1; padding:10px 13px; border-radius:999px; border:1px solid var(--border);">
            <button type="submit" class="btn-main">بحث</button>
        </form>

        <?php
        // قراءة الفلاتر من GET (تمت قراءتها أعلى الملف أيضاً للاستخدام في الاستعلام)
        $statusFilter = $_GET['status_filter'] ?? $statusFilter ?? '';
        $debtsFilter  = $_GET['debts_filter']  ?? $debtsFilter  ?? '';
        ?>
        <!-- فلاتر إضافية -->
        <form method="get" action="" style="margin-bottom:12px; display:flex; gap:8px; flex-wrap:wrap;">
            <input type="hidden" name="q" value="<?php echo htmlspecialchars($q ?? ''); ?>">

            <select name="status_filter"
                    style="padding:10px 13px; border-radius:999px; border:1px solid var(--border); font-weight:800;">
                <option value="">كل الحالات</option>
                <option value="active"   <?php echo ($statusFilter === 'active'   ? 'selected' : ''); ?>>مشتركين مستمرين فقط</option>
                <option value="ended"    <?php echo ($statusFilter === 'ended'    ? 'selected' : ''); ?>>مشتركين منتهية</option>
                <option value="frozen"   <?php echo ($statusFilter === 'frozen'   ? 'selected' : ''); ?>>مشتركين مجمدين</option>
            </select>

            <select name="debts_filter"
                    style="padding:10px 13px; border-radius:999px; border:1px solid var(--border); font-weight:800;">
                <option value="">الكل (بغض النظر عن المتبقي)</option>
                <option value="with_debts" <?php echo ($debtsFilter === 'with_debts' ? 'selected' : ''); ?>>
                    فقط من عليهم مبالغ متبقية
                </option>
                <option value="no_debts"   <?php echo ($debtsFilter === 'no_debts' ? 'selected' : ''); ?>>
                    فقط من لا يوجد عليهم متبقي
                </option>
            </select>

            <button type="submit" class="btn-main">تطبيق الفلاتر</button>
        </form>

        <div class="controls">
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php if ($canManageMembers): ?>
                    <a href="export_members_excel.php" class="btn-main">
                        <span>📥</span>
                        <span>تصدير المشتركين (Excel)</span>
                    </a>
                    <a href="download_members_template.php" class="btn-main">
                        <span>📄</span>
                        <span>تحميل نموذج إدخال الأسماء (Excel)</span>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($canManageMembers): ?>
                <button type="button" class="btn-main" id="btnShowAddForm">
                    <span>➕</span>
                    <span>إضافة مشترك جديد</span>
                </button>
            <?php endif; ?>
        </div>

        <?php if ($canManageMembers): ?>
            <div style="margin-bottom:16px;">
                <form method="post" action="import_members_excel.php" enctype="multipart/form-data">
                    <input type="file" name="members_file" accept=".xlsx,.xls" required
                           style="margin-bottom:10px;">
                    <button type="submit" class="btn-main">
                        <span>📤</span>
                        <span>استيراد المشتركين</span>
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- إحصائيات بناءً على الفلاتر الحالية -->
        <div style="margin-bottom:10px; display:flex; flex-wrap:wrap; gap:12px;">
            <div class="alert alert-success" style="margin:0;">
                <div>عدد المشتركين في الجدول الحالي:
                    <strong><?php echo (int)$totalMembers; ?></strong>
                </div>
            </div>

            <div class="alert alert-error" style="margin:0;">
                <div>عدد المشتركين الذين عليهم مبالغ متبقية في الجدول الحالي:
                    <strong><?php echo (int)$totalWithDebtsCount; ?></strong>
                </div>
                <div>إجمالي المبالغ المتبقية عليهم:
                    <strong><?php echo number_format($totalDebtsAmount, 2); ?></strong>
                </div>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>الاسم</th>
                    <th>الهاتف</th>
                    <th>الباركود</th>
                    <th>السن</th>
                    <th>النوع</th>
                    <th>الاشتراك</th>
                    <th>الأيام</th>
                    <th>أيام الـ Freeze</th>
                    <th>التمارين المتبقية</th>
                    <th>الدعوات</th>
                    <th>المتبقي</th>
                    <th>تاريخ البداية</th>
                    <th>تاريخ النهاية</th>
                    <th>الحالة</th>
                    <th>سداد</th>
                    <th>إجراءات</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$members): ?>
                    <tr>
                        <td colspan="16" style="text-align:center;color:var(--text-muted);font-weight:800;">
                            لا يوجد مشتركين مسجلين بالمعايير الحالية.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($members as $m): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($m['name']); ?></td>
                            <td><?php echo htmlspecialchars($m['phone']); ?></td>
                            <td><?php echo htmlspecialchars($m['barcode']); ?></td>
                            <td><?php echo (int)$m['age']; ?></td>
                            <td><?php echo htmlspecialchars($m['gender']); ?></td>
                            <td><?php echo htmlspecialchars($m['subscription_name']); ?></td>
                            <td><?php echo (int)$m['days']; ?></td>
                            <td><?php echo (int)$m['freeze_days']; ?></td>
                            <td><?php echo (int)$m['sessions_remaining']; ?></td>
                            <td><?php echo (int)$m['invites']; ?></td>
                            <td>
                                <?php if ($m['remaining_amount'] > 0): ?>
                                    <span class="badge-remaining-positive">
                                        <?php echo number_format($m['remaining_amount'], 2); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge-remaining-zero">0.00</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($m['start_date']); ?></td>
                            <td><?php echo htmlspecialchars($m['end_date']); ?></td>
                            <td><?php echo htmlspecialchars($m['status']); ?></td>
                            <td>
                                <?php if ($m['remaining_amount'] > 0 && $canManageMembers): ?>
                                    <form method="post" action="" style="display:flex;gap:6px;align-items:center;">
                                        <input type="hidden" name="action" value="pay_rest">
                                        <input type="hidden" name="member_id" value="<?php echo (int)$m['id']; ?>">
                                        <input type="number" step="0.01" name="pay_amount" min="0.01"
                                               max="<?php echo (float)$m['remaining_amount']; ?>"
                                               style="width:110px;padding:7px 10px;border-radius:999px;border:1px solid var(--border);font-size:15px;font-weight:800;">
                                        <button type="submit" class="btn-pay">تسديد</button>
                                    </form>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($canManageMembers): ?>
                                    <button
                                        type="button"
                                        class="btn-small-edit"
                                        onclick="openEditModal(
                                            <?php echo (int)$m['id']; ?>,
                                            '<?php echo htmlspecialchars($m['name'], ENT_QUOTES); ?>',
                                            '<?php echo htmlspecialchars($m['phone'], ENT_QUOTES); ?>',
                                            '<?php echo htmlspecialchars($m['barcode'], ENT_QUOTES); ?>',
                                            <?php echo (int)$m['age']; ?>,
                                            '<?php echo htmlspecialchars($m['gender'], ENT_QUOTES); ?>',
                                            '<?php echo htmlspecialchars($m['address'], ENT_QUOTES); ?>',
                                            <?php echo (int)$m['subscription_id']; ?>,
                                            <?php echo (int)$m['days']; ?>,
                                            <?php echo (int)$m['freeze_days']; ?>,
                                            <?php echo (int)$m['sessions_remaining']; ?>,
                                            <?php echo (float)$m['subscription_amount']; ?>,
                                            <?php echo (float)$m['paid_amount']; ?>,
                                            '<?php echo htmlspecialchars($m['start_date'], ENT_QUOTES); ?>'
                                        )"
                                    >تعديل</button>

                                    <form method="post" action=""
                                          style="display:inline-block;margin-right:6px;"
                                          onsubmit="return confirm('هل أنت متأكد من حذف هذا المشترك؟');">
                                        <input type="hidden" name="action" value="delete_member">
                                        <input type="hidden" name="member_id" value="<?php echo (int)$m['id']; ?>">
                                        <button type="submit" class="btn-small-danger">حذف</button>
                                    </form>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($canManageMembers): ?>
<div class="modal-backdrop" id="memberModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" id="modalTitle">إضافة مشترك جديد</div>
            <button type="button" class="modal-close" id="btnCloseModal">×</button>
        </div>
        <div class="modal-body">
            <form method="post" action="" id="memberForm">
                <input type="hidden" name="action" id="formAction" value="add_member">
                <input type="hidden" name="member_id" id="memberId" value="">

                <div class="field">
                    <label for="m_name">اسم المشترك</label>
                    <input type="text" id="m_name" name="name" required>
                </div>

                <div class="field">
                    <label for="m_phone">رقم التليفون</label>
                    <input type="text" id="m_phone" name="phone" required>
                </div>

                <div class="field">
                    <label for="m_barcode">باركود المشترك</label>
                    <input type="text" id="m_barcode" name="barcode">
                </div>

                <div class="field">
                    <label for="m_age">السن</label>
                    <input type="number" id="m_age" name="age" min="1" required>
                </div>

                <div class="field">
                    <label for="m_gender">النوع</label>
                    <select id="m_gender" name="gender">
                        <option value="ذكر">ذكر</option>
                        <option value="أنثى">أنثى</option>
                    </select>
                </div>

                <div class="field">
                    <label for="m_address">العنوان</label>
                    <input type="text" id="m_address" name="address" required>
                </div>

                <div class="field">
                    <label for="m_subscription_id">الاشتراك</label>
                    <select id="m_subscription_id" name="subscription_id" required>
                        <option value="">اختر اشتراكاً...</option>
                        <?php foreach ($subscriptions as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"
                                    data-days="<?php echo (int)$s['days']; ?>"
                                    data-sessions="<?php echo (int)$s['sessions']; ?>"
                                    data-invites="<?php echo (int)$s['invites']; ?>"
                                    data-freeze="<?php echo (int)$s['freeze_days']; ?>"
                                    data-amount="<?php echo (float)$s['price_after_discount']; ?>">
                                <?php echo htmlspecialchars($s['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- تاريخ بداية الاشتراك يحدده المستخدم -->
                <div class="field">
                    <label for="m_start_date">تاريخ بداية الاشتراك</label>
                    <input type="date" id="m_start_date" name="start_date" required>
                    <div class="muted">سيتم حساب تاريخ النهاية تلقائياً حسب عدد الأيام في الاشتراك.</div>
                </div>

                <div class="field">
                    <label>عدد الأيام</label>
                    <input type="number" id="m_sub_days" disabled>
                </div>

                <div class="field">
                    <label>عدد مرات التمرين</label>
                    <input type="number" id="m_sub_sessions" disabled>
                </div>

                <div class="field">
                    <label>عدد أيام الـ Freeze</label>
                    <input type="number" id="m_sub_freeze" disabled>
                </div>

                <div class="field">
                    <label>مبلغ الاشتراك</label>
                    <input type="number" id="m_sub_amount" disabled>
                </div>

                <div class="field">
                    <label for="m_paid_amount">المدفوع</label>
                    <input type="number" step="0.01" id="m_paid_amount" name="paid_amount" min="0">
                </div>

                <button type="submit" class="btn-main" style="margin-top:8px;">
                    <span>💾</span>
                    <span id="modalSaveText">حفظ المشترك</span>
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    const body = document.body;
    const switchEl = document.getElementById('themeSwitch');
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

    <?php if ($canManageMembers): ?>
    const modal      = document.getElementById('memberModal');
    const showBtn    = document.getElementById('btnShowAddForm');
    const closeBtn   = document.getElementById('btnCloseModal');
    const form       = document.getElementById('memberForm');
    const formAction = document.getElementById('formAction');
    const memberIdEl = document.getElementById('memberId');
    const modalTitle = document.getElementById('modalTitle');
    const modalSaveText = document.getElementById('modalSaveText');

    const subSelect      = document.getElementById('m_subscription_id');
    const daysInput      = document.getElementById('m_sub_days');
    const sessInput      = document.getElementById('m_sub_sessions');
    const freezeInput    = document.getElementById('m_sub_freeze');
    const amountInput    = document.getElementById('m_sub_amount');
    const paidInput      = document.getElementById('m_paid_amount');
    const startDateInput = document.getElementById('m_start_date');

    function openModal() {
        modal.style.display = 'flex';
    }
    function closeModal() {
        modal.style.display = 'none';
        formAction.value = 'add_member';
        memberIdEl.value = '';
        modalTitle.textContent = 'إضافة مشترك جديد';
        modalSaveText.textContent = 'حفظ المشترك';
        form.reset();
        daysInput.value   = '';
        sessInput.value   = '';
        freezeInput.value = '';
        amountInput.value = '';
    }

    if (showBtn) showBtn.addEventListener('click', () => {
        closeModal();
        openModal();
    });

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
    }

    if (subSelect) {
        subSelect.addEventListener('change', function () {
            const opt    = this.options[this.selectedIndex];
            const days   = opt.getAttribute('data-days')    || '';
            const sess   = opt.getAttribute('data-sessions')|| '';
            const freeze = opt.getAttribute('data-freeze')  || '';
            const amount = opt.getAttribute('data-amount')  || '';

            daysInput.value    = days;
            sessInput.value    = sess;
            freezeInput.value  = freeze;
            amountInput.value  = amount;
            if (!paidInput.value) paidInput.value = '0';
        });
    }

    function openEditModal(id, name, phone, barcode, age, gender, address, subscriptionId, days, freezeDays, sessionsRemaining, amount, paid, startDate) {
        formAction.value = 'edit_member';
        memberIdEl.value = id;
        modalTitle.textContent = 'تعديل بيانات المشترك';
        modalSaveText.textContent = 'حفظ التعديل';

        document.getElementById('m_name').value    = name;
        document.getElementById('m_phone').value   = phone;
        document.getElementById('m_barcode').value = barcode;
        document.getElementById('m_age').value     = age;
        document.getElementById('m_gender').value  = gender;
        document.getElementById('m_address').value = address;

        subSelect.value = subscriptionId;

        daysInput.value    = days;
        freezeInput.value  = freezeDays;
        sessInput.value    = sessionsRemaining;
        amountInput.value  = amount;
        paidInput.value    = paid;

        if (startDateInput && startDate) {
            startDateInput.value = startDate;
        }

        openModal();
    }

    window.openEditModal = openEditModal;
    <?php endif; ?>
</script>
</body>
</html>
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'permissions_helper.php';

$siteName = "Gym System";
try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {}

$username             = $_SESSION['username'] ?? '';
$role                 = $_SESSION['role'] ?? '';
$userId               = (int)($_SESSION['user_id'] ?? 0);
$isManager            = ($role === 'مدير');
$isSupervisor         = ($role === 'مشرف');
$perms                = loadUserPermissions($pdo, $role, $userId);
$canAccessAttendance  = $isManager || ($isSupervisor && !empty($perms['can_view_attendance']));
$canManageAttendance  = $canAccessAttendance;

if (!$canAccessAttendance) {
    header("Location: dashboard.php");
    exit;
}

$errors  = [];
$success = "";

// جلب سعر التمرينة الواحدة (للحصة الواحدة)
$singlePrice = 0.00;
try {
    $stmt = $pdo->query("SELECT price FROM single_session_price ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $singlePrice = (float)$row['price'];
    }
} catch (Exception $e) {}

// معالجة نماذج POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageAttendance) {
    $action = $_POST['action'] ?? '';
    $attendanceCreatedAt = date('Y-m-d H:i:s');

    // 1) حضور مشترك عادي بالباركود
    if ($action === 'attendance_member') {
        $barcode    = trim($_POST['barcode'] ?? '');
        $useInvite  = isset($_POST['use_invite']) ? (int)$_POST['use_invite'] : 0;
        $guestName  = trim($_POST['guest_name'] ?? '');
        $guestPhone = trim($_POST['guest_phone'] ?? '');

        if ($barcode === '') {
            $errors[] = "من فضلك أدخل الباركود.";
        } else {
            try {
                // جلب المشترك من الباركود
                $stmt = $pdo->prepare("
                    SELECT * FROM members
                    WHERE barcode = :bc
                    LIMIT 1
                ");
                $stmt->execute([':bc' => $barcode]);
                $member = $stmt->fetch();

                if (!$member) {
                    $errors[] = "لم يتم العثور على مشترك بهذا الباركود.";
                } else {
                    // لو الاشتراك في حالة إيقاف مؤقت لا يسمح بالحضور
                    if ($member['status'] === 'موقّف') {
                        $errors[] = "اشتراك هذا المشترك في حالة إيقاف مؤقت (Freeze)، لا يمكن تسجيل الحضور الآن.";
                    } else {
                        $memberId = (int)$member['id'];
                        $today    = date('Y-m-d');

                        // منع الحضور مرتين في نفس اليوم (كـ مشترك، ليس مدعو)
                        $stmt = $pdo->prepare("
                            SELECT COUNT(*) AS c FROM attendance
                            WHERE member_id = :mid
                              AND type = 'مشترك'
                              AND DATE(created_at) = :today
                        ");
                        $stmt->execute([':mid' => $memberId, ':today' => $today]);
                        $c = (int)$stmt->fetch()['c'];

                        if ($c > 0 && $useInvite === 0) {
                            $errors[] = "هذا المشترك تم تسجيل حضوره اليوم بالفعل ولا يمكن تسجيله مرة أخرى إلا إذا استخدمت دعوة.";
                        } else {
                            // استخدام دعوة
                            if ($useInvite === 1) {
                                if ($member['invites'] <= 0) {
                                    $errors[] = "لا يوجد رصيد دعوات لهذا المشترك.";
                                } elseif ($guestName === '' || $guestPhone === '') {
                                    $errors[] = "من فضلك أدخل اسم المدعو ورقم هاتفه لاستخدام الدعوة.";
                                } else {
                                    $pdo->beginTransaction();

                                    // خصم الدعوة
                                    $stmt = $pdo->prepare("
                                        UPDATE members
                                        SET invites = invites - 1
                                        WHERE id = :id AND invites > 0
                                    ");
                                    $stmt->execute([':id' => $memberId]);

                                    if ($stmt->rowCount() === 0) {
                                        $pdo->rollBack();
                                        $errors[] = "تعذر خصم الدعوة، ربما لا يوجد رصيد دعوات كافٍ.";
                                    } else {
                                        // تسجيل كـ مدعو
                                        $stmt = $pdo->prepare("
                                            INSERT INTO attendance (member_id, type, name, phone, barcode, is_guest, notes, single_paid, created_at)
                                            VALUES (:mid, 'مدعو', :n, :ph, :bc, 1, 'حضور باستخدام دعوة', 0, :created_at)
                                        ");
                                        $stmt->execute([
                                            ':mid'        => $memberId,
                                            ':n'          => $guestName,
                                            ':ph'         => $guestPhone,
                                            ':bc'         => $barcode,
                                            ':created_at' => $attendanceCreatedAt,
                                        ]);

                                        $pdo->commit();
                                        $success = "تم تسجيل حضور المدعو باستخدام دعوة من المشترك بنجاح.";
                                    }
                                }
                            } else {
                                // حضور كمشترك عادي
                                if ($member['sessions_remaining'] <= 0) {
                                    $errors[] = "لا يوجد رصيد تمرينات متبقي لهذا المشترك.";
                                } elseif ($member['status'] !== 'مستمر') {
                                    $errors[] = "اشتراك هذا المشترك غير مستمر، لا يمكن تسجيل الحضور.";
                                } else {
                                    $pdo->beginTransaction();

                                    // خصم تمرينة
                                    $stmt = $pdo->prepare("
                                        UPDATE members
                                        SET sessions_remaining = sessions_remaining - 1
                                        WHERE id = :id AND sessions_remaining > 0
                                    ");
                                    $stmt->execute([':id' => $memberId]);

                                    if ($stmt->rowCount() === 0) {
                                        $pdo->rollBack();
                                        $errors[] = "تعذر خصم التمرينة، ربما لا يوجد رصيد تمرينات كافٍ.";
                                    } else {
                                        // تسجيل الحضور
                                        $stmt = $pdo->prepare("
                                            INSERT INTO attendance (member_id, type, name, phone, barcode, is_guest, notes, single_paid, created_at)
                                            VALUES (:mid, 'مشترك', :n, :ph, :bc, 0, NULL, 0, :created_at)
                                        ");
                                        $stmt->execute([
                                            ':mid'        => $memberId,
                                            ':n'          => $member['name'],
                                            ':ph'         => $member['phone'],
                                            ':bc'         => $member['barcode'],
                                            ':created_at' => $attendanceCreatedAt,
                                        ]);

                                        $pdo->commit();
                                        $success = "تم تسجيل حضور المشترك وخصم تمرينة واحدة بنجاح.";
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = "حدث خطأ أثناء تسجيل الحضور.";
            }
        }
    }

    // 2) تسجيل مشترك حصة واحدة
    if ($action === 'single_session') {
        $name  = trim($_POST['single_name'] ?? '');
        $phone = trim($_POST['single_phone'] ?? '');
        $paid  = (float)($_POST['single_paid'] ?? 0);

        if ($name === '' || $phone === '') {
            $errors[] = "من فضلك أدخل اسم ورقم هاتف المشترك للحصة الواحدة.";
        } elseif ($paid <= 0) {
            $errors[] = "المبلغ المدفوع يجب أن يكون أكبر من صفر.";
        } elseif ($singlePrice <= 0) {
            $errors[] = "سعر التمرينة الواحدة غير مضبوط. من فضلك حدده أولاً.";
        } else {
            try {
                $oldDebt = 0.0;

                // قراءة آخر ملاحظة لحصة واحدة لهذا الهاتف لمعرفة المتبقي القديم
                $stmt = $pdo->prepare("
                    SELECT notes, single_paid FROM attendance
                    WHERE phone = :ph AND type = 'حصة_واحدة'
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute([':ph' => $phone]);
                if ($row = $stmt->fetch()) {
                    if (preg_match('/المتبقي=([0-9.]+)/u', $row['notes'], $m)) {
                        $oldDebt = (float)$m[1];
                    }
                }

                $totalPrice = $singlePrice;
                $required   = $totalPrice + $oldDebt;

                if ($oldDebt > 0 && $paid < $required) {
                    $errors[] = "تنبيه: يوجد مبلغ متبقي سابق قدره {$oldDebt}. يجب سداد المبلغ القديم + الجديد ({$required}) قبل تسجيل الحضور.";
                }

                if (empty($errors)) {
                    $remaining = max(0, $required - $paid);
                    // مهم: نكتب "المدفوع=" بهذه الصيغة ليستطيع REGEXP قراءتها
                    $notes = "حصة واحدة: السعر={$totalPrice}, المتبقي_قديم={$oldDebt}, المدفوع={$paid}, المتبقي={$remaining}";

                    $stmt = $pdo->prepare("
                        INSERT INTO attendance (member_id, type, name, phone, barcode, is_guest, notes, single_paid, created_at)
                        VALUES (NULL, 'حصة_واحدة', :n, :ph, NULL, 0, :nt, :paid, :created_at)
                    ");
                    $stmt->execute([
                        ':n'          => $name,
                        ':ph'         => $phone,
                        ':nt'         => $notes,
                        ':paid'       => $paid,
                        ':created_at' => $attendanceCreatedAt,
                    ]);

                    $success = "تم تسجيل حضور مشترك حصة واحدة بنجاح.";
                }
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء تسجيل مشترك الحصة الواحدة.";
            }
        }
    }

    // 3) حذف حضور (مشترك أو حصة واحدة) - مسموح للمدير فقط
    if ($action === 'delete_attendance' && $canManageAttendance) {
        $attId = (int)($_POST['attendance_id'] ?? 0);

        if ($attId <= 0) {
            $errors[] = "معرّف الحضور غير صحيح.";
        } else {
            try {
                $pdo->beginTransaction();

                // جلب سجل الحضور
                $stmt = $pdo->prepare("
                    SELECT id, member_id, type, single_paid
                    FROM attendance
                    WHERE id = :id
                    FOR UPDATE
                ");
                $stmt->execute([':id' => $attId]);
                $att = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$att) {
                    $pdo->rollBack();
                    $errors[] = "سجل الحضور غير موجود.";
                } else {
                    $memberId   = (int)$att['member_id'];
                    $attType    = $att['type'];

                    // في حالة حضور مشترك: نرجع له التمرينة
                    if ($attType === 'مشترك' && $memberId > 0) {
                        $stmt = $pdo->prepare("
                            UPDATE members
                            SET sessions_remaining = sessions_remaining + 1
                            WHERE id = :mid
                        ");
                        $stmt->execute([':mid' => $memberId]);
                    }

                    // حذف سجل الحضور
                    $stmt = $pdo->prepare("DELETE FROM attendance WHERE id = :id");
                    $stmt->execute([':id' => $attId]);

                    $pdo->commit();
                    $success = "تم حذف سجل الحضور بنجاح.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = "حدث خطأ أثناء حذف الحضور.";
            }
        }
    }
}

// جلب جدول حضور اليوم مع كل بيانات المشتركين (مضاف freeze_days)
$today = date('Y-m-d');
$attendanceList = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.member_id,
            a.type,
            a.name          AS att_name,
            a.phone         AS att_phone,
            a.barcode       AS att_barcode,
            a.is_guest,
            a.notes,
            a.created_at,

            m.name          AS m_name,
            m.phone         AS m_phone,
            m.barcode       AS m_barcode,
            m.age           AS m_age,
            m.gender        AS m_gender,
            m.address       AS m_address,
            m.days          AS m_days,
            m.sessions      AS m_sessions,
            m.sessions_remaining AS m_sessions_remaining,
            m.invites       AS m_invites,
            m.freeze_days   AS m_freeze_days,
            m.subscription_amount AS m_subscription_amount,
            m.paid_amount   AS m_paid_amount,
            m.remaining_amount AS m_remaining_amount,
            m.start_date    AS m_start_date,
            m.end_date      AS m_end_date,
            m.status        AS m_status,
            s.name          AS m_subscription_name
        FROM attendance a
        LEFT JOIN members m ON m.id = a.member_id
        LEFT JOIN subscriptions s ON s.id = m.subscription_id
        WHERE DATE(a.created_at) = :today
        ORDER BY a.id DESC
    ");
    $stmt->execute([':today' => $today]);
    $attendanceList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>حضور المشتركين - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }
        :root {
            --bg: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #6b7280;
            --primary: #22c55e;
            --primary-soft: rgba(34,197,94,0.15);
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
        .page { max-width: 1400px; margin: 30px auto 40px; padding: 0 20px; }
        .header-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
        .title-main { font-size:30px; font-weight:900; }
        .back-button {
            display:inline-flex;align-items:center;justify-content:center;gap:8px;
            padding:12px 24px;border-radius:999px;border:none;cursor:pointer;
            font-size:18px;font-weight:900;
            background:linear-gradient(90deg,#6366f1,#22c55e);color:#f9fafb;
            box-shadow:0 18px 40px rgba(79,70,229,0.55);text-decoration:none;
        }
        .back-button:hover { filter:brightness(1.05); }
        .card {
            background:var(--card-bg);border-radius:26px;padding:22px 24px 24px;
            box-shadow:0 24px 60px rgba(15,23,42,0.22),0 0 0 1px rgba(255,255,255,0.6);
            margin-bottom:20px;
        }
        .theme-toggle{display:flex;justify-content:flex-end;margin-bottom:14px;}
        .theme-switch{
            position:relative;width:78px;height:36px;border-radius:999px;
            background:#e5e7eb;box-shadow:inset 0 0 0 1px rgba(148,163,184,0.9);
            cursor:pointer;display:flex;align-items:center;justify-content:space-between;
            padding:0 8px;font-size:17px;color:#6b7280;font-weight:900;
        }
        .theme-switch span{z-index:2;user-select:none;}
        .theme-thumb{
            position:absolute;top:4px;right:4px;width:28px;height:28px;border-radius:999px;
            background:#facc15;box-shadow:0 4px 10px rgba(250,204,21,0.7);
            display:flex;align-items:center;justify-content:center;font-size:17px;
            transition:transform .25s ease,background .25s ease,box-shadow .25s.ease;
        }
        body.dark .theme-switch{background:#020617;box-shadow:inset 0 0 0 1pxrgba(30,64,175,0.9);color:#e5e7eb;}
        body.dark .theme-thumb{transform:translateX(-38px);background:#0f172a;box-shadow:0 4px 12px rgba(15,23,42,0.9);}
        .alert{padding:12px 14px;border-radius:14px;font-size:18px;margin-bottom:12px;font-weight:900;}
        .alert-error{background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.9);color:var(--danger);}
        .alert-success{background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.9);color:var(--primary);}
        .row { display:flex;flex-wrap:wrap;gap:18px; }
        .col-half { flex:1 1 420px; }
        .field{display:flex;flex-direction:column;gap:6px;margin-bottom:10px;}
        .field label{font-size:17px;color:var(--text-muted);font-weight:900;}
        input[type="text"],input[type="number"],input[type="tel"]{
            width:100%;padding:11px 15px;border-radius:999px;border:1px solid var(--border);
            background:var(--input-bg);font-size:18px;font-weight:800;color:var(--text-main);
        }
        input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 2px var(--primary-soft);}
        .btn-main{
            border-radius:999px;padding:11px 22px;border:none;cursor:pointer;font-size:18px;
            font-weight:900;display:inline-flex;align-items:center;justify-content:center;gap:8px;
            background:linear-gradient(90deg,#22c55e,#16a34a);color:#f9fafb;
            box-shadow:0 20px 44px rgba(22,163,74,0.7);text-decoration:none;
        }
        .btn-main:hover{filter:brightness(1.06);}
        .btn-secondary{
            border-radius:999px;padding:10px 18px;border:none;cursor:pointer;font-size:16px;
            font-weight:900;background:#e5e7eb;color:#374151;
        }
        body.dark .btn-secondary{background:#111827;color:#e5e7eb;}
        .table-wrapper{
            margin-top:12px;
            border-radius:24px;
            border:1px solid var(--border);
            overflow:auto;
            max-height:650px;
        }
        table{
            width:100%;
            border-collapse:collapse;
            font-size:16px;
            min-width:1300px;
        }
        thead{background:rgba(15,23,42,0.04);}
        body.dark thead{background:rgba(15,23,42,0.9);}
        th,td{
            padding:11px 13px;
            border-bottom:1px solid var(--border);
            text-align:right;
            white-space:nowrap;
        }
        th{
            font-weight:900;
            color:var(--text-muted);
            font-size:15px;
        }
        td{
            font-weight:800;
            font-size:15px;
        }
        .tag-type{
            border-radius:999px;
            padding:4px 10px;
            font-size:13px;
            font-weight:900;
            display:inline-block;
        }
        .tag-member{background:rgba(34,197,94,0.16);color:#15803d;}
        .tag-guest{background:rgba(59,130,246,0.18);color:#1d4ed8;}
        .tag-single{background:rgba(249,115,22,0.2);color:#c2410c;}
        .small-muted{font-size:14px;color:var(--text-muted);font-weight:700;}
        #cameraArea { margin-top:10px; display:none; }
        #reader { width: 100%; max-width: 380px; margin-top:8px; }
        #stopScanBtn { margin-top:8px; }
    </style>
    <!-- تحميل مكتبة html5-qrcode من مجلد admin/assets -->
    <script src="assets/html5-qrcode.min.js"></script>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div class="title-main">حضور المشتركين</div>
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
                <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="row">
            <!-- حضور مشترك / دعوة -->
            <div class="col-half">
                <h3 style="margin:0 0 10px;font-size:20px;">تسجيل حضور مشترك بالباركود</h3>
                <form method="post" action="" autocomplete="off">
                    <input type="hidden" name="action" value="attendance_member">

                    <div class="field">
                        <label for="barcode">باركود المشترك</label>
                        <input type="text" id="barcode" name="barcode"
                               placeholder="اكتب أو امسح الباركود ثم اضغط Enter"
                               autofocus>
                        <div class="small-muted">
                        </div>
                    </div>

                    <div class="field">
                        <label>
                            <input type="checkbox" name="use_invite" value="1" id="useInviteCheckbox">
                            استخدام دعوة من رصيد المشترك (تسجيل مدعو)
                        </label>
                    </div>

                    <div id="guestFields" style="display:none;">
                        <div class="field">
                            <label for="guest_name">اسم المدعو</label>
                            <input type="text" id="guest_name" name="guest_name">
                        </div>
                        <div class="field">
                            <label for="guest_phone">رقم هاتف المدعو</label>
                            <input type="tel" id="guest_phone" name="guest_phone">
                        </div>
                    </div>

                    <button type="submit" class="btn-main" style="margin-top:8px;">
                        <span>✅</span>
                        <span>تسجيل الحضور</span>
                    </button>

                    <!-- زر مسح الباركود بالكاميرا (موبايل أو كمبيوتر) -->
                    <button type="button" class="btn-secondary" id="btnOpenCamera" style="margin-right:8px;">
                        📷 مسح الباركود بالكاميرا
                    </button>

                    <div id="cameraArea">
                        <div class="small-muted">
                            وجّه الكاميرا إلى الباركود، وعند قراءة الكود سيتم تعبئة حقل الباركود تلقائياً.
                        </div>
                        <div id="reader"></div>
                        <button type="button" class="btn-secondary" id="stopScanBtn">
                            إيقاف الكاميرا
                        </button>
                    </div>
                </form>
            </div>

            <!-- مشترك حصة واحدة -->
            <div class="col-half">
                <h3 style="margin:0 0 10px;font-size:20px;">تسجيل مشترك حصة واحدة</h3>
                <form method="post" action="">
                    <input type="hidden" name="action" value="single_session">

                    <div class="field">
                        <label for="single_name">اسم المشترك</label>
                        <input type="text" id="single_name" name="single_name" required>
                    </div>

                    <div class="field">
                        <label for="single_phone">رقم الهاتف</label>
                        <input type="tel" id="single_phone" name="single_phone" required>
                    </div>

                    <!-- تنبيه المبلغ المتبقي السابق -->
                    <div class="field" id="single_debt_alert" style="display:none;">
                        <div class="alert alert-error" style="margin-bottom:0;">
                            <span id="single_debt_text"></span>
                        </div>
                    </div>

                    <div class="field">
                        <label for="single_paid">المبلغ المدفوع</label>
                        <input type="number" step="0.01" id="single_paid" name="single_paid" min="0.01" required>
                        <div class="small-muted">
                            سعر التمرينة الواحدة الحالي: <strong><?php echo number_format($singlePrice, 2); ?></strong>
                        </div>
                    </div>

                    <button type="submit" class="btn-main" style="margin-top:8px;background:linear-gradient(90deg,#f97316,#ea580c);box-shadow:0 20px 44px rgba(234,88,12,0.7);">
                        <span>💪</span>
                        <span>تسجيل حصة واحدة</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- جدول حضور اليوم + تصدير إكسل -->
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <div style="font-size:18px;">
                سجل الحضور ليوم:
                <strong><?php echo htmlspecialchars($today); ?></strong>
            </div>
            <form method="get" action="export_attendance_excel.php" style="margin:0;">
                <input type="hidden" name="date" value="<?php echo htmlspecialchars($today); ?>">
                <button type="submit" class="btn-secondary">
                    📥 تصدير كشف الحضور (Excel)
                </button>
            </form>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>الاسم</th>
                    <th>الهاتف</th>
                    <th>الباركود</th>
                    <th>النوع</th>
                    <th>ملاحظات</th>
                    <th>وقت الحضور</th>

                    <th>السن</th>
                    <th>النوع </th>
                    <th>العنوان</th>
                    <th>اسم الاشتراك</th>
                    <th>الأيام</th>
                    <th>أيام الـ Freeze</th>
                    <th>إجمالي التمرينات</th>
                    <th>التمرينات المتبقية</th>
                    <th>الدعوات</th>
                    <th>مبلغ الاشتراك</th>
                    <th>المدفوع</th>
                    <th>المتبقي</th>
                    <th>تاريخ البداية</th>
                    <th>تاريخ النهاية</th>
                    <th>حالة الاشتراك</th>
                    <?php if ($canManageAttendance): ?>
                        <th>إجراءات</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php if (!$attendanceList): ?>
                    <tr>
                        <td colspan="<?php echo $canManageAttendance ? 23 : 22; ?>" style="text-align:center;color:var(--text-muted);font-weight:800;font-size:18px;padding:18px 0;">
                            لا يوجد حضور مسجل اليوم حتى الآن.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($attendanceList as $row): ?>
                        <tr>
                            <td><?php echo (int)$row['id']; ?></td>

                            <!-- الاسم (الحضور + المشتركين في نفس العمود) -->
                            <td>
                                <?php echo htmlspecialchars($row['att_name']); ?>
                                <?php if (!empty($row['m_name']) && $row['m_name'] !== $row['att_name']): ?>
                                    <div class="small-muted">
                                        (في المشتركين: <?php echo htmlspecialchars($row['m_name']); ?>)
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- الهاتف (الحضور + المشتركين في نفس العمود) -->
                            <td>
                                <?php echo htmlspecialchars($row['att_phone']); ?>
                                <?php if (!empty($row['m_phone']) && $row['m_phone'] !== $row['att_phone']): ?>
                                    <div class="small-muted">
                                        (في المشتركين: <?php echo htmlspecialchars($row['m_phone']); ?>)
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- الباركود (الحضور + المشتركين في نفس العمود) -->
                            <td>
                                <?php echo htmlspecialchars($row['att_barcode']); ?>
                                <?php if (!empty($row['m_barcode']) && $row['m_barcode'] !== $row['att_barcode']): ?>
                                    <div class="small-muted">
                                        (في المشتركين: <?php echo htmlspecialchars($row['m_barcode']); ?>)
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($row['type'] === 'مشترك'): ?>
                                    <span class="tag-type tag-member">مشترك</span>
                                <?php elseif ($row['type'] === 'مدعو'): ?>
                                    <span class="tag-type tag-guest">مدعو</span>
                                <?php else: ?>
                                    <span class="tag-type tag-single">حصة واحدة</span>
                                <?php endif; ?>
                            </td>

                            <td><?php echo htmlspecialchars($row['notes'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['created_at']); ?></td>

                            <td><?php echo htmlspecialchars($row['m_age'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_gender'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_address'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_subscription_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_days'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_freeze_days'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_sessions'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_sessions_remaining'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_invites'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_subscription_amount'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_paid_amount'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_remaining_amount'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_start_date'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_end_date'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_status'] ?? ''); ?></td>

                            <?php if ($canManageAttendance): ?>
                                <td>
                                    <form method="post" action=""
                                          onsubmit="return confirm('هل أنت متأكد من حذف هذا الحضور؟');"
                                          style="display:inline;">
                                        <input type="hidden" name="action" value="delete_attendance">
                                        <input type="hidden" name="attendance_id" value="<?php echo (int)$row['id']; ?>">
                                        <button type="submit"
                                                style="border:none;border-radius:999px;padding:6px 12px;
                                                       background:#ef4444;color:#fff;font-weight:900;
                                                       cursor:pointer;font-size:13px;">
                                            حذف
                                        </button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
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

    // إظهار / إخفاء حقول المدعو عند استخدام الدعوة
    const useInviteCheckbox = document.getElementById('useInviteCheckbox');
    const guestFields       = document.getElementById('guestFields');
    if (useInviteCheckbox && guestFields) {
        useInviteCheckbox.addEventListener('change', () => {
            guestFields.style.display = useInviteCheckbox.checked ? 'block' : 'none';
        });
    }

    // مسح الباركود بالكاميرا باستخدام html5-qrcode
    let html5QrCode = null;
    const btnOpenCamera = document.getElementById('btnOpenCamera');
    const cameraArea    = document.getElementById('cameraArea');
    const stopScanBtn   = document.getElementById('stopScanBtn');
    const barcodeInput  = document.getElementById('barcode');

    // مراجع النموذج
    const attendanceForm = barcodeInput ? barcodeInput.form : null;

    // إرسال النموذج تلقائياً عند الضغط على Enter في حقل الباركود
    if (barcodeInput && attendanceForm) {
        barcodeInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                attendanceForm.submit();
            }
        });
    }

    function startScanner() {
        if (!window.Html5Qrcode) {
            alert("مكتبة قراءة الباركود بالكاميرا غير محملة. تأكد من وجود ملف html5-qrcode.min.js في مجلد assets واستدعائه بشكل صحيح.");
            return;
        }

        cameraArea.style.display = 'block';

        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode("reader");
        }

        const config = {
            fps: 10,
            qrbox: { width: 250, height: 250 }
        };

        Html5Qrcode.getCameras().then(devices => {
            if (!devices || devices.length === 0) {
                alert("لم يتم العثور على أي كاميرا على هذا الجهاز.");
                return;
            }

            // نحاول اختيار الكاميرا الخلفية قدر الإمكان
            let cameraId = devices[0].id;

            // أولوية 1: كاميرا اسمها فيه back أو rear أو environment
            const backCam = devices.find(d =>
                /back|rear|environment/i.test(d.label || '')
            );
            if (backCam) {
                cameraId = backCam.id;
            } else {
                // أولوية 2: لو فيه أكثر من كاميرا، نفترض الثانية هي الخلفية في أغلب الأجهزة
                if (devices.length > 1) {
                    cameraId = devices[1].id;
                }
            }

            html5QrCode.start(
                cameraId,
                config,
                (decodedText, decodedResult) => {
                    barcodeInput.value = decodedText;
                    stopScanner();
                    if (attendanceForm) {
                        attendanceForm.submit();
                    }
                },
                (errorMessage) => {
                    // يمكن تجاهل أخطاء القراءة اللحظية
                }
            ).catch(err => {
                console.error(err);
                alert("تعذر تشغيل الكاميرا. تأكد من منح الصلاحية في المتصفح.");
            });
        }).catch(err => {
            console.error(err);
            alert("تعذر الوصول إلى الكاميرا. تأكد من منح الصلاحية في المتصفح.");
        });
    }

    function stopScanner() {
        if (html5QrCode) {
            html5QrCode.stop().then(() => {
                html5QrCode.clear();
            }).catch(err => {
                console.error(err);
            });
        }
        cameraArea.style.display = 'none';
    }

    if (btnOpenCamera) {
        btnOpenCamera.addEventListener('click', () => {
            startScanner();
        });
    }
    if (stopScanBtn) {
        stopScanBtn.addEventListener('click', () => {
            stopScanner();
        });
    }

    // ===== تنبيه الدين القديم لمشترك الحصة الواحدة قبل كتابة المبلغ =====
    const singlePhoneInput = document.getElementById('single_phone');
    const singlePaidInput  = document.getElementById('single_paid');
    const debtAlertBox     = document.getElementById('single_debt_alert');
    const debtAlertText    = document.getElementById('single_debt_text');

    let currentOldDebt = 0;

    function fetchSingleSessionDebt(phone) {
        if (!phone || phone.trim() === '') {
            currentOldDebt = 0;
            if (debtAlertBox) debtAlertBox.style.display = 'none';
            return;
        }

        fetch('get_single_session_debt.php?phone=' + encodeURIComponent(phone))
            .then(res => res.json())
            .then(data => {
                currentOldDebt = parseFloat(data.old_debt || 0);

                if (currentOldDebt > 0) {
                    if (debtAlertBox && debtAlertText) {
                        debtAlertText.textContent =
                            "تنبيه: يوجد مبلغ متبقي سابق قدره " +
                            currentOldDebt.toFixed(2) +
                            " جنيه. من فضلك خذ هذا في الاعتبار قبل كتابة المبلغ الجديد.";
                        debtAlertBox.style.display = 'block';
                    }
                } else {
                    if (debtAlertBox) debtAlertBox.style.display = 'none';
                }
            })
            .catch(err => {
                console.error(err);
            });
    }

    if (singlePhoneInput) {
        singlePhoneInput.addEventListener('blur', function () {
            fetchSingleSessionDebt(singlePhoneInput.value);
        });

        singlePhoneInput.addEventListener('change', function () {
            fetchSingleSessionDebt(singlePhoneInput.value);
        });
    }
</script>
</body>
</html>

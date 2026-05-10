<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

// التحقق أن المستخدم الحالي مدير
$currentRole = $_SESSION['role'] ?? '';
if ($currentRole !== 'مدير') {
    http_response_code(403);
    echo "غير مسموح بالدخول إلى هذه الصفحة.";
    exit;
}

$siteName = "Gym System";
try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {}

$errors  = [];
$success = "";

// جلب user_id المطلوب من GET أو POST
$targetUserId = (int)($_GET['user_id'] ?? ($_POST['user_id'] ?? 0));

// جلب بيانات المستخدم الهدف
$targetUser = null;
if ($targetUserId > 0) {
    $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $targetUserId]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$targetUser || $targetUser['role'] !== 'مشرف') {
        $errors[] = "المستخدم غير موجود أو ليس مشرفاً.";
        $targetUser = null;
    }
} else {
    $errors[] = "لم يتم تحديد مستخدم.";
}

// تحميل الصلاحيات الحالية (أو القيم الافتراضية)
$perms = [
    'can_view_members'        => 1,
    'can_view_renew_members'  => 1,
    'can_view_attendance'     => 1,
    'can_view_expenses'       => 1,
    'can_view_stats'          => 1,
    'can_view_settings'       => 1,
    'can_view_closing'        => 1,
];

if ($targetUser) {
    try {
        $stmtPerm = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $targetUserId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            foreach ($perms as $k => $v) {
                if (isset($rowPerm[$k])) {
                    $perms[$k] = (int)$rowPerm[$k];
                }
            }
        }
    } catch (Exception $e) {}
}

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $targetUser) {
    // قراءة checkboxes (إذا لم تُرسل فالقيمة 0)
    $newPerms = [
        'can_view_members'        => isset($_POST['can_view_members']) ? 1 : 0,
        'can_view_renew_members'  => isset($_POST['can_view_renew_members']) ? 1 : 0,
        'can_view_attendance'     => isset($_POST['can_view_attendance']) ? 1 : 0,
        'can_view_expenses'       => isset($_POST['can_view_expenses']) ? 1 : 0,
        'can_view_stats'          => isset($_POST['can_view_stats']) ? 1 : 0,
        'can_view_settings'       => isset($_POST['can_view_settings']) ? 1 : 0,
        'can_view_closing'        => isset($_POST['can_view_closing']) ? 1 : 0,
    ];

    try {
        // هل يوجد سطر صلاحيات لهذا المستخدم؟
        $stmtCheck = $pdo->prepare("SELECT id FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtCheck->execute([':uid' => $targetUserId]);
        if ($rowC = $stmtCheck->fetch(PDO::FETCH_ASSOC)) {
            // تحديث
            $stmtUpd = $pdo->prepare("
                UPDATE user_permissions
                SET
                    can_view_members       = :m,
                    can_view_renew_members = :rm,
                    can_view_attendance    = :att,
                    can_view_expenses      = :ex,
                    can_view_stats         = :st,
                    can_view_settings      = :se,
                    can_view_closing       = :cl
                WHERE user_id = :uid
            ");
        } else {
            // إدخال جديد
            $stmtUpd = $pdo->prepare("
                INSERT INTO user_permissions
                    (user_id, can_view_members, can_view_renew_members, can_view_attendance,
                     can_view_expenses, can_view_stats, can_view_settings, can_view_closing)
                VALUES
                    (:uid, :m, :rm, :att, :ex, :st, :se, :cl)
            ");
        }

        $stmtUpd->execute([
            ':uid' => $targetUserId,
            ':m'   => $newPerms['can_view_members'],
            ':rm'  => $newPerms['can_view_renew_members'],
            ':att' => $newPerms['can_view_attendance'],
            ':ex'  => $newPerms['can_view_expenses'],
            ':st'  => $newPerms['can_view_stats'],
            ':se'  => $newPerms['can_view_settings'],
            ':cl'  => $newPerms['can_view_closing'],
        ]);

        $perms   = $newPerms;
        $success = "تم حفظ صلاحيات المستخدم بنجاح.";
    } catch (Exception $e) {
        $errors[] = "حدث خطأ أثناء حفظ الصلاحيات.";
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>صلاحيات المستخدمين - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background: #f3f4f6;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #111827;
        }
        .page {
            max-width: 900px;
            margin: 30px auto;
            padding: 0 16px;
        }
        .header-bar {
            display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;
        }
        .title-main {font-size:24px;font-weight:900;}
        .title-sub  {font-size:14px;color:#6b7280;margin-top:4px;}
        .back-button {
            display:inline-flex;align-items:center;gap:8px;
            padding:10px 18px;border-radius:999px;border:none;
            background:linear-gradient(90deg,#6366f1,#22c55e);
            color:#f9fafb;text-decoration:none;font-weight:800;
            box-shadow:0 10px 30px rgba(79,70,229,0.4);
        }
        .card {
            background:#ffffff;border-radius:20px;padding:20px;
            box-shadow:0 20px 45px rgba(15,23,42,0.15);
        }
        .alert {
            padding:10px 12px;border-radius:10px;font-size:14px;margin-bottom:10px;
        }
        .alert-error {
            background:rgba(239,68,68,0.08);
            border:1px solid rgba(239,68,68,0.8);
            color:#b91c1c;
        }
        .alert-success {
            background:rgba(34,197,94,0.08);
            border:1px solid rgba(34,197,94,0.8);
            color:#166534;
        }
        .perm-group {
            display:grid;
            grid-template-columns: repeat(auto-fit,minmax(220px,1fr));
            gap:12px;
            margin-top:14px;
        }
        .perm-item {
            display:flex;align-items:center;gap:8px;
            padding:8px 10px;border-radius:12px;
            background:#f9fafb;border:1px solid #e5e7eb;
            font-size:14px;
        }
        .perm-item input[type="checkbox"] {
            width:18px;height:18px;
        }
        .btn-save {
            margin-top:18px;
            border:none;border-radius:999px;padding:10px 22px;
            background:#22c55e;color:#f9fafb;font-weight:800;
            cursor:pointer;box-shadow:0 12px 30px rgba(34,197,94,0.4);
        }
        .muted {font-size:13px;color:#6b7280;margin-top:4px;}
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">صلاحيات المستخدمين</div>
            <div class="title-sub">تحديد الأزرار والصفحات التي يمكن للمشرف رؤيتها في لوحة التحكم.</div>
        </div>
        <a href="dashboard.php" class="back-button">
            <span>🏠</span>
            <span>العودة للوحة التحكم</span>
        </a>
    </div>

    <div class="card">
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

        <?php if ($targetUser): ?>
            <h3 style="margin-top:0;">المستخدم: <?php echo htmlspecialchars($targetUser['username']); ?> (مشرف)</h3>
            <div class="muted">قم باختيار الصفحات التي تظهر لهذا الحساب في القائمة الجانبية.</div>

            <form method="post" action="user_permissions.php">
                <input type="hidden" name="user_id" value="<?php echo (int)$targetUser['id']; ?>">

                <div class="perm-group">
                    <label class="perm-item">
                        <input type="checkbox" name="can_view_members" <?php echo $perms['can_view_members'] ? 'checked' : ''; ?>>
                        <span>عرض زر "المشتركين"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_renew_members" <?php echo $perms['can_view_renew_members'] ? 'checked' : ''; ?>>
                        <span>عرض زر "تجديد الاشتراكات"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_attendance" <?php echo $perms['can_view_attendance'] ? 'checked' : ''; ?>>
                        <span>عرض زر "حضور المشتركين"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_expenses" <?php echo $perms['can_view_expenses'] ? 'checked' : ''; ?>>
                        <span>عرض زر "المصروفات"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_stats" <?php echo $perms['can_view_stats'] ? 'checked' : ''; ?>>
                        <span>عرض زر "الإحصائيات"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_settings" <?php echo $perms['can_view_settings'] ? 'checked' : ''; ?>>
                        <span>عرض زر "إعدادات الموقع"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_closing" <?php echo $perms['can_view_closing'] ? 'checked' : ''; ?>>
                        <span>عرض "زر التقفيل" (اليومي/الشهري)</span>
                    </label>
                </div>

                <button type="submit" class="btn-save">💾 حفظ الصلاحيات</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
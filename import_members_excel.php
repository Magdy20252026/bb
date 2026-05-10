<?php
// عرض الأخطاء أثناء التطوير (يمكنك إزالته لاحقاً في الإنتاج)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

// السماح فقط للمدير
$role      = $_SESSION['role'] ?? '';
$isManager = ($role === 'مدير');
if (!$isManager) {
    die('غير مسموح.');
}

// التحقق من الملف
if (!isset($_FILES['members_file']) || $_FILES['members_file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['import_error'] = 'حدث خطأ أثناء رفع الملف.';
    header('Location: members.php');
    exit;
}

// مسار مؤقت
$tmpFilePath = $_FILES['members_file']['tmp_name'];

try {
    $spreadsheet = IOFactory::load($tmpFilePath);
    $sheet       = $spreadsheet->getActiveSheet();
} catch (Exception $e) {
    $_SESSION['import_error'] = 'الملف غير صالح كملف Excel.';
    header('Location: members.php');
    exit;
}

// قراءة الرؤوس من الصف الأول لتحديد الأعمدة
$headerRow = 1;
$highestColumn      = $sheet->getHighestColumn();
$highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

$columnsMap = []; // "اسم العمود بالعربي" => رقم العمود (1,2,...)
for ($colIndex = 1; $colIndex <= $highestColumnIndex; $colIndex++) {
    $colLetter = Coordinate::stringFromColumnIndex($colIndex);
    $value     = trim((string)$sheet->getCell($colLetter . $headerRow)->getValue());
    if ($value !== '') {
        $columnsMap[$value] = $colIndex;
    }
}

// الأعمدة المطلوبة
$requiredHeaders = [
    'اسم المشترك',
    'رقم التليفون',
    'الباركود',
    'السن',
    'النوع',
    'العنوان',
    'اسم الاشتراك',
    'المدفوع',
];

foreach ($requiredHeaders as $h) {
    if (!isset($columnsMap[$h])) {
        $_SESSION['import_error'] = 'ملف Excel لا يحتوي على العمود المطلوب: ' . $h;
        header('Location: members.php');
        exit;
    }
}

// عمود تاريخ البداية (اختياري)
$startHeader     = 'تاريخ بداية الاشتراك (YYYY-MM-DD اختياري)';
$hasStartDateCol = isset($columnsMap[$startHeader]);

// تحميل الاشتراكات (اسم -> بيانات)
$subscriptionsMap = [];
try {
    $stmt = $pdo->query("SELECT id, name, days, sessions, invites, price_after_discount FROM subscriptions");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $subscriptionsMap[$row['name']] = $row;
    }
} catch (Exception $e) {
    $_SESSION['import_error'] = 'تعذر جلب بيانات الاشتراكات من قاعدة البيانات.';
    header('Location: members.php');
    exit;
}

$highestRow    = $sheet->getHighestRow();
$importedCount = 0;
$errors        = [];

// تحميل كل الباركودات الحالية دفعة واحدة لتسريع التحقق
$existingBarcodes = [];
try {
    $stmt = $pdo->query("SELECT barcode FROM members WHERE barcode IS NOT NULL AND barcode <> ''");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingBarcodes[$row['barcode']] = true;
    }
} catch (Exception $e) {
    // في حالة الخطأ نستمر بدون هذا التحسين، لكن سنفشل لاحقاً في الإضافة إذا كان هناك مشكلة
}

// دالة مساعدة للحصول على قيمة خلية حسب اسم العمود بالعربي
function getCellValueByHeader(Worksheet $sheet, array $columnsMap, string $header, int $row)
{
    $colIndex  = $columnsMap[$header];
    $colLetter = Coordinate::stringFromColumnIndex($colIndex);
    return $sheet->getCell($colLetter . $row)->getValue();
}

for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
    $name            = trim((string)getCellValueByHeader($sheet, $columnsMap, 'اسم المشترك', $row));
    $phoneRaw        = getCellValueByHeader($sheet, $columnsMap, 'رقم التليفون', $row);
    $barcode         = trim((string)getCellValueByHeader($sheet, $columnsMap, 'الباركود', $row));
    $age             = (int)getCellValueByHeader($sheet, $columnsMap, 'السن', $row);
    $gender          = trim((string)getCellValueByHeader($sheet, $columnsMap, 'النوع', $row));
    $address         = trim((string)getCellValueByHeader($sheet, $columnsMap, 'العنوان', $row));
    $subscriptionName= trim((string)getCellValueByHeader($sheet, $columnsMap, 'اسم الاشتراك', $row));

    // المدفوع نحسبه كقيمة محسوبة
    $paidColIndex  = $columnsMap['المدفوع'];
    $paidColLetter = Coordinate::stringFromColumnIndex($paidColIndex);
    $paidAmountVal = $sheet->getCell($paidColLetter . $row)->getCalculatedValue();

    // تاريخ البداية (اختياري)
    $startDateCell = null;
    if ($hasStartDateCol) {
        $startDateCell = getCellValueByHeader($sheet, $columnsMap, $startHeader, $row);
    }

    // إذا كان الصف فارغ تقريباً نتجاوزه
    if ($name === '' && $phoneRaw === null && $subscriptionName === '') {
        continue;
    }

    // تحويل رقم الهاتف لنص
    $phone = trim((string)$phoneRaw);

    // تحقق أساسي من البيانات
    if ($name === '' || $phone === '' || $age <= 0 || $address === '' || !in_array($gender, ['ذكر','أنثى'], true)) {
        $errors[] = "سطر $row: بيانات أساسية غير صحيحة.";
        continue;
    }

    if ($subscriptionName === '' || !isset($subscriptionsMap[$subscriptionName])) {
        $errors[] = "سطر $row: اسم الاشتراك غير موجود في النظام.";
        continue;
    }

    // منع تكرار الباركود إن وُجد
    if ($barcode !== '') {
        // تحقق من الباركود المكرر في الملف نفسه
        if (isset($existingBarcodes[$barcode])) {
            $errors[] = "سطر $row: لا يمكن استيراد مشترك بباركود مكرر ({$barcode}) موجود بالفعل في النظام.";
            continue;
        }
    }

    $sub = $subscriptionsMap[$subscriptionName];

    $days     = (int)$sub['days'];
    $sessions = (int)$sub['sessions'];
    $invites  = (int)$sub['invites'];
    $amount   = (float)$sub['price_after_discount'];

    $paidAmount = (float)$paidAmountVal;
    if ($paidAmount < 0 || $paidAmount > $amount) {
        $errors[] = "سطر $row: مبلغ المدفوع يجب أن يكون بين 0 وقيمة الاشتراك.";
        continue;
    }

    $remaining         = $amount - $paidAmount;
    $sessionsRemaining = $sessions;

    // معالجة تاريخ البداية بشكل صحيح (يدعم أرقام تواريخ إكسل أو نص)
    if ($startDateCell !== null && $startDateCell !== '') {
        if (is_numeric($startDateCell)) {
            // القيمة رقم تسلسلي من إكسل
            try {
                $dt = ExcelDate::excelToDateTimeObject($startDateCell);
                $startDate = $dt->format('Y-m-d');
            } catch (Exception $e) {
                // احتياط: نحاول تحويلها كنص
                $startDate = date('Y-m-d', strtotime($startDateCell));
            }
        } else {
            // نص مثل 2026-01-01 أو 01/01/2026
            $startDate = date('Y-m-d', strtotime($startDateCell));
        }
    } else {
        // لو الخلية فاضية نستخدم تاريخ اليوم
        $startDate = date('Y-m-d');
    }

    $endDate = date('Y-m-d', strtotime($startDate . ' + ' . $days . ' days'));
    $status  = 'مستمر';

    try {
        $stmtIns = $pdo->prepare("
            INSERT INTO members
            (name, phone, barcode, age, gender, address, subscription_id,
             days, sessions, sessions_remaining, invites,
             subscription_amount, paid_amount, remaining_amount,
             start_date, end_date, status)
            VALUES
            (:n,:ph,:bc,:a,:g,:ad,:sid,
             :d,:s,:sr,:i,
             :amt,:paid,:rem,
             :sd,:ed,:st)
        ");

        $stmtIns->execute([
            ':n'   => $name,
            ':ph'  => $phone,
            ':bc'  => $barcode,
            ':a'   => $age,
            ':g'   => $gender,
            ':ad'  => $address,
            ':sid' => $sub['id'],
            ':d'   => $days,
            ':s'   => $sessions,
            ':sr'  => $sessionsRemaining,
            ':i'   => $invites,
            ':amt' => $amount,
            ':paid'=> $paidAmount,
            ':rem' => $remaining,
            ':sd'  => $startDate,
            ':ed'  => $endDate,
            ':st'  => $status,
        ]);

        // في حالة نجاح الإدخال، نسجل الباركود في القائمة لمنع تكراره داخل نفس الملف
        if ($barcode !== '') {
            $existingBarcodes[$barcode] = true;
        }

        $importedCount++;
    } catch (Exception $e) {
        $errors[] = "سطر $row: حدث خطأ أثناء إضافة المشترك.";
        continue;
    }
}

// حفظ النتائج في السيشن لإظهارها في members.php
if ($importedCount > 0) {
    $_SESSION['import_success'] = "تم استيراد $importedCount مشترك بنجاح.";
}
if (!empty($errors)) {
    $_SESSION['import_error'] = implode(" / ", $errors);
}

header('Location: members.php');
exit;
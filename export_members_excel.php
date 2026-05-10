<?php
// عرض الأخطاء أثناء التطوير (يمكنك إزالته لاحقاً)
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

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

// السماح فقط للمدير
$role      = $_SESSION['role'] ?? '';
$isManager = ($role === 'مدير');
if (!$isManager) {
    die('غير مسموح.');
}

// جلب البيانات من قاعدة البيانات
try {
    $stmt = $pdo->query("
        SELECT
            m.id,
            m.name,
            m.phone,
            m.barcode,
            m.age,
            m.gender,
            m.address,
            s.name AS subscription_name,
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
        ORDER BY m.id DESC
    ");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die('خطأ في جلب البيانات: ' . $e->getMessage());
}

// إنشاء ملف Excel
$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('المشتركين');

// رؤوس الأعمدة بالعربية
$headers = [
    'A1' => 'رقم المشترك',
    'B1' => 'اسم المشترك',
    'C1' => 'رقم التليفون',
    'D1' => 'الباركود',
    'E1' => 'السن',
    'F1' => 'النوع',
    'G1' => 'العنوان',
    'H1' => 'اسم الاشتراك',
    'I1' => 'عدد الأيام',
    'J1' => 'عدد مرات التمرين',
    'K1' => 'التمرينات المتبقية',
    'L1' => 'عدد الدعوات',
    'M1' => 'مبلغ الاشتراك',
    'N1' => 'المدفوع',
    'O1' => 'المتبقي',
    'P1' => 'تاريخ البداية',
    'Q1' => 'تاريخ النهاية',
    'R1' => 'حالة الاشتراك',
];

foreach ($headers as $cell => $text) {
    $sheet->setCellValue($cell, $text);
}

// تعبئة البيانات
$rowNum = 2;
foreach ($members as $m) {
    $sheet->setCellValue('A' . $rowNum, $m['id']);
    $sheet->setCellValue('B' . $rowNum, $m['name']);

    // رقم التليفون كنص للحفاظ على الصفر على اليسار
    $sheet->setCellValueExplicit('C' . $rowNum, $m['phone'], DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('D' . $rowNum, (string)$m['barcode'], DataType::TYPE_STRING);

    $sheet->setCellValue('E' . $rowNum, $m['age']);
    $sheet->setCellValue('F' . $rowNum, $m['gender']);
    $sheet->setCellValue('G' . $rowNum, $m['address']);
    $sheet->setCellValue('H' . $rowNum, $m['subscription_name']);
    $sheet->setCellValue('I' . $rowNum, $m['days']);
    $sheet->setCellValue('J' . $rowNum, $m['sessions']);
    $sheet->setCellValue('K' . $rowNum, $m['sessions_remaining']);
    $sheet->setCellValue('L' . $rowNum, $m['invites']);
    $sheet->setCellValue('M' . $rowNum, $m['subscription_amount']);
    $sheet->setCellValue('N' . $rowNum, $m['paid_amount']);
    $sheet->setCellValue('O' . $rowNum, $m['remaining_amount']);
    $sheet->setCellValue('P' . $rowNum, $m['start_date']);
    $sheet->setCellValue('Q' . $rowNum, $m['end_date']);
    $sheet->setCellValue('R' . $rowNum, $m['status']);

    $rowNum++;
}

// جعل الأعمدة مناسبة
foreach (range('A', 'R') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$filename = 'members_' . date('Y-m-d_H-i-s') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=utf-8');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
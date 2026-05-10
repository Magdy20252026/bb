<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'غير مسموح']);
    exit;
}

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

$phone = trim($_GET['phone'] ?? '');
if ($phone === '') {
    echo json_encode(['old_debt' => 0]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT notes
        FROM attendance
        WHERE phone = :ph AND type = 'حصة_واحدة'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':ph' => $phone]);

    $oldDebt = 0.0;

    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (preg_match('/المتبقي=([0-9.]+)/u', $row['notes'], $m)) {
            $oldDebt = (float)$m[1];
        }
    }

    echo json_encode(['old_debt' => $oldDebt]);
} catch (Exception $e) {
    echo json_encode(['old_debt' => 0]);
}
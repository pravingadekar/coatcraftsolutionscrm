<?php
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array(current_user()['role'], ['owner', 'admin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed.']);
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

const PLAN_AMOUNT_PAISE = 99900; // ₹999

try {
    $api = new \Razorpay\Api\Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);
    $order = $api->order->create([
        'amount' => PLAN_AMOUNT_PAISE,
        'currency' => 'INR',
        'receipt' => 'company_' . $companyId . '_' . time(),
    ]);

    $orderId = $order['id'];
    $amountPaise = PLAN_AMOUNT_PAISE;
    $stmt = $conn->prepare("INSERT INTO payments (company_id, razorpay_order_id, amount_paise, status) VALUES (?, ?, ?, 'created')");
    $stmt->bind_param('isi', $companyId, $orderId, $amountPaise);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        'ok' => true,
        'order_id' => $order['id'],
        'amount' => PLAN_AMOUNT_PAISE,
        'key_id' => RAZORPAY_KEY_ID,
    ]);
} catch (\Throwable $e) {
    error_log('Razorpay order creation failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not create payment order.']);
}

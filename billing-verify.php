<?php
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array(current_user()['role'], ['owner', 'admin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed.']);
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$razorpayOrderId = $payload['razorpay_order_id'] ?? '';
$razorpayPaymentId = $payload['razorpay_payment_id'] ?? '';
$razorpaySignature = $payload['razorpay_signature'] ?? '';

if ($razorpayOrderId === '' || $razorpayPaymentId === '' || $razorpaySignature === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing payment details.']);
    exit;
}

// Only verify orders that were created for this company — prevents a payment
// made for one tenant being replayed to extend a different tenant's access.
$stmt = $conn->prepare("SELECT id FROM payments WHERE company_id = ? AND razorpay_order_id = ? AND status = 'created'");
$stmt->bind_param('is', $companyId, $razorpayOrderId);
$stmt->execute();
$paymentRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$paymentRow) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'No matching pending order for this company.']);
    exit;
}

try {
    $api = new \Razorpay\Api\Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);
    $api->utility->verifyPaymentSignature([
        'razorpay_order_id' => $razorpayOrderId,
        'razorpay_payment_id' => $razorpayPaymentId,
        'razorpay_signature' => $razorpaySignature,
    ]);
} catch (\Throwable $e) {
    error_log('Razorpay signature verification failed: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Payment signature could not be verified.']);
    exit;
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("UPDATE payments SET razorpay_payment_id = ?, status = 'paid' WHERE id = ?");
    $stmt->bind_param('si', $razorpayPaymentId, $paymentRow['id']);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE companies SET valid_until = GREATEST(valid_until, NOW()) + INTERVAL 30 DAY, status = 'active' WHERE id = ?");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
} catch (\Throwable $e) {
    $conn->rollback();
    error_log('Billing verify DB update failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Payment verified but could not update your account. Contact support.']);
    exit;
}

$stmt = $conn->prepare("SELECT valid_until FROM companies WHERE id = ?");
$stmt->bind_param('i', $companyId);
$stmt->execute();
$newValidUntil = $stmt->get_result()->fetch_assoc()['valid_until'];
$stmt->close();

echo json_encode(['ok' => true, 'valid_until' => date('d M Y', strtotime($newValidUntil))]);

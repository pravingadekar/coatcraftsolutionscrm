<?php
require_once __DIR__ . '/auth.php'; // session_start() only, no require_login() — this endpoint is also used by public pages
require 'db.php';
require __DIR__ . '/rate_limit.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isRateLimited($conn, 'subscribe:' . clientIp(), 10, 600)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests, please try again later.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    // Resolve tenant: prefer the logged-in dashboard session (admin/staff
    // enabling notifications for themselves). For public/unauthenticated
    // callers, require an explicit form_token instead of trusting any
    // client-supplied company_id — same rule as sendmail.php.
    $companyId = current_company_id();
    if ($companyId === 0 && !empty($input['form_token'])) {
        $stmt = $conn->prepare("SELECT company_id FROM tenant_settings WHERE form_token = ?");
        $stmt->bind_param('s', $input['form_token']);
        $stmt->execute();
        $tenant = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $companyId = $tenant ? (int)$tenant['company_id'] : 0;
    }

    if ($companyId === 0) {
        echo json_encode(['error' => 'Unable to resolve tenant for this subscription']);
        exit;
    }

    if ($input && isset($input['endpoint'])) {
        $endpoint = $input['endpoint'];
        $p256dh = $input['keys']['p256dh'] ?? '';
        $auth = $input['keys']['auth'] ?? '';

        $stmt = $conn->prepare("INSERT INTO push_subscriptions (company_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE p256dh=VALUES(p256dh), auth=VALUES(auth), company_id=VALUES(company_id)");
        $stmt->bind_param('isss', $companyId, $endpoint, $p256dh, $auth);
        $success = $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => $success]);
    } else {
        echo json_encode(['error' => 'Invalid subscription data']);
    }
} else {
    echo json_encode(['error' => 'Method not allowed']);
}
?>

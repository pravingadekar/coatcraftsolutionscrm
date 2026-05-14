<?php
require 'db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if ($input && isset($input['endpoint'])) {
        $endpoint = $input['endpoint'];
        $p256dh = $input['keys']['p256dh'] ?? '';
        $auth = $input['keys']['auth'] ?? '';
        
        $stmt = $conn->prepare("INSERT INTO push_subscriptions (endpoint, p256dh, auth) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE p256dh=VALUES(p256dh), auth=VALUES(auth)");
        $stmt->bind_param('sss', $endpoint, $p256dh, $auth);
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
<?php
// One-time maintenance script: marks CoatCraft Solutions' own tenant (slug
// 'coatcraft') as active with a far-future valid_until, so the "plan
// expired" banner stops appearing for the owner's own account. Delete this
// file from the server after running it once.
//
// Usage: visit https://coatcraftcrm.workmanager.in/fix-plan-lifetime.php?secret=YOUR_CRON_SECRET

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

if (!isset($_GET['secret']) || !hash_equals(CRON_SECRET, (string) $_GET['secret'])) {
    http_response_code(403);
    die('Forbidden');
}

$stmt = $conn->prepare("UPDATE companies SET status = 'active', valid_until = NOW() + INTERVAL 100 YEAR WHERE slug = ?");
$slug = 'coatcraft';
$stmt->bind_param('s', $slug);
$stmt->execute();
echo "Rows updated: " . $stmt->affected_rows . "\n";
$stmt->close();

$check = $conn->query("SELECT id, name, slug, status, valid_until FROM companies WHERE slug = 'coatcraft'")->fetch_assoc();
print_r($check);

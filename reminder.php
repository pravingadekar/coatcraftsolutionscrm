<?php
require_once __DIR__ . '/auth.php';
require_login();
require 'db.php';
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/push-config.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

function sendPushNotification($title, $body, $url = "/", $type = "general") {
    global $conn;

    $auth = [
        'VAPID' => [
            'subject' => VAPID_SUBJECT,
            'publicKey' => VAPID_PUBLIC_KEY,
            'privateKey' => VAPID_PRIVATE_KEY,
        ],
    ];

    $webPush = new WebPush($auth);
    $result = $conn->query("SELECT endpoint, p256dh, auth FROM push_subscriptions");
    if (!$result) {
        return false;
    }

    $payload = json_encode([
        'type' => $type,
        'title' => $title,
        'body' => $body,
        'url' => $url,
    ]);

    $success = true;
    while ($row = $result->fetch_assoc()) {
        try {
            $subscription = Subscription::create([
                'endpoint' => $row['endpoint'],
                'publicKey' => $row['p256dh'],
                'authToken' => $row['auth'],
            ]);
            $report = $webPush->sendOneNotification($subscription, $payload);
            if (!$report->isSuccess()) {
                error_log('Push send failure: ' . $report->getReason());
                $success = false;
            }
        } catch (\Exception $e) {
            error_log('Push exception: ' . $e->getMessage());
            $success = false;
        }
    }

    return $success;
}

function runReminderChecks() {
    global $conn;
    $results = [];

    $subscriptionCount = $conn->query("SELECT COUNT(*) AS c FROM push_subscriptions")->fetch_assoc()['c'] ?? 0;
    $results[] = "Push subscriptions: $subscriptionCount";

    $overdue = $conn->query("SELECT ef.*, e.name, e.phone FROM enquiry_followups ef JOIN enquiries e ON ef.enquiry_id = e.id WHERE ef.status='Open' AND ef.due_date < CURDATE() AND ef.due_date IS NOT NULL");
    if ($overdue && $overdue->num_rows) {
        while ($row = $overdue->fetch_assoc()) {
            $status = sendPushNotification("Follow-up Overdue", "Follow-up for {$row['name']} ({$row['phone']}) is overdue: {$row['note']}", "/crm-dashboard.php?view=followups", "followup_reminder") ? 'sent' : 'failed';
            $results[] = "Overdue follow-up ({$row['id']}): $status";
        }
    } else {
        $results[] = 'No overdue follow-ups found.';
    }

    $dueSoon = $conn->query("SELECT ef.*, e.name, e.phone FROM enquiry_followups ef JOIN enquiries e ON ef.enquiry_id = e.id WHERE ef.status='Open' AND ef.due_date = CURDATE()");
    if ($dueSoon && $dueSoon->num_rows) {
        while ($row = $dueSoon->fetch_assoc()) {
            $status = sendPushNotification("Follow-up Due Today", "Follow-up for {$row['name']} ({$row['phone']}) is due today: {$row['note']}", "/crm-dashboard.php?view=followups", "followup_reminder") ? 'sent' : 'failed';
            $results[] = "Due today follow-up ({$row['id']}): $status";
        }
    } else {
        $results[] = 'No follow-ups due today.';
    }

    return $results;
}

$run = isset($_GET['run']);
$results = [];
if ($run) {
    $results = runReminderChecks();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reminder Test</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{font-family:Arial,sans-serif;background:#f4f7fb;color:#1f2937;margin:0;padding:24px;}
        .container{max-width:680px;margin:0 auto;background:#fff;padding:24px;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.08);}
        h1{margin-top:0;color:#0f4a78;}
        .button{display:inline-block;padding:12px 18px;border-radius:12px;background:#0f4a78;color:#fff;text-decoration:none;font-weight:700;margin-top:12px;}
        .result{background:#eef2ff;border:1px solid #c7d2fe;border-radius:12px;padding:16px;margin-top:18px;white-space:pre-wrap;}
        .note{margin-top:14px;color:#475569;}
    </style>
</head>
<body>
    <div class="container">
        <h1>Follow-up Reminder Test</h1>
        <p class="note">If a follow-up has <strong>today's date</strong>, clicking the button will attempt to send the due-today notification now.</p>
        <p class="note">If a follow-up date is before today and still open, it will send an overdue notification.</p>
        <a class="button" href="?run=1">Run Reminder Check</a>
        <?php if ($run): ?>
            <div class="result"><?php echo implode("\n", array_map('htmlspecialchars', $results)); ?></div>
        <?php endif; ?>
    </div>
</body>
</html>

<?php
require_once __DIR__ . '/auth.php';
require 'db.php';
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/push-config.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/leads.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

// Two ways to trigger this:
// 1) Logged-in browser button -> runs reminder checks for the current
//    user's own company only (manual test).
// 2) A cron job hitting ?key=CRON_SECRET (no session) -> loops over every
//    active company and runs the checks for each. This is the path a real
//    system cron should call, since cron has no login session.
$isCronRun = isset($_GET['key']) && hash_equals(CRON_SECRET, $_GET['key']);
if (!$isCronRun) {
    if (isset($_GET['key'])) {
        error_log('reminder.php cron call rejected: key did not match CRON_SECRET');
    }
    require_login();
}

function sendPushNotification($companyId, $title, $body, $url = "/", $type = "general", $tag = null) {
    global $conn;

    $auth = [
        'VAPID' => [
            'subject' => VAPID_SUBJECT,
            'publicKey' => VAPID_PUBLIC_KEY,
            'privateKey' => VAPID_PRIVATE_KEY,
        ],
    ];

    $webPush = new WebPush($auth);
    $stmt = $conn->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE company_id = ?");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $result = $stmt->get_result();

    $payload = json_encode([
        'type' => $type,
        'tag' => $tag ?: $type,
        'title' => $title,
        'body' => $body,
        'url' => $url,
    ]);

    // A tenant can have several subscriptions (multiple devices/browsers, some
    // stale). Report success if delivery reaches at least one live device,
    // and prune subscriptions the push service confirms are gone for good.
    $anySuccess = false;
    while ($row = $result->fetch_assoc()) {
        try {
            $subscription = Subscription::create([
                'endpoint' => $row['endpoint'],
                'publicKey' => $row['p256dh'],
                'authToken' => $row['auth'],
            ]);
            $report = $webPush->sendOneNotification($subscription, $payload);
            if ($report->isSuccess()) {
                $anySuccess = true;
            } else {
                error_log('Push send failure: ' . $report->getReason());
                if ($report->isSubscriptionExpired()) {
                    $del = $conn->prepare("DELETE FROM push_subscriptions WHERE id = ?");
                    $del->bind_param('i', $row['id']);
                    $del->execute();
                    $del->close();
                }
            }
        } catch (\Exception $e) {
            error_log('Push exception: ' . $e->getMessage());
        }
    }

    return $anySuccess;
}

function runReminderChecks($companyId) {
    global $conn;
    $results = [];

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM push_subscriptions WHERE company_id = ?");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $subscriptionCount = $stmt->get_result()->fetch_assoc()['c'] ?? 0;
    $results[] = "Push subscriptions: $subscriptionCount";

    $stmt = $conn->prepare("SELECT ef.*, e.name, e.phone FROM enquiry_followups ef JOIN enquiries e ON ef.enquiry_id = e.id AND e.company_id = ef.company_id WHERE ef.company_id = ? AND ef.status='Open' AND ef.due_date < CURDATE() AND ef.due_date IS NOT NULL");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $overdue = $stmt->get_result();
    if ($overdue && $overdue->num_rows) {
        while ($row = $overdue->fetch_assoc()) {
            $status = sendPushNotification($companyId, "Follow-up Overdue", "Follow-up for {$row['name']} ({$row['phone']}) is overdue: {$row['note']}", "/crm-dashboard.php?view=notifications", "followup_reminder", "followup-{$row['id']}") ? 'sent' : 'failed';
            $results[] = "Overdue follow-up ({$row['id']}): $status";
        }
    } else {
        $results[] = 'No overdue follow-ups found.';
    }

    $stmt = $conn->prepare("SELECT ef.*, e.name, e.phone FROM enquiry_followups ef JOIN enquiries e ON ef.enquiry_id = e.id AND e.company_id = ef.company_id WHERE ef.company_id = ? AND ef.status='Open' AND ef.due_date = CURDATE()");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $dueSoon = $stmt->get_result();
    if ($dueSoon && $dueSoon->num_rows) {
        while ($row = $dueSoon->fetch_assoc()) {
            $status = sendPushNotification($companyId, "Follow-up Due Today", "Follow-up for {$row['name']} ({$row['phone']}) is due today: {$row['note']}", "/crm-dashboard.php?view=notifications", "followup_reminder", "followup-{$row['id']}") ? 'sent' : 'failed';
            $results[] = "Due today follow-up ({$row['id']}): $status";
        }
    } else {
        $results[] = 'No follow-ups due today.';
    }

    $stmt = $conn->prepare("SELECT sv.*, e.name, e.phone, e.location FROM enquiry_site_visits sv JOIN enquiries e ON sv.enquiry_id = e.id AND e.company_id = sv.company_id WHERE sv.company_id = ? AND sv.visit_date = CURDATE()");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $visitsToday = $stmt->get_result();
    if ($visitsToday && $visitsToday->num_rows) {
        while ($row = $visitsToday->fetch_assoc()) {
            $timeLabel = date('g:i A', strtotime($row['visit_time']));
            $status = sendPushNotification($companyId, "Site Visit Today", "You have a site visit with {$row['name']} ({$row['phone']}) at {$row['location']} at $timeLabel. You need to go!", "/crm-dashboard.php?view=notifications", "site_visit_reminder", "sitevisit-{$row['id']}") ? 'sent' : 'failed';
            $results[] = "Site visit today ({$row['id']}): $status";
        }
    } else {
        $results[] = 'No site visits scheduled today.';
    }

    $results = array_merge($results, sendDueReviewRequests($companyId));

    return $results;
}

// Asks each finished customer for a Google review once, REVIEW_REQUEST_DELAY_DAYS
// (default 2) after their lead was first marked 'Work Done'.
function sendDueReviewRequests($companyId) {
    global $conn;
    if (!reviewRequestEnabled()) {
        return ['Review requests off (set WA_TEMPLATE_REVIEW_REQUEST + GOOGLE_REVIEW_URL).'];
    }

    $delayDays = defined('REVIEW_REQUEST_DELAY_DAYS') ? (int) REVIEW_REQUEST_DELAY_DAYS : 2;
    $stmt = $conn->prepare("SELECT company_display_name FROM tenant_settings WHERE company_id = ?");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $tenantName = $stmt->get_result()->fetch_assoc()['company_display_name'] ?? 'CoatCraft Solutions';
    $stmt->close();

    $stmt = $conn->prepare("SELECT id, name, phone FROM enquiries WHERE company_id = ? AND status = 'Work Done' AND review_requested_at IS NULL AND work_done_at <= DATE_SUB(NOW(), INTERVAL ? DAY) AND phone <> '' LIMIT 50");
    $stmt->bind_param('ii', $companyId, $delayDays);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $results = [];
    foreach ($rows as $row) {
        $sent = sendReviewRequestWhatsApp($row['phone'], (string) $row['name'], (string) $tenantName);
        if ($sent) {
            $upd = $conn->prepare("UPDATE enquiries SET review_requested_at = NOW() WHERE id = ? AND company_id = ?");
            $upd->bind_param('ii', $row['id'], $companyId);
            $upd->execute();
            $upd->close();
            addLeadUpdate($conn, $companyId, (int) $row['id'], 'Google review request sent on WhatsApp');
        }
        $results[] = "Review request ({$row['id']}): " . ($sent ? 'sent' : 'failed');
    }
    if (!$rows) {
        $results[] = 'No review requests due.';
    }
    return $results;
}

// Morning WhatsApp to the owner (DAILY_SUMMARY_PHONE): yesterday's new
// enquiries, today's site visits, quotations still to send (site visit done
// but lead still New/Contacted) and follow-ups due. The hourly cron sends it
// once a day, on the first run at or after DAILY_SUMMARY_HOUR (IST);
// $force (manual ?summary=1) skips the hour/once-a-day checks for testing
// and isn't logged, so a test never cancels that day's automatic send.
function sendOwnerDailySummary($companyId, $force = false) {
    global $conn;
    if (!defined('DAILY_SUMMARY_PHONE') || DAILY_SUMMARY_PHONE === ''
        || (int) $companyId !== (int) DAILY_SUMMARY_COMPANY_ID) {
        return [];
    }

    $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $today = $now->format('Y-m-d');
    $sendHour = defined('DAILY_SUMMARY_HOUR') ? (int) DAILY_SUMMARY_HOUR : 8;

    if (!$force) {
        if ((int) $now->format('G') < $sendHour) {
            return ["Daily summary: waiting until {$sendHour}:00 IST."];
        }
        $stmt = $conn->prepare("SELECT 1 FROM owner_daily_summary_log WHERE company_id = ? AND summary_date = ?");
        $stmt->bind_param('is', $companyId, $today);
        $stmt->execute();
        $alreadySent = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($alreadySent) {
            return ['Daily summary: already sent today.'];
        }
    }

    $summary = buildOwnerDailySummary($conn, $companyId);

    // Owner messaged the business number in the last 24h → free-form text allowed.
    $ownerDigits = substr(preg_replace('/\D/', '', DAILY_SUMMARY_PHONE), -10);
    $stmt = $conn->prepare("SELECT 1 FROM whatsapp_inbound_messages WHERE company_id = ? AND RIGHT(phone, 10) = ? AND received_at >= NOW() - INTERVAL 23 HOUR LIMIT 1");
    $stmt->bind_param('is', $companyId, $ownerDigits);
    $stmt->execute();
    $recent = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();

    $sent = sendOwnerDailySummaryWhatsApp(DAILY_SUMMARY_PHONE, $summary['text'], $summary['params'], $recent);
    if ($sent && !$force) {
        $stmt = $conn->prepare("INSERT IGNORE INTO owner_daily_summary_log (company_id, summary_date) VALUES (?, ?)");
        $stmt->bind_param('is', $companyId, $today);
        $stmt->execute();
        $stmt->close();
    }
    return ['Daily summary (' . ($recent ? 'text' : 'template') . '): ' . ($sent ? 'sent' : 'failed')];
}

$run = isset($_GET['run']) || $isCronRun;
$results = [];
if ($run && $isCronRun) {
    // Cron path: loop every active company, scope every check to its own company_id.
    $companies = $conn->query("SELECT id, name FROM companies WHERE status IN ('trial', 'active')")->fetch_all(MYSQLI_ASSOC);
    foreach ($companies as $company) {
        $results[] = "=== {$company['name']} (company_id={$company['id']}) ===";
        $results = array_merge($results, runReminderChecks((int)$company['id']));
        $results = array_merge($results, sendOwnerDailySummary((int)$company['id']));
    }
    file_put_contents(__DIR__ . '/cron-log.txt', date('Y-m-d H:i:s') . ' cron ran, companies=' . count($companies) . "\n", FILE_APPEND);
} elseif ($run) {
    // Manual browser test: scope to the logged-in user's own company only.
    $results = isset($_GET['summary'])
        ? sendOwnerDailySummary(current_company_id(), true)
        : runReminderChecks(current_company_id());
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
        <h1>Reminder Test</h1>
        <p class="note">If a follow-up has <strong>today's date</strong>, clicking the button will attempt to send the due-today notification now.</p>
        <p class="note">If a follow-up date is before today and still open, it will send an overdue notification.</p>
        <p class="note">If a site visit is scheduled for <strong>today</strong>, it will send a "Site Visit Today" reminder too.</p>
        <a class="button" href="?run=1">Run Reminder Check</a>
        <a class="button" href="?run=1&amp;summary=1">Send Daily WhatsApp Summary Now</a>
        <?php if ($run): ?>
            <div class="result"><?php echo implode("\n", array_map('htmlspecialchars', $results)); ?></div>
        <?php endif; ?>
    </div>
</body>
</html>

<?php
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

// Safety net: if a fatal error happens anywhere below (e.g. missing file,
// bad include), still return JSON instead of breaking the frontend's
// res.json() parsing. Registered before the requires so it also covers
// failures in those (e.g. a missing vendor/autoload.php on deploy).
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        error_log('Fatal error in sendmail.php: ' . $error['message']);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $error['message']]);
    }
});

require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/push-config.php';
require_once __DIR__ . '/mailer.php';

// ===== OLD CODE (PHPMailer direct SMTP) — kept for rollback reference,
// replaced by sendViaBrevoApi() because the live host blocks outbound SMTP
// ports (465/587) to external mail servers. Not executed.
// require_once __DIR__ . '/PHPMailer/src/Exception.php';
// require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
// require_once __DIR__ . '/PHPMailer/src/SMTP.php';
// use PHPMailer\PHPMailer\PHPMailer;
// use PHPMailer\PHPMailer\Exception;

// Define push notification function first
function sendPushNotification($companyId, $title, $body, $url = "/", $type = "general") {
    global $conn;

    try {
        $auth = [
            'VAPID' => [
                'subject' => VAPID_SUBJECT,
                'publicKey' => VAPID_PUBLIC_KEY,
                'privateKey' => VAPID_PRIVATE_KEY,
            ],
        ];

        $webPush = new WebPush($auth);
        $stmt = $conn->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE company_id = ?");
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result) {
            return;
        }

        $payload = json_encode([
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ]);

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
                }
            } catch (Exception $e) {
                error_log('Push exception: ' . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log('Push notification error: ' . $e->getMessage());
    }
}

try {
    require 'db.php';
    require __DIR__ . '/rate_limit.php';

    // Resolve tenant from the public form's token — never trust a
    // client-supplied company_id directly, since that would be trivially
    // spoofable and let anyone write leads into another tenant's account.
    $formToken = trim($_POST['form_token'] ?? '');
    if ($formToken === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing form token']);
        exit;
    }

    // Max 5 submissions per IP+form per 10 minutes — stops one spammer from
    // flooding a tenant's lead inbox or hammering the shared SMTP relay.
    if (isRateLimited($conn, 'sendmail:' . clientIp() . ':' . $formToken, 5, 600)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many submissions, please try again later.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT company_id, smtp_from_name, smtp_from_email, notify_email, company_display_name, logo_path FROM tenant_settings WHERE form_token = ?");
    $stmt->bind_param('s', $formToken);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$tenant) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid form token']);
        exit;
    }
    $companyId = (int)$tenant['company_id'];

    // Determine enquiry type
    $enquiry_type = isset($_POST['address']) && !empty($_POST['address']) ? 'residential' : 'commercial';

    // Get all form data
    $name = $_POST['name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $email = $_POST['email'] ?? '';
    $location = $_POST['location'] ?? '';
    $address = $_POST['address'] ?? '';
    $area = $_POST['area'] ?? '';
    $slab = $_POST['slab'] ?? '';
    $industry_usage = $_POST['industry_usage'] ?? '';
    $work_type = $_POST['work_type'] ?? '';
    $heavyload = $_POST['heavyload'] ?? '';
    $timeline = $_POST['timeline'] ?? '';
    $epoxy_type = $_POST['epoxy_type'] ?? '';
    $thickness = $_POST['thickness'] ?? '';
    $message = $_POST['message'] ?? '';
    $budget = $_POST['budget'] ?? '';
    $concrete_grade = $_POST['concrete_grade'] ?? '';
    $slab_age = $_POST['slab_age'] ?? '';
    $cracks = $_POST['cracks'] ?? '';
    $contamination = $_POST['contamination'] ?? '';
    $previous_coating = $_POST['previous_coating'] ?? '';
    $industry_type = $_POST['industry_type'] ?? '';
    $forklift = $_POST['forklift'] ?? '';
    $max_load = $_POST['max_load'] ?? '';
    $chemical_exposure = $_POST['chemical_exposure'] ?? '';
    $moisture_issue = $_POST['moisture_issue'] ?? '';
    $water_washing = $_POST['water_washing'] ?? '';
    $anti_skid = $_POST['anti_skid'] ?? '';
    $preferred_color = $_POST['preferred_color'] ?? '';
    $finish_type = $_POST['finish_type'] ?? '';
    $line_marking = $_POST['line_marking'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $urgent = $_POST['urgent'] ?? '';
    $working_hours = $_POST['working_hours'] ?? '';

    // SAVE TO DATABASE
    $stmt = $conn->prepare(
        "INSERT INTO enquiries
        (company_id, type, name, phone, email, location, address, area, slab, industry_usage, work_type,
         heavyload, timeline, epoxy_type, thickness, message, budget, concrete_grade, slab_age,
         cracks, contamination, previous_coating, industry_type, forklift, max_load,
         chemical_exposure, moisture_issue, water_washing, anti_skid, preferred_color, finish_type,
         line_marking, start_date, urgent, working_hours)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );

    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    // Bind 35 parameters (company_id + type + 33 fields)
    $stmt->bind_param(
        "issssssssssssssssssssssssssssssssss",
        $companyId, $enquiry_type, $name, $phone, $email, $location, $address, $area, $slab,
        $industry_usage, $work_type, $heavyload, $timeline, $epoxy_type, $thickness,
        $message, $budget, $concrete_grade, $slab_age, $cracks, $contamination,
        $previous_coating, $industry_type, $forklift, $max_load, $chemical_exposure,
        $moisture_issue, $water_washing, $anti_skid, $preferred_color, $finish_type,
        $line_marking, $start_date, $urgent, $working_hours
    );

    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $stmt->close();

    // SEND EMAIL
    $fromName = $tenant['smtp_from_name'] ?: SMTP_FROM_NAME;
    $notifyEmail = $tenant['notify_email'] ?: SMTP_FROM_EMAIL;
    $subject = $enquiry_type === 'residential' ? 'New Residential Flooring Enquiry' : 'New Commercial Epoxy Enquiry';
    $notifyBody = "New $enquiry_type enquiry from $name\n\nPhone: $phone\nEmail: $email\nArea: $area\n\nDetails:\n$message";
    if (!sendViaBrevoApi(SMTP_FROM_EMAIL, $fromName, $notifyEmail, '', $subject, nl2br(htmlspecialchars($notifyBody)), $notifyBody)) {
        error_log('Tenant notification email failed for company_id=' . $companyId);
    }
    // OLD CODE (PHPMailer direct SMTP) — kept for rollback reference, not executed:
    // try {
    //     $mail = new PHPMailer(true);
    //     $mail->isSMTP();
    //     $mail->Host = SMTP_HOST;
    //     $mail->SMTPAuth = true;
    //     $mail->Username = SMTP_USERNAME;
    //     $mail->Password = SMTP_PASSWORD;
    //     $mail->SMTPSecure = SMTP_SECURE;
    //     $mail->Port = SMTP_PORT;
    //     $mail->Timeout = 10;
    //     $mail->setFrom(SMTP_FROM_EMAIL, $fromName);
    //     $mail->addAddress($notifyEmail);
    //     $mail->Subject = $subject;
    //     $mail->Body = $notifyBody;
    //     $mail->send();
    // } catch (Exception $e) {
    //     error_log('Email Error: ' . $e->getMessage());
    // }

    // Thank-you email to the enquirer, sent via the sales mailbox (separate
    // from the tenant-notification SMTP account above).
    if ($email !== '') {
        $tenantDisplayName = $tenant['company_display_name'] ?: SALES_SMTP_FROM_NAME;
        $tenantLogoPath = __DIR__ . ($tenant['logo_path'] ?: '/new_logo.png');
        sendEnquiryThankYou($email, $name, $tenantDisplayName, $tenantLogoPath, $enquiry_type);
    }

    // Send Push Notifications
    sendPushNotification($companyId, "New " . ucfirst($enquiry_type) . " Enquiry", "From $name - $phone", "/view-leads.php", "new_enquiry");

    $conn->close();

    // Return Success
    echo json_encode(['success' => true, 'message' => 'Enquiry submitted successfully']);

} catch (\Throwable $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

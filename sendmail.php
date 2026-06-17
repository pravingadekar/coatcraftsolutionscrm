<?php
require 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/push-config.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

// Define push notification function first
function sendPushNotification($title, $body, $url = "/", $type = "general") {
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
        $result = $conn->query("SELECT endpoint, p256dh, auth FROM push_subscriptions");
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
        (type, name, phone, email, location, address, area, slab, industry_usage, work_type, 
         heavyload, timeline, epoxy_type, thickness, message, budget, concrete_grade, slab_age, 
         cracks, contamination, previous_coating, industry_type, forklift, max_load, 
         chemical_exposure, moisture_issue, water_washing, anti_skid, preferred_color, finish_type, 
         line_marking, start_date, urgent, working_hours)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    // Bind 34 parameters (type + 33 fields)
    $stmt->bind_param(
        "ssssssssssssssssssssssssssssssssss",
        $enquiry_type, $name, $phone, $email, $location, $address, $area, $slab,
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
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = 'tls';
        $mail->Port = SMTP_PORT;
        $mail->Timeout = 10;

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress(SMTP_FROM_EMAIL);

        $subject = $enquiry_type === 'residential' ? 'New Residential Flooring Enquiry' : 'New Commercial Epoxy Enquiry';
        $mail->Subject = $subject;
        $mail->Body = "New $enquiry_type enquiry from $name\n\nPhone: $phone\nEmail: $email\nArea: $area\n\nDetails:\n$message";

        $mail->send();
    } catch (Exception $e) {
        error_log('Email Error: ' . $e->getMessage());
    }

    // Send Push Notifications
    sendPushNotification("New " . ucfirst($enquiry_type) . " Enquiry", "From $name - $phone", "/view-leads.php", "new_enquiry");

    $conn->close();

    // Return Success
    echo json_encode(['success' => true, 'message' => 'Enquiry submitted successfully']);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

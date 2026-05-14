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
ini_set('display_errors', 1);

// ðŸ”¹ Get Data
$name = $_POST['name'] ?? '';
$phone = $_POST['phone'] ?? '';
$email = $_POST['email'] ?? '';
$location = $_POST['location'] ?? '';
$area = $_POST['area'] ?? '';
$slab = $_POST['slab'] ?? '';
$usage = $_POST['industry_usage'] ?? '';
$load = $_POST['heavyload'] ?? '';
$timeline = $_POST['timeline'] ?? '';
$message = $_POST['message'] ?? '';
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
$epoxy_type = $_POST['epoxy_type'] ?? '';
$thickness = $_POST['thickness'] ?? '';

// ðŸ”¹ SAVE TO DATABASE
$stmt = $conn->prepare("INSERT INTO enquiries 
(name, phone, email, location, area, slab, industry_usage, heavyload, timeline, epoxy_type, thickness, message,

concrete_grade, slab_age, cracks, contamination, previous_coating,
industry_type, forklift, max_load, chemical_exposure,
moisture_issue, water_washing, anti_skid,
preferred_color, finish_type, line_marking,
start_date, urgent, working_hours)

VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

$stmt->bind_param("ssssssssssssssssssssssssssssss",
$name,
$phone,
$email,
$location,
$area,
$slab,
$usage,
$load,
$timeline,
$epoxy_type,
$thickness,
$message,

$concrete_grade,
$slab_age,
$cracks,
$contamination,
$previous_coating,

$industry_type,
$forklift,
$max_load,
$chemical_exposure,

$moisture_issue,
$water_washing,
$anti_skid,

$preferred_color,
$finish_type,
$line_marking,

$start_date,
$urgent,
$working_hours
);

if(!$stmt->execute()){
    die("SQL Error: " . $stmt->error);
}
$stmt->close();

// ðŸ”¹ SEND EMAIL (wrapped in try catch)
$mailError = false;
try {

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.titan.email';
    $mail->SMTPAuth = true;
    $mail->Username = 'info@workmanager.in';
    $mail->Password = 'Pravin@123!';  // CHANGE PASSWORD
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;
    $mail->Timeout = 10;

    $mail->setFrom('info@workmanager.in', 'CoatCraft Solutions');
    $mail->addAddress('info@workmanager.in');

    $mail->Subject = 'New Epoxy Enquiry';
    $mail->Body = "New enquiry from $name - $phone";

    $mail->send();
    // 🔹 WhatsApp Notification to Admin (SAFE)

$whatsapp_text = "📩 New Epoxy Enquiry\n\n".
"Company: $name\n".
"Phone: $phone\n".
"Email: $email\n".
"Location: $location\n".
"Area: $area\n".
"Epoxy Type: $epoxy_type\n".
"Thickness: $thickness\n".
"Industry: $usage\n".
"Timeline: $timeline";

// API Data
$data = [
    "messaging_product" => "whatsapp",
    "to" => "917745889111", // Tumhara WhatsApp number
    "type" => "text",
    "text" => [
        "body" => $whatsapp_text
    ]
];

// 🔹 API Details
$token = "EAAYQ7afpKLQBQ9ZBnqob4dR4h2zpceusFOOZBvRiOwvtuQ7phOTnWOAxBgZCW2AFzG87HioShAwkINYx8TvGxoI1pXxaxaPLMVZAespt5ZBmW5XfpSBZCsA44S3pODoESZAaAYx3Q9E9za8R2iHZCalZB6ZBiMm1rZAEj4dRbZCZA1DwbcdX6wIprWJHnmCLPXR1CvZA34xzVUZB1xnuQrRsj5tGNMajrERWOeYgs95ocl3eaFLF2ARVi1UP2xmng4LpiYe0RZCCW5nWClWVpWw8CVoJ1ljh";
$phone_number_id = "1047443235113207";

$url = "https://graph.facebook.com/v22.0/$phone_number_id/messages";

$options = [
    "http" => [
        "header" => "Content-Type: application/json\r\nAuthorization: Bearer $token",
        "method" => "POST",
        "content" => json_encode($data)
    ]
];

$context = stream_context_create($options);
file_get_contents($url, false, $context);

} catch (\Exception $e) {
    error_log('Mailer/WhatsApp Error: ' . $e->getMessage());
    $mailError = true;
}

// Send Push Notifications for New Enquiry even if email/WhatsApp fails
sendPushNotification("New Enquiry Submitted", "New enquiry from $name - $phone", "/view-leads.php", "new_enquiry");

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
                // Optionally remove invalid subscription or log error
                error_log('Push send failure: ' . $report->getReason());
            }
        } catch (\Exception $e) {
            error_log('Push exception: ' . $e->getMessage());
        }
    }
}

$conn->close();

// ðŸ”¹ Redirect Immediately
$successMessage = $mailError ? 'Enquiry submitted successfully, but email/WhatsApp notification failed.' : 'Enquiry Submitted Successfully!';
echo "<script>
alert('" . addslashes($successMessage) . "');
window.location.href='enquiry.html';
</script>";
exit;
?>
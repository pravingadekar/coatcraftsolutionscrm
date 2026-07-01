<?php
// Shared helper for client-facing email (enquiry thank-you, site visit
// confirmations) — sent via the Brevo HTTP API (api.brevo.com), not SMTP.
// Several hosts block outbound SMTP ports (465/587) to external mail
// servers but always allow outbound HTTPS, so the API is used for every
// outgoing email in this app instead of PHPMailer/SMTP.

require_once __DIR__ . '/config.php';

// ===== OLD CODE (PHPMailer direct SMTP) — kept for rollback reference,
// replaced by sendViaBrevoApi() below because the live host blocks
// outbound SMTP ports (465/587) to external mail servers. Not executed.
// require_once __DIR__ . '/PHPMailer/src/Exception.php';
// require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
// require_once __DIR__ . '/PHPMailer/src/SMTP.php';
// use PHPMailer\PHPMailer\PHPMailer;
//
// /* Embeds the tenant logo (if the file exists) into $mail and returns an <img> tag referencing it. */
// function embedTenantLogoHtml_OLD(PHPMailer $mail, string $logoFilePath, string $tenantName): string {
//     if (!is_file($logoFilePath)) {
//         return '';
//     }
//     $cid = 'tenantlogo';
//     $mail->addEmbeddedImage($logoFilePath, $cid);
//     return '<img src="cid:' . $cid . '" alt="' . htmlspecialchars($tenantName) . '" style="max-height:56px;margin-bottom:18px;">';
// }

const SALES_EMAIL_FOOTER = "\n\nThanks & Regards,\nGauri Gadekar\nCoatCraft Solutions\n\n📞 +917745889111\n📧 sales@coatcraftsolutions.com\n🌐 www.coatcraftsolutions.com\n\n✨ Epoxy Flooring | PU Flooring | Industrial Coatings\n📍 Pune, Maharashtra";

/* Sends one transactional email via the Brevo API. $attachments is an array
   of ['name' => filename, 'content' => raw bytes] — content is base64-encoded
   here, not by the caller. Returns true on a 2xx response from Brevo. */
function sendViaBrevoApi(
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlContent,
    string $textContent = '',
    array $attachments = []
): bool {
    $payload = [
        'sender' => ['email' => $fromEmail, 'name' => $fromName],
        'to' => [['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail]],
        'subject' => $subject,
        'htmlContent' => $htmlContent,
    ];
    if ($textContent !== '') {
        $payload['textContent'] = $textContent;
    }
    if ($attachments) {
        $payload['attachment'] = array_map(
            fn($a) => ['name' => $a['name'], 'content' => base64_encode($a['content'])],
            $attachments
        );
    }

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'api-key: ' . BREVO_API_KEY,
            'Content-Type: application/json',
            'accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }
    error_log('Brevo API send failed: HTTP ' . $httpCode . ' ' . ($curlError ?: $response));
    return false;
}

/* Sends one transactional SMS via the Brevo API. Returns true on a 2xx
   response. Delivery to Indian numbers additionally requires the sender ID
   and message template to be DLT-registered on Brevo's dashboard (TRAI
   regulation) — a 2xx here only means Brevo accepted the request, not that
   the carrier delivered it. */
function sendViaBrevoSms(string $recipientPhone, string $content): bool {
    $payload = [
        'sender' => BREVO_SMS_SENDER,
        'recipient' => $recipientPhone,
        'content' => $content,
        'type' => 'transactional',
    ];

    $ch = curl_init('https://api.brevo.com/v3/transactionalSMS/sms');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'api-key: ' . BREVO_API_KEY,
            'Content-Type: application/json',
            'accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }
    error_log('Brevo SMS send failed: HTTP ' . $httpCode . ' ' . ($curlError ?: $response));
    return false;
}

/* Converts a raw lead phone number into Brevo's expected international
   digits-only format (no '+'). Leads in this app are entered as plain
   10-digit Indian mobile numbers, so this assumes +91 unless a country
   code is already present. Returns null if the number can't be normalized. */
function normalizeIndianPhoneForSms(string $phone): ?string {
    $digits = preg_replace('/\D/', '', $phone);
    if ($digits === '') {
        return null;
    }
    if (strlen($digits) === 10) {
        return '91' . $digits;
    }
    if (strlen($digits) === 11 && $digits[0] === '0') {
        return '91' . substr($digits, 1);
    }
    if (strlen($digits) === 12 && substr($digits, 0, 2) === '91') {
        return $digits;
    }
    return strlen($digits) >= 10 ? $digits : null;
}

/* Sends a short site-visit confirmation SMS, alongside sendSiteVisitInvite()'s
   email. Best-effort: returns false (and logs) rather than throwing, so a
   failed SMS never blocks the email or the visit-scheduling request. */
function sendSiteVisitSms(
    string $phone,
    string $toName,
    string $tenantName,
    string $visitDate,
    string $slotStart,
    string $slotEnd
): bool {
    $recipient = normalizeIndianPhoneForSms($phone);
    if ($recipient === null) {
        return false;
    }
    try {
        $startTs = strtotime("$visitDate $slotStart");
        $dayLabel = date('d M Y', $startTs);
        $timeLabel = date('g:i A', $startTs) . ' - ' . date('g:i A', strtotime("$visitDate $slotEnd"));
        $content = "Hi $toName, your site visit with $tenantName is confirmed for $dayLabel, $timeLabel. Queries: +917745889111 - CoatCraft Solutions";
        return sendViaBrevoSms($recipient, $content);
    } catch (\Throwable $e) {
        error_log('sendSiteVisitSms failed: ' . $e->getMessage());
        return false;
    }
}

/* Returns an <img> tag pointing at the tenant logo's public URL (if the
   file exists locally), suitable for embedding in HTML email bodies. Links
   to SITE_URL rather than base64-embedding the file — embedding pushed
   branded emails over Gmail's ~102KB clipping threshold, truncating them. */
function embedTenantLogoHtml(string $logoFilePath, string $tenantName): string {
    if (!is_file($logoFilePath)) {
        return '';
    }
    $publicUrl = SITE_URL . str_replace(__DIR__, '', $logoFilePath);
    return '<img src="' . htmlspecialchars($publicUrl) . '" alt="' . htmlspecialchars($tenantName) . '" style="max-height:56px;margin-bottom:18px;">';
}


function sendClientEmail(string $toEmail, string $toName, string $subject, string $body): bool {
    $text = $body . SALES_EMAIL_FOOTER;
    return sendViaBrevoApi(
        SALES_SMTP_FROM_EMAIL,
        SALES_SMTP_FROM_NAME,
        $toEmail,
        $toName,
        $subject,
        nl2br(htmlspecialchars($text)),
        $text
    );
}
// OLD CODE (PHPMailer direct SMTP), kept for rollback reference, not executed:
// function sendClientEmail_OLD(string $toEmail, string $toName, string $subject, string $body): bool {
//     try {
//         $mail = new PHPMailer(true);
//         $mail->isSMTP();
//         $mail->Host = SALES_SMTP_HOST;
//         $mail->SMTPAuth = true;
//         $mail->Username = SALES_SMTP_USERNAME;
//         $mail->Password = SALES_SMTP_PASSWORD;
//         $mail->SMTPSecure = SALES_SMTP_SECURE;
//         $mail->Port = SALES_SMTP_PORT;
//         $mail->Timeout = 10;
//         $mail->setFrom(SALES_SMTP_FROM_EMAIL, SALES_SMTP_FROM_NAME);
//         $mail->addAddress($toEmail, $toName);
//         $mail->Subject = $subject;
//         $mail->Body = $body . SALES_EMAIL_FOOTER;
//         $mail->send();
//         return true;
//     } catch (\Throwable $e) {
//         error_log('sendClientEmail failed: ' . $e->getMessage());
//         return false;
//     }
// }

/* Sends a branded site-visit confirmation with the company logo and an .ics
   calendar attachment, so Gmail/Outlook show it as an event with a reminder. */
function sendSiteVisitInvite(
    string $toEmail,
    string $toName,
    string $tenantName,
    string $logoFilePath,
    string $visitDate,
    string $slotStart,
    string $slotEnd,
    string $location = ''
): bool {
    try {
        $tz = new \DateTimeZone('Asia/Kolkata');
        $startDt = new \DateTime("$visitDate $slotStart", $tz);
        $endDt   = new \DateTime("$visitDate $slotEnd",   $tz);
        $startTs = $startDt->getTimestamp();
        $endTs   = $endDt->getTimestamp();

        $dayLabel = $startDt->format('l, d M Y');
        $timeLabel = $startDt->format('g:i A') . ' - ' . $endDt->format('g:i A');

        $logoHtml = embedTenantLogoHtml($logoFilePath, $tenantName);

        $safeName = htmlspecialchars($toName);
        $safeDay = htmlspecialchars($dayLabel);
        $safeTime = htmlspecialchars($timeLabel);
        $safeLocation = htmlspecialchars($location);

        $htmlBody = '
        <div style="font-family:Arial,sans-serif;background:#f4f7fb;padding:32px 0;">
            <div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:16px;padding:36px;box-shadow:0 8px 24px rgba(15,23,42,.08);">
                <div style="text-align:center;margin-bottom:24px;">' . $logoHtml . '</div>
                <h1 style="margin:0 0 16px;color:#0f4a78;font-size:26px;text-align:center;">Your Site Visit is Confirmed</h1>
                <p style="color:#1f2937;margin:0 0 4px;text-align:center;">Hi ' . $safeName . ', we look forward to seeing you.</p>
                <p style="color:#475569;margin:0 0 28px;text-align:center;">Thank you for choosing ' . htmlspecialchars($tenantName) . '.<br>Our team will visit your site on the scheduled date and time.</p>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;margin-bottom:24px;">
                    <tr>
                        <td style="padding:22px;vertical-align:top;text-align:left;">
                            <p style="margin:0 0 12px;color:#0f172a;">📅 <strong>Date:</strong> ' . $safeDay . '</p>
                            <p style="margin:0 0 12px;color:#0f172a;">⏰ <strong>Time:</strong> ' . $safeTime . '</p>
                            <p style="margin:0 0 12px;color:#0f172a;">📍 <strong>Type:</strong> On-Site Visit' . ($location !== '' ? ' - ' . $safeLocation : '') . '</p>
                            <p style="margin:0;color:#0f172a;">👤 We will be represented by our technical expert.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 22px 22px;border-top:1px solid #e2e8f0;padding-top:18px;text-align:center;">
                            <p style="font-size:32px;margin:0 0 8px;">🏗️</p>
                            <p style="color:#0f4a78;font-weight:600;margin:0;">We will assess your requirements and suggest the best flooring solution.</p>
                        </td>
                    </tr>
                </table>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;margin-bottom:24px;">
                    <tr>
                        <td width="50%" style="padding:14px 8px;text-align:center;vertical-align:top;">
                            <p style="font-size:22px;margin:0;">🛡️</p>
                            <p style="margin:6px 0 0;font-weight:600;color:#0f172a;font-size:13px;">High Quality Materials</p>
                            <p style="margin:2px 0 0;color:#94a3b8;font-size:12px;">Premium &amp; Durable</p>
                        </td>
                        <td width="50%" style="padding:14px 8px;text-align:center;vertical-align:top;">
                            <p style="font-size:22px;margin:0;">👷</p>
                            <p style="margin:6px 0 0;font-weight:600;color:#0f172a;font-size:13px;">Expert Team</p>
                            <p style="margin:2px 0 0;color:#94a3b8;font-size:12px;">Skilled &amp; Experienced</p>
                        </td>
                    </tr>
                    <tr>
                        <td width="50%" style="padding:14px 8px;text-align:center;vertical-align:top;">
                            <p style="font-size:22px;margin:0;">🏅</p>
                            <p style="margin:6px 0 0;font-weight:600;color:#0f172a;font-size:13px;">Timely Execution</p>
                            <p style="margin:2px 0 0;color:#94a3b8;font-size:12px;">On-time Delivery</p>
                        </td>
                        <td width="50%" style="padding:14px 8px;text-align:center;vertical-align:top;">
                            <p style="font-size:22px;margin:0;">✅</p>
                            <p style="margin:6px 0 0;font-weight:600;color:#0f172a;font-size:13px;">Warranty Assured</p>
                            <p style="margin:2px 0 0;color:#94a3b8;font-size:12px;">Workmanship Guarantee</p>
                        </td>
                    </tr>
                </table>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;border-radius:14px;margin-bottom:24px;">
                    <tr>
                        <td style="padding:22px;vertical-align:top;text-align:left;">
                            <h3 style="margin:0 0 12px;color:#0f4a78;font-size:16px;">What to Expect on the Visit?</h3>
                            <p style="margin:0 0 6px;color:#1f2937;font-size:14px;">✔ Site inspection &amp; requirement discussion</p>
                            <p style="margin:0 0 6px;color:#1f2937;font-size:14px;">✔ Floor condition assessment</p>
                            <p style="margin:0 0 6px;color:#1f2937;font-size:14px;">✔ Solution recommendation</p>
                            <p style="margin:0 0 6px;color:#1f2937;font-size:14px;">✔ Material &amp; process explanation</p>
                            <p style="margin:0;color:#1f2937;font-size:14px;">✔ Quotation &amp; timeline (if applicable)</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 22px 22px;border-top:1px solid #e2e8f0;padding-top:18px;text-align:left;">
                            <h3 style="margin:0 0 12px;color:#0f4a78;font-size:16px;">Need to Reschedule?</h3>
                            <p style="margin:0 0 16px;color:#1f2937;font-size:14px;">If you need to reschedule or have any questions, feel free to contact us.</p>
                            <a href="tel:+917745889111" style="display:inline-block;background:#f59e0b;color:#fff;font-weight:700;text-decoration:none;padding:10px 18px;border-radius:10px;font-size:14px;">📞 +91 77458 89111</a>
                        </td>
                    </tr>
                </table>

                <div style="text-align:center;background:#f8fafc;border-radius:14px;padding:18px;margin-bottom:24px;">
                    <p style="margin:0 0 10px;color:#0f172a;font-size:14px;">📸 Want to see our completed work?</p>
                    <a href="https://coatcraftsolutions.com/gallery.html" style="display:inline-block;background:#0f4a78;color:#fff;font-weight:700;text-decoration:none;padding:10px 18px;border-radius:10px;font-size:14px;">View Our Gallery</a>
                </div>

                <p style="color:#1f2937;margin:0 0 4px;">We value your time and business.</p>
                <p style="color:#1f2937;margin:0 0 24px;">See you at your site!</p>

                <div style="border-top:1px solid #e2e8f0;padding-top:20px;text-align:center;">
                    ' . $logoHtml . '
                    <div style="font-size:13px;color:#475569;">
                        <p style="margin:0 0 4px;">📞 +91 77458 89111</p>
                        <p style="margin:0 0 4px;">📧 <a href="mailto:sales@coatcraftsolutions.com" style="color:#0f4a78;text-decoration:none;">sales@coatcraftsolutions.com</a></p>
                        <p style="margin:0;">🌐 <a href="https://www.coatcraftsolutions.com" style="color:#0f4a78;text-decoration:none;">www.coatcraftsolutions.com</a></p>
                    </div>
                </div>
                <p style="text-align:center;color:#94a3b8;font-size:12px;margin:20px 0 0;">This is an automated email. Please do not reply to this email.</p>
            </div>
        </div>';
        $textBody = "Hi $toName,\n\nYour site visit has been scheduled for $dayLabel at $timeLabel" . ($location !== '' ? " at $location" : '') . ".\n\nNeed to reschedule? Call us at +91 77458 89111.\n\nA calendar invite is attached.\n\nWant to see our completed work? https://coatcraftsolutions.com/gallery.html";

        $uid = 'sitevisit-' . md5($toEmail . $startTs) . '@coatcraftsolutions.com';
        $ics = "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//CoatCraft Solutions//Site Visit//EN\r\n"
            . "METHOD:REQUEST\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:$uid\r\n"
            . "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n"
            . "DTSTART;TZID=Asia/Kolkata:" . $startDt->format('Ymd\THis') . "\r\n"
            . "DTEND;TZID=Asia/Kolkata:" . $endDt->format('Ymd\THis') . "\r\n"
            . "SUMMARY:Site Visit - $tenantName\r\n"
            . ($location !== '' ? "LOCATION:" . str_replace(',', '\\,', $location) . "\r\n" : '')
            . "ORGANIZER;CN=$tenantName:mailto:" . SALES_SMTP_FROM_EMAIL . "\r\n"
            . "ATTENDEE;CN=$toName:mailto:$toEmail\r\n"
            . "STATUS:CONFIRMED\r\n"
            . "BEGIN:VALARM\r\n"
            . "TRIGGER:-PT30M\r\n"
            . "ACTION:DISPLAY\r\n"
            . "DESCRIPTION:Site visit reminder\r\n"
            . "END:VALARM\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";

        return sendViaBrevoApi(
            SALES_SMTP_FROM_EMAIL,
            SALES_SMTP_FROM_NAME,
            $toEmail,
            $toName,
            'Your Site Visit is Confirmed - ' . $tenantName,
            $htmlBody,
            $textBody,
            [['name' => 'site-visit.ics', 'content' => $ics]]
        );
    } catch (\Throwable $e) {
        error_log('sendSiteVisitInvite failed: ' . $e->getMessage());
        return false;
    }
}
// OLD CODE (PHPMailer direct SMTP), kept for rollback reference, not executed:
// function sendSiteVisitInvite(
//     string $toEmail,
//     string $toName,
//     string $tenantName,
//     string $logoFilePath,
//     string $visitDate,
//     string $slotStart,
//     string $slotEnd,
//     string $location = ''
// ): bool {
//     try {
//         $startTs = strtotime("$visitDate $slotStart");
//         $endTs = strtotime("$visitDate $slotEnd");
//
//         $dayLabel = date('l, d M Y', $startTs);
//         $timeLabel = date('g:i A', $startTs) . ' - ' . date('g:i A', $endTs);
//
//         $mail = new PHPMailer(true);
//         $mail->isSMTP();
//         $mail->Host = SALES_SMTP_HOST;
//         $mail->SMTPAuth = true;
//         $mail->Username = SALES_SMTP_USERNAME;
//         $mail->Password = SALES_SMTP_PASSWORD;
//         $mail->SMTPSecure = SALES_SMTP_SECURE;
//         $mail->Port = SALES_SMTP_PORT;
//         $mail->Timeout = 10;
//
//         $mail->setFrom(SALES_SMTP_FROM_EMAIL, SALES_SMTP_FROM_NAME);
//         $mail->addAddress($toEmail, $toName);
//         $mail->Subject = 'Your Site Visit is Confirmed - ' . $tenantName;
//         $mail->isHTML(true);
//
//         $logoHtml = embedTenantLogoHtml($mail, $logoFilePath, $tenantName);
//
//         $safeName = htmlspecialchars($toName);
//         $safeDay = htmlspecialchars($dayLabel);
//         $safeTime = htmlspecialchars($timeLabel);
//         $safeLocation = htmlspecialchars($location);
//
//         $mail->Body = '
//         <div style="font-family:Arial,sans-serif;background:#f4f7fb;padding:32px 0;">
//             <div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:16px;padding:36px;box-shadow:0 8px 24px rgba(15,23,42,.08);">
//                 <div style="text-align:center;margin-bottom:24px;">' . $logoHtml . '</div>
//                 <h1 style="margin:0 0 16px;color:#0f4a78;font-size:26px;text-align:center;">Your Site Visit is Confirmed</h1>
//                 <p style="color:#1f2937;margin:0 0 4px;text-align:center;">Hi ' . $safeName . ', we look forward to seeing you.</p>
//                 <p style="color:#475569;margin:0 0 28px;text-align:center;">Thank you for choosing ' . htmlspecialchars($tenantName) . '.<br>Our team will visit your site on the scheduled date and time.</p>
//
//                 <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;margin-bottom:24px;">
//                     <tr>
//                         <td style="padding:22px;vertical-align:top;text-align:left;">
//                             <p style="margin:0 0 12px;color:#0f172a;">📅 <strong>Date:</strong> ' . $safeDay . '</p>
//                             <p style="margin:0 0 12px;color:#0f172a;">⏰ <strong>Time:</strong> ' . $safeTime . '</p>
//                             <p style="margin:0 0 12px;color:#0f172a;">📍 <strong>Type:</strong> On-Site Visit' . ($location !== '' ? ' - ' . $safeLocation : '') . '</p>
//                             <p style="margin:0;color:#0f172a;">👤 We will be represented by our technical expert.</p>
//                         </td>
//                     </tr>
//                     <tr>
//                         <td style="padding:0 22px 22px;border-top:1px solid #e2e8f0;padding-top:18px;text-align:center;">
//                             <p style="font-size:32px;margin:0 0 8px;">🏗️</p>
//                             <p style="color:#0f4a78;font-weight:600;margin:0;">We will assess your requirements and suggest the best flooring solution.</p>
//                         </td>
//                     </tr>
//                 </table>
//
//                 <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;margin-bottom:24px;">
//                     <tr>
//                         <td width="50%" style="padding:14px 8px;text-align:center;vertical-align:top;">
//                             <p style="font-size:22px;margin:0;">🛡️</p>
//                             <p style="margin:6px 0 0;font-weight:600;color:#0f172a;font-size:13px;">High Quality Materials</p>
//                             <p style="margin:2px 0 0;color:#94a3b8;font-size:12px;">Premium &amp; Durable</p>
//                         </td>
//                         <td width="50%" style="padding:14px 8px;text-align:center;vertical-align:top;">
//                             <p style="font-size:22px;margin:0;">👷</p>
//                             <p style="margin:6px 0 0;font-weight:600;color:#0f172a;font-size:13px;">Expert Team</p>
//                             <p style="margin:2px 0 0;color:#94a3b8;font-size:12px;">Skilled &amp; Experienced</p>
//                         </td>
//                     </tr>
//                     <tr>
//                         <td width="50%" style="padding:14px 8px;text-align:center;vertical-align:top;">
//                             <p style="font-size:22px;margin:0;">🏅</p>
//                             <p style="margin:6px 0 0;font-weight:600;color:#0f172a;font-size:13px;">Timely Execution</p>
//                             <p style="margin:2px 0 0;color:#94a3b8;font-size:12px;">On-time Delivery</p>
//                         </td>
//                         <td width="50%" style="padding:14px 8px;text-align:center;vertical-align:top;">
//                             <p style="font-size:22px;margin:0;">✅</p>
//                             <p style="margin:6px 0 0;font-weight:600;color:#0f172a;font-size:13px;">Warranty Assured</p>
//                             <p style="margin:2px 0 0;color:#94a3b8;font-size:12px;">Workmanship Guarantee</p>
//                         </td>
//                     </tr>
//                 </table>
//
//                 <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;border-radius:14px;margin-bottom:24px;">
//                     <tr>
//                         <td style="padding:22px;vertical-align:top;text-align:left;">
//                             <h3 style="margin:0 0 12px;color:#0f4a78;font-size:16px;">What to Expect on the Visit?</h3>
//                             <p style="margin:0 0 6px;color:#1f2937;font-size:14px;">✔ Site inspection &amp; requirement discussion</p>
//                             <p style="margin:0 0 6px;color:#1f2937;font-size:14px;">✔ Floor condition assessment</p>
//                             <p style="margin:0 0 6px;color:#1f2937;font-size:14px;">✔ Solution recommendation</p>
//                             <p style="margin:0 0 6px;color:#1f2937;font-size:14px;">✔ Material &amp; process explanation</p>
//                             <p style="margin:0;color:#1f2937;font-size:14px;">✔ Quotation &amp; timeline (if applicable)</p>
//                         </td>
//                     </tr>
//                     <tr>
//                         <td style="padding:0 22px 22px;border-top:1px solid #e2e8f0;padding-top:18px;text-align:left;">
//                             <h3 style="margin:0 0 12px;color:#0f4a78;font-size:16px;">Need to Reschedule?</h3>
//                             <p style="margin:0 0 16px;color:#1f2937;font-size:14px;">If you need to reschedule or have any questions, feel free to contact us.</p>
//                             <a href="tel:+917745889111" style="display:inline-block;background:#f59e0b;color:#fff;font-weight:700;text-decoration:none;padding:10px 18px;border-radius:10px;font-size:14px;">📞 +91 77458 89111</a>
//                         </td>
//                     </tr>
//                 </table>
//
//                 <div style="text-align:center;background:#f8fafc;border-radius:14px;padding:18px;margin-bottom:24px;">
//                     <p style="margin:0 0 10px;color:#0f172a;font-size:14px;">📸 Want to see our completed work?</p>
//                     <a href="https://coatcraftsolutions.com/gallery.html" style="display:inline-block;background:#0f4a78;color:#fff;font-weight:700;text-decoration:none;padding:10px 18px;border-radius:10px;font-size:14px;">View Our Gallery</a>
//                 </div>
//
//                 <p style="color:#1f2937;margin:0 0 4px;">We value your time and business.</p>
//                 <p style="color:#1f2937;margin:0 0 24px;">See you at your site!</p>
//
//                 <div style="border-top:1px solid #e2e8f0;padding-top:20px;text-align:center;">
//                     ' . $logoHtml . '
//                     <div style="font-size:13px;color:#475569;">
//                         <p style="margin:0 0 4px;">📞 +91 77458 89111</p>
//                         <p style="margin:0 0 4px;">📧 <a href="mailto:sales@coatcraftsolutions.com" style="color:#0f4a78;text-decoration:none;">sales@coatcraftsolutions.com</a></p>
//                         <p style="margin:0;">🌐 <a href="https://www.coatcraftsolutions.com" style="color:#0f4a78;text-decoration:none;">www.coatcraftsolutions.com</a></p>
//                     </div>
//                 </div>
//                 <p style="text-align:center;color:#94a3b8;font-size:12px;margin:20px 0 0;">This is an automated email. Please do not reply to this email.</p>
//             </div>
//         </div>';
//         $mail->AltBody = "Hi $toName,\n\nYour site visit has been scheduled for $dayLabel at $timeLabel" . ($location !== '' ? " at $location" : '') . ".\n\nNeed to reschedule? Call us at +91 77458 89111.\n\nA calendar invite is attached.\n\nWant to see our completed work? https://coatcraftsolutions.com/gallery.html";
//
//         $uid = 'sitevisit-' . md5($toEmail . $startTs) . '@coatcraftsolutions.com';
//         $ics = "BEGIN:VCALENDAR\r\n"
//             . "VERSION:2.0\r\n"
//             . "PRODID:-//CoatCraft Solutions//Site Visit//EN\r\n"
//             . "METHOD:REQUEST\r\n"
//             . "BEGIN:VEVENT\r\n"
//             . "UID:$uid\r\n"
//             . "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n"
//             . "DTSTART:" . gmdate('Ymd\THis\Z', $startTs) . "\r\n"
//             . "DTEND:" . gmdate('Ymd\THis\Z', $endTs) . "\r\n"
//             . "SUMMARY:Site Visit - $tenantName\r\n"
//             . ($location !== '' ? "LOCATION:" . str_replace(',', '\\,', $location) . "\r\n" : '')
//             . "ORGANIZER;CN=$tenantName:mailto:" . SALES_SMTP_FROM_EMAIL . "\r\n"
//             . "ATTENDEE;CN=$toName:mailto:$toEmail\r\n"
//             . "STATUS:CONFIRMED\r\n"
//             . "BEGIN:VALARM\r\n"
//             . "TRIGGER:-PT30M\r\n"
//             . "ACTION:DISPLAY\r\n"
//             . "DESCRIPTION:Site visit reminder\r\n"
//             . "END:VALARM\r\n"
//             . "END:VEVENT\r\n"
//             . "END:VCALENDAR\r\n";
//
//         $mail->addStringAttachment($ics, 'site-visit.ics', 'base64', 'text/calendar; method=REQUEST; charset=UTF-8');
//
//         $mail->send();
//         return true;
//     } catch (\Throwable $e) {
//         error_log('sendSiteVisitInvite failed: ' . $e->getMessage());
//         return false;
//     }
// }

/* Sends the branded thank-you email after an enquiry form submission
   (residential or industrial/commercial), with the tenant's logo. */
function sendEnquiryThankYou(
    string $toEmail,
    string $toName,
    string $tenantName,
    string $logoFilePath,
    string $enquiryType
): bool {
    try {
        $logoHtml = embedTenantLogoHtml($logoFilePath, $tenantName);

        $safeName = htmlspecialchars($toName);
        $safeTenant = htmlspecialchars($tenantName);
        $enquiryLabel = $enquiryType === 'residential' ? 'residential' : 'commercial';

        $htmlBody = '
        <div style="font-family:Arial,sans-serif;background:#f4f7fb;padding:32px 0;">
            <div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:18px;padding:0;box-shadow:0 8px 24px rgba(15,23,42,.1);overflow:hidden;">
                <div style="height:6px;background:#f59e0b;"></div>
                <div style="padding:36px 36px 28px;">
                    <div style="text-align:center;margin-bottom:24px;">' . $logoHtml . '</div>

                    <table role="presentation" style="margin:0 auto 14px;">
                        <tr>
                            <td style="vertical-align:middle;padding-right:14px;">
                                <span style="display:inline-block;width:48px;height:48px;line-height:48px;border-radius:50%;background:#0f4a78;color:#fff;font-size:22px;text-align:center;">📧</span>
                            </td>
                            <td style="vertical-align:middle;">
                                <h1 style="margin:0;color:#0f4a78;font-size:24px;">Thanks for your enquiry, <span style="color:#f59e0b;">' . $safeName . '!</span></h1>
                            </td>
                        </tr>
                    </table>
                    <p style="color:#475569;text-align:center;margin:0 0 24px;">We have received your enquiry and our team will get in touch with you shortly.</p>

                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;border-radius:14px;margin-bottom:24px;">
                        <tr>
                            <td width="64" style="padding:20px 0 20px 20px;vertical-align:middle;">
                                <span style="display:inline-block;width:48px;height:48px;line-height:48px;border-radius:50%;background:#0f4a78;color:#fff;font-size:20px;text-align:center;">🤝</span>
                            </td>
                            <td style="padding:20px 20px 20px 16px;vertical-align:middle;">
                                <p style="margin:0;color:#0f172a;">Thank you for reaching out to <strong style="color:#0f4a78;">' . $safeTenant . '</strong> about your ' . $enquiryLabel . ' epoxy flooring requirement.</p>
                            </td>
                        </tr>
                    </table>

                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
                        <tr>
                            <td width="50%" style="padding:0 8px 16px;text-align:center;vertical-align:top;">
                                <span style="display:inline-block;width:42px;height:42px;line-height:42px;border-radius:50%;background:#0f4a78;color:#fff;font-size:18px;">🛡️</span>
                                <p style="margin:8px 0 0;font-weight:600;color:#0f172a;font-size:12.5px;">Premium Quality<br>Materials</p>
                            </td>
                            <td width="50%" style="padding:0 8px 16px;text-align:center;vertical-align:top;">
                                <span style="display:inline-block;width:42px;height:42px;line-height:42px;border-radius:50%;background:#0f4a78;color:#fff;font-size:18px;">👷</span>
                                <p style="margin:8px 0 0;font-weight:600;color:#0f172a;font-size:12.5px;">Experienced<br>Professionals</p>
                            </td>
                        </tr>
                        <tr>
                            <td width="50%" style="padding:0 8px;text-align:center;vertical-align:top;">
                                <span style="display:inline-block;width:42px;height:42px;line-height:42px;border-radius:50%;background:#0f4a78;color:#fff;font-size:18px;">⏱️</span>
                                <p style="margin:8px 0 0;font-weight:600;color:#0f172a;font-size:12.5px;">On-Time<br>Delivery</p>
                            </td>
                            <td width="50%" style="padding:0 8px;text-align:center;vertical-align:top;">
                                <span style="display:inline-block;width:42px;height:42px;line-height:42px;border-radius:50%;background:#0f4a78;color:#fff;font-size:18px;">🏅</span>
                                <p style="margin:8px 0 0;font-weight:600;color:#0f172a;font-size:12.5px;">Durable &amp; Long<br>Lasting Results</p>
                            </td>
                        </tr>
                    </table>

                    <div style="text-align:center;background:#f8fafc;border-radius:14px;padding:18px;margin-bottom:24px;">
                        <p style="margin:0 0 10px;color:#0f172a;font-size:14px;">📸 Want to see our completed work?</p>
                        <a href="https://coatcraftsolutions.com/gallery.html" style="display:inline-block;background:#0f4a78;color:#fff;font-weight:700;text-decoration:none;padding:10px 18px;border-radius:10px;font-size:14px;">View Our Gallery</a>
                    </div>

                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0f4a78;border-radius:20px;margin-bottom:28px;">
                        <tr>
                            <td width="58" style="padding:14px 0 14px 18px;vertical-align:middle;">
                                <span style="display:inline-block;width:30px;height:30px;line-height:30px;border-radius:50%;background:#f59e0b;color:#fff;font-size:14px;text-align:center;">📞</span>
                            </td>
                            <td style="padding:14px 18px 14px 8px;vertical-align:middle;">
                                <p style="margin:0;color:#fff;font-size:13.5px;">If you have any urgent questions, feel free to reply to this email or call us.</p>
                            </td>
                        </tr>
                    </table>

                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td width="50%" style="vertical-align:top;padding-right:12px;">
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td width="56" style="vertical-align:top;">
                                            <span style="display:inline-block;width:48px;height:48px;line-height:48px;border-radius:50%;background:#e2e8f0;color:#0f4a78;font-size:20px;text-align:center;">👤</span>
                                        </td>
                                        <td style="vertical-align:top;">
                                            <p style="margin:0;color:#f59e0b;font-size:13px;">Thanks &amp; Regards,</p>
                                            <p style="margin:2px 0 0;color:#0f4a78;font-weight:700;">Gauri Gadekar</p>
                                            <p style="margin:0;color:#475569;font-size:13px;">CoatCraft Solutions</p>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                            <td width="50%" style="vertical-align:top;text-align:left;font-size:13px;color:#475569;">
                                <p style="margin:0 0 4px;">📞 +91 77458 89111</p>
                                <p style="margin:0 0 4px;">📧 <a href="mailto:sales@coatcraftsolutions.com" style="color:#0f4a78;text-decoration:none;">sales@coatcraftsolutions.com</a></p>
                                <p style="margin:0;">🌐 <a href="https://www.coatcraftsolutions.com" style="color:#0f4a78;text-decoration:none;">www.coatcraftsolutions.com</a></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0f4a78;">
                    <tr>
                        <td style="padding:20px 24px 8px;text-align:center;color:#fff;font-size:12px;">
                            ♦️ Epoxy Flooring &nbsp;&nbsp;🛠️ PU Flooring &nbsp;&nbsp;🏭 Industrial Coatings &nbsp;&nbsp;📍 Pune, Maharashtra
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 24px 20px;text-align:center;color:#fff;font-size:12px;">
                            <p style="margin:0 0 6px;">Follow Us</p>
                            <span style="display:inline-block;width:26px;height:26px;line-height:26px;border-radius:50%;background:rgba(255,255,255,.18);margin:0 3px;">📘</span>
                            <span style="display:inline-block;width:26px;height:26px;line-height:26px;border-radius:50%;background:rgba(255,255,255,.18);margin:0 3px;">📷</span>
                            <span style="display:inline-block;width:26px;height:26px;line-height:26px;border-radius:50%;background:rgba(255,255,255,.18);margin:0 3px;">💼</span>
                        </td>
                    </tr>
                </table>
                <div style="background:#f59e0b;padding:10px;text-align:center;color:#fff;font-size:11.5px;">
                    © ' . date('Y') . ' CoatCraft Solutions. All Rights Reserved.
                </div>
            </div>
        </div>';
        $textBody = "Hi $toName,\n\nThank you for reaching out to $tenantName about your $enquiryLabel epoxy flooring requirement. We've received your enquiry and our team will get in touch with you shortly.\n\nIf you have any urgent questions, feel free to reply to this email or call us.\n\nWant to see our completed work? https://coatcraftsolutions.com/gallery.html" . SALES_EMAIL_FOOTER;

        return sendViaBrevoApi(
            SALES_SMTP_FROM_EMAIL,
            SALES_SMTP_FROM_NAME,
            $toEmail,
            $toName,
            "Thanks for your enquiry, $toName!",
            $htmlBody,
            $textBody
        );
    } catch (\Throwable $e) {
        error_log('sendEnquiryThankYou failed: ' . $e->getMessage());
        return false;
    }
}
// OLD CODE (PHPMailer direct SMTP), kept for rollback reference, not executed:
// function sendEnquiryThankYou(
//     string $toEmail,
//     string $toName,
//     string $tenantName,
//     string $logoFilePath,
//     string $enquiryType
// ): bool {
//     try {
//         $mail = new PHPMailer(true);
//         $mail->isSMTP();
//         $mail->Host = SALES_SMTP_HOST;
//         $mail->SMTPAuth = true;
//         $mail->Username = SALES_SMTP_USERNAME;
//         $mail->Password = SALES_SMTP_PASSWORD;
//         $mail->SMTPSecure = SALES_SMTP_SECURE;
//         $mail->Port = SALES_SMTP_PORT;
//         $mail->Timeout = 10;
//
//         $mail->setFrom(SALES_SMTP_FROM_EMAIL, SALES_SMTP_FROM_NAME);
//         $mail->addAddress($toEmail, $toName);
//         $mail->Subject = "Thanks for your enquiry, $toName!";
//         $mail->isHTML(true);
//
//         $logoHtml = embedTenantLogoHtml($mail, $logoFilePath, $tenantName);
//
//         $safeName = htmlspecialchars($toName);
//         $safeTenant = htmlspecialchars($tenantName);
//         $enquiryLabel = $enquiryType === 'residential' ? 'residential' : 'commercial';
//
//         $mail->Body = '
//         <div style="font-family:Arial,sans-serif;background:#f4f7fb;padding:32px 0;">
//             <div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:18px;padding:0;box-shadow:0 8px 24px rgba(15,23,42,.1);overflow:hidden;">
//                 <div style="height:6px;background:#f59e0b;"></div>
//                 <div style="padding:36px 36px 28px;">
//                     <div style="text-align:center;margin-bottom:24px;">' . $logoHtml . '</div>
//
//                     <table role="presentation" style="margin:0 auto 14px;">
//                         <tr>
//                             <td style="vertical-align:middle;padding-right:14px;">
//                                 <span style="display:inline-block;width:48px;height:48px;line-height:48px;border-radius:50%;background:#0f4a78;color:#fff;font-size:22px;text-align:center;">📧</span>
//                             </td>
//                             <td style="vertical-align:middle;">
//                                 <h1 style="margin:0;color:#0f4a78;font-size:24px;">Thanks for your enquiry, <span style="color:#f59e0b;">' . $safeName . '!</span></h1>
//                             </td>
//                         </tr>
//                     </table>
//                     <p style="color:#475569;text-align:center;margin:0 0 24px;">We have received your enquiry and our team will get in touch with you shortly.</p>
//
//                     <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;border-radius:14px;margin-bottom:24px;">
//                         <tr>
//                             <td width="64" style="padding:20px 0 20px 20px;vertical-align:middle;">
//                                 <span style="display:inline-block;width:48px;height:48px;line-height:48px;border-radius:50%;background:#0f4a78;color:#fff;font-size:20px;text-align:center;">🤝</span>
//                             </td>
//                             <td style="padding:20px 20px 20px 16px;vertical-align:middle;">
//                                 <p style="margin:0;color:#0f172a;">Thank you for reaching out to <strong style="color:#0f4a78;">' . $safeTenant . '</strong> about your ' . $enquiryLabel . ' epoxy flooring requirement.</p>
//                             </td>
//                         </tr>
//                     </table>
//
//                     <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
//                         <tr>
//                             <td width="50%" style="padding:0 8px 16px;text-align:center;vertical-align:top;">
//                                 <span style="display:inline-block;width:42px;height:42px;line-height:42px;border-radius:50%;background:#0f4a78;color:#fff;font-size:18px;">🛡️</span>
//                                 <p style="margin:8px 0 0;font-weight:600;color:#0f172a;font-size:12.5px;">Premium Quality<br>Materials</p>
//                             </td>
//                             <td width="50%" style="padding:0 8px 16px;text-align:center;vertical-align:top;">
//                                 <span style="display:inline-block;width:42px;height:42px;line-height:42px;border-radius:50%;background:#0f4a78;color:#fff;font-size:18px;">👷</span>
//                                 <p style="margin:8px 0 0;font-weight:600;color:#0f172a;font-size:12.5px;">Experienced<br>Professionals</p>
//                             </td>
//                         </tr>
//                         <tr>
//                             <td width="50%" style="padding:0 8px;text-align:center;vertical-align:top;">
//                                 <span style="display:inline-block;width:42px;height:42px;line-height:42px;border-radius:50%;background:#0f4a78;color:#fff;font-size:18px;">⏱️</span>
//                                 <p style="margin:8px 0 0;font-weight:600;color:#0f172a;font-size:12.5px;">On-Time<br>Delivery</p>
//                             </td>
//                             <td width="50%" style="padding:0 8px;text-align:center;vertical-align:top;">
//                                 <span style="display:inline-block;width:42px;height:42px;line-height:42px;border-radius:50%;background:#0f4a78;color:#fff;font-size:18px;">🏅</span>
//                                 <p style="margin:8px 0 0;font-weight:600;color:#0f172a;font-size:12.5px;">Durable &amp; Long<br>Lasting Results</p>
//                             </td>
//                         </tr>
//                     </table>
//
//                     <div style="text-align:center;background:#f8fafc;border-radius:14px;padding:18px;margin-bottom:24px;">
//                         <p style="margin:0 0 10px;color:#0f172a;font-size:14px;">📸 Want to see our completed work?</p>
//                         <a href="https://coatcraftsolutions.com/gallery.html" style="display:inline-block;background:#0f4a78;color:#fff;font-weight:700;text-decoration:none;padding:10px 18px;border-radius:10px;font-size:14px;">View Our Gallery</a>
//                     </div>
//
//                     <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0f4a78;border-radius:20px;margin-bottom:28px;">
//                         <tr>
//                             <td width="58" style="padding:14px 0 14px 18px;vertical-align:middle;">
//                                 <span style="display:inline-block;width:30px;height:30px;line-height:30px;border-radius:50%;background:#f59e0b;color:#fff;font-size:14px;text-align:center;">📞</span>
//                             </td>
//                             <td style="padding:14px 18px 14px 8px;vertical-align:middle;">
//                                 <p style="margin:0;color:#fff;font-size:13.5px;">If you have any urgent questions, feel free to reply to this email or call us.</p>
//                             </td>
//                         </tr>
//                     </table>
//
//                     <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
//                         <tr>
//                             <td width="50%" style="vertical-align:top;padding-right:12px;">
//                                 <table role="presentation" cellpadding="0" cellspacing="0" border="0">
//                                     <tr>
//                                         <td width="56" style="vertical-align:top;">
//                                             <span style="display:inline-block;width:48px;height:48px;line-height:48px;border-radius:50%;background:#e2e8f0;color:#0f4a78;font-size:20px;text-align:center;">👤</span>
//                                         </td>
//                                         <td style="vertical-align:top;">
//                                             <p style="margin:0;color:#f59e0b;font-size:13px;">Thanks &amp; Regards,</p>
//                                             <p style="margin:2px 0 0;color:#0f4a78;font-weight:700;">Gauri Gadekar</p>
//                                             <p style="margin:0;color:#475569;font-size:13px;">CoatCraft Solutions</p>
//                                         </td>
//                                     </tr>
//                                 </table>
//                             </td>
//                             <td width="50%" style="vertical-align:top;text-align:left;font-size:13px;color:#475569;">
//                                 <p style="margin:0 0 4px;">📞 +91 77458 89111</p>
//                                 <p style="margin:0 0 4px;">📧 <a href="mailto:sales@coatcraftsolutions.com" style="color:#0f4a78;text-decoration:none;">sales@coatcraftsolutions.com</a></p>
//                                 <p style="margin:0;">🌐 <a href="https://www.coatcraftsolutions.com" style="color:#0f4a78;text-decoration:none;">www.coatcraftsolutions.com</a></p>
//                             </td>
//                         </tr>
//                     </table>
//                 </div>
//
//                 <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0f4a78;">
//                     <tr>
//                         <td style="padding:20px 24px 8px;text-align:center;color:#fff;font-size:12px;">
//                             ♦️ Epoxy Flooring &nbsp;&nbsp;🛠️ PU Flooring &nbsp;&nbsp;🏭 Industrial Coatings &nbsp;&nbsp;📍 Pune, Maharashtra
//                         </td>
//                     </tr>
//                     <tr>
//                         <td style="padding:8px 24px 20px;text-align:center;color:#fff;font-size:12px;">
//                             <p style="margin:0 0 6px;">Follow Us</p>
//                             <span style="display:inline-block;width:26px;height:26px;line-height:26px;border-radius:50%;background:rgba(255,255,255,.18);margin:0 3px;">📘</span>
//                             <span style="display:inline-block;width:26px;height:26px;line-height:26px;border-radius:50%;background:rgba(255,255,255,.18);margin:0 3px;">📷</span>
//                             <span style="display:inline-block;width:26px;height:26px;line-height:26px;border-radius:50%;background:rgba(255,255,255,.18);margin:0 3px;">💼</span>
//                         </td>
//                     </tr>
//                 </table>
//                 <div style="background:#f59e0b;padding:10px;text-align:center;color:#fff;font-size:11.5px;">
//                     © ' . date('Y') . ' CoatCraft Solutions. All Rights Reserved.
//                 </div>
//             </div>
//         </div>';
//         $mail->AltBody = "Hi $toName,\n\nThank you for reaching out to $tenantName about your $enquiryLabel epoxy flooring requirement. We've received your enquiry and our team will get in touch with you shortly.\n\nIf you have any urgent questions, feel free to reply to this email or call us.\n\nWant to see our completed work? https://coatcraftsolutions.com/gallery.html" . SALES_EMAIL_FOOTER;
//
//         $mail->send();
//         return true;
//     } catch (\Throwable $e) {
//         error_log('sendEnquiryThankYou failed: ' . $e->getMessage());
//         return false;
//     }
// }

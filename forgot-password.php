<?php
require_once __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email !== '') {
        // A given email can belong to a user in more than one company
        // (email is unique per-company, not globally) — send a reset
        // link for every matching account.
        $stmt = $conn->prepare("SELECT u.id, c.name AS company_name FROM users u JOIN companies c ON c.id = u.company_id WHERE u.email = ? AND u.status = 'active'");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($users as $user) {
            $token = bin2hex(random_bytes(32));
            $stmt = $conn->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
            $stmt->bind_param('is', $user['id'], $token);
            $stmt->execute();
            $stmt->close();

            $resetLink = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/reset-password.php?token=' . $token;

            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = SMTP_HOST;
                $mail->SMTPAuth = true;
                $mail->Username = SMTP_USERNAME;
                $mail->Password = SMTP_PASSWORD;
                $mail->SMTPSecure = SMTP_SECURE;
                $mail->Port = SMTP_PORT;
                $mail->Timeout = 10;
                $mail->setFrom(SMTP_FROM_EMAIL, $user['company_name'] . ' CRM');
                $mail->addAddress($email);
                $mail->Subject = 'Reset your CRM password';
                $mail->Body = "We received a request to reset your password for {$user['company_name']}.\n\nClick this link to set a new password (valid for 1 hour):\n$resetLink\n\nIf you didn't request this, you can ignore this email.";
                $mail->send();
            } catch (\Throwable $e) {
                error_log('Password reset email error: ' . $e->getMessage());
            }
        }
    }

    // Always show the same message, whether or not the email matched —
    // avoids leaking which emails have accounts.
    $message = 'If an account exists for that email, a password reset link has been sent.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Forgot Password | CRM</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#1f2937;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
.box{background:#fff;padding:32px;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.08);width:100%;max-width:360px;}
h1{margin:0 0 20px;color:#0f4a78;font-size:22px;}
label{display:block;margin-bottom:14px;font-weight:600;}
input{width:100%;padding:10px;margin-top:6px;border:1px solid #d9e2ea;border-radius:8px;font-size:14px;box-sizing:border-box;}
button{width:100%;padding:12px;border:none;border-radius:8px;background:#0f4a78;color:#fff;font-weight:700;cursor:pointer;margin-top:8px;}
.message{color:#166534;margin-bottom:14px;}
.foot{margin-top:16px;font-size:13px;text-align:center;color:#475569;}
.foot a{color:#0f4a78;font-weight:600;text-decoration:none;}
</style>
</head>
<body>
<div class="box">
<h1>Forgot Password</h1>
<?php if ($message): ?>
    <p class="message"><?= htmlspecialchars($message) ?></p>
<?php else: ?>
    <form method="POST">
    <label>Email<input type="email" name="email" required autofocus></label>
    <button type="submit">Send Reset Link</button>
    </form>
<?php endif; ?>
<p class="foot"><a href="login.php">Back to sign in</a></p>
</div>
</body>
</html>

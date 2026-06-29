<?php
require_once __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = '';
$done = false;

function findValidReset(mysqli $conn, string $token): ?array {
    if ($token === '') {
        return null;
    }
    $stmt = $conn->prepare("SELECT id, user_id FROM password_resets WHERE token = ? AND expires_at > NOW()");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

$reset = findValidReset($conn, $token);

if (!$reset) {
    $error = 'This reset link is invalid or has expired. Please request a new one.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->bind_param('si', $passwordHash, $reset['user_id']);
        $stmt->execute();
        $stmt->close();

        // Reset token is single-use.
        $stmt = $conn->prepare("DELETE FROM password_resets WHERE id = ?");
        $stmt->bind_param('i', $reset['id']);
        $stmt->execute();
        $stmt->close();

        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reset Password | CRM</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#1f2937;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
.box{background:#fff;padding:32px;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.08);width:100%;max-width:360px;}
h1{margin:0 0 20px;color:#0f4a78;font-size:22px;}
label{display:block;margin-bottom:14px;font-weight:600;}
input{width:100%;padding:10px;margin-top:6px;border:1px solid #d9e2ea;border-radius:8px;font-size:14px;box-sizing:border-box;}
button{width:100%;padding:12px;border:none;border-radius:8px;background:#0f4a78;color:#fff;font-weight:700;cursor:pointer;margin-top:8px;}
.error{color:#b91c1c;margin-bottom:14px;}
.message{color:#166534;margin-bottom:14px;}
.foot{margin-top:16px;font-size:13px;text-align:center;color:#475569;}
.foot a{color:#0f4a78;font-weight:600;text-decoration:none;}
</style>
</head>
<body>
<div class="box">
<h1>Reset Password</h1>
<?php if ($done): ?>
    <p class="message">Your password has been reset. You can now sign in.</p>
<?php elseif ($error && !$reset): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
<?php else: ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="POST">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <label>New Password<input type="password" name="password" minlength="8" required autofocus></label>
    <label>Confirm Password<input type="password" name="confirm_password" minlength="8" required></label>
    <button type="submit">Reset Password</button>
    </form>
<?php endif; ?>
<p class="foot"><a href="login.php">Back to sign in</a></p>
</div>
</body>
</html>

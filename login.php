<?php
require_once __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT id, company_id, password_hash, role FROM users WHERE email = ? AND status = 'active'");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['company_id'] = (int)$user['company_id'];
        $_SESSION['user_email'] = $email;
        $_SESSION['role'] = $user['role'];
        header('Location: view-leads.php');
        exit;
    }
    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login | CoatCraft CRM</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#1f2937;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
.box{background:#fff;padding:32px;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.08);width:100%;max-width:360px;}
h1{margin:0 0 20px;color:#0f4a78;font-size:22px;}
label{display:block;margin-bottom:14px;font-weight:600;}
input{width:100%;padding:10px;margin-top:6px;border:1px solid #d9e2ea;border-radius:8px;font-size:14px;box-sizing:border-box;}
button{width:100%;padding:12px;border:none;border-radius:8px;background:#0f4a78;color:#fff;font-weight:700;cursor:pointer;margin-top:8px;}
.error{color:#b91c1c;margin-bottom:14px;}
.foot{margin-top:16px;font-size:13px;text-align:center;color:#475569;}
.foot a{color:#0f4a78;font-weight:600;text-decoration:none;}
</style>
</head>
<body>
<div class="box">
<h1>CRM Login</h1>
<?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="POST">
<label>Email<input type="email" name="username" required autofocus></label>
<label>Password<input type="password" name="password" required></label>
<button type="submit">Sign In</button>
</form>
<p class="foot"><a href="forgot-password.php">Forgot password?</a></p>
<p class="foot">Don't have an account? <a href="signup.php">Sign up</a></p>
</div>
</body>
</html>

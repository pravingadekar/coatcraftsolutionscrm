<?php
require_once __DIR__ . '/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === ADMIN_USERNAME && password_verify($password, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
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
</style>
</head>
<body>
<div class="box">
<h1>CoatCraft CRM Login</h1>
<?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="POST">
<label>Username<input type="text" name="username" required autofocus></label>
<label>Password<input type="password" name="password" required></label>
<button type="submit">Sign In</button>
</form>
</div>
</body>
</html>

<?php
require_once __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

function slugify(string $text): string {
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'company';
}

function uniqueSlug(mysqli $conn, string $base): string {
    $slug = $base;
    $i = 1;
    while (true) {
        $stmt = $conn->prepare("SELECT id FROM companies WHERE slug = ?");
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            return $slug;
        }
        $i++;
        $slug = $base . '-' . $i;
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $companyName = trim($_POST['company_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($companyName === '' || $email === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $slug = uniqueSlug($conn, slugify($companyName));
        $formToken = bin2hex(random_bytes(16));
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO companies (name, slug, status, valid_until) VALUES (?, ?, 'trial', NOW() + INTERVAL 14 DAY)");
            $stmt->bind_param('ss', $companyName, $slug);
            $stmt->execute();
            $companyId = $conn->insert_id;
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO tenant_settings (company_id, company_display_name, logo_path, theme_color, smtp_from_name, smtp_from_email, notify_email, form_token)
                 VALUES (?, ?, '/new_logo.png', '#0f4a78', ?, ?, ?, ?)"
            );
            $stmt->bind_param('isssss', $companyId, $companyName, $companyName, $email, $email, $formToken);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO users (company_id, email, password_hash, role, status) VALUES (?, ?, ?, 'owner', 'active')");
            $stmt->bind_param('iss', $companyId, $email, $passwordHash);
            $stmt->execute();
            $userId = $conn->insert_id;
            $stmt->close();

            $conn->commit();

            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = $userId;
            $_SESSION['company_id'] = $companyId;
            $_SESSION['user_email'] = $email;
            $_SESSION['role'] = 'owner';
            header('Location: crm-dashboard.php');
            exit;
        } catch (\Throwable $e) {
            $conn->rollback();
            error_log('Signup error: ' . $e->getMessage());
            $error = 'Something went wrong while creating your account. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sign Up | CRM</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#1f2937;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
.box{background:#fff;padding:32px;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.08);width:100%;max-width:400px;}
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
<h1>Create your CRM account</h1>
<?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="POST">
<label>Company Name<input type="text" name="company_name" value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>" required autofocus></label>
<label>Your Email<input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required></label>
<label>Password<input type="password" name="password" minlength="8" required></label>
<label>Confirm Password<input type="password" name="confirm_password" minlength="8" required></label>
<button type="submit">Create Account</button>
</form>
<p class="foot">Already have an account? <a href="login.php">Sign in</a></p>
</div>
</body>
</html>

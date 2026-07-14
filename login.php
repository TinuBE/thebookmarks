<?php
require_once __DIR__ . '/includes/functions.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    if (verify_password($password)) {
        // Session-ID neu erzeugen gegen Session-Fixation
        session_regenerate_id(true);
        $_SESSION['ls_logged_in'] = true;
        header('Location: backend/index.php');
        exit;
    }
    $error = 'Falsches Passwort.';
}

if (is_logged_in()) {
    header('Location: backend/index.php');
    exit;
}

$config = get_config();
$siteTitle = h($config['site_title'] ?? 'Link-Sammlung');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login &ndash; <?= $siteTitle ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-body">
    <div class="login-card glass">
        <h1><?= $siteTitle ?></h1>
        <p class="login-sub">Backend-Login</p>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <label for="password">Passwort</label>
            <input type="password" id="password" name="password" required autofocus>
            <button type="submit">Anmelden</button>
        </form>
        <a class="back-link" href="index.php">&larr; Zur Link-Sammlung</a>
    </div>
</body>
</html>

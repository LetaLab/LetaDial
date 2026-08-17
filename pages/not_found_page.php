<?php
declare(strict_types=1);
defined('DIALVAULT_APP') or die();
$app_name = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>404 — <?= $app_name ?></title>
<link rel="shortcut icon" href="/assets/icons/favicon.png" type="image/png">
<link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/design-system.css">
<script nonce="<?= CSP::nonce() ?>">(function(){const t=localStorage.getItem('dv-theme');if(t)document.documentElement.setAttribute('data-theme',t)})();</script>
<link rel="stylesheet" href="/assets/css/pages/404.css">
</head>
<body>
<div class="box">
    <div class="code">404</div>
    <h1>Page not found</h1>
    <p>The page you're looking for doesn't exist or has been moved.</p>
    <a href="/" class="btn btn-primary">← Back to <?= $app_name ?></a>
</div>
</body>
</html>

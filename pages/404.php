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
<script>(function(){const t=localStorage.getItem('dv-theme');if(t)document.documentElement.setAttribute('data-theme',t)})();</script>
<style>
body{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem}
.box{text-align:center;max-width:400px}
.code{font-size:6rem;font-weight:800;color:var(--primary);line-height:1;margin-bottom:.5rem}
h1{font-size:1.4rem;margin-bottom:.75rem}
p{color:var(--text-muted);margin-bottom:1.5rem}
</style>
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

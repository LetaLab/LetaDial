<?php
declare(strict_types=1);
defined('DIALVAULT_APP') or die();

// SEC-055: Only allow POST for logout.
// GET-based logout could be triggered by <img src="/logout"> CSRF — not session theft,
// but forced logout. Reject GET with a plain redirect to login.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /login');
    exit;
}

CSRF::require();
Auth::logout();
header('Location: /login');
exit;

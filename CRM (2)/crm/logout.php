<?php
require __DIR__ . '/inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!empty($_SESSION['uid'])) activity('logout');
    $_SESSION = [];
    session_destroy();
}
redirect('login.php');

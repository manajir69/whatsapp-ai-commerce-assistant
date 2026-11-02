<?php
/** টেস্ট/অ্যাডমিন পাতাগুলোর সাধারণ Sign in (session ভিত্তিক) */

require_once __DIR__ . '/db.php';

function requireLogin() {
    if (session_status() === PHP_SESSION_NONE) session_start();

    if (($_GET['logout'] ?? '') === '1') {
        $_SESSION['bis_ok'] = false;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    if ($_SESSION['bis_ok'] ?? false) return;

    $pw = (string)cfg('console.password', '');
    if ($pw === '') {
        exit('Set console.password in config.php before signing in.');
    }

    $err = '';
    if (isset($_POST['pw'])) {
        if (hash_equals($pw, (string)$_POST['pw'])) {
            $_SESSION['bis_ok'] = true;
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
        $err = 'Incorrect password.';
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title>Sign in</title>
      <body style="font:15px/1.6 system-ui,sans-serif;max-width:340px;margin:80px auto;padding:0 16px">
      <h3 style="margin-bottom:4px">Demo Commerce Bot</h3>
      <p style="color:#666;margin-top:0;font-size:14px">Enter your console password</p>
      <form method="post">
        <input name="pw" type="password" autofocus
               style="width:100%;padding:11px;font-size:15px;border:1px solid #ccc;border-radius:6px">
        <button style="margin-top:10px;padding:11px 22px;border:0;border-radius:6px;
                       background:#075E54;color:#fff;font-size:15px;cursor:pointer">Sign in</button>
      </form>';
    if ($err) echo '<p style="color:#b91c1c">' . $err . '</p>';
    exit;
}

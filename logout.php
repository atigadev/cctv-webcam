<?php
require_once __DIR__ . '/includes/auth.php';

// Logout hanya lewat POST + token, supaya situs lain tidak bisa
// memaksa pengguna keluar lewat link/gambar.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !empty($_SESSION['csrf'])
    && hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    logout();
    header('Location: login.php');
    exit;
}

header('Location: ' . (is_logged_in() ? 'monitor3.php' : 'login.php'));
exit;

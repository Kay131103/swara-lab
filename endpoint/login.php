<?php
include('../conn/conn.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../login1.php');
}
require_csrf();

$username = trim(isset($_POST['username']) ? $_POST['username'] : '');
$password = isset($_POST['password']) ? $_POST['password'] : '';

$stmt = $conn->prepare(
    'SELECT tbl_user_id, password, CASE WHEN verified THEN 1 ELSE 0 END AS verified '
    . 'FROM tbl_user WHERE username = :username'
);
$stmt->execute(['username' => $username]);
$user = $stmt->fetch();

// Same message for unknown user and wrong password, so usernames can't be probed.
if (!$user || !password_verify($password, $user['password'])) {
    flash('Incorrect username or password.');
    redirect('../login1.php');
}

if (!$user['verified']) {
    flash('Please verify your email address before logging in.', 'warning');
    redirect('../login1.php');
}

session_regenerate_id(true);
$_SESSION['username'] = $username;
$_SESSION['user_id'] = (int) $user['tbl_user_id'];
redirect('../index.php');

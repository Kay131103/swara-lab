<?php
// Shared bootstrap: config, session, database connection and small helpers.

// config.php holds local settings; without it, the defaults in
// config.sample.php (overridable by environment variables) are used.
$configFile = __DIR__ . '/../config.php';
$config = require (file_exists($configFile) ? $configFile : __DIR__ . '/../config.sample.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

try {
    $conn = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset=utf8mb4",
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection failed.');
}

/** HTML-escape a value for output. */
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Redirect (relative to the app root) and stop. */
function redirect($path)
{
    header('Location: ' . $path);
    exit();
}

/** Store a one-off message to show on the next page. */
function flash($message, $type = 'danger')
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

/** Render and clear the pending flash message, if any. */
function render_flash()
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return '<div class="alert alert-' . e($f['type']) . '">' . e($f['message']) . '</div>';
}

function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Abort unless the POSTed CSRF token matches the session's. */
function require_csrf()
{
    $sent = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Invalid form submission. Please go back, reload the page and try again.');
    }
}

function require_login()
{
    if (!isset($_SESSION['username'])) {
        flash('You must log in first.', 'warning');
        redirect('login1.php');
    }
}

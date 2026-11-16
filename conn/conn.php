<?php
// Shared bootstrap: config, session, database connection and small helpers.

// config.php holds local settings; without it, the defaults in
// config.sample.php (overridable by environment variables) are used.
$configFile = __DIR__ . '/../config.php';
$config = require (file_exists($configFile) ? $configFile : __DIR__ . '/../config.sample.php');
$config['app_env'] = isset($config['app_env']) ? $config['app_env'] : (getenv('APP_ENV') ?: 'development');
$config['max_jobs_per_hour'] = max(
    1,
    (int) (isset($config['max_jobs_per_hour']) ? $config['max_jobs_per_hour'] : (getenv('MAX_JOBS_PER_HOUR') ?: 10))
);
$config['max_concurrent_jobs_per_user'] = max(
    1,
    min(3, (int) (isset($config['max_concurrent_jobs_per_user']) ? $config['max_concurrent_jobs_per_user'] : (getenv('MAX_CONCURRENT_JOBS_PER_USER') ?: 1)))
);
$config['max_user_storage_mb'] = max(
    1,
    (int) (isset($config['max_user_storage_mb']) ? $config['max_user_storage_mb'] : (getenv('MAX_USER_STORAGE_MB') ?: 100))
);

if ($config['app_env'] === 'production') {
    if (empty($config['db']['url'])) {
        throw new RuntimeException('Production requires a PostgreSQL connection URL.');
    }
}

try {
    if ($config['db']['url'] !== '') {
        $dbUrl = parse_url($config['db']['url']);
        if (
            !$dbUrl
            || !isset($dbUrl['scheme'], $dbUrl['host'], $dbUrl['path'], $dbUrl['user'], $dbUrl['pass'])
            || !in_array($dbUrl['scheme'], ['postgres', 'postgresql'], true)
            || strpos($dbUrl['host'], ';') !== false
        ) {
            throw new RuntimeException('POSTGRES_URL must be a valid PostgreSQL connection URL.');
        }
        $query = [];
        parse_str(isset($dbUrl['query']) ? $dbUrl['query'] : '', $query);
        $sslMode = isset($query['sslmode']) ? $query['sslmode'] : 'require';
        $dbName = rawurldecode(ltrim($dbUrl['path'], '/'));
        if (
            !preg_match('/^[A-Za-z0-9_.-]+$/', $dbName)
            || !in_array($sslMode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)
        ) {
            throw new RuntimeException('POSTGRES_URL contains an unsupported database name or SSL mode.');
        }
        $dsn = 'pgsql:host=' . $dbUrl['host']
            . ';port=' . (isset($dbUrl['port']) ? $dbUrl['port'] : 5432)
            . ';dbname=' . $dbName
            . ';sslmode=' . $sslMode;
        $dbUser = rawurldecode($dbUrl['user']);
        $dbPass = rawurldecode($dbUrl['pass']);
    } else {
        $dsn = "pgsql:host={$config['db']['host']};port={$config['db']['port']};dbname={$config['db']['name']}";
        $dbUser = $config['db']['user'];
        $dbPass = $config['db']['pass'];
    }

    $conn = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection failed.');
}

/* apply the schema on first boot so app_sessions exists before session_start */
try {
    $conn->query('SELECT 1 FROM tbl_user LIMIT 1');
} catch (Throwable $schemaMissing) {
    $schemaFile = __DIR__ . '/../login_email_verification.sql';
    if (is_file($schemaFile)) {
        $conn->exec(file_get_contents($schemaFile));
    }
}


require_once __DIR__ . '/PostgresSessionHandler.php';
if (session_status() === PHP_SESSION_NONE) {
    session_set_save_handler(new PostgresSessionHandler($conn), true);
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => $config['app_env'] === 'production',
    ]);
}

/* --- demo mode: schema auto-applies, login removed, everyone shares
       a single guest account. Remove this block to restore login. --- */
try {
    $conn->prepare(
        "INSERT INTO tbl_user (first_name, last_name, email, username, password, verification_code, verified)
         VALUES ('Guest', 'User', 'guest@melomaster.local', 'guest', '', '000000', true)
         ON CONFLICT (email) DO NOTHING"
    )->execute();
    $stmt = $conn->prepare("SELECT tbl_user_id FROM tbl_user WHERE email = 'guest@melomaster.local' LIMIT 1");
    $stmt->execute();
    $guestId = $stmt->fetchColumn();
    if ($guestId && !isset($_SESSION['user_id'])) {
        $_SESSION['user_id'] = (int) $guestId;
        $_SESSION['username'] = 'guest';
    }
} catch (Throwable $e) {
    error_log('Demo-mode guest login failed: ' . $e->getMessage());
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
    if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
        unset($_SESSION['username'], $_SESSION['user_id']);
        flash('You must log in first.', 'warning');
        redirect('login1.php');
    }
}

function run_python_json($config, $script, $arguments)
{
    $command = array_merge([$config['python'], __DIR__ . '/../' . $script], $arguments);
    $stderrPath = tempnam(sys_get_temp_dir(), 'melo_stderr_');
    if ($stderrPath === false) {
        throw new RuntimeException('Could not create an audio processing log file.');
    }
    $proc = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['file', $stderrPath, 'w']],
        $pipes,
        __DIR__ . '/..'
    );
    if (!is_resource($proc)) {
        unlink($stderrPath);
        throw new RuntimeException('Could not start the audio processing runtime.');
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exitCode = proc_close($proc);
    $stderr = file_get_contents($stderrPath);
    if (!unlink($stderrPath)) {
        error_log("Could not remove temporary processing log $stderrPath.");
    }
    $result = json_decode($stdout, true);
    if ($exitCode !== 0 || !is_array($result)) {
        error_log("$script failed ($exitCode): $stderr $stdout");
        throw new RuntimeException(isset($result['error']) ? $result['error'] : ('Audio processing failed (exit ' . $exitCode . '): ' . substr($stderr, -140)));
    }
    return $result;
}

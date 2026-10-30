<?php
// Copy this file to config.php and fill in your own values (config.php is
// git-ignored so credentials never end up in the repository). Every value can
// also be set with environment variables for Vercel or Docker Compose.

function env_or($name, $default)
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

$postgresUrl = env_or(
    'POSTGRES_URL_NON_POOLING',
    env_or('POSTGRES_URL', env_or('DATABASE_URL', ''))
);
$dbPass = env_or('DB_PASS', '');
$smtpEnabled = filter_var(env_or('SMTP_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
$smtpUser = env_or('SMTP_USER', '');
$smtpPass = env_or('SMTP_PASS', '');
$smtpFrom = env_or('SMTP_FROM', '');

return [
    'app_env' => env_or('APP_ENV', 'development'),
    'db' => [
        'url' => $postgresUrl,
        'host' => env_or('DB_HOST', 'localhost'),
        'port' => (int) env_or('DB_PORT', 5432),
        'name' => env_or('DB_NAME', 'melomaster'),
        'user' => env_or('DB_USER', 'melomaster'),
        'pass' => $dbPass,
    ],

    // SMTP account used to send verification codes. For Gmail, create an
    // "App password" (Google Account > Security) and use it here.
    // While 'enabled' is false no email is sent and the code is shown on the
    // verification page instead - handy locally, never do this in production.
    'smtp' => [
        'enabled'    => $smtpEnabled,
        'host'       => env_or('SMTP_HOST', 'smtp.gmail.com'),
        'port'       => (int) env_or('SMTP_PORT', 465),
        'secure'     => env_or('SMTP_SECURE', 'ssl'),
        'user'       => $smtpUser,
        'pass'       => $smtpPass,
        'from_email' => $smtpFrom,
        'from_name'  => 'MeloMaster',
    ],

    // Python interpreter that has the packages from requirements.txt installed,
    // e.g. 'python3', 'py', or 'C:\\path\\to\\.venv\\Scripts\\python.exe'.
    'python' => env_or('PYTHON', 'python'),
    'max_upload_mb' => max(1, min(4, (int) env_or('MAX_UPLOAD_MB', 4))),
    'max_audio_seconds' => max(1, min(30, (int) env_or('MAX_AUDIO_SECONDS', 30))),
    'max_concurrent_jobs_per_user' => max(1, min(3, (int) env_or('MAX_CONCURRENT_JOBS_PER_USER', 1))),
    'max_jobs_per_hour' => max(1, (int) env_or('MAX_JOBS_PER_HOUR', 10)),
    'max_user_storage_mb' => max(1, (int) env_or('MAX_USER_STORAGE_MB', 100)),
    'job_retention_hours' => max(24, (int) env_or('JOB_RETENTION_HOURS', 24)),
];

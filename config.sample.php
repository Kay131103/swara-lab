<?php
// Copy this file to config.php and fill in your own values (config.php is
// git-ignored so credentials never end up in the repository). Every value can
// also be set with an environment variable, which is what docker-compose does.

function env_or($name, $default)
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

return [
    'db' => [
        'host' => env_or('DB_HOST', 'localhost'),
        'name' => env_or('DB_NAME', 'login_email_verification'),
        'user' => env_or('DB_USER', 'root'),
        'pass' => env_or('DB_PASS', ''),
    ],

    // SMTP account used to send verification codes. For Gmail, create an
    // "App password" (Google Account > Security) and use it here.
    // While 'enabled' is false no email is sent and the code is shown on the
    // verification page instead - handy locally, never do this in production.
    'smtp' => [
        'enabled'    => filter_var(env_or('SMTP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'host'       => env_or('SMTP_HOST', 'smtp.gmail.com'),
        'port'       => (int) env_or('SMTP_PORT', 465),
        'secure'     => env_or('SMTP_SECURE', 'ssl'),
        'user'       => env_or('SMTP_USER', 'you@example.com'),
        'pass'       => env_or('SMTP_PASS', ''),
        'from_email' => env_or('SMTP_FROM', 'you@example.com'),
        'from_name'  => 'MeloMaster',
    ],

    // Python interpreter that has the packages from requirements.txt installed,
    // e.g. 'python3', 'py', or 'C:\\path\\to\\.venv\\Scripts\\python.exe'.
    'python' => env_or('PYTHON', 'python'),

    'max_upload_mb' => 9,
];

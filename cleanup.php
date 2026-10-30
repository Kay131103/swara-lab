<?php
include('./conn/conn.php');

$secret = getenv('CRON_SECRET') ?: '';
$authorization = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
if ($secret === '' || !hash_equals('Bearer ' . $secret, $authorization)) {
    http_response_code(401);
    exit('Unauthorized.');
}

set_time_limit(240);

$stale = $conn->prepare(
    "UPDATE transcription_jobs SET status = 'failed', "
    . "audio_size_bytes = CASE WHEN audio_blob_path IS NULL THEN 0 ELSE audio_size_bytes END, "
    . "midi_size_bytes = CASE WHEN midi_blob_path IS NULL THEN 0 ELSE midi_size_bytes END, "
    . "error_message = 'Processing timed out. Please try again.', updated_at = now() "
    . "WHERE status = 'processing' AND created_at < now() - INTERVAL '10 minutes'"
);
$stale->execute();
$conn->exec('DELETE FROM app_sessions WHERE expires_at <= now()');

$stmt = $conn->prepare(
    "SELECT id, audio_blob_path, midi_blob_path FROM transcription_jobs "
    . "WHERE status IN ('completed', 'failed') "
    . "AND created_at < now() - make_interval(hours => CAST(:hours AS integer)) "
    . 'ORDER BY created_at LIMIT 20'
);
$deleted = 0;
$deadline = microtime(true) + 230;
do {
    $stmt->execute(['hours' => $config['job_retention_hours']]);
    $expired = $stmt->fetchAll();
    $batchDeleted = 0;
    foreach ($expired as $job) {
        if (microtime(true) >= $deadline) {
            break;
        }
        try {
            foreach ([$job['audio_blob_path'], $job['midi_blob_path']] as $path) {
                if ($path) {
                    run_python_json($config, 'blob_storage.py', ['delete', $path]);
                }
            }
            $conn->prepare('DELETE FROM transcription_jobs WHERE id = :id')->execute(['id' => $job['id']]);
            $batchDeleted++;
        } catch (Throwable $error) {
            error_log("Could not clean expired transcription {$job['id']}: " . $error->getMessage());
        }
    }
    $deleted += $batchDeleted;
} while (count($expired) === 20 && $batchDeleted > 0 && microtime(true) < $deadline);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['expired_jobs_deleted' => $deleted]);

<?php
include('./conn/conn.php');
require_login();

$id = isset($_GET['id']) ? $_GET['id'] : '';
$type = isset($_GET['type']) ? $_GET['type'] : '';
if (!preg_match('/^[a-f0-9]{32}$/', $id) || !in_array($type, ['audio', 'midi'], true)) {
    http_response_code(404);
    exit('File not found.');
}

$stmt = $conn->prepare(
    'SELECT audio_ext, audio_blob_path, midi_blob_path FROM transcription_jobs '
    . "WHERE id = :id AND user_id = :user_id AND status = 'completed'"
);
$stmt->execute(['id' => $id, 'user_id' => $_SESSION['user_id']]);
$job = $stmt->fetch();
$blobPath = $job ? ($type === 'midi' ? $job['midi_blob_path'] : $job['audio_blob_path']) : null;
if (!$job || !$blobPath) {
    http_response_code(404);
    exit('File not found.');
}

$tempPath = tempnam(sys_get_temp_dir(), 'melo_download_');
if ($tempPath === false) {
    http_response_code(500);
    exit('File is temporarily unavailable.');
}
try {
    run_python_json($config, 'blob_storage.py', ['get', $blobPath, '--file', $tempPath]);
    $ext = $type === 'midi' ? 'mid' : $job['audio_ext'];
    $contentTypes = [
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'flac' => 'audio/flac',
        'm4a' => 'audio/mp4',
        'mid' => 'audio/midi',
    ];
    header('Content-Type: ' . $contentTypes[$ext]);
    header('Content-Length: ' . filesize($tempPath));
    header('Content-Disposition: ' . ($type === 'midi' ? 'attachment' : 'inline')
        . '; filename="melomaster-' . $type . '.' . $ext . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($tempPath);
} catch (Throwable $error) {
    error_log("Could not retrieve $type Blob for job $id: " . $error->getMessage());
    http_response_code(500);
    echo 'File is temporarily unavailable.';
} finally {
    if (is_file($tempPath) && !unlink($tempPath)) {
        error_log("Could not remove temporary download $tempPath.");
    }
}

<?php
include('./conn/conn.php');
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
if (empty($_POST) && empty($_FILES)) {
    flash('That file is too large to upload.');
    redirect('index.php');
}
require_csrf();

const ALLOWED_AUDIO = ['mp3', 'wav', 'ogg', 'flac', 'm4a'];

function update_job_failure($conn, $jobId, $message)
{
    try {
        $stmt = $conn->prepare(
            "UPDATE transcription_jobs SET status = 'failed', error_message = :message, updated_at = now(), "
            . "audio_size_bytes = CASE WHEN audio_blob_path IS NULL THEN 0 ELSE audio_size_bytes END, "
            . "midi_size_bytes = CASE WHEN midi_blob_path IS NULL THEN 0 ELSE midi_size_bytes END "
            . "WHERE id = :id AND status = 'processing'"
        );
        $stmt->execute(['message' => substr($message, 0, 255), 'id' => $jobId]);
    } catch (Throwable $error) {
        error_log("Could not mark transcription job $jobId as failed: " . $error->getMessage());
    }
}

$file = isset($_FILES['audio_file']) ? $_FILES['audio_file'] : null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    $tooBig = $file && in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    flash($tooBig ? 'That file is too large to upload.' : 'Please choose an audio file to upload.');
    redirect('index.php');
}
if ($file['size'] > $config['max_upload_mb'] * 1024 * 1024) {
    flash("Size shouldn't exceed {$config['max_upload_mb']} MB.");
    redirect('index.php');
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ALLOWED_AUDIO, true)) {
    flash('Unsupported file type. Please upload ' . strtoupper(implode(', ', ALLOWED_AUDIO)) . '.');
    redirect('index.php');
}

$jobId = bin2hex(random_bytes(16));
$tempBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "melomaster-$jobId";
$audioPath = "$tempBase.$ext";
$midiPath = "$tempBase.mid";
if (!move_uploaded_file($file['tmp_name'], $audioPath)) {
    flash('Could not save the uploaded file for processing.');
    redirect('index.php');
}

$userId = (int) $_SESSION['user_id'];
try {
    $conn->beginTransaction();
    $lock = $conn->prepare('SELECT tbl_user_id FROM tbl_user WHERE tbl_user_id = :user_id FOR UPDATE');
    $lock->execute(['user_id' => $userId]);
    if (!$lock->fetch()) {
        $conn->rollBack();
        unset($_SESSION['username'], $_SESSION['user_id']);
        flash('Your account could not be found. Please log in again.', 'warning');
        redirect('login1.php');
    }

    $conn->prepare(
        "UPDATE transcription_jobs SET status = 'failed', "
        . "audio_size_bytes = CASE WHEN audio_blob_path IS NULL THEN 0 ELSE audio_size_bytes END, "
        . "error_message = 'Processing timed out. Please try again.', updated_at = now() "
        . "WHERE user_id = :user_id AND status = 'processing' "
        . "AND created_at < now() - INTERVAL '10 minutes'"
    )->execute(['user_id' => $userId]);

    $active = $conn->prepare(
        "SELECT COUNT(*) FROM transcription_jobs WHERE user_id = :user_id AND status = 'processing'"
    );
    $active->execute(['user_id' => $userId]);
    if ((int) $active->fetchColumn() >= $config['max_concurrent_jobs_per_user']) {
        $conn->rollBack();
        unlink($audioPath);
        flash('A transcription is already running. Please wait for it to finish.');
        redirect('index.php');
    }

    $recent = $conn->prepare(
        "SELECT COUNT(*) FROM transcription_jobs WHERE user_id = :user_id "
        . "AND created_at >= now() - INTERVAL '1 hour'"
    );
    $recent->execute(['user_id' => $userId]);
    if ((int) $recent->fetchColumn() >= $config['max_jobs_per_hour']) {
        $conn->rollBack();
        unlink($audioPath);
        flash('You have reached the transcription upload limit. Please try again later.');
        redirect('index.php');
    }

    $stored = $conn->prepare(
        'SELECT COALESCE(SUM(audio_size_bytes + midi_size_bytes), 0) '
        . 'FROM transcription_jobs WHERE user_id = :user_id'
    );
    $stored->execute(['user_id' => $userId]);
    $storageLimit = $config['max_user_storage_mb'] * 1024 * 1024;
    if ((int) $stored->fetchColumn() + $file['size'] > $storageLimit) {
        $conn->rollBack();
        unlink($audioPath);
        flash('Your stored audio has reached its limit. Please wait for older uploads to expire.');
        redirect('index.php');
    }

    $insert = $conn->prepare(
        "INSERT INTO transcription_jobs (id, user_id, audio_ext, audio_size_bytes, status) "
        . "VALUES (:id, :user_id, :audio_ext, :audio_size_bytes, 'processing')"
    );
    $insert->execute([
        'id' => $jobId,
        'user_id' => $userId,
        'audio_ext' => $ext,
        'audio_size_bytes' => $file['size'],
    ]);
    $conn->commit();
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    if (is_file($audioPath) && !unlink($audioPath)) {
        error_log("Could not remove temporary upload $jobId.");
    }
    error_log('Could not reserve transcription: ' . $error->getMessage());
    flash('The upload could not be started. Please try again later.');
    redirect('index.php');
}

session_write_close();
set_time_limit(270);
$audioBlobPath = "users/$userId/$jobId/audio.$ext";
$midiBlobPath = "users/$userId/$jobId/result.mid";
try {
    $tala = isset($_POST['tala']) ? trim($_POST['tala']) : 'auto';
    if ($tala !== 'auto' && !ctype_digit($tala)) {
        $tala = 'auto';
    }
    $result = run_python_json($config, 'process_audio.py', [
        $audioPath,
        $midiPath,
        '--max-duration',
        (string) $config['max_audio_seconds'],
        '--beats',
        (string) $tala,
    ]);
    $notes = $result['notes'];
    $raga = isset($result['raga']) ? $result['raga'] : null;

    run_python_json($config, 'blob_storage.py', [
        'put',
        $audioBlobPath,
        '--file',
        $audioPath,
        '--content-type',
        [
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'm4a' => 'audio/mp4',
        ][$ext],
    ]);
    $conn->prepare('UPDATE transcription_jobs SET audio_blob_path = :path WHERE id = :id')
        ->execute(['path' => $audioBlobPath, 'id' => $jobId]);

    run_python_json($config, 'blob_storage.py', [
        'put',
        $midiBlobPath,
        '--file',
        $midiPath,
        '--content-type',
        'audio/midi',
    ]);
    $conn->prepare(
        'UPDATE transcription_jobs SET midi_blob_path = :path, midi_size_bytes = :size WHERE id = :id'
    )->execute(['path' => $midiBlobPath, 'size' => filesize($midiPath), 'id' => $jobId]);
    $conn->prepare(
        "UPDATE transcription_jobs SET status = 'completed', notes_json = CAST(:notes AS jsonb), "
        . 'error_message = NULL, updated_at = now() WHERE id = :id'
    )->execute([
        'notes' => json_encode(
            $raga !== null ? ['notes' => $notes, 'raga' => $raga] : $notes,
            JSON_THROW_ON_ERROR
        ),
        'id' => $jobId,
    ]);
} catch (Throwable $error) {
    error_log("Transcription job $jobId failed: " . $error->getMessage());
    update_job_failure(
        $conn,
        $jobId,
        'Transcription failed: ' . substr($error->getMessage(), 0, 180)
    );
} finally {
    foreach ([$audioPath, $midiPath] as $tempPath) {
        if (is_file($tempPath) && !unlink($tempPath)) {
            error_log("Could not remove temporary audio processing file $tempPath.");
        }
    }
}

redirect('job.php?id=' . $jobId);

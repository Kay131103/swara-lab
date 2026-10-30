<?php
include('./conn/conn.php');
require_login();

$id = isset($_GET['id']) ? $_GET['id'] : '';
if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
    http_response_code(404);
    exit('Job not found.');
}

$stmt = $conn->prepare(
    'SELECT status, notes_json, error_message FROM transcription_jobs '
    . 'WHERE id = :id AND user_id = :user_id'
);
$stmt->execute(['id' => $id, 'user_id' => $_SESSION['user_id']]);
$job = $stmt->fetch();
if (!$job) {
    http_response_code(404);
    exit('Job not found.');
}

$notes = [];
if ($job['status'] === 'completed') {
    try {
        $notes = json_decode($job['notes_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($notes)) {
            throw new UnexpectedValueException('Stored notes are not a list.');
        }
    } catch (Throwable $error) {
        error_log("Could not decode transcription notes for job $id: " . $error->getMessage());
        http_response_code(500);
        exit('The transcription result could not be read.');
    }
}

$noteCount = count($notes);
$range = '';
if ($noteCount) {
    $midis = array_column($notes, 'midi');
    $low = $notes[array_search(min($midis), $midis)];
    $high = $notes[array_search(max($midis), $midis)];
    $range = $low['note'] === $high['note'] ? $low['note'] : $low['note'] . ' – ' . $high['note'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MeloMaster transcription</title>
    <style>
        body { font-family: sans-serif; max-width: 760px; margin: 3rem auto; padding: 0 1rem; color: #5C5766; background: #F4F1EB; }
        main { padding: 2rem; border-radius: 1rem; background: rgba(255, 248, 220, .9); }
        #notes { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: 1rem; }
        .note { padding: .5rem; border: 0; border-radius: .5rem; background: white; color: inherit; cursor: pointer; }
        .note.active { background: #E69A8D; color: white; }
        audio { width: 100%; margin-top: 1rem; }
    </style>
</head>
<body>
<main>
    <h1>Transcription</h1>
    <?php if ($job['status'] === 'failed') : ?>
        <p role="alert"><?= e($job['error_message'] ?: 'Transcription failed.') ?></p>
    <?php elseif ($job['status'] === 'processing') : ?>
        <p role="status">Transcription is still processing. Refresh this page in a moment.</p>
    <?php else : ?>
        <p>Transcription complete — <?= $noteCount ?> notes<?= $range ? ', range ' . e($range) : '' ?>.</p>
        <?php if ($noteCount === 0) : ?>
            <p>No clear notes were detected. Try a short recording of a single instrument or voice with less background noise.</p>
        <?php endif ?>
        <audio controls id="audioPlayer" preload="metadata" src="download.php?id=<?= e($id) ?>&amp;type=audio">
            Your browser does not support the audio element.
        </audio>
        <?php if ($noteCount > 0) : ?>
            <p><a href="download.php?id=<?= e($id) ?>&amp;type=midi">Download MIDI</a></p>
            <div id="notes">
                <?php foreach ($notes as $note) : ?>
                    <button class="note" type="button" data-start="<?= e($note['start']) ?>"
                            data-end="<?= e($note['end']) ?>"
                            title="<?= e(sprintf('%.2fs, %.1f Hz', $note['start'], $note['frequency'])) ?>">
                        <?= e($note['note']) ?>
                    </button>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    <?php endif ?>
    <p><a href="index.php">Back to MeloMaster</a></p>
</main>
<script>
    var player = document.getElementById('audioPlayer');
    if (player) {
        var noteElements = Array.prototype.slice.call(document.querySelectorAll('.note'));
        var notes = noteElements.map(function(element) {
            return {
                element: element,
                start: parseFloat(element.dataset.start),
                end: parseFloat(element.dataset.end)
            };
        });

        function highlightNotes() {
            var time = player.currentTime;
            var current = notes.find(function(note) {
                return time >= note.start && time < note.end;
            });
            notes.forEach(function(note) {
                note.element.classList.toggle('active', note === current);
            });
            if (!player.paused) window.requestAnimationFrame(highlightNotes);
        }

        player.addEventListener('play', highlightNotes);
        player.addEventListener('seeked', highlightNotes);
        notes.forEach(function(note) {
            note.element.addEventListener('click', function() {
                player.currentTime = note.start;
                player.play();
            });
        });
    }
</script>
</body>
</html>

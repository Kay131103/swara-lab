<?php
include('./conn/conn.php');
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
// An upload bigger than post_max_size arrives with an empty $_POST, so check
// that before the CSRF token (which would otherwise be missing too).
if (empty($_POST) && empty($_FILES)) {
    flash('That file is too large to upload.');
    redirect('index.php');
}
require_csrf();

const ALLOWED_AUDIO = [
    'mp3'  => 'audio/mpeg',
    'wav'  => 'audio/wav',
    'ogg'  => 'audio/ogg',
    'flac' => 'audio/flac',
    'm4a'  => 'audio/mp4',
];

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
if (!isset(ALLOWED_AUDIO[$ext])) {
    flash('Unsupported file type. Please upload ' . strtoupper(implode(', ', array_keys(ALLOWED_AUDIO))) . '.');
    redirect('index.php');
}

// Never reuse the user-supplied file name on disk.
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
$id = bin2hex(random_bytes(8));
$audioName = "$id.$ext";
$midiName = "$id.mid";
if (!move_uploaded_file($file['tmp_name'], "$uploadDir/$audioName")) {
    flash('Could not save the uploaded file.');
    redirect('index.php');
}

/**
 * Run the transcriber without going through a shell, so nothing in the
 * arguments can be interpreted as a command. Returns [notes, error].
 */
function run_transcriber($python, $audioPath, $midiPath)
{
    $cmd = [$python, __DIR__ . '/transcribe.py', '--format', 'json', '--midi', $midiPath, $audioPath];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
    if (!is_resource($proc)) {
        return [null, 'Could not start Python. Check the "python" setting in config.php.'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    $result = json_decode($stdout, true);
    if ($exitCode === 0 && isset($result['notes'])) {
        return [$result['notes'], null];
    }
    error_log("transcribe.py failed ($exitCode): $stderr $stdout");
    if (isset($result['error'])) {
        return [null, 'Could not analyse this file: ' . $result['error']];
    }
    return [null, 'The transcription script failed. Check that the Python requirements are installed.'];
}

set_time_limit(300);
list($notes, $error) = run_transcriber($config['python'], "$uploadDir/$audioName", "$uploadDir/$midiName");

if ($error !== null) {
    @unlink("$uploadDir/$audioName");
    flash($error);
    redirect('index.php');
}

$noteCount = count($notes);
$range = '';
if ($noteCount) {
    $midis = array_column($notes, 'midi');
    $low = $notes[array_search(min($midis), $midis)]['note'];
    $high = $notes[array_search(max($midis), $midis)]['note'];
    $range = $low === $high ? $low : "$low &ndash; $high";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MeloMaster</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Josefin+Slab:wght@600;700&display=swap">
    <style>
        body {
            font-family: 'Josefin Slab', serif;
            margin: 0;
            padding: 0 0 100px;
            background-color: #F4F1EB; /* Light beige background color */
            background-image: url('https://i.pinimg.com/originals/cf/dd/ff/cfddff3e2d2556a6a3cebce1e12c9859.jpg'); /* Soft-themed background image */
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        .audio-player {
            text-align: center;
            margin: 20px 0;
        }
        .audio-player audio {
            width: min(600px, 90%);
        }
        .navbar {
            background-color: rgba(255, 248, 220, 0.6); /* Cream semi-transparent navbar */
            overflow: hidden;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            padding: 10px 20px;
            box-sizing: border-box;
            z-index: 2;
            backdrop-filter: blur(5px); /* Soft blur effect */
        }
        .navbar a {
            float: left;
            display: block;
            color: #5C5766; /* Dark gray text color */
            text-align: center;
            padding: 14px 20px;
            text-decoration: none;
            font-size: 1.2rem;
            transition: background-color 0.3s, color 0.3s;
            border-radius: 30px; /* Rounded corners */
        }
        .navbar a:hover {
            background-color: rgba(255, 248, 220, 0.8); /* Cream semi-transparent on hover */
            color: #8F8DA2; /* Soft purple text color on hover */
        }
        .navbar .logout {
            float: right;
            margin-right: 20px;
        }
        .welcome-msg {
            color: #FFF8EC;
            font-weight: bold;
            font-size: 1.5rem;
            text-align: center;
            margin-top: 110px;
        }
        .content {
            max-width: 800px;
            margin: auto;
            padding: 20px;
            background-color: rgba(255, 248, 220, 0.8); /* Cream semi-transparent content background */
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            color: #5C5766;
        }
        .summary {
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }
        .actions a {
            display: inline-block;
            margin: 0 5px 15px;
            padding: 8px 18px;
            background-color: #E69A8D; /* Soft orange button background */
            color: #FDFDFD;
            border-radius: 40px;
            text-decoration: none;
            transition: background-color 0.3s;
        }
        .actions a:hover {
            background-color: #CB756B; /* Darker orange on hover */
        }
        .notes {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            justify-content: flex-start;
        }
        .note {
            min-width: 2.6em;
            padding: 6px 4px;
            border-radius: 8px;
            background-color: rgba(255, 255, 255, 0.7);
            cursor: pointer;
            font-size: 1.1rem;
            transition: background-color 0.15s, transform 0.15s;
        }
        .note:hover {
            background-color: #F6D6CF;
        }
        .note.active {
            background-color: #E69A8D;
            color: #FDFDFD;
            transform: scale(1.12);
        }
        footer {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background-color: rgba(255, 248, 220, 0.6); /* Cream semi-transparent background */
            padding: 20px 0;
            text-align: center;
            color: #5C5766;
        }
        .footer-content {
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <a href="index.php">MeloMaster</a>
        <a href="index.php?logout=1" class="logout">Logout</a>
    </div>

    <div class="welcome-msg">Musical Notes</div>
    <div class="audio-player">
        <audio controls id="audioPlayer" src="uploads/<?= e($audioName) ?>" preload="auto">
            Your browser does not support the audio element.
        </audio>
    </div>

    <div class="content">
        <?php if ($noteCount === 0) : ?>
            <p>No clear notes were detected. Try a recording of a single instrument or voice with less background noise.</p>
        <?php else : ?>
            <div class="summary">
                <div><strong><?= $noteCount ?></strong> notes</div>
                <div>Range: <strong><?= $range ?></strong></div>
            </div>
            <div class="actions">
                <a href="uploads/<?= e($midiName) ?>" download="<?= e(pathinfo($file['name'], PATHINFO_FILENAME)) ?>.mid">
                    <i class="fas fa-download"></i> Download MIDI
                </a>
            </div>
            <div class="notes" id="notes">
                <?php foreach ($notes as $n) : ?>
                    <span class="note" data-start="<?= e($n['start']) ?>" data-end="<?= e($n['end']) ?>"
                          title="<?= e(sprintf('%.2fs, %.1f Hz', $n['start'], $n['frequency'])) ?>"><?= e($n['note']) ?></span>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </div>

    <footer>
        <div class="footer-content">
            <p>&copy; MeloMaster 2024. All rights are preserved.</p>
        </div>
    </footer>

    <script>
        var audioPlayer = document.getElementById('audioPlayer');
        var noteElements = Array.prototype.slice.call(document.querySelectorAll('.note'));
        var notes = noteElements.map(function (el) {
            return { el: el, start: parseFloat(el.dataset.start), end: parseFloat(el.dataset.end) };
        });
        var activeNote = null;

        // Highlight the note sounding at the current playback time.
        function highlightNotes() {
            var t = audioPlayer.currentTime;
            var current = null;
            for (var i = 0; i < notes.length; i++) {
                if (t >= notes[i].start && t < notes[i].end) { current = notes[i]; break; }
                if (notes[i].start > t) break;
            }
            if (current !== activeNote) {
                if (activeNote) activeNote.el.classList.remove('active');
                if (current) {
                    current.el.classList.add('active');
                    current.el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
                activeNote = current;
            }
            if (!audioPlayer.paused) requestAnimationFrame(highlightNotes);
        }

        audioPlayer.addEventListener('play', highlightNotes);
        audioPlayer.addEventListener('seeked', highlightNotes);

        // Click a note to jump to it.
        notes.forEach(function (n) {
            n.el.addEventListener('click', function () {
                audioPlayer.currentTime = n.start;
                audioPlayer.play();
            });
        });
    </script>
</body>
</html>

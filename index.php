<?php
include('./conn/conn.php');

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    redirect('index.php');
}
require_login();
$maxMb = $config['max_upload_mb'];
$maxSeconds = $config['max_audio_seconds'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MeloMaster — recordings to notes</title>
    <meta name="description" content="Upload any short recording and get the notes, MIDI, sargam in your alto's written scale, and a raga shortlist. Runs free, on your account." />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&family=DM+Sans:wght@400;500;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --cream: #FAF8F5; --paper: #FFFDF9; --ink: #1A1A1A; --soft: #4A4A4A; --faint: #8A8A8A;
            --elec: #2563EB; --elec-soft: #DBEAFE; --coral: #F87171; --coral-soft: #FEE2E2;
            --sage: #86EFAC; --sage-soft: #DCFCE7; --butter: #FDE68A; --butter-dark: #FACC15;
            --sand: #E7D8C9; --sand-soft: #F5EDE4;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 0 18px 60px;
            background: var(--cream); color: var(--ink);
            font: 15px/1.6 "DM Sans", system-ui, sans-serif;
        }
        .wrap { max-width: 920px; margin: 0 auto; }
        .topbar {
            display: flex; align-items: center; gap: 10px;
            padding: 18px 0 6px;
        }
        .logo-tile {
            width: 40px; height: 40px; border-radius: 11px; background: var(--elec);
            display: grid; place-items: center; color: #fff; font-weight: 800;
            font-family: "Plus Jakarta Sans"; font-size: 17px;
            border: 2px solid var(--ink); box-shadow: 3px 3px 0 var(--ink);
            position: relative;
        }
        .logo-tile .dot { position: absolute; right: 6px; bottom: 6px; width: 6px; height: 6px; border-radius: 50%; background: var(--coral); }
        .brand { font-family: "Plus Jakarta Sans"; font-weight: 800; font-size: 19px; }
        .brand span { color: var(--elec); }
        .spacer { flex: 1; }
        .logout-btn {
            font: 700 12.5px "DM Sans"; padding: 7px 14px; border-radius: 999px;
            border: 2px solid var(--ink); background: var(--paper); color: var(--ink);
            box-shadow: 2px 2px 0 var(--ink); cursor: pointer; text-decoration: none;
        }
        .logout-btn:active { transform: translate(1px, 1px); box-shadow: 1px 1px 0 var(--ink); }

        .hero { padding: 26px 0 8px; }
        .hero .kicker { font-family: "JetBrains Mono", monospace; font-size: 12px; color: var(--faint); margin-bottom: 8px; }
        .hero h1 { font-family: "Plus Jakarta Sans"; font-weight: 800; font-size: clamp(30px, 5vw, 48px); line-height: 1.12; margin: 0 0 10px; }
        .hero h1 .hl { color: var(--elec); }
        .hero .sub { color: var(--soft); max-width: 640px; }

        .panel {
            background: var(--paper); border: 2px solid var(--ink); border-radius: 14px;
            box-shadow: 4px 4px 0 var(--ink); padding: 22px; margin-top: 24px;
        }
        .panel h2 { font-family: "JetBrains Mono", monospace; font-size: 12px; letter-spacing: 0.12em; color: var(--elec); margin: 0 0 14px; }

        .flash { border: 2px solid var(--ink); border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; background: var(--butter); font-size: 14px; }
        .flash.warning { background: var(--coral-soft); }

        .dropzone {
            display: block;
            border: 2px dashed var(--ink); border-radius: 12px; background: var(--sand-soft);
            padding: 26px 16px; text-align: center; cursor: pointer; transition: background 0.15s;
        }
        .dropzone:hover, .dropzone.dragover { background: var(--butter); }
        .dropzone .dz-ico { font-size: 34px; }
        .dropzone .dz-title { font-weight: 700; margin-top: 6px; }
        .dropzone .dz-sub { font-size: 12.5px; color: var(--faint); font-family: "JetBrains Mono", monospace; margin-top: 3px; }
        #audio_file { display: none; }

        .tala-row { display: flex; align-items: center; gap: 10px; margin: 16px 0 4px; flex-wrap: wrap; }
        .tala-row label { font-family: "JetBrains Mono", monospace; font-size: 11.5px; color: var(--soft); }
        select {
            font: 500 13.5px "DM Sans"; padding: 8px 10px; border-radius: 9px;
            border: 2px solid var(--ink); background: var(--paper); color: var(--ink);
        }
        .limits { font-family: "JetBrains Mono", monospace; font-size: 11px; color: var(--faint); margin: 10px 0 0; }
        .btn-primary {
            width: 100%; margin-top: 16px;
            font: 700 15px "DM Sans"; padding: 12px; border-radius: 11px;
            border: 2px solid var(--ink); background: var(--elec); color: #fff;
            box-shadow: 3px 3px 0 var(--ink); cursor: pointer; transition: transform 0.08s, box-shadow 0.08s;
        }
        .btn-primary:hover { transform: translate(-1px, -1px); box-shadow: 4px 4px 0 var(--ink); }
        .btn-primary:active { transform: translate(2px, 2px); box-shadow: 1px 1px 0 var(--ink); }
        .btn-primary:disabled { opacity: 0.6; cursor: wait; transform: none; }

        .foot { margin-top: 26px; font-family: "JetBrains Mono", monospace; font-size: 11.5px; color: var(--faint); text-align: center; }
        .foot a { color: var(--elec); }

        .flash-success { border: 2px solid var(--ink); border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; background: var(--sage-soft); font-size: 14px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div class="logo-tile">M<span class="dot"></span></div>
        <div class="brand">Melo<span>Master</span></div>
        <div class="spacer"></div>
        <a class="logout-btn" href="index.php?logout=1">Log out</a>
    </div>

    <div class="hero">
        <div class="kicker">MELOMASTER // recordings &rarr; notes &rarr; sargam &rarr; raga</div>
        <h1>Upload a recording.<br>Get the <span class="hl">sargam</span> you can play.</h1>
        <p class="sub">Notes, MIDI, and a raga shortlist from any short recording. Sargam is shown in your alto's written scale (base A#), grouped beatwise carnatic style, with the tala detected from the audio.</p>
    </div>

    <div class="panel">
        <h2>UPLOAD</h2>
        <?= csrf_field() ?>
        <?= render_flash() ?>
        <form action="upload_audio.php" method="post" enctype="multipart/form-data" id="uploadForm">
            <label class="dropzone" id="dropzone" for="audio_file">
                <div class="dz-ico">🎼</div>
                <div class="dz-title">Drop a recording here, or click to choose</div>
                <div class="dz-sub">MP3 · WAV · OGG · FLAC · M4A</div>
            </label>
            <input type="file" id="audio_file" name="audio_file" accept=".mp3,.wav,.ogg,.flac,.m4a,audio/*" required>
            <div class="file-info" id="fileInfo"></div>

            <div class="tala-row">
                <label for="tala">TALA (swaras per beat group)</label>
                <select id="tala" name="tala">
                    <option value="auto" selected>Auto-detect from the audio</option>
                    <option value="4">Adi / Chaturashra — 4</option>
                    <option value="3">Tisra / Rupaka — 3</option>
                    <option value="5">Khanda — 5</option>
                    <option value="7">Misra — 7</option>
                    <option value="9">Sankeerna — 9</option>
                </select>
            </div>

            <div class="limits">max <?= (int) $maxMb ?> MB · max <?= (int) $maxSeconds ?> seconds · transcription runs during the upload</div>
            <button type="submit" class="btn-primary" id="uploadButton">Upload and transcribe</button>
        </form>
    </div>

    <div class="foot">
        part of the <a href="https://portfolio.krithiuk.space">krithiuk.space</a> lab ·
        sax lab · autograd · judge · krithi.os
    </div>
</div>

<script>
    var MAX_MB = <?= (int) $maxMb ?>;
    var fileInput = document.getElementById('audio_file');
    var fileInfo = document.getElementById('fileInfo');
    var dropzone = document.getElementById('dropzone');
    var uploadButton = document.getElementById('uploadButton');

    fileInput.addEventListener('change', function() {
        if (fileInput.files.length === 0) {
            fileInfo.textContent = '';
            return;
        }
        var file = fileInput.files[0];
        if (file.size > MAX_MB * 1024 * 1024) {
            fileInfo.textContent = 'File is larger than ' + MAX_MB + ' MB.';
            fileInput.value = '';
            return;
        }
        fileInfo.textContent = file.name + ' · ' + (file.size / 1048576).toFixed(2) + ' MB';
    });

    ['dragover', 'dragenter'].forEach(function(ev) {
        dropzone.addEventListener(ev, function(e) { e.preventDefault(); dropzone.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(function(ev) {
        dropzone.addEventListener(ev, function(e) { e.preventDefault(); dropzone.classList.remove('dragover'); });
    });
    dropzone.addEventListener('drop', function(e) {
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            fileInput.dispatchEvent(new Event('change'));
        }
    });

    document.getElementById('uploadForm').addEventListener('submit', function() {
        uploadButton.disabled = true;
        uploadButton.textContent = 'Transcribing — this can take up to a minute…';
    });
</script>
</body>
</html>

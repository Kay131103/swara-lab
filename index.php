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
    <title>Swara Lab — recordings to swaras</title>
    <meta name="description" content="Upload any short recording and get the swaras, MIDI, and a raga shortlist — in your alto's written scale, grouped beatwise carnatic style." />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&family=DM+Sans:wght@400;500;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #101016; --panel: #17171f; --panel-2: #1d1d29;
            --line: rgba(255,255,255,0.09);
            --text: #ECECF2; --soft: #A6A6B4; --faint: #6E6E80;
            --gold: #F5B841; --gold-dim: rgba(245,184,65,0.16);
            --elec: #8B9BFF; --sage: #7BD88F; --coral: #FF7B72;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 0 18px 60px; min-height: 100vh;
            background: radial-gradient(900px 500px at 80% -10%, #1c1c2a 0%, var(--bg) 60%);
            color: var(--text); font: 15px/1.6 "DM Sans", system-ui, sans-serif;
        }
        .wrap { max-width: 920px; margin: 0 auto; }
        .topbar { display: flex; align-items: center; gap: 12px; padding: 22px 0 8px; }
        .logo-tile {
            width: 42px; height: 42px; border-radius: 12px; background: var(--gold);
            display: grid; place-items: center; color: #14141c; font-weight: 800;
            font-family: "Plus Jakarta Sans"; font-size: 18px; box-shadow: 0 6px 18px rgba(245,184,65,0.25);
        }
        .brand { font-family: "Plus Jakarta Sans"; font-weight: 800; font-size: 19px; }
        .brand span { color: var(--gold); }
        .spacer { flex: 1; }
        .logout-btn {
            font: 700 12.5px "DM Sans"; padding: 8px 15px; border-radius: 999px;
            border: 1.5px solid var(--line); background: var(--panel); color: var(--soft);
            cursor: pointer; text-decoration: none; transition: border-color 0.15s, color 0.15s;
        }
        .logout-btn:hover { border-color: var(--gold); color: var(--gold); }

        .hero { padding: 30px 0 10px; }
        .hero .kicker { font-family: "JetBrains Mono", monospace; font-size: 12px; color: var(--faint); margin-bottom: 10px; letter-spacing: 0.08em; }
        .hero h1 { font-family: "Plus Jakarta Sans"; font-weight: 800; font-size: clamp(30px, 5vw, 50px); line-height: 1.12; margin: 0 0 12px; }
        .hero h1 .hl { color: var(--gold); }
        .hero .sub { color: var(--soft); max-width: 660px; }

        .panel {
            background: var(--panel); border: 1.5px solid var(--line); border-radius: 16px;
            box-shadow: 0 14px 40px rgba(0,0,0,0.35); padding: 24px; margin-top: 26px;
        }
        .panel h2 { font-family: "JetBrains Mono", monospace; font-size: 11.5px; letter-spacing: 0.14em; color: var(--gold); margin: 0 0 16px; }

        .flash { border: 1.5px solid var(--line); border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; background: var(--panel-2); font-size: 14px; }
        .flash.warning { background: rgba(255,123,114,0.12); color: var(--coral); }
        .flash-success { border: 1.5px solid var(--line); border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; background: rgba(123,216,143,0.08); font-size: 14px; }

        .dropzone {
            display: block;
            border: 2px dashed rgba(245,184,65,0.45); border-radius: 14px; background: var(--panel-2);
            padding: 30px 16px; text-align: center; cursor: pointer; transition: background 0.15s, border-color 0.15s;
        }
        .dropzone:hover, .dropzone.dragover { background: rgba(245,184,65,0.06); border-color: var(--gold); }
        .dropzone .dz-ico { font-size: 36px; }
        .dropzone .dz-title { font-weight: 700; margin-top: 8px; }
        .dropzone .dz-sub { font-size: 12.5px; color: var(--faint); font-family: "JetBrains Mono", monospace; margin-top: 4px; }
        #audio_file { display: none; }
        .file-info { font-family: "JetBrains Mono", monospace; font-size: 12px; color: var(--sage); margin-top: 10px; min-height: 16px; }

        .tala-row { display: flex; align-items: center; gap: 12px; margin: 18px 0 4px; flex-wrap: wrap; }
        .tala-row label { font-family: "JetBrains Mono", monospace; font-size: 11.5px; color: var(--faint); }
        select {
            font: 500 13.5px "DM Sans"; padding: 9px 12px; border-radius: 10px;
            border: 1.5px solid var(--line); background: var(--panel-2); color: var(--text);
        }
        .limits { font-family: "JetBrains Mono", monospace; font-size: 11px; color: var(--faint); margin: 12px 0 0; }
        .btn-primary {
            width: 100%; margin-top: 18px;
            font: 700 15px "DM Sans"; padding: 13px; border-radius: 12px;
            border: none; background: var(--gold); color: #14141c;
            box-shadow: 0 8px 24px rgba(245,184,65,0.25); cursor: pointer;
            transition: transform 0.08s, box-shadow 0.08s;
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 10px 28px rgba(245,184,65,0.3); }
        .btn-primary:disabled { opacity: 0.55; cursor: wait; transform: none; }

        .foot { margin-top: 30px; font-family: "JetBrains Mono", monospace; font-size: 11.5px; color: var(--faint); text-align: center; }
        .foot a { color: var(--gold); }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div class="logo-tile">S</div>
        <div class="brand">Swara<span>Lab</span></div>
        <div class="spacer"></div>
        <a class="logout-btn" href="index.php?logout=1">Log out</a>
    </div>

    <div class="hero">
        <div class="kicker">SWARA LAB // recordings &rarr; swaras &rarr; raga</div>
        <h1>Upload a recording.<br>Get the <span class="hl">swaras</span> you can play.</h1>
        <p class="sub">Notes, MIDI, and a raga shortlist from any short recording. Swaras are shown in your alto's written scale (base A#), grouped beatwise carnatic style, with the tala detected from the audio. The <b>sax converter</b> below turns your Sa into the exact written notes (C, C#, D#…) to finger — and tells you which swara any written note is.</p>
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

    <div class="panel">
        <h2>SAX CONVERTER — SWARAS &rarr; NOTES ON YOUR ALTO</h2>
        <p style="margin:0 0 14px; color:var(--soft); font-size:14px">Every swara is measured from <b>Sa</b> — the tonic.
        Pick the written note you finger for Sa (auto-detected from your last recording), and the ladder below shows
        all 12 swaras: what each one is called, how far above Sa it sits, and the exact written note to play.
        Optionally highlight a scale to see its notes glow — the rest stay dimmed, not hidden.</p>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px">
            <div style="flex:1; min-width:200px">
                <label style="display:block; font:700 10.5px 'JetBrains Mono'; color:var(--faint); text-transform:uppercase; letter-spacing:.1em; margin-bottom:6px">Sa — the tonic (written note you finger)</label>
                <select id="convSa" style="width:100%; font:500 14px 'DM Sans'; padding:9px 11px; border-radius:10px; border:1.5px solid var(--line); background:var(--panel-2); color:var(--text)"></select>
                <div style="font:400 11px 'JetBrains Mono'; color:var(--faint); margin-top:5px" id="convSaNote"></div>
            </div>
            <div style="flex:1; min-width:240px">
                <label style="display:block; font:700 10.5px 'JetBrains Mono'; color:var(--faint); text-transform:uppercase; letter-spacing:.1em; margin-bottom:6px">Highlight a scale (optional)</label>
                <select id="convScale" style="width:100%; font:500 14px 'DM Sans'; padding:9px 11px; border-radius:10px; border:1.5px solid var(--line); background:var(--panel-2); color:var(--text)"></select>
                <div style="font:400 11px 'JetBrains Mono'; color:var(--faint); margin-top:5px" id="convScaleNote"></div>
            </div>
        </div>
        <table style="width:100%; border-collapse:collapse; font-family:'JetBrains Mono'; font-size:12.5px">
            <thead><tr>
                <th style="text-align:left; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:var(--faint); border-bottom:1.5px solid var(--line); padding:6px 8px">swara</th>
                <th style="text-align:left; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:var(--faint); border-bottom:1.5px solid var(--line); padding:6px 8px">distance above Sa</th>
                <th style="text-align:left; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:var(--faint); border-bottom:1.5px solid var(--line); padding:6px 8px">written (finger this)</th>
                <th style="text-align:left; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:var(--faint); border-bottom:1.5px solid var(--line); padding:6px 8px">sounds as</th>
            </tr></thead>
            <tbody id="convRows"></tbody>
        </table>
        <label style="display:block; font:700 10.5px 'JetBrains Mono'; color:var(--faint); text-transform:uppercase; letter-spacing:.1em; margin:18px 0 6px">Reverse — written note &rarr; swara</label>
        <select id="convRevNote" style="width:100%; max-width:220px; font:500 14px 'DM Sans'; padding:9px 11px; border-radius:10px; border:1.5px solid var(--line); background:var(--panel-2); color:var(--text); margin-bottom:12px"></select>
        <div style="margin-top:6px; background:var(--gold-dim); border-radius:10px; padding:12px 14px; font-family:'JetBrains Mono'; font-size:13.5px" id="convRev"></div>
        <div style="font-size:11.5px; color:var(--faint); margin-top:10px; line-height:1.55"><b>Written notes</b> are what you read and finger on the alto — they sound a major sixth lower than written, which is how the Sa you pick stays exactly where you want it. Row glow = the highlighted scale's notes.</div>
    </div>

    <div class="foot"><div class="foot">
        part of the <a href="https://portfolio.krithiuk.space">krithiuk.space</a> lab ·
        sax lab · autograd · judge · krithi.os
    </div>
</div>

<script>
    /* sax converter (embedded, client-side) */
    var SWARAS = [
        ["S", 0, "Shadja — the tonic, your reference note"],
        ["Ri(Shu)", 1, "Suddha Rishabham — a minor second above Sa"],
        ["Ri(Cha)", 2, "Chatushruti Rishabham — a major second above Sa"],
        ["Ri(Shat)", 3, "Shatshruti Rishabham — 3 semitones; on a keyboard it sounds like Suddha Gandharam"],
        ["Ga(Ant)", 4, "Antara Gandharam — a major third above Sa"],
        ["Ma(Shu)", 5, "Suddha Madhyamam — a perfect fourth above Sa"],
        ["Ma(Pra)", 6, "Prati Madhyamam — an augmented fourth above Sa (the sharp Ma)"],
        ["P", 7, "Panchama — a perfect fifth above Sa"],
        ["Dha(Shu)", 8, "Suddha Dhaivatham — a minor sixth above Sa (the sarali varisai's Dha)"],
        ["Dha(Cha)", 9, "Chatushruti Dhaivatham — a major sixth above Sa (the Dha in Mohanam, Kalyani)"],
        ["Dha(Shat)", 10, "Shatshruti Dhaivatham — 10 semitones; on a keyboard it sounds like Kaisiki Nishadam"],
        ["Ni(Kak)", 11, "Kaisiki Nishadam — a major seventh above Sa"]
    ];
    var SCALES = {
        "Mohanam": { sw: [0,2,4,7,9], d: "S R2 G3 P D2 — the major pentatonic sound; skips Ma and Ni" },
        "Hamsadhwani": { sw: [0,2,4,7,11], d: "S R2 G3 P N3 — bright and auspicious; skips Ma and Dha" },
        "Bilahari": { sw: [0,2,4,7,10], d: "S R2 G3 P D1 — major pentatonic with the lower Dha" },
        "Kambhoji": { sw: [0,2,4,7,9,11], d: "S R2 G3 P D2 N2 — folk-flavoured, gentle Ni" },
        "Sankarabharanam": { sw: [0,2,4,5,7,9,11], d: "the full major scale — all seven swaras" },
        "Kalyani": { sw: [0,2,4,6,7,9,11], d: "like the major scale but with the sharp Ma" },
        "Mayamalavagowla": { sw: [0,1,4,5,7,8,11], d: "the sarali varisai scale — flat 2nd and sharp 7th" },
        "Charukesi": { sw: [0,2,4,5,7,8,10], d: "major lower half, minor-flavoured upper half" },
        "Full ladder (all 12 swaras)": { sw: [0,1,2,3,4,5,6,7,8,9,10,11], d: "the complete chromatic ladder — no filtering" }
    };
    var NNAME = ["C","C#","D","D#","E","F","F#","G","G#","A","A#","B"];
    var SEMI = {C:0,"C#":1,D:2,"D#":3,E:4,F:5,"F#":6,G:7,"G#":8,A:9,"A#":10,B:11};
    function noteName(midi){ midi = ((midi % 12) + 12) % 12; return NNAME[midi]; }
    function parseSa(v){ var m = v.match(/^([A-G]#?)(\d)$/); return 12*(parseInt(m[2],10)+1) + SEMI[m[1]]; }

    var convSa = document.getElementById('convSa');
    var convScale = document.getElementById('convScale');
    Object.keys(SCALES).forEach(function(k){
        var o = document.createElement('option'); o.value = k; o.textContent = k; convScale.appendChild(o);
    });
    var convSaDefault = <?= isset($_SESSION['conv_sa']) ? json_encode($_SESSION['conv_sa']) : '"A4"' ?>;
    if (convSaDefault) {
        var has = [...convSa.options].some(function(o){ return o.value === convSaDefault; });
        if (!has) { var o = document.createElement('option'); o.value = convSaDefault; o.textContent = convSaDefault; convSa.appendChild(o); }
        convSa.value = convSaDefault;
    }
    convScale.value = "Full ladder (all 12 swaras)";

    function updateConv(){
        var saMidi = parseSa(convSa.value);
        var scale = SCALES[convScale.value];
        document.getElementById('convSaNote').textContent = "";
        document.getElementById('convScaleNote').textContent = scale.d;
        var rows = "";
        for (var i = 0; i < SWARAS.length; i++) {
            var s = SWARAS[i];
            var written = saMidi + s[1];
            var concert = written - 9;
            var inScale = scale.sw.indexOf(s[1]) >= 0;
            var dim = inScale ? "" : " opacity:0.42;";
            var glow = inScale ? " border-left:2.5px solid var(--cyan);" : "";
            rows += "<tr style='" + dim + glow + "'>" +
                "<td style='padding:9px 8px; border-bottom:1px solid rgba(255,255,255,0.05)'><b style='color:var(--gold)'>" + s[0] + "</b><br><span style='font-size:10.5px; color:var(--soft)'>" + s[2] + "</span></td>" +
                "<td style='padding:9px 8px; border-bottom:1px solid rgba(255,255,255,0.05)'>" + s[1] + " semitone" + (s[1] === 1 ? "" : "s") + "</td>" +
                "<td style='padding:9px 8px; border-bottom:1px solid rgba(255,255,255,0.05); color:var(--elec); font-weight:700'>" + noteName(written) + " (" + (Math.floor(written/12) - 1) + ")</td>" +
                "<td style='padding:9px 8px; border-bottom:1px solid rgba(255,255,255,0.05); color:var(--soft)'>" + noteName(concert) + "</td></tr>";
        }
        document.getElementById('convRows').innerHTML = rows;
        var revNote = document.getElementById('convRevNote').value;
        var rm = revNote.match(/^([A-G]#?)(\d)$/);
        var revMidi = 12*(parseInt(rm[2],10)+1) + SEMI[rm[1]];
        var off = ((revMidi - saMidi) % 12 + 12) % 12;
        var inS = scale.sw.indexOf(off) >= 0;
        var syl = "— outside the highlighted scale";
        for (var k = 0; k < SWARAS.length; k++) { if (SWARAS[k][1] === off) { syl = SWARAS[k][0]; break; } }
        document.getElementById('convRev').innerHTML = "Written <b style='color:var(--gold)'>" + revNote +
            "</b> relative to Sa <b style='color:var(--gold)'>" + convSa.value + "</b> = <b style='color:var(--gold)'>" + syl + "</b>" +
            (inS ? " ✓ in the highlighted scale" : " — not in the highlighted scale");
    }
    var revSel = document.getElementById('convRevNote');
    var convPanel = document.getElementById('convRows').closest('.panel');
    convSa.addEventListener('change', updateConv);
    convScale.addEventListener('change', updateConv);
    revSel.addEventListener('change', updateConv);
    updateConv();

    var MAX_MB =     var MAX_MB = <?= (int) $maxMb ?>;
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

<?php
include('./conn/conn.php');

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    redirect('index.php');
}
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
$raga = null;
if ($job['status'] === 'completed') {
    try {
        $decoded = json_decode($job['notes_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('Stored notes are not a list.');
        }
        if (isset($decoded['notes'], $decoded['raga'])) {
            $notes = $decoded['notes'];
            $raga = is_array($decoded['raga']) ? $decoded['raga'] : null;
        } else {
            $notes = $decoded;
        }
    } catch (Throwable $error) {
        error_log("Could not decode transcription notes for job $id: " . $error->getMessage());
        http_response_code(500);
        exit('The transcription result could not be read.');
    }
}

$noteCount = count($notes);
$range = '';
if ($noteCount > 0 && isset($notes[0]['midi'])) {
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
    <title>Swara Lab — transcription</title>
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

        .hero { padding: 26px 0 6px; }
        .hero .kicker { font-family: "JetBrains Mono", monospace; font-size: 12px; color: var(--faint); margin-bottom: 10px; letter-spacing: 0.08em; }
        .hero h1 { font-family: "Plus Jakarta Sans"; font-weight: 800; font-size: clamp(26px, 4vw, 40px); margin: 0 0 4px; }

        .panel {
            background: var(--panel); border: 1.5px solid var(--line); border-radius: 16px;
            box-shadow: 0 14px 40px rgba(0,0,0,0.35); padding: 22px; margin-top: 20px;
        }
        .panel h2 { font-family: "JetBrains Mono", monospace; font-size: 11.5px; letter-spacing: 0.14em; color: var(--gold); margin: 0 0 14px; }
        .alert { border: 1.5px solid var(--line); border-radius: 10px; padding: 10px 14px; background: rgba(255,123,114,0.1); color: var(--coral); font-size: 14px; }
        .status { border: 1.5px solid var(--line); border-radius: 10px; padding: 10px 14px; background: var(--gold-dim); color: var(--gold); font-size: 14px; }
        .done-line { font-size: 14.5px; color: var(--soft); margin: 0 0 12px; }
        .done-line b { color: var(--text); }

        audio { width: 100%; margin: 4px 0 6px; border-radius: 10px; }
        .midi-btn {
            display: inline-flex; align-items: center; gap: 7px;
            font: 700 13.5px "DM Sans"; padding: 10px 16px; border-radius: 10px;
            border: 1.5px solid var(--gold); background: transparent; color: var(--gold);
            cursor: pointer; text-decoration: none; transition: background 0.15s;
        }
        .midi-btn:hover { background: var(--gold-dim); }

        #notes { display: flex; flex-direction: column; gap: 10px; margin-top: 8px; }
        .beat-group {
            display: flex; align-items: flex-end; gap: 8px; flex-wrap: wrap;
            padding: 12px 14px; border: 1.5px solid var(--line); border-radius: 12px; background: var(--panel-2);
        }
        .swara-chip {
            display: block; text-align: center; min-width: 4.4rem;
            padding: 8px 9px; border: 1.5px solid var(--line); border-radius: 10px;
            background: var(--bg); color: var(--text); cursor: pointer; transition: transform 0.08s, border-color 0.15s, background 0.15s;
        }
        .swara-chip:hover { transform: translateY(-2px); border-color: var(--gold); background: rgba(245,184,65,0.06); }
        .swara-chip.active { background: var(--gold); border-color: var(--gold); }
        .swara-chip.active .syl, .swara-chip.active .nn, .swara-chip.active .dur { color: #14141c; }
        .swara-chip .syl { font-family: "JetBrains Mono", monospace; font-weight: 700; font-size: 14.5px; color: var(--gold); }
        .swara-chip .nn { font-size: 11.5px; font-weight: 700; margin-top: 2px; }
        .swara-chip .dur { font-size: 10px; color: var(--faint); font-family: "JetBrains Mono", monospace; }
        .bar-sep { align-self: center; font-size: 1.3rem; font-weight: 800; color: var(--faint); padding: 0 2px; }

        .raga-card {
            background: var(--panel); border: 1.5px solid var(--line); border-radius: 16px;
            box-shadow: 0 14px 40px rgba(0,0,0,0.35); padding: 20px; margin-top: 20px;
        }
        .raga-card h2 { font-family: "JetBrains Mono", monospace; font-size: 11.5px; letter-spacing: 0.14em; color: var(--gold); margin: 0 0 12px; }
        .raga-row { display: flex; align-items: center; gap: 10px; margin: 9px 0; }
        .raga-row b { min-width: 9rem; font-size: 13.5px; }
        .raga-bar { flex: 1; height: 14px; border: 1.5px solid var(--line); border-radius: 7px; background: var(--panel-2); overflow: hidden; }
        .raga-bar > div { height: 100%; background: var(--gold); }
        .raga-row .pct { font-family: "JetBrains Mono", monospace; font-size: 11px; width: 52px; text-align: right; color: var(--faint); }
        .raga-note { font-size: 12px; color: var(--faint); margin-top: 12px; }
        .gemini { border: 1.5px dashed var(--line); border-radius: 10px; padding: 10px 13px; margin-top: 14px; background: var(--panel-2); font-size: 13px; color: var(--soft); }
        .caveat { font-style: italic; color: var(--faint); }

        .foot { margin-top: 30px; font-family: "JetBrains Mono", monospace; font-size: 11.5px; color: var(--faint); text-align: center; }
        .foot a { color: var(--gold); }
        .back { font: 700 13px "DM Sans"; color: var(--gold); text-decoration: none; }
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
        <div class="kicker">SWARA LAB // transcription result</div>
        <h1>Your recording, in swaras</h1>
    </div>

    <?php if ($job['status'] === 'failed') : ?>
        <div class="panel"><p class="alert"><?= e($job['error_message'] ?: 'Transcription failed.') ?></p>
        <p style="margin-top:10px;"><a class="back" href="index.php">&larr; try another recording</a></p></div>
    <?php elseif ($job['status'] === 'processing') : ?>
        <div class="panel"><p class="status">Transcription is still processing. Refresh this page in a moment.</p></div>
    <?php else : ?>
        <div class="panel">
            <h2>RECORDING</h2>
            <p class="done-line">Transcription complete — <b><?= $noteCount ?> swaras</b><?= $range ? ', range ' . e($range) : '' ?>.</p>
            <?php if ($noteCount === 0) : ?>
                <p>No clear notes were detected. Try a short recording of a single instrument or voice with less background noise.</p>
            <?php endif ?>
            <audio controls id="audioPlayer" preload="metadata" src="download.php?id=<?= e($id) ?>&amp;type=audio">
                Your browser does not support the audio element.
            </audio>
            <?php if ($noteCount > 0) : ?>
                <p style="margin:12px 0 0;"><a class="midi-btn" href="download.php?id=<?= e($id) ?>&amp;type=midi">Download MIDI</a></p>
            <?php endif ?>
        </div>

        <?php if ($noteCount > 0) : ?>
        <div class="panel">
            <h2>SWARAS — grouped beatwise, your alto's written scale</h2>
            <?php $groups = $raga['groups'] ?? null; $beats = $raga['beats'] ?? 4; ?>
            <?php if ($groups) : ?>
                <p class="done-line" style="margin-top:0;">
                    sargam relative to Sa = <b><?= e($raga['sa']) ?></b> ·
                    grouped <?= (int) $beats ?> swaras per beat,
                    <?= e(($raga['beatsSource'] ?? 'selected') === 'auto-detected' ? 'auto-detected from the audio' : 'tala you selected') ?>.
                </p>
                <div id="notes">
                    <?php foreach ($groups as $group) : ?>
                        <div class="beat-group">
                            <?php foreach ($group as $ni) : $note = $notes[$ni]; ?>
                                <button class="swara-chip" type="button" data-start="<?= e($note['start']) ?>"
                                        data-end="<?= e($note['end']) ?>"
                                        title="<?= e(sprintf('%.2fs, %.1f Hz', $note['start'], $note['frequency'])) ?>">
                                    <div class="syl"><?= e($note['sargam'] ?: $note['note']) ?></div>
                                    <div class="nn"><?= e($note['note']) ?></div>
                                    <div class="dur"><?= e(sprintf('%.1fs', $note['duration'])) ?></div>
                                </button>
                                <?php if ($ni !== end($group)) : ?><span class="bar-sep">&middot;</span><?php endif ?>
                            <?php endforeach ?>
                        </div>
                        <span class="bar-sep">|</span>
                    <?php endforeach ?>
                </div>
            <?php else : ?>
                <div id="notes">
                    <?php foreach ($notes as $note) : ?>
                        <button class="swara-chip" type="button" data-start="<?= e($note['start']) ?>"
                                data-end="<?= e($note['end']) ?>">
                            <div class="syl"><?= e($note['note']) ?></div>
                            <div class="nn"><?= e(sprintf('%.1fs', $note['duration'])) ?></div>
                        </button>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
            <p class="raga-note">click a swara to play the recording from that moment. the notes are in your alto's written scale (base A#) — play them exactly as shown.</p>
        </div>
        <?php endif ?>

        <?php if ($raga !== null && !empty($raga['tonic'])) : ?>
        <div class="raga-card">
            <h2>RAGA ANALYSIS — a shortlist, not a verdict</h2>
            <p style="margin:0 0 10px;">Tonic detected: <b><?= e($raga['tonic']) ?></b></p>
            <?php foreach (($raga['candidates'] ?? []) as $i => $cand) : ?>
                <div class="raga-row">
                    <b><?= ($i + 1) . '. ' . e($cand['name']) ?></b>
                    <div class="raga-bar"><div style="height:100%; width:<?= (int) round($cand['score'] * 100) ?>%; background:var(--gold);"></div></div>
                    <span class="pct"><?= round($cand['score'] * 100) ?>% fit</span>
                </div>
            <?php endforeach ?>
            <?php if (!empty($raga['gemini_assist']['candidates'])) : ?>
                <div class="gemini">
                    <b>LLM-assisted opinion</b> (clearly labeled, not a fact)
                    <?php foreach ($raga['gemini_assist']['candidates'] as $c) : ?>
                        <br>&middot; <?= e($c['name']) ?> — <?= e(round(($c['confidence'] ?? 0) * 100)) ?>% · <?= e($c['why'] ?? '') ?>
                    <?php endforeach ?>
                    <?php if (!empty($raga['gemini_assist']['caveat'])) : ?>
                        <br><i><?= e($raga['gemini_assist']['caveat']) ?></i>
                    <?php endif ?>
                </div>
            <?php endif ?>
            <p class="raga-note">works best on solo and classical recordings; film songs with heavy accompaniment get an approximate shortlist.</p>
        </div>
        <?php endif ?>
    <?php endif ?>

    <p style="margin-top:26px;"><a class="back" href="index.php">&larr; back to Swara Lab</a></p>
    <div class="foot">part of the <a href="https://portfolio.krithiuk.space">krithiuk.space</a> lab ·
    sax lab · autograd · judge · krithi.os</div>
</div>

<script>
    var player = document.getElementById('audioPlayer');
    if (player) {
        var swaraChips = Array.prototype.slice.call(document.querySelectorAll('.swara-chip[data-start]'));
        var swaras = swaraChips.map(function(element) {
            return { element: element, start: parseFloat(element.dataset.start), end: parseFloat(element.dataset.end) };
        });

        function highlightSwaras() {
            var time = player.currentTime;
            var current = swaras.find(function(s) { return time >= s.start && time < s.end; });
            swaras.forEach(function(s) { s.element.classList.toggle('active', s === current); });
            if (!player.paused) window.requestAnimationFrame(highlightSwaras);
        }
        player.addEventListener('play', highlightSwaras);
        player.addEventListener('seeked', highlightSwaras);
        swaras.forEach(function(s) {
            s.element.addEventListener('click', function() {
                player.currentTime = s.start;
                player.play();
            });
        });
    }
</script>
</body>
</html>

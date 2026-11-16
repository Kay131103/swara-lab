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
    <title>MeloMaster — transcription</title>
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
        .topbar { display: flex; align-items: center; gap: 10px; padding: 18px 0 6px; }
        .logo-tile {
            width: 40px; height: 40px; border-radius: 11px; background: var(--elec);
            display: grid; place-items: center; color: #fff; font-weight: 800;
            font-family: "Plus Jakarta Sans"; font-size: 17px;
            border: 2px solid var(--ink); box-shadow: 3px 3px 0 var(--ink); position: relative;
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
        .hero { padding: 20px 0 4px; }
        .hero .kicker { font-family: "JetBrains Mono", monospace; font-size: 12px; color: var(--faint); margin-bottom: 8px; }
        .hero h1 { font-family: "Plus Jakarta Sans"; font-weight: 800; font-size: clamp(26px, 4vw, 40px); margin: 0 0 6px; }

        .panel {
            background: var(--paper); border: 2px solid var(--ink); border-radius: 14px;
            box-shadow: 4px 4px 0 var(--ink); padding: 20px; margin-top: 18px;
        }
        .panel h2 { font-family: "JetBrains Mono", monospace; font-size: 12px; letter-spacing: 0.12em; color: var(--elec); margin: 0 0 14px; }
        .alert { border: 2px solid var(--ink); border-radius: 10px; padding: 10px 14px; background: var(--coral-soft); font-size: 14px; }
        .status { border: 2px solid var(--ink); border-radius: 10px; padding: 10px 14px; background: var(--butter); font-size: 14px; }
        .done-line { font-size: 14.5px; color: var(--soft); margin: 0 0 12px; }
        .done-line b { color: var(--ink); }

        audio { width: 100%; margin: 6px 0 4px; }
        .midi-btn {
            display: inline-flex; align-items: center; gap: 7px;
            font: 700 13.5px "DM Sans"; padding: 9px 16px; border-radius: 10px;
            border: 2px solid var(--ink); background: var(--butter); color: var(--ink);
            box-shadow: 3px 3px 0 var(--ink); cursor: pointer; text-decoration: none;
        }
        .midi-btn:active { transform: translate(2px, 2px); box-shadow: 1px 1px 0 var(--ink); }

        #notes { display: flex; flex-direction: column; gap: 10px; margin-top: 8px; }
        .beat-group {
            display: flex; align-items: flex-end; gap: 8px; flex-wrap: wrap;
            padding: 10px 12px; border: 1.5px dashed var(--ink); border-radius: 10px; background: var(--sand-soft);
        }
        .swara-chip {
            display: block; text-align: center; min-width: 4.2rem;
            padding: 6px 8px; border: 2px solid var(--ink); border-radius: 9px;
            background: var(--paper); color: var(--ink); cursor: pointer; transition: transform 0.08s, background 0.15s;
        }
        .swara-chip:hover { transform: translateY(-2px); background: var(--butter); }
        .swara-chip.active { background: var(--elec); color: #fff; }
        .swara-chip.active .syl, .swara-chip.active .nn, .swara-chip.active .dur { color: #fff; }
        .swara-chip .syl { font-family: "JetBrains Mono", monospace; font-weight: 700; font-size: 14px; color: var(--elec); }
        .swara-chip .nn { font-size: 11.5px; font-weight: 700; margin-top: 1px; }
        .swara-chip .dur { font-size: 10px; color: var(--faint); font-family: "JetBrains Mono", monospace; }
        .bar-sep { align-self: center; font-size: 1.3rem; font-weight: 800; color: var(--soft); padding: 0 2px; }

        .raga-card {
            background: var(--paper); border: 2px solid var(--ink); border-radius: 14px;
            box-shadow: 4px 4px 0 var(--ink); padding: 18px; margin-top: 16px;
        }
        .raga-card h2 { font-family: "JetBrains Mono", monospace; font-size: 12px; letter-spacing: 0.12em; color: var(--elec); margin: 0 0 12px; }
        .raga-row { display: flex; align-items: center; gap: 10px; margin: 8px 0; }
        .raga-row b { min-width: 8.5rem; font-size: 13.5px; }
        .raga-bar { flex: 1; height: 14px; border: 2px solid var(--ink); border-radius: 7px; background: var(--sand-soft); overflow: hidden; }
        .raga-bar > div { height: 100%; background: var(--elec); }
        .raga-row .pct { font-family: "JetBrains Mono", monospace; font-size: 11px; width: 52px; text-align: right; }
        .raga-note { font-size: 12px; color: var(--faint); margin-top: 10px; }
        .gemini { border: 1.5px dashed var(--ink); border-radius: 10px; padding: 10px 13px; margin-top: 12px; background: var(--lav-soft, #EDE9FE); font-size: 13px; }
        .caveat { font-style: italic; color: var(--soft); }

        .foot { margin-top: 26px; font-family: "JetBrains Mono", monospace; font-size: 11.5px; color: var(--faint); text-align: center; }
        .foot a { color: var(--elec); }
        .back { font: 700 13px "DM Sans"; color: var(--elec); text-decoration: none; }
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
        <div class="kicker">MELOMASTER // transcription result</div>
        <h1>Your recording, transcribed</h1>
    </div>

    <?php if ($job['status'] === 'failed') : ?>
        <div class="panel"><p class="alert"><?= e($job['error_message'] ?: 'Transcription failed.') ?></p>
        <p style="margin-top:10px;"><a class="back" href="index.php">&larr; try another recording</a></p></div>
    <?php elseif ($job['status'] === 'processing') : ?>
        <div class="panel"><p class="status">Transcription is still processing. Refresh this page in a moment.</p></div>
    <?php else : ?>
        <div class="panel">
            <h2>RECORDING</h2>
            <p class="done-line">Transcription complete — <b><?= $noteCount ?> notes</b><?= $range ? ', range ' . e($range) : '' ?>.</p>
            <?php if ($noteCount === 0) : ?>
                <p>No clear notes were detected. Try a short recording of a single instrument or voice with less background noise.</p>
            <?php endif ?>
            <audio controls id="audioPlayer" preload="metadata" src="download.php?id=<?= e($id) ?>&amp;type=audio">
                Your browser does not support the audio element.
            </audio>
            <?php if ($noteCount > 0) : ?>
                <p style="margin:10px 0 0;"><a class="midi-btn" href="download.php?id=<?= e($id) ?>&amp;type=midi">Download MIDI</a></p>
            <?php endif ?>
        </div>

        <?php if ($noteCount > 0) : ?>
        <div class="panel">
            <h2>SARGAM — grouped beatwise, your alto's written scale</h2>
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
                    <div class="raga-bar"><div style="height:100%; width:<?= (int) round($cand['score'] * 100) ?>%; background:var(--elec);"></div></div>
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

    <p style="margin-top:24px;"><a class="back" href="index.php">&larr; back to MeloMaster</a></p>
    <div class="foot">part of the <a href="https://portfolio.krithiuk.space">krithiuk.space</a> lab</div>
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

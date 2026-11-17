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

        <?php if ($raga !== null && !empty($raga['tonic'])) :
            $_SESSION['conv_sa'] = $raga['sa']; ?>
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

    <!-- SAX CONVERTER — shown right after the transcription -->
    <div class="panel">
        <h2>RE-ANCHOR — YOUR RECORDING IN ANY KEY</h2>
        <p style="margin:0 0 14px; color:var(--soft); font-size:14px">The tonic was auto-detected as
        <b><?= e($raga['sa']) ?></b>. If your recording sits differently on your alto — your instrument's base is
        <b>A#</b> — pick the true tonic below and every transcribed swara re-labels instantly. The pitches never move;
        only their names relative to Sa do.</p>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; align-items:flex-end">
            <div style="flex:1; min-width:200px">
                <label style="display:block; font:700 10.5px 'JetBrains Mono'; color:var(--faint); text-transform:uppercase; letter-spacing:.1em; margin-bottom:6px">re-anchor Sa to</label>
                <select id="anchorTonic" style="width:100%; font:500 14px 'DM Sans'; padding:9px 11px; border-radius:10px; border:1.5px solid var(--line); background:var(--panel-2); color:var(--text)"></select>
            </div>
        </div>
        <table style="width:100%; border-collapse:collapse; font-family:'JetBrains Mono'; font-size:12.5px">
            <thead><tr>
                <th style="text-align:left; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:var(--faint); border-bottom:1.5px solid var(--line); padding:6px 8px">#</th>
                <th style="text-align:left; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:var(--faint); border-bottom:1.5px solid var(--line); padding:6px 8px">written note</th>
                <th style="text-align:left; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:var(--faint); border-bottom:1.5px solid var(--line); padding:6px 8px">swara relative to the chosen Sa</th>
            </tr></thead>
            <tbody id="anchorRows"></tbody>
        </table>
    </div>

    <div class="panel">
        <h2>SAX CONVERTER — SWARAS &rarr; NOTES ON YOUR ALTO</h2>
        <p style="margin:0 0 14px; color:var(--soft); font-size:14px">Sa is auto-detected from <b>this transcription</b> —
        the ladder shows all 12 swaras: what each is called, how far above Sa it sits, and the written note to finger.
        Optionally highlight the scale of this transcription to see its notes glow.</p>
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
    </div>

    <!-- HOW PITCH IS DETECTED -->
    <div class="panel">
        <h2>HOW THE PITCH WAS DETECTED</h2>
        <p style="margin:0 0 10px; color:var(--soft); font-size:14px">Your recording was analysed with
        <b>pYIN</b> (probabilistic YIN — librosa), a fundamental-frequency tracker that is robust against the
        harmonics a plain "loudest FFT bin" approach picks up.</p>
        <p style="color:var(--dim); font-size:13px; line-height:1.6">
        &middot; the audio is resampled to 22.05 kHz and split into ~23 ms frames (2048-sample windows, 512-sample hops)<br>
        &middot; each frame keeps a <b>probability distribution over candidate pitches</b> instead of one winner — that is what avoids octave jumps<br>
        &middot; the voiced pitch track is <b>median-filtered</b> to flatten vibrato and octave glitches<br>
        &middot; notes are split wherever the pitch changes by a semitone or more, the sound stops (RMS gate), or a fresh <b>onset</b> is detected — so repeated notes stay apart<br>
        &middot; blips shorter than the minimum note length are dropped<br>
        &middot; every note's average frequency is converted to semitones from the detected tonic (duration-weighted most-persistent pitch, cross-checked against common tonic relationships) and named on the 12-swara ladder</p>
    </div>

    <p style="margin-top:26px;"><a class="back" href="index.php">&larr; back to Swara Lab</a></p>
    <div class="foot">part of the <a href="https://portfolio.krithiuk.space">krithiuk.space</a> lab ·
    sax lab · autograd · judge · krithi.os</div>
</div>

<script>
    /* sax converter — auto-detects Sa from this transcription */
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
    ["A#3","B3","C4","C#4","D4","D#4","E4","F4","F#4","G4","G#4","A4","A#4","B4","C5","C#5","D5","D#5","E5","F5","F#5","G5"].forEach(function(n){
        var o = document.createElement('option'); o.value = n; o.textContent = n; convSa.appendChild(o);
    });
    Object.keys(SCALES).forEach(function(k){
        var o = document.createElement('option'); o.value = k; o.textContent = k; convScale.appendChild(o);
    });
    var convSaDefault = <?= json_encode(isset($raga['sa']) ? $raga['sa'] : 'A4') ?>;
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
    ["C4","C#4","D4","D#4","E4","F4","F#4","G4","G#4","A4","A#4","B4","C5","C#5","D5","D#5","E5","F5","F#5","G5"].forEach(function(n){
        var o = document.createElement('option'); o.value = n; o.textContent = n; revSel.appendChild(o);
    });
    var convPanel = document.getElementById('convRows').closest('.panel');
    convSa.addEventListener('change', updateConv);
    convScale.addEventListener('change', updateConv);
    revSel.addEventListener('change', updateConv);
    updateConv();


    /* re-anchor: the transcribed notes, re-labelled against any tonic */
    var recPitches = [...document.querySelectorAll('.swara-chip .nn')].map(function(e){ return e.textContent.trim(); });
    var anchorSel = document.getElementById('anchorTonic');
    var detected = <?= json_encode(isset($raga['sa']) ? $raga['sa'] : 'A4') ?>;
    ["A#3","B3","C4","C#4","D4","D#4","E4","F4","F#4","G4","G#4","A4","A#4","B4","C5","C#5","D5","D#5","E5","F5","F#5","G5","A5"].forEach(function(n){
        var o = document.createElement('option'); o.value = n; o.textContent = n + (n === detected ? " (detected)" : ""); anchorSel.appendChild(o);
    });
    anchorSel.value = detected;
    function midiOf(v){ var m = v.match(/^([A-G]#?)(\d)$/); return 12*(parseInt(m[2],10)+1) + SEMI[m[1]]; }
    function swaraFor(pitch, tonicMidi){
        var off = midiOf(pitch) - tonicMidi;
        var oct = Math.floor(off/12), wrapped = off - oct*12;
        var syl = SWARAS[wrapped][0];
        var tag = oct === 0 ? "" : " (" + (oct > 0 ? "+" : "") + oct + " oct)";
        return syl + tag;
    }
    function updateAnchor(){
        var tm = midiOf(anchorSel.value);
        var rows = recPitches.map(function(p, i){
            return "<tr><td style='color:var(--faint); padding:7px 8px; border-bottom:1px solid rgba(255,255,255,0.05)'>" + (i+1) +
                "</td><td style='padding:7px 8px; border-bottom:1px solid rgba(255,255,255,0.05); color:var(--elec); font-weight:700'>" + p +
                "</td><td class='syl' style='color:var(--gold); font-weight:700; padding:7px 8px; border-bottom:1px solid rgba(255,255,255,0.05)'>" + swaraFor(p, tm) + "</td></tr>";
        }).join("");
        document.getElementById('anchorRows').innerHTML = rows;
    }
    anchorSel.addEventListener('change', updateAnchor);
    updateAnchor();

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

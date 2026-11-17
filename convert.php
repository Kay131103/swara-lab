<?php
// Swara Lab — Sax Converter
// Convert a swara scale (any Sa + raga) into the written note names an alto
// saxophonist fingers (C, C#, D# …), and the reverse: a written note -> its swara.
require_once __DIR__ . "/conn/conn.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$logged_in = isset($_SESSION["user_id"]);

// 12 swara sthanas (semitones from Sa)
$SWARAS = [
    ["S", 0], ["Ri(Shu)", 1], ["Ri(Cha)", 2], ["Ri(Shat)", 3], ["Ga(Ant)", 4],
    ["Ma(Shu)", 5], ["Ma(Pra)", 6], ["P", 7], ["Dha(Shu)", 8], ["Dha(Cha)", 9],
    ["Dha(Shat)", 10], ["Ni(Kak)", 11],
];

// raga library: name => swara indices (0-11)
$RAGAS = [
    "Mohanam"            => [0, 2, 4, 7, 9],
    "Hamsadhwani"        => [0, 2, 4, 7, 11],
    "Bilahari"           => [0, 2, 4, 7, 10],
    "Kambhoji"           => [0, 2, 4, 7, 9, 11],
    "Sankarabharanam"    => [0, 2, 4, 5, 7, 9, 11],
    "Kalyani"            => [0, 2, 4, 6, 7, 9, 11],
    "Mayamalavagowla"    => [0, 1, 4, 5, 7, 8, 11],
    "Todi (ascent)"      => [0, 1, 3, 5, 7, 8, 10],
    "Charukesi"          => [0, 2, 4, 5, 7, 8, 10],
    "all 12 swaras"      => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],
];

// western note names (concert) starting from C
$NAMES = ["C", "C#", "D", "D#", "E", "F", "F#", "G", "G#", "A", "A#", "B"];

// alto sax: written = concert + 9 semitones (Eb instrument, sounds a major 6th below written)
function altoWritten(int $concertSemiFromC4, int &$octave): string {
    global $NAMES;
    // concert C4 = midi 60; written = concert + 9
    $midiWritten = 60 + $concertSemiFromC4 + 9;
    $octave = intdiv($midiWritten, 12) - 1; // scientific octave
    return $NAMES[$midiWritten % 12];
}

function concertSemi(int $saMidi, int $offset): int {
    return $saMidi + $offset;
}

$saName = $_GET["sa"] ?? "C4";
$raga = $_GET["raga"] ?? "Mohanam";
// Sa note: parse "C4" style -> midi
$saIndex = array_search(strtoupper(substr($saName, 0, 1)), array_map(fn($n) => $n[0], $NAMES));
$saOctave = (int) substr($saName, 1);
if ($saIndex === false) { $saIndex = 0; $saOctave = 4; }
$saMidi = 12 * ($saOctave + 1) + $saIndex;
$scaled = $RAGAS[$raga] ?? $RAGAS["Mohanam"];

$rows = [];
foreach ($scaled as $idx) {
    [$syl, $off] = $SWARAS[$idx];
    $semi = concertSemi($saMidi, $off);
    $oct = 0;
    $written = altoWritten($semi - 60, $oct);
    $rows[] = ["syl" => $syl, "concert" => $NAMES[$semi % 12] . (intdiv($semi, 12) - 1), "written" => $written . $oct];
}

// reverse: pick a written note -> swara relative to Sa
$revWritten = $_GET["note"] ?? "A4";
$revIdx = array_search(strtoupper(substr($revWritten, 0, 1)), array_map(fn($n) => $n[0], $NAMES));
$revOct = (int) substr($revWritten, 1);
if ($revIdx === false) { $revIdx = 9; $revOct = 4; }
$revMidi = 12 * ($revOct + 1) + $revIdx;
$revConcert = $revMidi - 9; // undo the transposition
$revOff = (($revConcert - $saMidi) % 12 + 12) % 12;
$revSyl = "";
foreach ($SWARAS as [$syl, $off]) { if ($off === $revOff) { $revSyl = $syl; break; } }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sax Converter — swaras to alto saxophone notes</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
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
    .topbar h1 { font-family: "Plus Jakarta Sans"; font-size: 17px; margin: 0; }
    .logout-btn { margin-left: auto; font: 700 12.5px "JetBrains Mono"; text-decoration: none;
        border: 1.5px solid var(--line); border-radius: 10px; padding: 8px 14px; color: var(--soft); }
    .kicker { font-family: "JetBrains Mono", monospace; font-size: 12px; color: var(--faint); letter-spacing: 0.08em; margin: 14px 0 10px; }
    h2.page { font-family: "Plus Jakarta Sans"; font-weight: 800; font-size: clamp(26px, 4vw, 38px); margin: 0 0 6px; }
    .sub { color: var(--soft); margin: 0 0 18px; }
    .panel {
        background: var(--panel); border: 1.5px solid var(--line); border-radius: 16px;
        box-shadow: 0 14px 40px rgba(0,0,0,0.35); padding: 22px; margin-top: 20px;
    }
    .panel h2 { font-family: "JetBrains Mono", monospace; font-size: 11.5px; letter-spacing: 0.14em; color: var(--gold); margin: 0 0 14px; }
    .row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    .field { flex: 1; min-width: 160px; }
    .field label { display: block; font: 700 11px "JetBrains Mono"; color: var(--faint); text-transform: uppercase; letter-spacing: .1em; margin-bottom: 6px; }
    select { width: 100%; font: 500 14px "DM Sans"; padding: 9px 11px; border-radius: 10px;
        border: 1.5px solid var(--line); background: var(--panel-2); color: var(--text); }
    select:focus { outline: none; border-color: var(--gold); }
    table { width: 100%; border-collapse: collapse; font-family: "JetBrains Mono"; font-size: 13px; }
    th { text-align: left; font-size: 10px; letter-spacing: .12em; text-transform: uppercase; color: var(--faint);
        border-bottom: 1.5px solid var(--line); padding: 6px 8px; }
    td { padding: 9px 8px; border-bottom: 1px solid rgba(255,255,255,0.05); }
    td.syl { color: var(--gold); font-weight: 700; }
    td.w { color: var(--elec); font-weight: 700; }
    .rev { background: var(--gold-dim); border-radius: 10px; padding: 12px 14px; font-family: "JetBrains Mono"; font-size: 14px; }
    .rev b { color: var(--gold); }
    .back { font: 700 12.5px "JetBrains Mono"; color: var(--soft); text-decoration: none; }
    .note { font-size: 12px; color: var(--faint); margin-top: 10px; line-height: 1.55; }
</style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div class="logo-tile">S</div>
        <h1>Swara Lab</h1>
        <a class="logout-btn" href="index.php?logout=1">Log out</a>
    </div>

    <div class="kicker">SWARA LAB // SAX CONVERTER</div>
    <h2 class="page">Swaras → your alto's notes</h2>
    <p class="sub">Pick a Sa and a scale — get the exact written note names to finger on the alto saxophone
    (E♭ instrument: written = concert + 9 semitones). And the reverse: what you're playing → which swara it is.</p>

    <div class="panel">
        <h2>SCALE → SAX NOTES</h2>
        <form method="get">
            <div class="row">
                <div class="field">
                    <label>Sa (tonic)</label>
                    <select name="sa">
                        <?php foreach (["C4","C#4","D4","D#4","E4","F4","F#4","G4","G#4","A4","A#4","B4","C5"] as $n): ?>
                            <option value="<?= $n ?>" <?= $n === $saName ? "selected" : "" ?>><?= $n ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Scale / raga</label>
                    <select name="raga">
                        <?php foreach ($RAGAS as $rName => $_): ?>
                            <option value="<?= $rName ?>" <?= $rName === $raga ? "selected" : "" ?>><?= $rName ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="flex:0 0 auto; align-self:flex-end">
                    <button class="btn ghost" type="submit">convert</button>
                </div>
            </div>
        </form>
        <table>
            <thead><tr><th>swara</th><th>concert pitch</th><th>written on the alto (finger this)</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="syl"><?= htmlspecialchars($r["syl"]) ?></td>
                    <td><?= htmlspecialchars($r["concert"]) ?></td>
                    <td class="w"><?= htmlspecialchars($r["written"]) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="note">Written notes are what you read and finger on the alto — they sound a major sixth lower
        than written (so written A sounds concert C, keeping your Sa where you chose it).</div>
    </div>

    <div class="panel">
        <h2>WRITTEN NOTE → SWARA</h2>
        <form method="get">
            <input type="hidden" name="sa" value="<?= htmlspecialchars($saName) ?>">
            <input type="hidden" name="raga" value="<?= htmlspecialchars($raga) ?>">
            <div class="row">
                <div class="field">
                    <label>the written note you're playing</label>
                    <select name="note">
                        <?php foreach (["A3","A#3","B3","C4","C#4","D4","D#4","E4","F4","F#4","G4","G#4","A4","A#4","B4","C5","C#5","D5","D#5","E5","F5","F#5","G5"] as $n): ?>
                            <option value="<?= $n ?>" <?= $n === $revWritten ? "selected" : "" ?>><?= $n ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="flex:0 0 auto; align-self:flex-end">
                    <button class="btn ghost" type="submit">which swara?</button>
                </div>
            </div>
        </form>
        <div class="rev">
            Written <b><?= htmlspecialchars($revWritten) ?></b> (sounds concert
            <?= htmlspecialchars($NAMES[$revConcert % 12] . (intdiv($revConcert, 12) - 1)) ?>) — relative to
            Sa = <b><?= htmlspecialchars($saName) ?></b>, that is <b><?= htmlspecialchars($revSyl ?: "—") ?></b>.
        </div>
        <div class="note">In scale <b><?= htmlspecialchars($raga) ?></b> — swaras outside this scale show as "—".</div>
    </div>

    <div style="margin-top:18px"><a class="back" href="index.php">← back to Swara Lab</a></div>
</div>
</body>
</html>

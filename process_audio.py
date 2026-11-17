"""Run a bounded transcription suitable for one Vercel Function invocation."""

import argparse
import json
import math
import os

NOTES_HOLDER = {}
from pathlib import Path
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent / "src"))
from raga import analyze_raga, NOTE_NAMES, SWARA_STHANAS  # noqa: E402
from transcribe import sargam_name  # noqa: E402

TRANSCRIPTION_TIMEOUT = 240


def audio_duration(path):
    try:
        result = subprocess.run(
            [
                "ffprobe",
                "-v",
                "error",
                "-show_entries",
                "format=duration",
                "-of",
                "default=noprint_wrappers=1:nokey=1",
                str(path),
            ],
            check=True,
            capture_output=True,
            text=True,
            timeout=15,
        )
        duration = float(result.stdout.strip())
        if math.isfinite(duration) and duration > 0:
            return duration
    except (FileNotFoundError, subprocess.CalledProcessError, ValueError):
        pass
    # ffprobe missing (local dev) — read the duration straight from the file
    import soundfile
    return float(soundfile.info(str(path)).duration)


def estimate_gati(audio_path):
    """Estimate swaras-per-beat from the audio: beat-track the melody,
    count note onsets inside each beat interval, take the most common count.
    Falls back to 4 when the audio has no clear pulse."""
    try:
        import librosa
        y, sr = librosa.load(audio_path, sr=22050, mono=True)
        onset_env = librosa.onset.onset_strength(y=y, sr=sr, hop_length=512)
        _, beat_frames = librosa.beat.beat_track(onset_envelope=onset_env, sr=sr, hop_length=512, trim=False)
        if len(beat_frames) < 2:
            return 4
        beat_times = librosa.frames_to_time(beat_frames, sr=sr, hop_length=512)
        starts = [n["start"] for n in NOTES_HOLDER["notes"]]
        per_beat = []
        for i in range(len(beat_times) - 1):
            count = sum(1 for s in starts if beat_times[i] <= s < beat_times[i + 1])
            if count:
                per_beat.append(count)
        if not per_beat:
            return 4
        from collections import Counter
        gati = Counter(per_beat).most_common(1)[0][0]
        return max(2, min(9, gati))
    except Exception as e:
        print(f"gati estimation failed, using 4: {e}", file=sys.stderr)
        return 4


# carnatic tala mapping: aksharas per cycle -> the tala most commonly
# performed at that cycle length (reuses the 35-tala system from Tala Lab:
# 7 families x 5 jaathis; L = laghu, D = drutham (2), A = anudhrutham (1))
CYCLE_TALAS = {
    3: ("Tisra Chapu", "Eka", "Tisra", [("L", 3)]),
    4: ("Chaturashra Eka", "Eka", "Chaturashra", [("L", 4)]),
    5: ("Khanda Chapu", "Eka", "Khanda", [("L", 5)]),
    6: ("Chaturashra Rupaka", "Rupaka", "Chaturashra", [("D", 2), ("L", 4)]),
    7: ("Misra Chapu", "Eka", "Misra", [("L", 7)]),
    8: ("Adi", "Triputa", "Chaturashra", [("L", 4), ("D", 2), ("D", 2)]),
    9: ("Khanda Triputa", "Triputa", "Khanda", [("L", 5), ("D", 2), ("D", 2)]),
    10: ("Chaturashra Matya", "Matya", "Chaturashra", [("L", 4), ("D", 2), ("L", 4)]),
    11: ("Sankeerna Rupaka", "Rupaka", "Sankeerna", [("D", 2), ("L", 9)]),
    12: ("Chaturashra Ata", "Ata", "Chaturashra", [("L", 4), ("L", 4), ("D", 2), ("D", 2)]),
    13: ("Sankeerna Triputa", "Triputa", "Sankeerna", [("L", 9), ("D", 2), ("D", 2)]),
    14: ("Khanda Ata", "Ata", "Khanda", [("L", 5), ("L", 5), ("D", 2), ("D", 2)]),
}
ANGA_LABEL = {"L": "LAGHU", "D": "DRUTHAM", "A": "ANUDHRUTHAM"}


def detect_tala(audio_path, gati):
    """Detect the carnatic tala: find the akshara-cycle periodicity in the
    onset-strength envelope (3-14 aksharas per cycle), align the phase, and
    map the cycle length to the most commonly performed tala at that length."""
    try:
        import librosa
        y, sr = librosa.load(audio_path, sr=22050, mono=True)
        onset_env = librosa.onset.onset_strength(y=y, sr=sr, hop_length=512)
        _, beat_frames = librosa.beat.beat_track(y=y, onset_envelope=onset_env, sr=sr, hop_length=512, trim=False)
        if len(beat_frames) < 4:
            return None
        import numpy as np
        beat_period = float(np.median(np.diff(beat_frames)))
        if beat_period <= 0:
            return None
        ac = librosa.autocorrelate(onset_env, max_size=max(64, len(onset_env) // 2))
        if ac.size == 0 or float(np.max(np.abs(ac))) == 0:
            return None

        best_c, best_score, scores = None, -1.0, {}
        for cycle in range(3, 15):
            lag = int(round(cycle * beat_period))
            if lag < 2 or lag >= ac.size:
                continue
            window = ac[max(1, lag - 2) : lag + 3]
            score = float(window.max()) / (float(np.max(np.abs(ac))) + 1e-9)
            scores[cycle] = score
            if score > best_score:
                best_c, best_score = cycle, score
        if best_c is None:
            return None

        name, family, jaathi, angas = CYCLE_TALAS.get(best_c, ("Adi", "Triputa", "Chaturashra", [("L", 4), ("D", 2), ("D", 2)]))

        # phase: the offset of the cycle with the strongest onsets (the sam)
        best_phase, best_energy = 0, -1.0
        for phase in range(best_c):
            idx = beat_frames[[b for b in range(len(beat_frames)) if (b - phase) % best_c == 0]]
            idx = idx[(idx >= 0) & (idx < len(onset_env))]
            energy = float(onset_env[idx].sum()) if idx.size else 0.0
            if energy > best_energy:
                best_phase, best_energy = phase, energy

        confidence = int(round(max(0.0, min(1.0, best_score)) * 100))
        anga_sizes = [{"anga": a, "label": ANGA_LABEL[a], "aksharas": k} for a, k in angas]
        return {
            "name": name,
            "full": f"{jaathi} {family}",
            "family": family,
            "jaathi": jaathi,
            "angas": anga_sizes,
            "cycle": best_c,
            "phase": best_phase,
            "gati": gati,
            "confidence": confidence,
        }
    except Exception as e:
        print(f"tala detection failed: {e}", file=sys.stderr)
        return None


def group_by_angas(notes, tala):
    """Group the notes per akshara, ordered by the tala's anga cycle."""
    import librosa
    import bisect
    import numpy as np
    y, sr = librosa.load(audio_path_for_tala(tala), sr=22050, mono=True)
    onset_env = librosa.onset.onset_strength(y=y, sr=sr, hop_length=512)
    _, beat_frames = librosa.beat.beat_track(y=y, onset_envelope=onset_env, sr=sr, hop_length=512, trim=False)
    beat_starts = librosa.frames_to_time(beat_frames, sr=sr, hop_length=512)
    if len(beat_starts) == 0:
        return None

    # expand the tala into a per-akshara anga tag (cyclic)
    tags = []
    for a in tala["angas"]:
        tags.extend([a["anga"]] * a["aksharas"])
    cycle = len(tags)
    phase = tala["phase"] % cycle

    groups, group_angas = [], []
    cur, cur_anga = [], None
    last_ak = -1
    for ni, n in enumerate(notes):
        i = bisect.bisect_right(beat_starts, n["start"]) - 1
        i = max(0, i)
        ak = max(i, last_ak) if last_ak >= 0 else i
        pos = (ak - phase) % cycle
        tag = tags[pos]
        # a group = one akshara (one beat group); the anga tag rides along
        if cur_anga is not None and (ak != last_ak or tag != cur_anga):
            groups.append(cur)
            group_angas.append({"anga": cur_anga, "label": ANGA_LABEL[cur_anga]})
            cur = []
        cur.append(ni)
        cur_anga = tag
        last_ak = ak
    if cur:
        groups.append(cur)
        group_angas.append({"anga": cur_anga, "label": ANGA_LABEL[cur_anga]})
    return groups, group_angas


def audio_path_for_tala(tala):
    return NOTES_HOLDER.get("audio_path", "")



def run_transcription(audio_path, midi_path, max_duration):
    duration = audio_duration(audio_path)
    if duration > max_duration:
        raise ValueError(f"Audio must be {max_duration} seconds or shorter.")

    result = subprocess.run(
        [
            sys.executable,
            str(Path(__file__).resolve().parent / "transcribe.py"),
            "--format",
            "json",
            "--midi",
            str(midi_path),
            str(audio_path),
        ],
        check=False,
        capture_output=True,
        text=True,
        timeout=TRANSCRIPTION_TIMEOUT,
    )
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or "Transcription failed.")
    payload = json.loads(result.stdout)
    if "error" in payload:
        raise RuntimeError(payload["error"])
    if not isinstance(payload.get("notes"), list) or not Path(midi_path).is_file():
        raise RuntimeError("Transcription produced an invalid result.")
    return payload["notes"]


def main(argv=None):
    global NOTES_HOLDER
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("audio_path")
    parser.add_argument("midi_path")
    parser.add_argument("--max-duration", type=int, required=True)
    parser.add_argument("--beats", default="4",
        help="swaras per beat group: an integer, or 'auto' to detect from the audio")
    args = parser.parse_args(argv)
    try:
        notes = run_transcription(args.audio_path, args.midi_path, args.max_duration)
        NOTES_HOLDER["notes"] = notes
        NOTES_HOLDER["audio_path"] = args.audio_path
    except Exception as error:
        print(json.dumps({"error": str(error)}))
        return 1
    # transpose concert -> written pitch (+9) so every note is playable on
    # the alto saxophone, and the scale names match what the player fingers
    transpose = int(os.environ.get("SAX_TRANSPOSE", "9"))
    beats_source = "selected"
    tala = None
    if str(args.beats).strip().lower() == "auto":
        beats = estimate_gati(args.audio_path)
        beats_source = "auto-detected"
        tala = detect_tala(args.audio_path, beats)
    else:
        beats = max(2, min(16, int(args.beats)))
    for n in notes:
        n["midi"] += transpose
        pc = n["midi"] % 12
        n["note"] = NOTE_NAMES[pc] + str(n["midi"] // 12 - 1)

    raga_out = analyze_raga(notes)

    # sargam for every note, relative to the detected tonic (in written pitch)
    if raga_out.get("tonic") and notes:
        tonic_pc = NOTE_NAMES.index(raga_out["tonic"])
        first = notes[0]["midi"]
        sa_midi = first - ((first - tonic_pc) % 12)
        raga_out["sa"] = NOTE_NAMES[sa_midi % 12] + str(sa_midi // 12 - 1)
        for n in notes:
            syl, tag = sargam_name(n["midi"], sa_midi)
            sthana = SWARA_STHANAS[(n["midi"] - sa_midi) % 12]
            n["sargam"] = syl
            n["sthana"] = sthana
            n["octaveTag"] = tag
        raga_out["sargam_sa_midi"] = sa_midi

    # beatwise grouping, carnatic-notation style
    raga_out["beats"] = beats
    raga_out["beatsSource"] = beats_source
    if tala:
        grouped = group_by_angas(notes, tala)
        if grouped:
            raga_out["groups"], raga_out["groupAngas"] = grouped
            raga_out["tala"] = {
                "name": tala["name"],
                "full": tala["full"],
                "family": tala["family"],
                "jaathi": tala["jaathi"],
                "angas": tala["angas"],
                "confidence": tala["confidence"],
            }
        else:
            raga_out["groups"] = [
                list(range(i, min(i + beats, len(notes))))
                for i in range(0, len(notes), beats)
            ]
    else:
        raga_out["groups"] = [
            list(range(i, min(i + beats, len(notes))))
            for i in range(0, len(notes), beats)
        ]

    print(json.dumps({"notes": notes, "raga": raga_out}, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    sys.exit(main())

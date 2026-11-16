"""Run a bounded transcription suitable for one Vercel Function invocation."""

import argparse
import json
import math
import os
from pathlib import Path
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent / "src"))
from raga import analyze_raga, NOTE_NAMES, SWARA_STHANAS  # noqa: E402
from transcribe import sargam_name  # noqa: E402

TRANSCRIPTION_TIMEOUT = 240


def audio_duration(path):
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
    if not math.isfinite(duration) or duration <= 0:
        raise ValueError("Audio duration could not be determined.")
    return duration


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
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("audio_path")
    parser.add_argument("midi_path")
    parser.add_argument("--max-duration", type=int, required=True)
    parser.add_argument("--beats", type=int, default=4, help="swaras per beat group (tala gati)")
    args = parser.parse_args(argv)
    try:
        notes = run_transcription(args.audio_path, args.midi_path, args.max_duration)
    except Exception as error:
        print(json.dumps({"error": str(error)}))
        return 1
    # transpose concert -> written pitch (+9) so every note is playable on
    # the alto saxophone, and the scale names match what the player fingers
    transpose = int(os.environ.get("SAX_TRANSPOSE", "9"))
    beats = max(2, min(16, args.beats))
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

    # beatwise grouping, carnatic-notation style (default: 4 swaras per group)
    raga_out["beats"] = beats
    raga_out["groups"] = [
        list(range(i, min(i + beats, len(notes))))
        for i in range(0, len(notes), beats)
    ]

    print(json.dumps({"notes": notes, "raga": raga_out}, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    sys.exit(main())

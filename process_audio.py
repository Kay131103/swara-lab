"""Run a bounded transcription suitable for one Vercel Function invocation."""

import argparse
import json
import math
from pathlib import Path
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent / "src"))
from raga import analyze_raga  # noqa: E402

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


def transcribe(audio_path, midi_path, max_duration):
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
    args = parser.parse_args(argv)
    try:
        notes = transcribe(args.audio_path, args.midi_path, args.max_duration)
    except Exception as error:
        print(json.dumps({"error": str(error)}))
        return 1
    print(json.dumps({"notes": notes, "raga": analyze_raga(notes)}, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    sys.exit(main())

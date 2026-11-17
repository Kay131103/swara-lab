"""Transcribe a monophonic audio recording into musical notes.

Pipeline:
  1. Track the fundamental frequency with pYIN (robust against the
     harmonics that a plain "loudest FFT bin" approach picks up).
  2. Smooth the pitch track and quantise it to the nearest semitone.
  3. Split the track into notes wherever the pitch changes, the sound
     stops, or an onset is detected (so repeated notes are kept apart).
  4. Drop blips that are too short to be real notes.

Usage:
    python transcribe.py song.mp3                    # plain text
    python transcribe.py song.mp3 --format json      # timed notes as JSON
    python transcribe.py song.mp3 --midi song.mid    # also write a MIDI file
"""

import argparse
import json
import struct
import sys

import librosa
import numpy as np
import scipy.signal

def freq_to_note(freq, a4=440.0):
    """Return the nearest note name (e.g. 'A4', 'C#5') for a frequency in Hz."""
    if freq is None or freq <= 0:
        return None
    midi = int(round(69 + 12 * np.log2(freq / a4)))
    return librosa.midi_to_note(midi, unicode=False)


HOP_LENGTH = 512
FRAME_LENGTH = 2048

SARGAM = ["Sa", "Re", "Ga", "Ma", "Pa", "Dha", "Ni"]
# semitone offset from Sa -> sargam syllable (with komal/tivra marks),
# octave-wrapped: 12 -> Sa', 13 -> re' ... exactly like Sax Lab's labels.
# Carnatic 12 swara sthanas (matches Sax Lab / raga.py naming)
_OFFSET_SYLLABLE = {
    0: "S",
    1: "Ri(Shu)", 2: "Ri(Cha)", 3: "Ri(Shat)",
    4: "Ga(Ant)", 5: "Ma(Shu)", 6: "Ma(Pra)", 7: "P",
    8: "Dha(Shu)", 9: "Dha(Cha)", 10: "Dha(Shat)", 11: "Ni(Kak)",
}


def sargam_name(midi, sa_midi):
    """Sargam syllable + octave tag for an absolute midi note, relative to Sa."""
    offset = midi - sa_midi
    octaves, wrapped = divmod(offset, 12)
    if wrapped < 0:
        octaves -= 1
        wrapped += 12
    syl = _OFFSET_SYLLABLE[wrapped]
    if octaves > 0:
        syl += "'" * octaves
    elif octaves < 0:
        syl = syl.lower() if syl[0].isupper() and len(syl) == 1 else syl + "̂"  # below Sa
    tag = "base" if octaves == 0 else ("pitch higher" if octaves > 0 else "lower")
    return syl, tag


def transcribe(
    audio_file,
    fmin="C2",
    fmax="C7",
    min_duration=0.08,
    voicing_threshold=0.5,
    a4=440.0,
):
    """Return a list of notes: dicts with note, midi, start, end, duration, frequency."""
    y, sr = librosa.load(audio_file, sr=22050, mono=True)
    if y.size == 0:
        return []

    f0, voiced, voiced_prob = librosa.pyin(
        y,
        fmin=librosa.note_to_hz(fmin),
        fmax=librosa.note_to_hz(fmax),
        sr=sr,
        frame_length=FRAME_LENGTH,
        hop_length=HOP_LENGTH,
    )
    voiced = voiced & (voiced_prob >= voicing_threshold) & ~np.isnan(f0)

    # Fractional MIDI pitch, corrected for the reference tuning.
    midi = np.full(f0.shape, np.nan)
    midi[voiced] = 69 + 12 * np.log2(f0[voiced] / a4)

    # Median-filter voiced frames to flatten vibrato and octave glitches.
    if voiced.sum() >= 5:
        midi[voiced] = scipy.signal.medfilt(midi[voiced], kernel_size=5)
    semitone = np.where(voiced, np.round(midi), -1).astype(int)

    # Onsets are only used to split repeated notes of the same pitch. No
    # backtracking: that would move the onset into the previous note's release
    # and chop its tail off as a spurious extra note.
    # An onset only counts as a re-attack if the energy dipped before it, so
    # vibrato and noise inside a sustained note don't split it.
    rms = librosa.feature.rms(y=y, frame_length=FRAME_LENGTH, hop_length=HOP_LENGTH)[0]
    onset_frames = set()
    for f in librosa.onset.onset_detect(y=y, sr=sr, hop_length=HOP_LENGTH, units="frames"):
        before = rms[max(0, f - 4) : f + 1]
        after = rms[f : f + 5]
        if before.size and after.size and before.min() < 0.75 * after.max():
            onset_frames.add(int(f))

    frame_time = HOP_LENGTH / sr
    segments = []
    start = 0
    for i in range(1, len(semitone) + 1):
        boundary = (
            i == len(semitone)
            or semitone[i] != semitone[start]
            or (i in onset_frames and semitone[i] != -1)
        )
        if boundary:
            segments.append((start, i, semitone[start]))
            start = i

    notes = []
    for s, e, pitch in segments:
        if pitch < 0:
            continue
        duration = (e - s) * frame_time
        if duration < min_duration:
            continue
        # Merge with the previous note if it is the same pitch and only a
        # dropped short blip separated them (no onset at this frame).
        if (
            notes
            and notes[-1]["midi"] == pitch
            and s * frame_time - notes[-1]["end"] < min_duration
            and s not in onset_frames
        ):
            notes[-1]["end"] = round(e * frame_time, 3)
            notes[-1]["duration"] = round(notes[-1]["end"] - notes[-1]["start"], 3)
            continue
        hz = float(np.nanmedian(f0[s:e]))
        # intonation: deviation of the median f0 from equal temperament ( Sax Lab style)
        et_freq = a4 * 2 ** ((pitch - 69) / 12)
        cents = 1200 * np.log2(hz / et_freq) if hz > 0 else None
        notes.append(
            {
                "note": librosa.midi_to_note(int(pitch), unicode=False),
                "midi": int(pitch),
                "start": round(s * frame_time, 3),
                "end": round(e * frame_time, 3),
                "duration": round(duration, 3),
                "frequency": round(hz, 2),
                "cents": round(float(cents), 1) if cents is not None else None,
            }
        )
    return notes


def format_text(notes, notes_per_line=16):
    names = [n["note"] for n in notes]
    lines = [
        " ".join(names[i : i + notes_per_line])
        for i in range(0, len(names), notes_per_line)
    ]
    return "\n".join(lines)


def write_midi(notes, path, tempo_bpm=120, velocity=90):
    """Write notes to a single-track Standard MIDI File (no extra deps)."""
    ticks_per_beat = 480
    ticks_per_sec = ticks_per_beat * tempo_bpm / 60

    def varlen(value):
        out = [value & 0x7F]
        value >>= 7
        while value:
            out.insert(0, (value & 0x7F) | 0x80)
            value >>= 7
        return bytes(out)

    events = []
    for n in notes:
        events.append((round(n["start"] * ticks_per_sec), 0x90, n["midi"], velocity))
        events.append((round(n["end"] * ticks_per_sec), 0x80, n["midi"], 0))
    # Note-offs before note-ons at the same tick.
    events.sort(key=lambda ev: (ev[0], ev[1] == 0x90))

    track = bytearray()
    us_per_beat = int(60_000_000 / tempo_bpm)
    track += b"\x00\xff\x51\x03" + us_per_beat.to_bytes(3, "big")
    last = 0
    for tick, status, pitch, vel in events:
        track += varlen(tick - last) + bytes([status, pitch, vel])
        last = tick
    track += b"\x00\xff\x2f\x00"

    with open(path, "wb") as f:
        f.write(b"MThd" + struct.pack(">IHHH", 6, 0, 1, ticks_per_beat))
        f.write(b"MTrk" + struct.pack(">I", len(track)) + track)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("audio_file")
    parser.add_argument("--midi", metavar="PATH", help="also write a MIDI file")
    parser.add_argument("--fmin", default="C2", help="lowest expected note (default C2)")
    parser.add_argument("--fmax", default="C7", help="highest expected note (default C7)")
    parser.add_argument(
        "--min-duration", type=float, default=0.08,
        help="ignore notes shorter than this many seconds (default 0.08)",
    )
    parser.add_argument("--a4", type=float, default=440.0, help="tuning of A4 in Hz")
    parser.add_argument(
        "--format", dest="fmt", choices=["text", "json"], default="text",
        help="output format (kept for backwards compat)")
    parser.add_argument(
        "--sargam", action="store_true",
        help="also print the melody as sargam syllables relative to the first note",
    )
    parser.add_argument("--sargam-sa", type=int, default=None,
        help="midi note to treat as Sa (default: the first transcribed note)")
    args = parser.parse_args(argv)

    try:
        notes = transcribe(
            args.audio_file,
            fmin=args.fmin,
            fmax=args.fmax,
            min_duration=args.min_duration,
            a4=args.a4,
        )
        if args.midi:
            write_midi(notes, args.midi)
        if args.sargam and notes:
            sa_midi = args.sargam_sa if args.sargam_sa is not None else notes[0]["midi"]
            sa_note = librosa.midi_to_note(sa_midi, unicode=False)
            print(f"Sa = {sa_note}")
            for n in notes:
                syl, tag = sargam_name(n["midi"], sa_midi)
                cents = f", {n['cents']:+.0f}c" if n.get("cents") is not None else ""
                print(f"{syl:>4} ({tag}) — {n['note']}{cents}")
    except Exception as e:  # report cleanly to the caller (CLI or PHP)
        if args.fmt == "json":
            print(json.dumps({"error": str(e)}))
        else:
            print(f"Error: {e}", file=sys.stderr)
        return 1

    if args.fmt == "json":
        print(json.dumps({"notes": notes}))
    else:
        print(format_text(notes) if notes else "No notes detected.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

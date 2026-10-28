import os
import sys

import numpy as np
import soundfile as sf

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
import transcribe  # noqa: E402

SR = 22050


def tone(note, seconds, harmonics=(1.0, 0.6, 0.4, 0.3)):
    """A harmonic-rich tone with attack/decay, like a plucked or bowed note."""
    f = 440.0 * 2 ** ((transcribe.librosa.note_to_midi(note) - 69) / 12)
    t = np.arange(int(SR * seconds)) / SR
    wave = sum(a * np.sin(2 * np.pi * f * (k + 1) * t) for k, a in enumerate(harmonics))
    env = np.minimum(1, t / 0.01) * np.exp(-1.5 * t)
    env[-int(0.02 * SR):] *= np.linspace(1, 0, int(0.02 * SR))  # short release
    return 0.3 * wave * env


def silence(seconds):
    return np.zeros(int(SR * seconds))


def render(tmp_path, parts):
    path = str(tmp_path / "melody.wav")
    sf.write(path, np.concatenate(parts), SR)
    return path


def test_freq_to_note_rounds_and_uses_c_octaves():
    assert transcribe.freq_to_note(440) == "A4"
    assert transcribe.freq_to_note(523.25) == "C5"   # old code said "C4"
    assert transcribe.freq_to_note(261.0) == "C4"    # slightly flat C4, old code said "B3"
    assert transcribe.freq_to_note(0) is None


def test_scale_with_rests(tmp_path):
    melody = ["C4", "D4", "E4", "F4", "G4", "A4", "B4", "C5"]
    parts = []
    for n in melody:
        parts += [tone(n, 0.4), silence(0.1)]
    notes = transcribe.transcribe(render(tmp_path, parts))
    assert [n["note"] for n in notes] == melody
    # Each note starts roughly every 0.5s.
    for i, n in enumerate(notes):
        assert abs(n["start"] - 0.5 * i) < 0.08


def test_repeated_notes_are_kept_separate(tmp_path):
    parts = [tone("G4", 0.4), tone("G4", 0.4), tone("E4", 0.4), silence(0.2)]
    notes = transcribe.transcribe(render(tmp_path, parts))
    assert [n["note"] for n in notes] == ["G4", "G4", "E4"]


def test_low_bass_notes(tmp_path):
    parts = [tone("E2", 0.6), silence(0.1), tone("A2", 0.6)]
    notes = transcribe.transcribe(render(tmp_path, parts))
    assert [n["note"] for n in notes] == ["E2", "A2"]


def test_vibrato_and_noise(tmp_path):
    t = np.arange(int(SR * 1.0)) / SR
    f = 440.0 * 2 ** (0.4 / 12 * np.sin(2 * np.pi * 5.5 * t))  # +/-40 cent vibrato
    phase = 2 * np.pi * np.cumsum(f) / SR
    sung = 0.3 * (np.sin(phase) + 0.5 * np.sin(2 * phase))
    noise = 0.01 * np.random.default_rng(0).standard_normal(t.size)
    notes = transcribe.transcribe(render(tmp_path, [sung + noise, silence(0.2)]))
    assert [n["note"] for n in notes] == ["A4"]


def test_silence_gives_no_notes(tmp_path):
    assert transcribe.transcribe(render(tmp_path, [silence(1.0)])) == []


def test_midi_and_cli(tmp_path, capsys):
    path = render(tmp_path, [tone("C4", 0.5), silence(0.1), tone("E4", 0.5)])
    mid = tmp_path / "out.mid"
    assert transcribe.main([path, "--format", "json", "--midi", str(mid)]) == 0
    out = capsys.readouterr().out
    assert '"note": "C4"' in out and '"note": "E4"' in out
    data = mid.read_bytes()
    assert data.startswith(b"MThd") and b"MTrk" in data
    assert bytes([0x90, 60]) in data and bytes([0x90, 64]) in data

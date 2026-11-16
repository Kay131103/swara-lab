import asyncio
from argparse import Namespace
from pathlib import Path
from types import SimpleNamespace

import pytest

import blob_storage
import process_audio


def test_audio_duration_rejects_nonfinite_value(monkeypatch, tmp_path):
    monkeypatch.setattr(
        process_audio.subprocess,
        "run",
        lambda *args, **kwargs: SimpleNamespace(stdout="inf"),
    )
    with pytest.raises(ValueError, match="could not be determined"):
        process_audio.audio_duration(tmp_path / "song.wav")


def test_transcribe_rejects_audio_above_configured_duration(monkeypatch, tmp_path):
    monkeypatch.setattr(process_audio, "audio_duration", lambda path: 30.1)
    with pytest.raises(ValueError, match="30 seconds or shorter"):
        process_audio.run_transcription(tmp_path / "song.wav", tmp_path / "song.mid", 30)


def test_transcribe_passes_deadline_and_returns_notes(monkeypatch, tmp_path):
    audio = tmp_path / "song.wav"
    midi = tmp_path / "song.mid"
    audio.write_bytes(b"audio")
    monkeypatch.setattr(process_audio, "audio_duration", lambda path: 10)
    captured = {}

    def fake_run(command, **kwargs):
        captured.update(kwargs)
        midi.write_bytes(b"MThd")
        return SimpleNamespace(returncode=0, stdout='{"notes":[{"note":"A4"}]}', stderr="")

    monkeypatch.setattr(process_audio.subprocess, "run", fake_run)

    notes = process_audio.run_transcription(audio, midi, 30)

    assert notes == [{"note": "A4"}]
    assert captured["timeout"] == process_audio.TRANSCRIPTION_TIMEOUT


def test_local_blob_put_get_delete_are_private_and_path_scoped(monkeypatch, tmp_path):
    root = tmp_path / "storage"
    source = tmp_path / "upload.wav"
    output = tmp_path / "download.wav"
    source.write_bytes(b"test audio")
    monkeypatch.setenv("BLOB_STORAGE_MODE", "local")
    monkeypatch.setenv("LOCAL_STORAGE_DIR", str(root))

    args = Namespace(operation="put", pathname="users/1/id/audio.wav", file=str(source), content_type="audio/wav")
    assert asyncio.run(blob_storage.run(args))["pathname"] == args.pathname
    args.operation = "get"
    args.file = str(output)
    assert asyncio.run(blob_storage.run(args))["bytes"] == len(b"test audio")
    assert output.read_bytes() == b"test audio"
    args.operation = "delete"
    assert asyncio.run(blob_storage.run(args))["deleted"]
    assert not (root / args.pathname).exists()


def test_local_blob_rejects_path_traversal(monkeypatch, tmp_path):
    monkeypatch.setenv("BLOB_STORAGE_MODE", "local")
    monkeypatch.setenv("LOCAL_STORAGE_DIR", str(tmp_path / "storage"))
    args = Namespace(operation="delete", pathname="../outside", file=None, content_type=None)
    with pytest.raises(ValueError, match="Invalid storage pathname"):
        asyncio.run(blob_storage.run(args))

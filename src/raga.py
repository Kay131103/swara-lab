"""Raga detection from transcribed notes.

Deterministic core (always runs):
  1. tonic estimation — the duration-weighted most persistent pitch,
     cross-checked against common tonic relationships
  2. swara histogram relative to the tonic
  3. set-matching ranking over a raga library (same method as
     Sax Lab's free-play detector) — returns a SHORTLIST with fit
     percentages, never a single verdict

Optional Gemini layer (runs when GEMINI_API_KEY is set):
  the transcribed swara sequence is sent to the model, which knows
  raga grammar (vadi/samvadi, phrasing) — its candidates are merged
  with the deterministic shortlist and clearly labeled as an
  "LLM-assisted" opinion.
"""
import json
import os
import urllib.request
from collections import defaultdict

# 18 ragas — swara sthanas (0-11 relative to Sa) heard in aro/avarohanam
RAGAS = [
    {"name": "Mohanam", "swaras": [0, 2, 4, 7, 9]},
    {"name": "Hamsadhwani", "swaras": [0, 2, 4, 7, 11]},
    {"name": "Shuddha Saveri", "swaras": [0, 2, 5, 7, 9]},
    {"name": "Madhyamavati", "swaras": [0, 2, 5, 7, 10]},
    {"name": "Hindolam", "swaras": [0, 3, 5, 8, 10]},
    {"name": "Abheri", "swaras": [0, 2, 3, 7, 10]},
    {"name": "Bilahari", "swaras": [0, 2, 4, 7, 9, 11]},
    {"name": "Kambhoji", "swaras": [0, 2, 4, 5, 7, 9, 10]},
    {"name": "Charukesi", "swaras": [0, 2, 3, 5, 7, 9, 10]},
    {"name": "Shankarabharanam", "swaras": [0, 2, 4, 5, 7, 9, 11]},
    {"name": "Kalyani", "swaras": [0, 2, 4, 5, 7, 9, 11]},
    {"name": "Kedar", "swaras": [0, 2, 4, 6, 7, 9, 11]},
    {"name": "Mayamalavagowla", "swaras": [0, 1, 4, 5, 7, 8, 11]},
    {"name": "Pantuvarali", "swaras": [0, 1, 4, 6, 7, 8, 11]},
    {"name": "Todi", "swaras": [0, 1, 3, 5, 7, 8, 10]},
    {"name": "Sindhu Bhairavi", "swaras": [0, 1, 3, 5, 7, 9, 10]},
    {"name": "Kharaharapriya", "swaras": [0, 2, 3, 5, 7, 8, 10]},
    {"name": "Natabhairavi", "swaras": [0, 2, 3, 5, 7, 8, 10]},
]

NOTE_NAMES = ["C", "C#", "D", "D#", "E", "F", "F#", "G", "G#", "A", "A#", "B"]
SWARA_STHANAS = ["S", "R1", "R2", "R3", "G3", "M1", "M2", "P", "D1", "D2", "D3", "N3"]


def note_name(midi):
    """pitch-class name (octave isn't meaningful for a tonic class)"""
    n = int(round(midi))
    return NOTE_NAMES[n % 12]


def estimate_tonic(notes):
    """Duration-weighted most persistent pitch, smoothed over the octave.

    Film songs lack a drone, so the true tonic is inferred from which
    candidate makes the swara distribution most tonic-like — we try the
    top-3 duration candidates and each one's P (7 semitones up, the
    tanpura's companion), scoring by histogram concentration."""
    if not notes:
        return None
    duration_by_midi = defaultdict(float)
    for n in notes:
        duration_by_midi[int(n["midi"]) % 12] += n.get("duration", 0.1)
    # collapse to pitch classes
    by_pc = defaultdict(float)
    for pc, d in duration_by_midi.items():
        by_pc[pc % 12] += d

    candidates = sorted(by_pc, key=lambda pc: -by_pc[pc])[:3]
    best, best_score = candidates[0], -1.0
    for sa in candidates:
        # concentration: how much weight sits on the chosen Sa and its Pa
        score = by_pc[sa] + 0.6 * by_pc.get((sa + 7) % 12, 0)
        if score > best_score:
            best, best_score = sa, score
    return best


def swara_histogram(notes, tonic_pc):
    hist = defaultdict(float)
    for n in notes:
        pc = (int(n["midi"]) - tonic_pc) % 12
        hist[pc] += n.get("duration", 0.1)
    return dict(hist)


def rank_ragas(hist):
    total = sum(hist.values()) or 1.0
    ranked = []
    for r in RAGAS:
        sw = set(r["swaras"])
        inside = sum(d for pc, d in hist.items() if pc in sw)
        outside = sum(d for pc, d in hist.items() if pc not in sw)
        purity = inside / total
        coverage = len([pc for pc in sw if pc in hist]) / len(sw)
        ranked.append({
            "name": r["name"],
            "purity": round(purity, 3),
            "coverage": round(coverage, 3),
            "score": round(purity * (0.5 + 0.5 * coverage), 3),
        })
    ranked.sort(key=lambda r: -r["score"])
    return ranked


def gemini_assist(notes, tonic_pc, top_deterministic):
    """Optional LLM opinion: the swara sequence (with timing) is sent to
    Gemini, which knows raga grammar. Returns candidates or None."""
    key = os.environ.get("GEMINI_API_KEY")
    if not key:
        return None
    model = os.environ.get("JUDGE_MODEL", "gemini-3.5-flash-lite")
    seq = " ".join(
        f"{SWARA_STHANAS[(int(n['midi']) - tonic_pc) % 12]}({n['duration']:.1f}s)"
        for n in notes[:60]
    )
    det = ", ".join(f"{c['name']} ({c['score']:.2f})" for c in top_deterministic[:4])
    prompt = (
        "You are a Carnatic music expert. A melody was transcribed relative to its tonic. "
        f"The swara sequence (duration-weighted) is:\n{seq}\n\n"
        f"A deterministic swara-set matcher suggests: {det}.\n\n"
        "Identify the most likely ragam(s). Consider arohanam/avarohanam, common phrases "
        "and which swaras dominate. Reply ONLY with minified JSON: "
        '{"candidates": [{"name": "...", "confidence": 0.0, "why": "..."}], '
        '"caveat": "one line on how reliable this is for this kind of audio"}'
    )
    payload = {
        "system_instruction": {"parts": [{"text": "You identify Carnatic ragams from swara sequences. Be honest about uncertainty."}]},
        "contents": [{"parts": [{"text": prompt}]}],
        "generationConfig": {"temperature": 0.2, "maxOutputTokens": 500,
                             "responseMimeType": "application/json"},
    }
    url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={key}"
    try:
        req = urllib.request.Request(url, data=json.dumps(payload).encode(),
                                     headers={"Content-Type": "application/json"})
        with urllib.request.urlopen(req, timeout=25) as resp:
            data = json.loads(resp.read())
        obj = json.loads(data["candidates"][0]["content"]["parts"][0]["text"].strip())
        cands = obj.get("candidates", [])[:3]
        return {"candidates": cands, "caveat": obj.get("caveat", "")}
    except Exception as e:
        return {"error": str(e)[:150]}


def analyze_raga(notes):
    """Full analysis dict for a transcribed note list."""
    tonic_pc = estimate_tonic(notes)
    if tonic_pc is None:
        return {"tonic": None, "candidates": [], "gemini": None}
    hist = swara_histogram(notes, tonic_pc)
    ranked = rank_ragas(hist)
    top = ranked[:5]
    tonic_name = note_name(tonic_pc)
    out = {
        "tonic": tonic_name,
        "swara_histogram": {
            SWARA_STHANAS[pc]: round(d, 2) for pc, d in
            sorted(hist.items(), key=lambda x: -x[1])
        },
        "candidates": top,
        "note": "percentages are a shortlist, not a verdict — film songs with "
                "heavy accompaniment make this approximate",
    }
    if os.environ.get("GEMINI_API_KEY"):
        out["gemini_assist"] = gemini_assist(notes, tonic_pc, top)
    return out


if __name__ == "__main__":
    import sys
    notes = json.loads(sys.stdin.read()) if not sys.argv[1:] else json.loads(open(sys.argv[1]).read())
    print(json.dumps(analyze_raga(notes), indent=2))

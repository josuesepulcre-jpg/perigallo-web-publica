# La Perigalla 01 — Voice pipeline lineage

Status: **LINEAGE NOT FULLY VERIFIED**

This document records only evidence present in the Git repository and the supplied source archive. It deliberately does not infer a V9 synthesis or mastering pipeline from the `es-ES-ElviraNeural` voice ID alone.

## Current production narration

- Path: `la-perigalla-01/media/storytelling/perigalla-01/audio/v9-scenes/`
- Files: 23 MP3 scene files
- Git introduction: `89be347128c7f577105dc4ab09e6840170ad504f` (`Actualiza la historia de La Perigalla 01`)
- Encapsulation measured from the immutable baseline: MP3, mono, 48 kHz, 128 kb/s.

The commit adds the 23 finished MP3 files and the player bundle together. It contains no source-generation program, job log, concat list, intermediate V8 files, FFmpeg command, loudness report, or V9 diagnostics manifest.

## Proven V7 evidence

Git commit `c42f96454c6974c74875eb4ea73f662f0d7a0cdc` introduced the V7 beat files and `audio/v7/diagnostics.json`. That diagnostics file identifies:

- Provider: `Microsoft Edge Neural TTS`
- Model: `Edge Read Aloud Neural`
- Locale: `es-ES`
- Narrator voice: `es-ES-ElviraNeural`

The supplied source archive also contains the V7 implementation, but it is not versioned in this Git repository:

- `scripts/generate-story-voice-beats.mjs`
- `scripts/render-edge-voice-beats.py`
- `scripts/requirements-voice.txt` (`edge-tts==7.2.8`)

That implementation prepares per-sentence beats, applies scene-dependent rate/pitch/volume/emphasis/pause values, constructs SSML through `edge_tts.communicate.mkssml`, and writes individual V7 MP3 beats plus `diagnostics.json`. Its V7 output sample is MP3, mono, 24 kHz, 48 kb/s.

## V7 → V9 comparison

V9 is not byte-identical to any V7 beat and has different measured encoding (48 kHz/128 kb/s vs V7 24 kHz/48 kb/s). This is consistent with a later render or re-encode, but does not identify the exact operation.

Summed V7 beat durations also diverge from several V9 durations; for example, `market` differs by +1.128 s while `message` differs by -11.232 s. Those measurements rule out treating a simple, timestamp-preserving file concatenation as proven lineage. They do not prove a new voice or a particular audio-processing tool.

## V8

No V8 audio files, scripts, manifests, build output, or Git history were found.

## Missing evidence required to approve a surgical repair

1. The exact V9 generation/assembly script and its source revision.
2. Provider/runtime/dependency version used for V9, including whether the remote Edge voice service changed.
3. The FFmpeg or equivalent arguments, concat order, silence policy, re-encode settings and mastering filters.
4. A V9 diagnostics manifest mapping every V9 time range to its source V7 beat(s).
5. The original V9 generation log or an immutable artifact that proves the voice service output.

Because these are absent, the V7 pipeline is documented evidence only; it is **not** authorization to regenerate any V9 fragment.

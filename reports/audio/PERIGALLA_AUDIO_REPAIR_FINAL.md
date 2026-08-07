# LA PERIGALLA 01 — AUDIO FINAL QA

## Result

**FAIL / BLOCKED — no production audio was changed.**

| Check | Result |
| --- | --- |
| Original MP3 | 23 |
| Readable MP3 | 23/23 |
| Original hashes captured | 23/23 |
| Acoustic metadata captured | 23/23 |
| Transcribed with word-timestamp ASR | 0/23 — blocked: no ASR engine/model is available in this checkout |
| Compared to source transcript | 0/23 — cannot claim comparison without ASR output |
| Unexpected spoken `punto` before | Not measured; no transcript was fabricated |
| Unexpected spoken `punto` after | Not applicable; no audio changed |
| Stutters before / after | Not measured / not applicable |
| Critical pronunciation issues after | Not measurable without ASR and review |
| Repaired scenes | None |
| Untouched scenes | All 23 V9 scene files |
| Alternative TTS used | NO |
| Voice identity preserved | YES — by retaining every original MP3 byte-for-byte |
| `AUDIO_VERSION` | Unchanged (`20260807-03`) |

## Integrity evidence

- Baseline hashes: `reports/audio/original-v9-sha256.json`
- Baseline codec, duration and channel metadata: `reports/audio/v9-acoustic-baseline.json`
- Expected literary source text (not an ASR transcript): `reports/audio/expected-narration.json`
- Pipeline finding and stop condition: `reports/audio/VOICE_PIPELINE_LINEAGE.md` and `reports/audio/VOICE_PIPELINE_BLOCKER.md`

## Required to change this result to PASS

1. Supply the exact V9 generator/assembly provenance, including the FFmpeg (or equivalent) command and all intermediate artifacts.
2. Supply a reproducible ASR environment with word timestamps for the 23 baseline hashes.
3. Generate and compare all transcripts, identify a specific original beat for each defect, and accept only candidates that pass the same-pipeline and acoustic/voice-identity checks.

Until then, replacing any V9 MP3 would breach the no-new-narrator and no-approximation requirements.

#!/usr/bin/env node

import { existsSync, readFileSync, statSync, writeFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { spawnSync } from "node:child_process";

const root = process.cwd();
const audioRoot = join(root, "la-perigalla-01/media/storytelling/perigalla-01/audio/v9-scenes");
const scenes = ["cover", "arrival", "market", "message", "beach", "dalias-first", "marina", "snorkel", "cala-hort", "sunset", "kiss", "interlude", "sunday", "roots", "dalias-second", "tagomago", "formentera", "table", "fire", "proposal", "dalt-vila", "final-celebration", "epilogue"];
const transcriptArgument = process.argv.find((argument) => argument.startsWith("--transcript="));
const outputArgument = process.argv.find((argument) => argument.startsWith("--output="));
const strict = process.argv.includes("--strict");
const transcripts = transcriptArgument ? JSON.parse(readFileSync(resolve(root, transcriptArgument.slice("--transcript=".length)), "utf8")) : {};

function command(commandName, args) {
  const result = spawnSync(commandName, args, { encoding: "utf8" });
  return result.error ? null : result;
}

function duration(file) {
  const result = command("ffprobe", ["-v", "error", "-show_entries", "format=duration", "-of", "default=noprint_wrappers=1:nokey=1", file]);
  const value = Number(result?.stdout.trim());
  return Number.isFinite(value) ? Number(value.toFixed(3)) : null;
}

function silence(file) {
  const result = command("ffmpeg", ["-hide_banner", "-i", file, "-af", "silencedetect=noise=-45dB:d=0.35", "-f", "null", "-"]);
  if (!result) return { available: false, intervals: [] };
  const starts = [...result.stderr.matchAll(/silence_start: ([0-9.]+)/g)].map((match) => Number(match[1]));
  const ends = [...result.stderr.matchAll(/silence_end: ([0-9.]+) \| silence_duration: ([0-9.]+)/g)].map((match) => ({ end: Number(match[1]), duration: Number(match[2]) }));
  return { available: true, intervals: starts.map((start, index) => ({ start, end: ends[index]?.end ?? null, duration: ends[index]?.duration ?? null })) };
}

function hasMp3Frame(file) {
  const data = readFileSync(file);
  for (let index = 0; index < Math.min(data.length - 1, 32768); index += 1) {
    if (data[index] === 0xff && (data[index + 1] & 0xe0) === 0xe0) return true;
  }
  return false;
}

function transcriptFindings(text) {
  if (typeof text !== "string" || !text.trim()) return { status: "not-provided", findings: ["Transcripción no disponible: revisar locución manualmente o aportar --transcript=archivo.json."] };
  const findings = [];
  if (/\bpunto\b/i.test(text)) findings.push("La transcripción contiene la palabra «punto»: regenerar este MP3 desde el guion limpio.");
  return { status: "provided", findings };
}

const report = scenes.map((scene) => {
  const file = join(audioRoot, `${scene}.mp3`);
  const exists = existsSync(file);
  const size = exists ? statSync(file).size : 0;
  const readable = exists && size > 1024 && hasMp3Frame(file);
  const audioDuration = readable ? duration(file) : null;
  const silenceReport = readable ? silence(file) : { available: false, intervals: [] };
  const transcript = transcriptFindings(transcripts[scene]);
  const findings = [...transcript.findings];
  if (!exists) findings.unshift("Archivo ausente.");
  else if (!readable) findings.unshift("Archivo vacío, corrupto o sin cabecera MP3 reconocible.");
  if (audioDuration !== null && audioDuration < 0.75) findings.push("Duración anormalmente corta.");
  if (silenceReport.available && silenceReport.intervals.some((interval) => interval.duration !== null && interval.duration > 3)) findings.push("Silencio superior a tres segundos: confirmar si es intencional.");
  return { scene, file: file.replace(`${root}/`, ""), bytes: size, duration_seconds: audioDuration, mp3_readable: readable, transcription: transcript.status, differences: findings, silence: silenceReport, severity: !exists || !readable ? "error" : findings.some((finding) => finding.includes("punto")) ? "warning" : "info", recommended_action: findings.length ? findings.join(" ") : "Correcto. Mantener control de versión mediante AUDIO_VERSION." };
});

const payload = { generated_at: new Date().toISOString(), audio_root: audioRoot.replace(`${root}/`, ""), transcript_source: transcriptArgument ? transcriptArgument.slice("--transcript=".length) : null, report };
if (outputArgument) writeFileSync(resolve(root, outputArgument.slice("--output=".length)), `${JSON.stringify(payload, null, 2)}\n`);
else process.stdout.write(`${JSON.stringify(payload, null, 2)}\n`);
if (strict && report.some((item) => item.severity === "error")) process.exitCode = 1;

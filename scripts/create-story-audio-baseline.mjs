import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import { mkdir, readdir, readFile, writeFile } from "node:fs/promises";
import { basename, join, relative } from "node:path";

const root = process.cwd();
const audioRoot = join(root, "la-perigalla-01/media/storytelling/perigalla-01/audio");
const sceneRoot = join(audioRoot, "v9-scenes");
const reportRoot = join(root, "reports/audio");

function ffprobe(file) {
  const raw = execFileSync("ffprobe", [
    "-v", "error",
    "-show_entries", "format=duration,format_name,bit_rate:stream=codec_name,sample_rate,channels,bit_rate",
    "-of", "json",
    file,
  ], { encoding: "utf8" });
  const metadata = JSON.parse(raw);
  const stream = metadata.streams?.[0] ?? {};
  return {
    durationSeconds: Number(metadata.format?.duration),
    format: metadata.format?.format_name ?? null,
    formatBitRate: Number(metadata.format?.bit_rate ?? 0) || null,
    codec: stream.codec_name ?? null,
    sampleRate: Number(stream.sample_rate ?? 0) || null,
    channels: Number(stream.channels ?? 0) || null,
    streamBitRate: Number(stream.bit_rate ?? 0) || null,
  };
}

async function fingerprint(file) {
  const body = await readFile(file);
  return {
    path: relative(root, file),
    sha256: createHash("sha256").update(body).digest("hex"),
    bytes: body.byteLength,
    ...ffprobe(file),
  };
}

const scenes = (await readdir(sceneRoot))
  .filter((file) => file.endsWith(".mp3"))
  .sort()
  .map((file) => join(sceneRoot, file));

if (scenes.length !== 23) {
  throw new Error(`Expected 23 v9 scene MP3s; found ${scenes.length}.`);
}

await mkdir(reportRoot, { recursive: true });
const generatedAt = new Date().toISOString();
const records = await Promise.all(scenes.map(fingerprint));
const hashes = {
  generatedAt,
  immutableBaseline: true,
  sourceCommit: execFileSync("git", ["rev-parse", "HEAD"], { cwd: root, encoding: "utf8" }).trim(),
  audioRoot: "la-perigalla-01/media/storytelling/perigalla-01/audio/v9-scenes",
  sceneCount: records.length,
  files: records.map(({ path, sha256, bytes }) => ({ path, sha256, bytes })),
};
const acoustic = {
  generatedAt,
  immutableBaseline: true,
  sourceCommit: hashes.sourceCommit,
  audioRoot: hashes.audioRoot,
  sceneCount: records.length,
  files: records.map(({ path, ...metadata }) => ({ sceneId: basename(path, ".mp3"), path, ...metadata })),
  conclusion: "The baseline describes existing V9 files only. It does not establish their source TTS pipeline or authorize regeneration.",
};

await writeFile(join(reportRoot, "original-v9-sha256.json"), `${JSON.stringify(hashes, null, 2)}\n`);
await writeFile(join(reportRoot, "v9-acoustic-baseline.json"), `${JSON.stringify(acoustic, null, 2)}\n`);
console.log(`Wrote immutable hashes and acoustic metadata for ${records.length} V9 scenes.`);

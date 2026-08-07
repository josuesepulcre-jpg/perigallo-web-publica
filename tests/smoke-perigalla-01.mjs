import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";

const url = process.argv[2] || "https://perigallo.com/la-perigalla-01/";
const response = await fetch(url, { redirect: "manual" });

if (response.status !== 200) {
  throw new Error(`Expected HTTP 200 for ${url}, received ${response.status}.`);
}

const html = await response.text();
for (const marker of ["id=\"root\"", "/la-perigalla-01/assets/", "La Perigalla 01"]) {
  if (!html.includes(marker)) throw new Error(`La Perigalla smoke check is missing ${marker}.`);
}

console.log(`La Perigalla smoke check passed: ${url}`);

const root = resolve(import.meta.dirname, "..");
const bundle = readFileSync(resolve(root, "la-perigalla-01/assets/index-XqHqQZsO.js"), "utf8");

if (!bundle.includes("(orientation: portrait) and (max-width: 1100px)")) {
  throw new Error("The story bundle is missing its portrait-art direction media query.");
}

for (const marker of [
  "function posterForScene",
  "getSceneGastronomy(c)&&Q(`dismissing`)",
  "!i||w||!getSceneGastronomy(A)",
  "gastronomy-layer phase-",
  "is-starting",
]) {
  if (!bundle.includes(marker)) throw new Error(`The story visual motor is missing ${marker}.`);
}

const motionCss = readFileSync(resolve(root, "la-perigalla-01/assets/story-motion-v2.css"), "utf8");
for (const marker of [
  "env(safe-area-inset-top)",
  "object-fit: contain",
  "phase-revealing",
  "story-v2-gastro-out",
  "prefers-reduced-motion",
  "z-index: 30",
  "story-camera-delay",
  ".story-player.is-starting .scene-composition.is-active",
  "story-v3-scene-exit",
  "story-frozen-transform",
  ".event-page .story-teaser",
  ".hero-copy > button",
  "height: 64svh",
  "min-height: 36svh",
  "welcome image and its promise are one opening composition",
  "hosts-hero-v6-right-face-refined.png",
]) {
  if (!motionCss.includes(marker)) throw new Error(`The visual motion stylesheet is missing ${marker}.`);
}

const mobileAudioGuard = readFileSync(resolve(root, "la-perigalla-01/assets/mobile-audio-recovery.js"), "utf8");
for (const marker of [
  "waitForPlayableNarration",
  "needs-audio-recovery",
  "NotAllowedError",
  "story-audio-recovery",
]) {
  if (!mobileAudioGuard.includes(marker)) throw new Error(`The mobile narration guard is missing ${marker}.`);
}

if (!html.includes("mobile-audio-recovery.js")) {
  throw new Error("The published story page does not load the mobile narration guard.");
}

for (const marker of [
  "dataset.storyScene",
  "preload:`auto`",
  "playback failed",
  "media error",
  "xe(!0,!1)",
  "fromMotionAge",
  "story-camera-delay",
  "getAnimations?.()",
  "fromTransform",
  "toMotionAge",
  "Descubrir la historia",
  "[i,a]=(0,l.useState)(e===`auto`)",
]) {
  if (!bundle.includes(marker)) throw new Error(`The story playback resilience check is missing ${marker}.`);
}

if (bundle.includes("Comenzar la experiencia") || bundle.includes("Ver cuento")) {
  throw new Error("The La Perigalla entry flow still contains an intermediate story action.");
}

const audioAudit = JSON.parse(readFileSync(resolve(root, "reports/story-audio-audit.json"), "utf8"));
if (audioAudit.report?.length !== 23 || audioAudit.report.some((item) => item.severity === "error")) {
  throw new Error("The generated narration audio audit is incomplete or has errors.");
}

for (let scene = 0; scene <= 21; scene += 1) {
  const file = resolve(
    root,
    `la-perigalla-01/media/storytelling/perigalla-01/posters-mobile/scene-${String(scene).padStart(2, "0")}-mobile.jpg`,
  );
  if (!existsSync(file)) throw new Error(`Missing portrait art direction asset: ${file}`);
}

console.log("Portrait art direction check passed: 22 mobile scene assets available.");
console.log("Narration audio audit passed: 23 unchanged MP3 scenes are valid.");

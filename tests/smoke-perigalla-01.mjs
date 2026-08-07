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
]) {
  if (!motionCss.includes(marker)) throw new Error(`The visual motion stylesheet is missing ${marker}.`);
}

for (let scene = 0; scene <= 21; scene += 1) {
  const file = resolve(
    root,
    `la-perigalla-01/media/storytelling/perigalla-01/posters-mobile/scene-${String(scene).padStart(2, "0")}-mobile.jpg`,
  );
  if (!existsSync(file)) throw new Error(`Missing portrait art direction asset: ${file}`);
}

console.log("Portrait art direction check passed: 22 mobile scene assets available.");

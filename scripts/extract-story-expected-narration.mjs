import { createHash } from "node:crypto";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, resolve } from "node:path";

const sourceArgument = process.argv.find((argument) => argument.startsWith("--source="));
if (!sourceArgument) {
  throw new Error("Usage: node scripts/extract-story-expected-narration.mjs --source=/absolute/path/to/lib/perigalla-story.ts");
}

const root = process.cwd();
const sourcePath = resolve(sourceArgument.slice("--source=".length));
const source = await readFile(sourcePath, "utf8");
const sourceHash = createHash("sha256").update(source).digest("hex");
const scenePattern = /scene\(\{([\s\S]*?)\}\),/g;
const scenes = [...source.matchAll(scenePattern)].map(([, body]) => {
  const sceneId = body.match(/id:"([^"]+)"/)?.[1];
  const narration = body.match(/narration:"([^"]*)"/)?.[1]?.replace(/\\n/g, "\n");
  if (!sceneId || narration === undefined) {
    throw new Error("Could not extract a scene ID and narration from the supplied source.");
  }
  return { sceneId, narration };
});

if (scenes.length !== 23) {
  throw new Error(`Expected 23 scenes; extracted ${scenes.length}.`);
}

const report = {
  generatedAt: new Date().toISOString(),
  purpose: "Expected script baseline only; this file is not an ASR transcript.",
  source: {
    suppliedArtifact: "user-supplied source archive",
    sourceFile: "lib/perigalla-story.ts",
    sha256: sourceHash,
    versionControlledInThisRepository: false,
  },
  sceneCount: scenes.length,
  scenes,
};
const output = resolve(root, "reports/audio/expected-narration.json");
await mkdir(dirname(output), { recursive: true });
await writeFile(output, `${JSON.stringify(report, null, 2)}\n`);
console.log(`Wrote expected narration baseline for ${scenes.length} scenes.`);

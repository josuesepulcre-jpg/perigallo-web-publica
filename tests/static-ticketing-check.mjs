import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";

const root = process.cwd();
const required = [
  "api/index.php",
  "api/src/Ticketing.php",
  "api/src/Redsys.php",
  "database/migrations/001_ticketing_schema.sql",
  "database/migrations/002_event_editor.sql",
  "database/migrations/003_suite_experience_integration.sql",
  "eventos/index.html",
  "eventos/evento.html",
  "entradas/checkout/index.html",
  "entradas/pedido/index.html",
  "entradas/pago/correcto/index.html",
  "entradas/pago/error/index.html",
  "admin/entradas/index.html",
  "admin/entradas/evento/index.html",
  "admin/entradas/vista-previa/index.html",
  "admin/entradas/acceso/index.html",
  "solicitud-evento/index.html",
  "docs/CYBERPAC_REDSYS_PERIGALLO_COM.md",
  "docs/TICKETING_DEPLOYMENT.md",
  "docs/TICKETING_PRODUCTION_CHECKLIST.md",
  ".env.example",
  ".htaccess",
];

const missing = required.filter((file) => !existsSync(join(root, file)));
if (missing.length) {
  throw new Error(`Missing required files:\n${missing.join("\n")}`);
}

const activeFiles = [
  "index.html",
  "eventos/index.html",
  "eventos/evento.html",
  "entradas/checkout/index.html",
  "entradas/pedido/index.html",
  "admin/entradas/index.html",
  "admin/entradas/evento/index.html",
  "admin/entradas/vista-previa/index.html",
  "admin/entradas/acceso/index.html",
  "solicitud-evento/index.html",
  "assets/js/ticketing.js",
  "assets/js/ticketing-admin.js",
];

const forbidden = [
  /<iframe[^>]+(?:reservas|suite)\.perigallo\.com/i,
  /mailto:hola@perigallo/i,
  /gestor interno de reservas/i,
  /Panel operativo para el equipo/i,
  /REDSYS_SECRET_KEY=(?!\s*$).+/,
];

for (const file of activeFiles) {
  const body = readFileSync(join(root, file), "utf8");
  for (const pattern of forbidden) {
    if (pattern.test(body)) {
      throw new Error(`Forbidden pattern ${pattern} found in ${file}`);
    }
  }
}

const api = readFileSync(join(root, "api/index.php"), "utf8");
const ticketing = readFileSync(join(root, "api/src/Ticketing.php"), "utf8");
for (const marker of [
  "/admin/events/([0-9]+)/preview",
  "adminUpdateEvent",
  "adminDuplicateEvent",
  "adminDuplicateTicketType",
  "adminArchiveOrDeleteTicketType",
  "adminUploadImage",
  "publication_at <= NOW()",
  "require_suite_service",
  "integrationUpdateExperience",
  "media_type",
  "video/quicktime",
  "logo_url",
]) {
  if (!(api + ticketing).includes(marker)) {
    throw new Error(`Missing event editor contract: ${marker}`);
  }
}

const editor = readFileSync(join(root, "admin/entradas/evento/index.html"), "utf8");
for (const marker of ["data-event-media-manager", "name=\"social_image_url\"", "name=\"gallery\""]) {
  if (!editor.includes(marker)) throw new Error(`Missing media editor marker: ${marker}`);
}
if (editor.includes("Galería (una URL por línea)") || editor.includes("data-upload-media")) {
  throw new Error("Legacy media uploader is still present in the event editor.");
}

console.log("Static ticketing checks passed");

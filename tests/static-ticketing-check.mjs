import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";

const root = process.cwd();
const required = [
  "api/index.php",
  "api/src/Ticketing.php",
  "api/src/Redsys.php",
  "database/migrations/001_ticketing_schema.sql",
  "eventos/index.html",
  "eventos/evento.html",
  "entradas/checkout/index.html",
  "entradas/pedido/index.html",
  "entradas/pago/correcto/index.html",
  "entradas/pago/error/index.html",
  "admin/entradas/index.html",
  "admin/entradas/acceso/index.html",
  "solicitud-evento/index.html",
  "docs/CYBERPAC_REDSYS_PERIGALLO_COM.md",
  "docs/TICKETING_DEPLOYMENT.md",
  "docs/TICKETING_PRODUCTION_CHECKLIST.md",
  ".env.example",
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

console.log("Static ticketing checks passed");

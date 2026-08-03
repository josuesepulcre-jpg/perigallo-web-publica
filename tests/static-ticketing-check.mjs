import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";
import vm from "node:vm";

const root = process.cwd();
const required = [
  "api/index.php",
  "api/src/Ticketing.php",
  "api/src/Redsys.php",
  "database/migrations/001_ticketing_schema.sql",
  "database/migrations/002_event_editor.sql",
  "database/migrations/003_suite_experience_integration.sql",
  "database/migrations/004_long_public_event_information.sql",
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
  ".user.ini",
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
  "assets/css/event-information-accordions.css",
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
  "/admin/events/([0-9]+)/public-information",
  "adminUpdateEvent",
  "adminUpdatePublicInformation",
  "adminDuplicateEvent",
  "adminDuplicateTicketType",
  "adminArchiveOrDeleteTicketType",
  "adminUploadImage",
  "upload_max_filesize y post_max_size",
  "publication_at <= NOW()",
  "require_suite_service",
  "integrationUpdateExperience",
  "media_type",
  "video/quicktime",
  "logo_url",
  "trim((string) ($merged['dress_code'] ?? ''))",
]) {
  if (!(api + ticketing).includes(marker)) {
    throw new Error(`Missing event editor contract: ${marker}`);
  }
}

const publicTicketing = readFileSync(join(root, "assets/js/ticketing.js"), "utf8");
for (const marker of ["experience-accordion-nested", "Preguntas frecuentes", "Cancelaciones y devoluciones", "linkifyText"]) {
  if (!publicTicketing.includes(marker)) throw new Error(`Missing public information accordion marker: ${marker}`);
}

const editor = readFileSync(join(root, "admin/entradas/evento/index.html"), "utf8");
for (const marker of ["data-event-media-manager", "name=\"social_image_url\"", "name=\"gallery\""]) {
  if (!editor.includes(marker)) throw new Error(`Missing media editor marker: ${marker}`);
}
if (editor.includes("Galería (una URL por línea)") || editor.includes("data-upload-media")) {
  throw new Error("Legacy media uploader is still present in the event editor.");
}

for (const marker of ["data-public-information", "data-public-input", "data-faq-preview"]) {
  if (!editor.includes(marker)) throw new Error(`Missing public information editor marker: ${marker}`);
}

for (const marker of ["data-open-ticket-form", "data-ticket-drawer", "data-ticket-final-price", "data-close-ticket-drawer"]) {
  if (!editor.includes(marker)) throw new Error(`Missing ticket drawer marker: ${marker}`);
}

const longPublicInformationMigration = readFileSync(join(root, "database/migrations/004_long_public_event_information.sql"), "utf8");
for (const field of ["included_text", "access_conditions", "minor_policy", "refund_policy", "contact_info", "recommendations", "dress_code", "accessibility_info"]) {
  if (!new RegExp(`MODIFY COLUMN ${field} LONGTEXT`).test(longPublicInformationMigration)) {
    throw new Error(`Long public information migration does not upgrade ${field}.`);
  }
}

const adminJs = readFileSync(join(root, "assets/js/ticketing-admin.js"), "utf8");
for (const marker of ["function parseFaq", "line.indexOf(\"|\")", "Guardando cambios y abriendo vista previa", "post_max_size en Plesk", "savePublicInformation", "public-information", "No se han podido guardar los cambios. El contenido permanece en el editor."]) {
  if (!adminJs.includes(marker)) throw new Error(`Missing reliable public information behavior: ${marker}`);
}
for (const marker of ["openTicketDrawer", "closeTicketDrawer", "updateTicketPricePreview", "validateTicketForm", "Tienes cambios sin guardar. ¿Quieres cerrar el formulario?"]) {
  if (!adminJs.includes(marker)) throw new Error(`Missing ticket drawer behavior: ${marker}`);
}
const parseFaqSource = adminJs.match(/function parseFaq\(value\) \{[\s\S]*?\n  \}\n\n  function formData/);
if (!parseFaqSource) throw new Error("Unable to locate the FAQ parser.");
const faqSandbox = {};
vm.runInNewContext(`${parseFaqSource[0].replace(/\n  function formData$/, "")}\nthis.parseFaq = parseFaq;`, faqSandbox);
const longFaq = Array.from({ length: 30 }, (_, index) => `Pregunta ${index + 1} | Respuesta extensa con | barras y tildes: acción, € y ñ.`).join("\n");
const parsedFaq = faqSandbox.parseFaq(longFaq);
if (parsedFaq.rows.length !== 30 || parsedFaq.rows[0].answer.indexOf("| barras") === -1 || parsedFaq.invalidLines.length !== 0) {
  throw new Error("FAQ parser does not preserve long answers or additional separators.");
}
const incompleteFaq = faqSandbox.parseFaq("Pregunta sin separador\nPregunta válida | Respuesta");
if (incompleteFaq.rows.length !== 2 || incompleteFaq.invalidLines.join(",") !== "1") {
  throw new Error("FAQ parser does not preserve or report incomplete lines.");
}

const publicJs = readFileSync(join(root, "assets/js/ticketing.js"), "utf8");
for (const marker of ["Información de la experiencia", "Código de vestimenta", "event.contact_info", "experienceAccordions", "initExperienceAccordions", "aria-expanded", "eventMetadata", "event-story-has-media", "poster="]) {
  if (!publicJs.includes(marker)) throw new Error(`Missing public information rendering: ${marker}`);
}

const css = readFileSync(join(root, "assets/css/ticketing.css"), "utf8");
for (const marker of [".public-information-editor", "white-space:pre-wrap", ".event-public-information", ".experience-accordion", "prefers-reduced-motion"]) {
  if (!css.includes(marker)) throw new Error(`Missing long-text presentation style: ${marker}`);
}
for (const marker of [".ticket-editor-drawer", ".ticket-drawer-grid", ".ticket-action-menu", ".ticket-final-price"]) {
  if (!css.includes(marker)) throw new Error(`Missing ticket drawer style: ${marker}`);
}

console.log("Static ticketing checks passed");

import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";
import vm from "node:vm";

const root = process.cwd();
const required = [
  "api/index.php",
  "api/src/Ticketing.php",
  "api/src/TicketDeliveryService.php",
  "api/src/WhatsAppDeliveryService.php",
  "api/src/Redsys.php",
  "database/migrations/001_ticketing_schema.sql",
  "database/migrations/002_event_editor.sql",
  "database/migrations/003_suite_experience_integration.sql",
  "database/migrations/004_long_public_event_information.sql",
  "database/migrations/005_configure_la_perigalla_01_publication.sql",
  "database/migrations/006_test_checkout_sandbox.sql",
  "database/migrations/007_la_perigalla_total_white_dress_code.sql",
  "database/migrations/008_secure_ticket_delivery_and_qr.sql",
  "database/migrations/009_ticket_access_movements.sql",
  "eventos/index.html",
  "eventos/evento.html",
  "entradas/checkout/index.html",
  "entradas/pedido/index.html",
  "entradas/pago/correcto/index.html",
  "entradas/pago/error/index.html",
  "entradas/pago/correcto/index.html",
  "entradas/pago/error/index.html",
  "admin/entradas/index.html",
  "admin/entradas/evento/index.html",
  "admin/entradas/vista-previa/index.html",
  "admin/entradas/acceso/index.html",
  "check-in/index.html",
  "assets/vendor/qrcode-generator.min.js",
  "assets/vendor/jspdf.umd.min.js",
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
  "assets/css/checkout.css",
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
const whatsAppDelivery = readFileSync(join(root, "api/src/WhatsAppDeliveryService.php"), "utf8");
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

for (const marker of ["data-open-ticket-form", "data-ticket-drawer", "data-ticket-final-price", "data-close-ticket-drawer", "data-publication-editor", "data-publication-status", "data-copy-public-url"]) {
  if (!editor.includes(marker)) throw new Error(`Missing ticket drawer marker: ${marker}`);
}

const longPublicInformationMigration = readFileSync(join(root, "database/migrations/004_long_public_event_information.sql"), "utf8");
for (const field of ["included_text", "access_conditions", "minor_policy", "refund_policy", "contact_info", "recommendations", "dress_code", "accessibility_info"]) {
  if (!new RegExp(`MODIFY COLUMN ${field} LONGTEXT`).test(longPublicInformationMigration)) {
    throw new Error(`Long public information migration does not upgrade ${field}.`);
  }
}

const perigallaPublicationMigration = readFileSync(join(root, "database/migrations/005_configure_la_perigalla_01_publication.sql"), "utf8");
for (const marker of ["WHERE id = 1", "status = 'draft'", "la-perigalla-01-ibicenca", "show_availability = 1"]) {
  if (!perigallaPublicationMigration.includes(marker)) throw new Error(`La Perigalla publication migration is missing ${marker}.`);
}

const perigallaDressCodeMigration = readFileSync(join(root, "database/migrations/007_la_perigalla_total_white_dress_code.sql"), "utf8");
for (const marker of ["WHERE id = 1", "La Perigalla 01", "TOTAL WHITE", "Obligatorio ir de blanco para acceder"]) {
  if (!perigallaDressCodeMigration.includes(marker)) throw new Error(`La Perigalla dress-code migration is missing ${marker}.`);
}

const adminJs = readFileSync(join(root, "assets/js/ticketing-admin.js"), "utf8");
for (const marker of ["function parseFaq", "line.indexOf(\"|\")", "Guardando cambios y abriendo vista previa", "post_max_size en Plesk", "savePublicInformation", "public-information", "No se han podido guardar los cambios. El contenido permanece en el editor.", "initPublicationEditor", "normalizePublicationSlug", "refreshPublicationEditor"]) {
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
for (const marker of ["Información de la experiencia", "Código de vestimenta", "event.contact_info", "experienceAccordions", "initExperienceAccordions", "aria-expanded", "eventMetadata", "event-story-has-media", "poster=", "Probar recorrido de compra", "Seleccionar entradas", "ticketPurchaseAction", "ticket-access-secondary", "event-brand-signature", "event-hero-introduction", "renderCheckoutPreview", "Modo de pruebas", "checkoutTicketMarkup", "data-quantity-action", "renderCheckoutSummary"]) {
  if (!publicJs.includes(marker)) throw new Error(`Missing public information rendering: ${marker}`);
}
for (const marker of ["adminRequest", "data-start-test-payment", "submitPaymentForm", "Redsys TEST", "MODO DE PRUEBAS", "initPaymentResult", "data-payment-result"]) {
  if (!publicJs.includes(marker)) throw new Error(`Missing sandbox checkout marker: ${marker}`);
}
for (const forbiddenMarker of ["Simular pago aceptado", "Simular pago rechazado", "Cancelar prueba", "initTestPayment", "data-test-payment"]) {
  if (publicJs.includes(forbiddenMarker)) throw new Error(`Legacy simulated payment marker is still public: ${forbiddenMarker}`);
}
if (existsSync(join(root, "entradas/pago/prueba/index.html"))) {
  throw new Error("Legacy simulated payment page must not be present.");
}

const paymentSuccess = readFileSync(join(root, "entradas/pago/correcto/index.html"), "utf8");
const paymentError = readFileSync(join(root, "entradas/pago/error/index.html"), "utf8");
for (const [file, marker] of [[paymentSuccess, 'data-payment-result="success"'], [paymentError, 'data-payment-result="error"']]) {
  if (!file.includes(marker) || !file.includes('/assets/js/ticketing.js')) throw new Error(`Payment return page is missing ${marker}.`);
}

const testMigration = readFileSync(join(root, "database/migrations/006_test_checkout_sandbox.sql"), "utf8");
for (const marker of ["is_test", "environment", "payment_status", "delivery_status", "test_session_id", "ticket_delivery_logs"]) {
  if (!testMigration.includes(marker)) throw new Error(`Sandbox migration is missing ${marker}.`);
}

const secureDeliveryMigration = readFileSync(join(root, "database/migrations/008_secure_ticket_delivery_and_qr.sql"), "utf8");
for (const marker of ["qr_token_ciphertext", "not_configured", "device_reference", "revertida"]) {
  if (!secureDeliveryMigration.includes(marker)) throw new Error(`Secure delivery migration is missing ${marker}.`);
}
const accessMovementMigration = readFileSync(join(root, "database/migrations/009_ticket_access_movements.sql"), "utf8");
for (const marker of ["ticket_access_movements", "access_status", "allow_reentry", "maximum_reentries", "reentry_until", "reversal_of_id"]) {
  if (!accessMovementMigration.includes(marker)) throw new Error(`Access movement migration is missing ${marker}.`);
}
for (const marker of ["ticketQrUrl", "encryptQrToken", "extractQrToken", "adminEventAttendees", "reverseTicketCheckIn", "registerAccessMovement", "ticket_access_movements", "resendOrderEmail"]) {
  if (!(api + ticketing).includes(marker)) throw new Error(`Missing secure delivery contract: ${marker}`);
}
for (const marker of ["/admin/tickets/access-preview", "/admin/tickets/access-movement", "requireAccessCsrf"]) {
  if (!api.includes(marker)) throw new Error(`Missing access movement endpoint contract: ${marker}`);
}
for (const file of ["check-in/index.html", "admin/entradas/acceso/index.html"]) {
  const page = readFileSync(join(root, file), "utf8");
  for (const marker of ["name=\"access_mode\"", "data-access-modal", "data-connection-status"]) {
    if (!page.includes(marker)) throw new Error(`Missing access scanner UI marker ${marker} in ${file}`);
  }
}
for (const marker of ["access-preview", "Validar entrada", "ticket-access-modal", "perigallo-access-mode"]) {
  if (!adminJs.includes(marker)) throw new Error(`Missing access scanner behavior: ${marker}`);
}
const adminAuth = readFileSync(join(root, "api/src/AdminAuth.php"), "utf8");
for (const marker of ["ACCESS_USERNAME", "ACCESS_PASSWORD_HASH", "control_acceso", "requireAccessCsrf", "can_revert"]) {
  if (!(adminAuth + api).includes(marker)) throw new Error(`Missing access-control role contract: ${marker}`);
}
if (!whatsAppDelivery.includes("WHATSAPP_PROVIDER") || !whatsAppDelivery.includes("meta_cloud")) {
  throw new Error("Missing transactional WhatsApp provider adapter.");
}

for (const marker of ["data-download-all", "qrcode", "application/pdf", "Descargar todas las entradas"]) {
  if (!publicJs.includes(marker)) throw new Error(`Missing ticket delivery client marker: ${marker}`);
}
for (const marker of ["createTestOrder", "assertConfigured", "assertSandboxConfigured", "redsysForm", "ticket_delivery_logs", "is_test = 0", "TicketDeliveryService", "Ds_SignatureVersion", "notification processed"]) {
  if (!(api + ticketing).includes(marker)) throw new Error(`Missing isolated test-order contract: ${marker}`);
}
const redsys = readFileSync(join(root, "api/src/Redsys.php"), "utf8");
for (const marker of ["function terminal", "str_pad", "str_replace(' ', '+', $value)"]) {
  if (!redsys.includes(marker)) throw new Error(`Missing Redsys terminal or Base64 normalization: ${marker}`);
}

const checkout = readFileSync(join(root, "entradas/checkout/index.html"), "utf8");
for (const marker of ["data-checkout-eyebrow", "data-checkout-title", "data-checkout-safety-copy", "data-checkout-summary", "data-checkout-submit", "checkout.css"]) {
  if (!checkout.includes(marker)) throw new Error(`Missing preview-aware checkout marker: ${marker}`);
}

const checkoutCss = readFileSync(join(root, "assets/css/checkout.css"), "utf8");
for (const marker of [".quantity-stepper", ":-webkit-autofill", ".checkout-check-mark", ".checkout-summary", ".checkout-submit", "@media(max-width:860px)"]) {
  if (!checkoutCss.includes(marker)) throw new Error(`Missing premium checkout style: ${marker}`);
}

const css = readFileSync(join(root, "assets/css/ticketing.css"), "utf8");
for (const marker of [".public-information-editor", "white-space:pre-wrap", ".event-public-information", ".experience-accordion", "prefers-reduced-motion"]) {
  if (!css.includes(marker)) throw new Error(`Missing long-text presentation style: ${marker}`);
}
for (const marker of [".ticket-editor-drawer", ".ticket-drawer-grid", ".ticket-action-menu", ".ticket-final-price"]) {
  if (!css.includes(marker)) throw new Error(`Missing ticket drawer style: ${marker}`);
}

const publicAccessCss = readFileSync(join(root, "assets/css/event-information-accordions.css"), "utf8");
for (const marker of [".ticket-access-heading", ".ticket-access-secondary", ".ticket-access-decision", ".ticket-access-status-dot"]) {
  if (!publicAccessCss.includes(marker)) throw new Error(`Missing editorial ticket access style: ${marker}`);
}

console.log("Static ticketing checks passed");

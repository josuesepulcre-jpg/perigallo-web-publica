import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";
import vm from "node:vm";

const root = process.cwd();
const required = [
  "api/index.php",
  "api/src/Ticketing.php",
  "api/src/TicketDeliveryService.php",
  "api/src/WhatsAppDeliveryService.php",
  "api/src/DiscountCodes.php",
  "api/src/Redsys.php",
  "tests/redsys-secret-key-test.php",
  "database/migrations/001_ticketing_schema.sql",
  "database/migrations/002_event_editor.sql",
  "database/migrations/003_suite_experience_integration.sql",
  "database/migrations/004_long_public_event_information.sql",
  "database/migrations/005_configure_la_perigalla_01_publication.sql",
  "database/migrations/006_test_checkout_sandbox.sql",
  "database/migrations/007_la_perigalla_total_white_dress_code.sql",
  "database/migrations/008_secure_ticket_delivery_and_qr.sql",
  "database/migrations/009_ticket_access_movements.sql",
  "database/migrations/010_order_access_recovery.sql",
  "database/migrations/011_admin_users_and_order_operations.sql",
  "database/migrations/012_payment_methods_bizum.sql",
  "database/migrations/013_discount_codes.sql",
  "database/migrations/014_reference_ticket_price.sql",
  "database/migrations/016_first_party_analytics.sql",
  "database/migrations/017_ticket_attendee_allergies.sql",
  "database/migrations/018_order_access_conditions.sql",
  "database/migrations/019_public_lead_form.sql",
  "database/migrations/020_holded_fiscal_sync.sql",
  "database/migrations/021_holded_invoice_delivery.sql",
  "database/migrations/023_ticket_order_tax_breakdown.sql",
  "database/migrations/024_admin_cash_ticket_orders.sql",
  "database/migrations/022_checkout_runtime_compatibility.sql",
  "api/scripts/apply-migration.php",
  "api/scripts/purge-test-ticketing-data.php",
  "api/scripts/holded-health.php",
  "api/cron/holded-sync.php",
  "api/src/HoldedClient.php",
  "api/src/HoldedSyncService.php",
  "api/src/HoldedFiscalPolicy.php",
  "api/src/Analytics.php",
  "api/cron/analytics-report.php",
  "assets/js/analytics.js",
  "admin/analitica/index.html",
  "docs/ANALYTICS_DEPLOYMENT.md",
  "eventos/index.html",
  "eventos/evento.html",
  "entradas/checkout/index.html",
  "entradas/pedido/index.html",
  "mis-entradas/index.html",
  "entradas/pago/correcto/index.html",
  "entradas/pago/error/index.html",
  "entradas/pago/correcto/index.html",
  "entradas/pago/error/index.html",
  "admin/entradas/index.html",
  "admin/entradas/evento/index.html",
  "admin/entradas/vista-previa/index.html",
  "admin/entradas/acceso/index.html",
  "admin/index.html",
  "admin/login/index.html",
  "admin/eventos/index.html",
  "admin/ventas/index.html",
  "admin/usuarios/index.html",
  "admin/descuentos/index.html",
  "check-in/index.html",
  "assets/vendor/qrcode-generator.min.js",
  "assets/vendor/jspdf.umd.min.js",
  "solicitud-evento/index.html",
  "formulario/index.html",
  "la-perigalla-01/index.html",
  "la-perigalla-01/media/storytelling/perigalla-01/audio/v9-score.mp3",
  "la-perigalla-01/media/storytelling/perigalla-01/audio/v9-scenes/cover.mp3",
  "la-perigalla-01/media/storytelling/perigalla-01/audio/v9-scenes/final-celebration.mp3",
  "la-perigalla-01/media/storytelling/perigalla-01/hosts/hosts-hero-cover.png",
  "scripts/audit-story-audio.mjs",
  "docs/LA_PERIGALLA_AUDIO_AUDIT.md",
  "admin/formulario/index.html",
  "api/src/LeadForms.php",
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
  "assets/js/admin-backoffice.js",
  "assets/css/checkout.css",
  "assets/css/event-information-accordions.css",
  "assets/css/admin-orders.css",
  "assets/css/admin-cash-orders.css",
  "formulario/index.html",
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
const database = readFileSync(join(root, "api/src/Database.php"), "utf8");
const testDataPurge = readFileSync(join(root, "api/scripts/purge-test-ticketing-data.php"), "utf8");
const holdedClient = readFileSync(join(root, "api/src/HoldedClient.php"), "utf8");
const holdedSync = readFileSync(join(root, "api/src/HoldedSyncService.php"), "utf8");
const holdedPolicy = readFileSync(join(root, "api/src/HoldedFiscalPolicy.php"), "utf8");
const holdedMigration = readFileSync(join(root, "database/migrations/020_holded_fiscal_sync.sql"), "utf8");
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
for (const marker of ["adminSetEventArchived", "adminDeleteEvent", "/(archive|restore)", "No se puede eliminar un evento con ventas o reservas reales"]) {
  if (!(api + ticketing).includes(marker)) throw new Error(`Missing explicit event lifecycle contract: ${marker}`);
}
const holdedInvoiceDeliveryMigration = readFileSync(join(root, "database/migrations/021_holded_invoice_delivery.sql"), "utf8");
for (const marker of ["holded_invoice_delivery_status", "holded_invoice_delivery_attempts", "idx_ticket_orders_holded_invoice_delivery"]) {
  if (!holdedInvoiceDeliveryMigration.includes(marker)) throw new Error(`Holded invoice-delivery migration is missing ${marker}.`);
}

const publicTicketing = readFileSync(join(root, "assets/js/ticketing.js"), "utf8");
for (const marker of ["DateTimeZone", "DateTimeImmutable", "SET time_zone"]) {
  if (!database.includes(marker)) throw new Error(`Database session timezone configuration is missing ${marker}.`);
}
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

const adminBackoffice = readFileSync(join(root, "assets/js/admin-backoffice.js"), "utf8");
for (const marker of ["/admin/login/", "data-admin-dashboard", "data-admin-events-list", "data-admin-orders-list", "data-admin-users-page", "/admin/eventos/", "/admin/acceso/", "/admin/usuarios/", "data-order-action", "record-refund", "purge-test"]) {
  if (!adminBackoffice.includes(marker)) throw new Error(`Missing central backoffice marker: ${marker}`);
}
const cashMigration = readFileSync(join(root, "database/migrations/024_admin_cash_ticket_orders.sql"), "utf8");
const adminSales = readFileSync(join(root, "admin/ventas/index.html"), "utf8");
const cashSalesCss = readFileSync(join(root, "assets/css/admin-cash-orders.css"), "utf8");
for (const marker of ["sales_channel", "cash_payment_status", "cash_payment_recorded_at"]) {
  if (!cashMigration.includes(marker)) throw new Error(`Cash-order migration is missing ${marker}.`);
}
for (const marker of ["data-cash-order-total", "data-cash-order-total-amount", "Total en efectivo"]) {
  if (!adminSales.includes(marker)) throw new Error(`Cash-order total UI is missing ${marker}.`);
}
if (!cashSalesCss.includes(".admin-cash-order-total")) throw new Error("Cash-order total styles are missing.");
for (const marker of ["updateCashOrderTotal", "cashWhatsAppUrl", "whatsapp_url", "Total cobrado en efectivo"]) {
  if (!adminBackoffice.includes(marker)) throw new Error(`Cash-order total or WhatsApp behavior is missing ${marker}.`);
}
for (const marker of ["cashOrderWhatsAppUrl", "whatsapp_url", "Aplica la migración 024"]) {
  if (!ticketing.includes(marker)) throw new Error(`Cash-order API contract is missing ${marker}.`);
}
if (!api.includes("['ok' => true] + $ticketing->adminCreateCashOrder")) {
  throw new Error("Cash-order endpoint must return the WhatsApp URL at the top level.");
}
const adminLogin = readFileSync(join(root, "admin/login/index.html"), "utf8");
for (const marker of ["Administración Perigallo", "data-admin-login-page", "data-toggle-password"]) {
  if (!adminLogin.includes(marker)) throw new Error(`Missing central login marker: ${marker}`);
}
const rootHtaccess = readFileSync(join(root, ".htaccess"), "utf8");
for (const marker of ["^admin/acceso", "^admin/eventos/([0-9]+)/editar"]) {
  if (!rootHtaccess.includes(marker)) throw new Error(`Missing central admin rewrite: ${marker}`);
}
const publicLeadForm = readFileSync(join(root, "formulario/index.html"), "utf8");
for (const marker of ["https://perigallo.com/formulario/", "privacy-accepted", "website", "fetch('/api/formulario'", "Formulario temporalmente en pausa"]) {
  if (!publicLeadForm.includes(marker)) throw new Error(`Missing public lead form marker: ${marker}`);
}
if (publicLeadForm.includes("emailjs.init(")) throw new Error("The public lead form must not initialise EmailJS.");
const leadForms = readFileSync(join(root, "api/src/LeadForms.php"), "utf8");
for (const marker of ["lead_requests", "lead_form_settings", "PGF-", "email_status", "normaliseAnswers", "privacy_accepted", "INTERVAL 15 MINUTE"]) {
  if (!leadForms.includes(marker)) throw new Error(`Missing lead form persistence contract: ${marker}`);
}
for (const marker of ["/formulario/settings", "POST' && $path === '/formulario'", "/admin/formulario/solicitudes", "CONTENT_LENGTH"]) {
  if (!api.includes(marker)) throw new Error(`Missing lead form API contract: ${marker}`);
}
const leadMigration = readFileSync(join(root, "database/migrations/019_public_lead_form.sql"), "utf8");
for (const marker of ["lead_form_settings", "lead_requests", "follow_up", "proposal_sent", "email_status", "ip_hash"]) {
  if (!leadMigration.includes(marker)) throw new Error(`Lead form migration is missing ${marker}.`);
}
for (const marker of ["^solicitud-evento/?$ https://suite.perigallo.com/solicitud?origen=web-perigallo [R=302,L,NE]", "^admin/formulario/?$ admin/formulario/index.html"]) {
  if (!rootHtaccess.includes(marker)) throw new Error(`Missing lead form route contract: ${marker}`);
}
const publicRequestPages = [
  "index.html",
  "contacto/index.html",
  "bodas-alicante/index.html",
  "celebraciones-familiares-alicante/index.html",
  "comuniones-alicante/index.html",
  "bautizos-alicante/index.html",
  "eventos-empresa-alicante/index.html",
  "eventos-privados-alicante/index.html",
  "experiencias/index.html",
  "finca-la-llaguna/index.html",
  "sobre-perigallo/index.html",
  "solicitud-evento/index.html",
];
const initialRequestUrl = "https://suite.perigallo.com/solicitud?origen=web-perigallo";
for (const page of publicRequestPages) {
  const content = readFileSync(join(root, page), "utf8");
  if (!content.includes(initialRequestUrl)) throw new Error(`Public request route is missing from ${page}.`);
  if (content.includes('href="/formulario/"')) throw new Error(`Private wedding form is publicly linked from ${page}.`);
}
const leadAdmin = readFileSync(join(root, "admin/formulario/index.html"), "utf8");
if (!leadAdmin.includes("data-admin-lead-form-page")) throw new Error("Missing lead form admin page marker.");
for (const marker of ["/admin/formulario/", "data-admin-nav-item=\"lead_form\"", "initLeadForms", "leadStatusLabel"]) {
  if (!adminBackoffice.includes(marker)) throw new Error(`Missing lead form admin behavior: ${marker}`);
}
const adminUsersMigration = readFileSync(join(root, "database/migrations/011_admin_users_and_order_operations.sql"), "utf8");
for (const marker of ["ticket_admin_users", "password_hash", "ticket_admin_audit_logs", "control_acceso"]) {
  if (!adminUsersMigration.includes(marker)) throw new Error(`Admin users migration is missing ${marker}.`);
}
for (const marker of ["/admin/orders/([0-9]+)/cancel", "/admin/orders/([0-9]+)/record-refund", "/admin/orders/([0-9]+)/test", "/admin/users", "requireOwner"]) {
  if (!api.includes(marker) && !adminBackoffice.includes(marker)) throw new Error(`Missing protected backoffice operation: ${marker}`);
}

for (const marker of ["--confirm", "WHERE is_test = 1", "adminPurgeTestOrder", "Limpieza inicial por terminal"]) {
  if (!testDataPurge.includes(marker)) throw new Error(`Test-data purge command is missing ${marker}.`);
}
for (const marker of ["HOLDED_ENABLED=false", "HOLDED_DRY_RUN=true", "HOLDED_API_KEY=", "HOLDED_DEFAULT_TAX_ID=", "HOLDED_TREASURY_ID="]) {
  if (!readFileSync(join(root, ".env.example"), "utf8").includes(marker)) throw new Error(`Holded safe configuration missing ${marker}.`);
}
for (const marker of ["holded_status", "holded_sync_logs", "holded_contacts", "holded_refund_requests", "requires_review"]) {
  if (!holdedMigration.includes(marker)) throw new Error(`Holded migration is missing ${marker}.`);
}
const taxBreakdownMigration = readFileSync(join(root, "database/migrations/023_ticket_order_tax_breakdown.sql"), "utf8");
for (const marker of ["unit_base_cents", "unit_tax_cents", "tax_rate", "unit_fee_cents"]) {
  if (!taxBreakdownMigration.includes(marker)) throw new Error(`Order tax breakdown migration is missing ${marker}.`);
}
for (const marker of ["unit_base_cents", "holded_tax_mapping", "unit_fee_cents"]) {
  if (!holdedSync.includes(marker)) throw new Error(`Holded tax breakdown missing ${marker}.`);
}
if (!ticketing.includes('tt.price_cents + ROUND(tt.price_cents * tt.tax_rate / 100) + tt.fee_cents')) {
  throw new Error('Public event cards must use the final ticket price including taxes and fees.');
}
for (const marker of ["https://api.holded.com/api/v2", "Authorization: Bearer", "HOLDED_DRY_RUN", "recordInvoicePayment", "createSalesReceipt", "createCreditNote"]) {
  if (!holdedClient.includes(marker)) throw new Error(`Holded client contract is missing ${marker}.`);
}
for (const marker of ["queuePaidProductionOrder", "holded_status = \"processing\"", "requires_review", "holded_sync_logs", "HOLDED_SIMPLIFIED_MAX_CENTS"]) {
  if (!(holdedSync + ticketing + holdedPolicy).includes(marker)) throw new Error(`Holded sync contract is missing ${marker}.`);
}
for (const marker of ["billing_requested", "normaliseBilling", "queuePaidProductionOrder", "/admin/holded/health", "/holded/retry"]) {
  if (!(ticketing + api + readFileSync(join(root, "assets/js/ticketing.js"), "utf8") + adminBackoffice).includes(marker)) throw new Error(`Holded checkout/admin contract is missing ${marker}.`);
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
for (const marker of ["Información de la experiencia", "Código de vestimenta", "event.contact_info", "experienceInformation", "experienceDesktopGuide", "initExperienceAccordions", "aria-expanded", "eventMetadata", "event-story-editorial", "Probar recorrido de compra", "Comprar entradas", "ticketPurchaseAction", "ticket-access-secondary", "event-hero-introduction", "event-hero-facts", "renderCheckoutPreview", "Modo de pruebas", "checkoutTicketMarkup", "data-quantity-action", "data-event-quantity-action", "renderCheckoutSummary", "storyCtaUrl", "storyCtaLabel", "Descubrir la historia", "event-card-primary-link", "https://perigallo.com/la-perigalla-01/", "data-analytics-click=\"descubrir-historia\""]) {
  if (!publicJs.includes(marker)) throw new Error(`Missing public information rendering: ${marker}`);
}
for (const marker of ["dressCodeNotice", "perigallaDressCodeFact", "Código de vestimenta obligatorio", "Es obligatorio acudir vestido íntegramente de blanco", "No se permitirá el acceso"]) {
  if (!publicJs.includes(marker)) throw new Error(`Missing clear public dress-code notice: ${marker}`);
}

const homeExperiences = readFileSync(join(root, "assets/js/home-experiences.js"), "utf8");
for (const marker of ["Total White · +18", "Por persona", "reference_price_from_cents", "experience-carousel-price-values", "Descubrir la experiencia", "Ver todas las experiencias"]) {
  if (!homeExperiences.includes(marker)) throw new Error(`Missing editorial home experience marker: ${marker}`);
}
for (const forbiddenMarker of ["Código de vestimenta obligatorio", "No se permitirá el acceso"]) {
  if (homeExperiences.includes(forbiddenMarker)) throw new Error(`La agenda de la home conserva un detalle operativo: ${forbiddenMarker}`);
}
for (const marker of ["adminRequest", "data-start-test-payment", "submitPaymentForm", "Redsys TEST", "MODO DE PRUEBAS", "initPaymentResult", "data-payment-result"]) {
  if (!publicJs.includes(marker)) throw new Error(`Missing sandbox checkout marker: ${marker}`);
}
for (const forbiddenMarker of ["Simular pago aceptado", "Simular pago rechazado", "Cancelar prueba", "initTestPayment", "data-test-payment"]) {
  if (publicJs.includes(forbiddenMarker)) throw new Error(`Legacy simulated payment marker is still public: ${forbiddenMarker}`);
}

const perigallaStoryPage = readFileSync(join(root, "la-perigalla-01/index.html"), "utf8");
const storyAssets = Array.from(perigallaStoryPage.matchAll(/(?:src|href)="(\/la-perigalla-01\/assets\/[^\"]+)"/g), (match) => match[1]);
const storyAssetPath = (asset) => asset.split("?", 1)[0];
const storyJavaScriptAssets = storyAssets.filter((asset) => storyAssetPath(asset).endsWith(".js"));
const storyStylesheetAssets = storyAssets.filter((asset) => storyAssetPath(asset).endsWith(".css"));
const storyApplicationAsset = storyJavaScriptAssets.find((asset) => /\/index-[^/]+\.js$/.test(asset));
if (!storyApplicationAsset || storyStylesheetAssets.length < 1) throw new Error("La Perigalla story page must reference its application bundle and at least one stylesheet asset.");
for (const asset of storyAssets) {
  if (!existsSync(join(root, storyAssetPath(asset).slice(1)))) throw new Error(`La Perigalla story asset is missing: ${asset}`);
}
const storyBundle = readFileSync(join(root, storyAssetPath(storyApplicationAsset).slice(1)), "utf8");
for (const marker of ["Bienvenidos a", "La Perigalla 01", "v9-scenes", "final-celebration", "Quiero vivir la historia", "/entradas/checkout/?event=la-perigalla-01-ibicenca&quantity=1&ticketType=1", "story_transition_start", "story_transition_end", "20260807-03"]) {
  if (!storyBundle.includes(marker)) throw new Error(`La Perigalla story bundle is missing ${marker}.`);
}
const storyStyles = storyStylesheetAssets.map((asset) => readFileSync(join(root, storyAssetPath(asset).slice(1)), "utf8")).join("\n");
if (!storyStyles.includes("hosts-hero-cover.png")) {
  throw new Error("La Perigalla story stylesheet is missing the approved cover image.");
}
if (!storyStyles.includes("background-position: 50% 0%")) {
  throw new Error("La Perigalla desktop hero must preserve the hosts' faces.");
}
for (const marker of ["scene-composition", "story-scene-exit", "story-content-enter", "story-act-matte"]) {
  if (!storyStyles.includes(marker)) throw new Error(`La Perigalla story stylesheet is missing ${marker}.`);
}
if (storyBundle.includes('"/media/storytelling/perigalla-01')) {
  throw new Error("La Perigalla story bundle still contains an unscoped media URL.");
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
const referencePriceMigration = readFileSync(join(root, "database/migrations/014_reference_ticket_price.sql"), "utf8");
for (const marker of ["reference_price_cents", "promotional_label", "show_reference_price", "reference_unit_price_cents", "La Perigalla 01", "9000"]) {
  if (!referencePriceMigration.includes(marker)) throw new Error(`Reference price migration is missing ${marker}.`);
}
for (const marker of ["visibleReferencePrice", "reference_unit_price_cents", "reference_total_cents", "orderReferenceTotal"]) {
  if (!ticketing.includes(marker)) throw new Error(`Ticketing reference-price contract is missing ${marker}.`);
}
for (const marker of ["reference_price", "promotional_label", "show_reference_price"]) {
  if (!adminJs.includes(marker)) throw new Error(`Admin reference-price editor is missing ${marker}.`);
}
for (const marker of ["Valor de la experiencia", "Ahorro en esta reserva", "ticket-reference-price"]) {
  if (!publicJs.includes(marker)) throw new Error(`Public reference-price rendering is missing ${marker}.`);
}
const accessMovementMigration = readFileSync(join(root, "database/migrations/009_ticket_access_movements.sql"), "utf8");
for (const marker of ["ticket_access_movements", "access_status", "allow_reentry", "maximum_reentries", "reentry_until", "reversal_of_id"]) {
  if (!accessMovementMigration.includes(marker)) throw new Error(`Access movement migration is missing ${marker}.`);
}
const orderRecoveryMigration = readFileSync(join(root, "database/migrations/010_order_access_recovery.sql"), "utf8");
for (const marker of ["ticket_order_access_links", "token_hash", "expires_at", "ticket_access_recovery_requests", "identifier_hash", "ip_hash"]) {
  if (!orderRecoveryMigration.includes(marker)) throw new Error(`Order recovery migration is missing ${marker}.`);
}
const myTickets = readFileSync(join(root, "mis-entradas/index.html"), "utf8");
for (const marker of ["data-order-recovery", "data-order-recovery-form", "data-order-status", "Enviar enlace seguro"]) {
  if (!myTickets.includes(marker)) throw new Error(`My tickets page is missing ${marker}.`);
}
for (const marker of ["/orders/recover", "/orders/access/", "requestOrderAccessRecovery", "getOrderByAccessLink", "initMyTickets", "queueOrderRecoveryEmail"]) {
  if (!(api + ticketing + publicJs).includes(marker)) throw new Error(`Missing secure recovery contract: ${marker}`);
}
for (const marker of ["ticketQrUrl", "encryptQrToken", "extractQrToken", "adminEventAttendees", "reverseTicketCheckIn", "registerAccessMovement", "ticket_access_movements", "resendOrderEmail"]) {
  if (!(api + ticketing).includes(marker)) throw new Error(`Missing secure delivery contract: ${marker}`);
}
for (const marker of ["/admin/tickets/access-preview", "/admin/tickets/access-movement", "requireAccessCsrf"]) {
  if (!api.includes(marker)) throw new Error(`Missing access movement endpoint contract: ${marker}`);
}
for (const file of ["check-in/index.html", "admin/entradas/acceso/index.html"]) {
  const page = readFileSync(join(root, file), "utf8");
  for (const marker of ["name=\"access_mode\"", "data-access-modal", "data-connection-status", "data-open-manual", "data-toggle-flash", "data-manual-code-panel", "Leer QR"]) {
    if (!page.includes(marker)) throw new Error(`Missing access scanner UI marker ${marker} in ${file}`);
  }
  for (const forbidden of ["Punto de acceso", "data-access-mode"]) {
    if (page.includes(forbidden)) throw new Error(`Access scanner should not expose ${forbidden} in ${file}.`);
  }
}
for (const marker of ["access-preview", "Validar entrada", "ticket-access-modal", "toggleFlash", "requestWakeLock", "getUserMedia", "data-manual-code-panel"]) {
  if (!adminJs.includes(marker)) throw new Error(`Missing access scanner behavior: ${marker}`);
}
for (const marker of ["recentQrScans", "cameraErrorMessage", "data-clear-manual", "data-retry-access", "visibilityState === \"hidden\"", "ENTRADA DE OTRA EXPERIENCIA", "NO SE HA PODIDO COMPROBAR LA ENTRADA"]) {
  if (!adminJs.includes(marker)) throw new Error(`Missing mobile access-safety behavior: ${marker}`);
}
const accessScannerPage = readFileSync(join(root, "admin/entradas/acceso/index.html"), "utf8");
for (const marker of ["data-access-permission-state", "autocorrect=\"off\"", "enterkeyhint=\"done\"", "Coloca el QR dentro del recuadro"]) {
  if (!accessScannerPage.includes(marker)) throw new Error(`Missing mobile access page marker: ${marker}`);
}
const adminMobileCss = readFileSync(join(root, "assets/css/admin-mobile.css"), "utf8");
for (const marker of ["safe-area-inset-top", "admin-drawer-open", ".admin-mobile-menu", "min-height:44px"]) {
  if (!adminMobileCss.includes(marker)) throw new Error(`Missing responsive admin navigation style: ${marker}`);
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
const mailer = readFileSync(join(root, "api/src/Mailer.php"), "utf8");
const ticketDelivery = readFileSync(join(root, "api/src/TicketDeliveryService.php"), "utf8");
for (const marker of ["multipart/alternative", "Content-Type: text/html", "basicOrderHtml", "recoveryOrderHtml", "perigallo-logo-original.png"]) {
  if (!mailer.includes(marker)) throw new Error(`Missing HTML email delivery support: ${marker}.`);
}
for (const marker of ["deliverDueInvoiceEmails", "queueInvoiceEmail", "holded_invoice_delivery_status"]) {
  if (!(holdedSync + mailer).includes(marker)) throw new Error(`Missing Holded invoice-delivery behavior: ${marker}.`);
}
for (const marker of ["Accede de nuevo a tus entradas", "Abrir mis entradas", "Tus entradas siguen aquí"]) {
  if (!mailer.includes(marker)) throw new Error(`Missing premium recovery email marker: ${marker}.`);
}
for (const marker of ["Descargar mis entradas", "Tu experiencia está confirmada", "orderEmailHtml", "El enlace abre tu pedido", "perigallo-logo-original.png"]) {
  if (!ticketDelivery.includes(marker)) throw new Error(`Missing premium ticket email marker: ${marker}.`);
}
const envExample = readFileSync(join(root, ".env.example"), "utf8");
const redsys = readFileSync(join(root, "api/src/Redsys.php"), "utf8");
for (const marker of ["function terminal", "str_pad", "str_replace(' ', '+', $value)"]) {
  if (!redsys.includes(marker)) throw new Error(`Missing Redsys terminal or Base64 normalization: ${marker}`);
}
for (const marker of ["invoicePdfByToken", "/orders/([A-Za-z0-9_-]+)/invoice", "Descargar factura"]) {
  if (!(ticketing + api + publicJs).includes(marker)) throw new Error(`Missing secure invoice download contract: ${marker}.`);
}
for (const marker of ["function secretKey", "REDSYS_TEST_SECRET_KEY", "REDSYS_PRODUCTION_SECRET_KEY"]) {
  if (!redsys.includes(marker)) throw new Error(`Missing Redsys environment-specific secret-key handling: ${marker}`);
}
if (!/REDSYS_PRODUCTION_SECRET_KEY[\s\S]*?throw new RuntimeException/.test(redsys)) {
  throw new Error("Redsys production configuration must reject a missing production key.");
}
if (!envExample.includes("REDSYS_TEST_SECRET_KEY=") || !envExample.includes("REDSYS_PRODUCTION_SECRET_KEY=")) {
  throw new Error("Redsys environment-specific secret keys are missing from .env.example.");
}
if (redsys.includes("error_log(")) {
  throw new Error("Redsys must not write secret-bearing configuration to logs.");
}
const bizumMigration = readFileSync(join(root, "database/migrations/012_payment_methods_bizum.sql"), "utf8");
for (const marker of ["payment_method", "ENUM('card','bizum')", "idx_payment_attempts_method"]) {
  if (!bizumMigration.includes(marker)) throw new Error(`Bizum payment migration is missing ${marker}.`);
}
for (const marker of ["availablePaymentMethods", "REDSYS_BIZUM_ENABLED", "DS_MERCHANT_PAYMETHODS", "'z'"]) {
  if (!(redsys + ticketing).includes(marker)) throw new Error(`Bizum payment contract is missing ${marker}.`);
}
for (const marker of ["available' => true", "'id' => 'bizum'", "'available' => $this->bizumEnabled()"]) {
  if (!redsys.includes(marker)) throw new Error(`Bizum visibility contract is missing ${marker}.`);
}

const discountCodes = readFileSync(join(root, "api/src/DiscountCodes.php"), "utf8");
const discountMigration = readFileSync(join(root, "database/migrations/013_discount_codes.sql"), "utf8");
for (const marker of ["discount_codes", "discount_code_events", "discount_code_ticket_types", "discount_code_usages", "discount_amount_cents", "per_ticket", "maximum_uses_per_customer"]) {
  if (!discountMigration.includes(marker)) throw new Error(`Discount migration is missing ${marker}.`);
}
for (const marker of ["function quote", "function reserve", "function consumeForOrder", "function releaseForOrder", "function deleteUnused", "FOR UPDATE", "maximum_total_uses", "maximum_uses_per_customer"]) {
  if (!discountCodes.includes(marker)) throw new Error(`Discount rule contract is missing ${marker}.`);
}
for (const marker of ["/discounts/validate", "/admin/discount-codes", "adminSaveDiscountCode", "adminDeleteUnusedDiscountCode", "adminDiscountCodeHistory", "validateTestDiscount"]) {
  if (!(api + ticketing).includes(marker)) throw new Error(`Discount API contract is missing ${marker}.`);
}
if (!envExample.includes("REDSYS_BIZUM_ENABLED=false")) throw new Error("Bizum feature flag is missing from .env.example.");

const checkout = readFileSync(join(root, "entradas/checkout/index.html"), "utf8");
for (const marker of ["data-checkout-eyebrow", "data-checkout-title", "data-checkout-safety-copy", "data-checkout-summary", "data-checkout-submit", "checkout.css", "data-checkout-attendees", "Necesidades alimentarias", "data-checkout-access-conditions", "Condiciones de acceso", "Total White"]) {
  if (!checkout.includes(marker)) throw new Error(`Missing preview-aware checkout marker: ${marker}`);
}
for (const marker of ["data-payment-method-section", "data-payment-methods", "payment_method", "Elige cómo pagar"]) {
  if (!checkout.includes(marker) && !publicJs.includes(marker)) throw new Error(`Checkout payment method UI is missing ${marker}.`);
}
for (const marker of ["Próximamente", "data-unavailable", "checkout-payment-unavailable"]) {
  if (!publicJs.includes(marker)) throw new Error(`Bizum pending-activation UI is missing ${marker}.`);
}
for (const marker of ["Código de descuento", "data-apply-discount", "data-clear-discount", "checkout-discount"]) {
  if (!checkout.includes(marker)) throw new Error(`Checkout discount UI is missing ${marker}.`);
}
for (const marker of ["clearDiscount", "discounts/validate", "discount_code", "checkout-summary-discount"]) {
  if (!publicJs.includes(marker)) throw new Error(`Checkout discount behavior is missing ${marker}.`);
}
for (const marker of ["FOOD_ALLERGENS", "attendeesPayload", "normaliseAttendees", "ticket_attendees", "ticket_attendee_allergens", "adminOrderAttendees", "/admin/orders/([0-9]+)/attendees"]) {
  if (!(publicJs + ticketing + api).includes(marker)) throw new Error(`Missing attendee allergy contract: ${marker}`);
}
for (const marker of ["data-checkout-step=\"1\"", "data-checkout-step=\"7\"", "data-checkout-billing-choice", "data-checkout-allergy-choice", "data-checkout-discount-choice", "data-checkout-next"]) {
  if (!checkout.includes(marker)) throw new Error(`Checkout wizard is missing ${marker}.`);
}
for (const marker of ["wizardStepValidation", "applyNoAllergies", "billing_requested", "discountFingerprint", "submitPaymentForm"]) {
  if (!publicJs.includes(marker)) throw new Error(`Checkout flow behavior is missing ${marker}.`);
}
const dietaryMigration = readFileSync(join(root, "database/migrations/025_ticket_attendee_dietary_preferences.sql"), "utf8");
for (const marker of ["dietary_preference", "dietary_notes"]) {
  if (!dietaryMigration.includes(marker) || !ticketing.includes(marker)) throw new Error(`Dietary attendee persistence is missing ${marker}.`);
}
for (const marker of ["ticketAttendeeDietarySchemaAvailable", "No se puede registrar una dieta especial"]) {
  if (!ticketing.includes(marker)) throw new Error(`Checkout deployment compatibility is missing ${marker}.`);
}
const attendeeMigration = readFileSync(join(root, "database/migrations/017_ticket_attendee_allergies.sql"), "utf8");
for (const marker of ["ticket_attendees", "ticket_attendee_allergens", "allergy_notes", "severe_allergy", "fk_ticket_attendees_ticket"]) {
  if (!attendeeMigration.includes(marker)) throw new Error(`Attendee allergy migration is missing ${marker}.`);
}
const accessConditionsMigration = readFileSync(join(root, "database/migrations/018_order_access_conditions.sql"), "utf8");
for (const marker of ["age_requirement_accepted", "age_requirement_accepted_at", "dress_code_accepted", "dress_code_accepted_at", "dress_code_version"]) {
  if (!accessConditionsMigration.includes(marker) || !ticketing.includes(marker)) throw new Error(`Access conditions persistence is missing ${marker}.`);
}
const discountsAdmin = readFileSync(join(root, "admin/descuentos/index.html"), "utf8");
for (const marker of ["data-admin-discounts-page", "data-admin-discount-form", "maximum_uses_per_customer", "per_ticket"]) {
  if (!discountsAdmin.includes(marker)) throw new Error(`Discount admin UI is missing ${marker}.`);
}
for (const marker of ["initDiscountCodes", "admin/discount-codes", "data-discount-action=\"archive\"", "data-discount-action=\"delete\""]) {
  if (!adminBackoffice.includes(marker)) throw new Error(`Discount admin behavior is missing ${marker}.`);
}
if (!rootHtaccess.includes("^admin/descuentos")) throw new Error("Discount admin route is missing.");

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

const analytics = readFileSync(join(root, "api/src/Analytics.php"), "utf8");
const analyticsMigration = readFileSync(join(root, "database/migrations/016_first_party_analytics.sql"), "utf8");
const analyticsClient = readFileSync(join(root, "assets/js/analytics.js"), "utf8");
const analyticsAdmin = readFileSync(join(root, "admin/analitica/index.html"), "utf8");
const analyticsCron = readFileSync(join(root, "api/cron/analytics-report.php"), "utf8");
for (const marker of ["analytics_visitors", "analytics_sessions", "analytics_events", "analytics_settings", "analytics_reports", "idx_analytics_events_period_type"]) {
  if (!analyticsMigration.includes(marker)) throw new Error(`Analytics migration is missing ${marker}.`);
}
for (const marker of ["function track", "function dashboard", "function sendDueReports", "ticket_orders", "status = \"paid\"", "is_test = 0", "isBot"]) {
  if (!analytics.includes(marker)) throw new Error(`Analytics backend is missing ${marker}.`);
}
for (const marker of ["sendBeacon", "IntersectionObserver", "scroll_depth", "checkout_start", "payment_start", "perigallo-analytics-consent"]) {
  if (!analyticsClient.includes(marker)) throw new Error(`Analytics client is missing ${marker}.`);
}
for (const marker of ["/analytics/events", "/admin/analytics", "requireOwner", "analytics->dashboard"]) {
  if (!api.includes(marker)) throw new Error(`Analytics API route is missing ${marker}.`);
}
for (const marker of ["data-admin-analytics-page", "admin-backoffice.js"]) {
  if (!analyticsAdmin.includes(marker)) throw new Error(`Analytics admin UI is missing ${marker}.`);
}
for (const marker of ["sendDueReports", "flock", "analytics-report.lock"]) {
  if (!analyticsCron.includes(marker)) throw new Error(`Analytics cron is missing ${marker}.`);
}
for (const marker of ["data-analytics-section=\"entradas\"", "data-analytics-section=\"experiencia\"", "data-analytics-click=\"ver-experiencia\""]) {
  if (!publicJs.includes(marker)) throw new Error(`Analytics public event instrumentation is missing ${marker}.`);
}
if (!envExample.includes("ANALYTICS_REPORT_EMAIL=")) throw new Error("Analytics report environment variable is missing.");

const publicAccessCss = readFileSync(join(root, "assets/css/event-information-accordions.css"), "utf8");
for (const marker of [".ticket-access-heading", ".ticket-access-secondary", ".ticket-access-decision", ".ticket-access-status-dot"]) {
  if (!publicAccessCss.includes(marker)) throw new Error(`Missing editorial ticket access style: ${marker}`);
}

console.log("Static ticketing checks passed");

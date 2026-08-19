import { readFileSync } from "node:fs";
import { join } from "node:path";
import { spawnSync } from "node:child_process";

const root = new URL("..", import.meta.url).pathname;
const required = {
  migration: ["whatsapp_phone_e164", "ticket_delivery_documents", "ticket_delivery_jobs", "uq_ticket_delivery_jobs_idempotency", "provider_message_id"],
  queue: ["enqueuePaidOrder", "processDue", "idempotency_key", "TicketDocumentService", "sendTicketDocumentEmail"],
  whatsapp: ["META_WABA_ID", "META_PHONE_NUMBER_ID", "message_templates", "verifyWebhookSignature", "provider_message_id", "downloadToken", "sub_type", "template_url_button"],
  document: ["render-ticket-pdf.mjs", "qr_token_ciphertext", "ticket_delivery_documents", "%PDF-"],
  checkout: ["whatsapp_country_code", "whatsapp_consent", "checkoutPhoneValid"],
  routes: ["/whatsapp/webhook", "delivery/(email|whatsapp)/retry", "template-status", "ticketPdfByToken", "/orders/tickets/([A-Za-z0-9_-]+)"],
};
const sources = {
  migration: readFileSync(join(root, "database/migrations/027_whatsapp_document_delivery.sql"), "utf8"),
  queue: readFileSync(join(root, "api/src/TicketDeliveryQueue.php"), "utf8"),
  whatsapp: readFileSync(join(root, "api/src/WhatsAppDeliveryService.php"), "utf8"),
  document: readFileSync(join(root, "api/src/TicketDocumentService.php"), "utf8"),
  checkout: readFileSync(join(root, "assets/js/ticketing.js"), "utf8") + readFileSync(join(root, "entradas/checkout/index.html"), "utf8"),
  routes: readFileSync(join(root, "api/index.php"), "utf8"),
};
for (const [name, markers] of Object.entries(required)) {
  for (const marker of markers) {
    if (!sources[name].includes(marker)) throw new Error(`Missing ${name} contract: ${marker}`);
  }
}

const renderer = join(root, "api/scripts/render-ticket-pdf.mjs");
const sample = JSON.stringify({
  reference: "PG-TEST-001",
  is_test: true,
  tickets: [{ event_title: "Evento de prueba", event_subtitle: "Entrega segura", starts_at: "29/08/2026 · 19:00", location: "Finca", locality: "Alicante", ticket_type_name: "Entrada", public_code: "PG-TEST-1", qr_value: "https://perigallo.example/check-in/?ticket=abcdefghijklmnopqrstuvwxyz0123456789" }],
});
const rendered = spawnSync("node", [renderer], { input: sample, encoding: null });
if (rendered.status !== 0 || !Buffer.from(rendered.stdout).subarray(0, 5).equals(Buffer.from("%PDF-"))) {
  throw new Error("Ticket PDF renderer did not emit a valid PDF header.");
}
console.log("WhatsApp ticket delivery static checks passed");

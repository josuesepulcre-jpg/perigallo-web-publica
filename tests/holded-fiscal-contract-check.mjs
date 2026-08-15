import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { join } from "node:path";

const root = process.cwd();
const read = (file) => readFileSync(join(root, file), "utf8");
const migration = read("database/migrations/020_holded_fiscal_sync.sql");
const client = read("api/src/HoldedClient.php");
const sync = read("api/src/HoldedSyncService.php");
const policy = read("api/src/HoldedFiscalPolicy.php");
const ticketing = read("api/src/Ticketing.php");
const checkout = read("entradas/checkout/index.html");
const checkoutJs = read("assets/js/ticketing.js");
const api = read("api/index.php");
const cron = read("api/cron/holded-sync.php");
const requeue = read("api/scripts/holded-requeue.php");
const env = read(".env.example");
const billingPage = read("admin/facturacion/index.html");
const adminJs = read("assets/js/admin-backoffice.js");

const cases = [
  ["default disabled", () => assert.match(env, /HOLDED_ENABLED=false/)],
  ["Holded tax rate is configured", () => assert.match(env, /HOLDED_DEFAULT_TAX_RATE=/)],
  ["default dry run", () => assert.match(env, /HOLDED_DRY_RUN=true/)],
  ["no fiscal secret in browser", () => assert.doesNotMatch(checkout + checkoutJs, /HOLDED_API_KEY|Authorization: Bearer/)],
  ["fiscal migration persists request", () => assert.match(migration, /billing_requested/)],
  ["fiscal migration tracks document", () => assert.match(migration, /holded_document_id/)],
  ["fiscal migration tracks state", () => assert.match(migration, /requires_review/)],
  ["contacts are locally deduplicated", () => assert.match(migration, /holded_contacts/)],
  ["sync attempts are logged", () => assert.match(migration, /holded_sync_logs/)],
  ["refund model is only prepared", () => assert.match(migration, /holded_refund_requests/)],
  ["checkout exposes opt-in", () => assert.match(checkout, /billing_requested/)],
  ["checkout validates fiscal fields", () => assert.match(checkoutJs, /Completa los datos fiscales/)],
  ["server validates fiscal fields", () => assert.match(ticketing, /normaliseBilling/)],
  ["invoice threshold is centralized", () => assert.match(policy, /HOLDED_SIMPLIFIED_MAX_CENTS/)],
  ["callback queues after signed payment validation", () => assert.match(ticketing, /queuePaidProductionOrder\(\(int\) \$order\['id'\]\)/)],
  ["queue derives production from the persisted order", () => assert.match(sync, /WHERE id = \? AND is_test = 0 AND environment = "production" AND status = "paid"/)],
  ["queue never depends on Redsys real/test literals", () => assert.doesNotMatch(sync, /paymentEnvironment/)],
  ["holded never blocks callback", () => assert.doesNotMatch(ticketing.match(/processRedsysNotification[\s\S]*?public function adminSummary/)?.[0] || "", /createInvoice\(/)],
  ["cron is separately locked", () => assert.match(cron, /LOCK_EX \| LOCK_NB/)],
  ["cron emits safe diagnostics", () => assert.match(cron, /error_type.*safe_code.*http_status/s)],
  ["admin retry is server-protected", () => assert.match(api, /\/holded\/retry/)],
  ["ambiguous retries require explicit external review", () => assert.match(sync, /confirmNoExternalDocument/)],
  ["recoverable orders can be previewed and requeued", () => assert.match(requeue, /--apply/)],
  ["admin billing route is available", () => assert.match(billingPage, /data-admin-billing-page/)],
  ["billing uses Holded health only for the owner", () => assert.match(adminJs, /sessionData\.is_owner/) ],
  ["billing never issues documents from the browser", () => assert.doesNotMatch(billingPage + adminJs, /createInvoice\(|HOLDED_API_KEY/) ],
  ["Holded stores the taxable base separately", () => assert.match(sync, /unit_base_cents/)],
  ["Holded prevents a mismatched tax mapping", () => assert.match(sync, /holded_tax_mapping/)],
  ["Holded validates configured tax rate", () => assert.match(policy, /HOLDED_DEFAULT_TAX_RATE inválido/)],
  ["document ID is persisted before payment", () => {
    const remember = sync.indexOf("rememberDocument($orderId");
    const payment = sync.indexOf("recordInvoicePayment");
    assert.ok(remember >= 0 && payment > remember);
  }],
  ["existing document is reused on retry", () => assert.match(sync, /\$documentId = \(string\) \(\$order\['holded_document_id'\] \?\? ''\)/)],
  ["synced orders are terminal", () => assert.match(sync, /if \(\$order\['holded_status'\] === 'synced'\)/)],
  ["health reports every required state", () => assert.match(sync, /\['not_required', 'pending', 'error', 'requires_review', 'processing', 'synced'\]/)],
  ["health is safe before fiscal migrations", () => assert.match(sync, /holded_schema_unavailable/)],
  ["client remains on current Holded v2 API", () => assert.match(client, /https:\/\/api\.holded\.com\/api\/v2/)],
];

for (const [name, check] of cases) {
  check();
  process.stdout.write(`✓ ${name}\n`);
}
process.stdout.write(`Holded fiscal contract checks passed (${cases.length}).\n`);

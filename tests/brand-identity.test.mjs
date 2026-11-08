import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { TICKET_PAGE } from '../assets/js/ticket-pdf-layout.mjs';

// Approved artwork is deliberate: rebranding requires updating this baseline.
const artwork = JSON.parse(readFileSync(new URL('./fixtures/approved-brand-artwork.json', import.meta.url)));
test('approved logos and compatibility icons cannot revert to the old monogram', () => {
  for (const [path, hash] of Object.entries(artwork)) {
    assert.ok(existsSync(path), path);
    assert.equal(createHash('sha256').update(readFileSync(path)).digest('hex'), hash, path);
  }
});
test('all customer email templates use the approved email-compatible PNG', () => {
  for (const path of ['api/src/Mailer.php', 'api/src/TicketDeliveryService.php']) {
    const source = readFileSync(path, 'utf8');
    assert.ok(source.includes('/brand/perigallo-lockup-white.png'), path);
    assert.ok(!source.includes('perigallo-logo-original.png'), path);
    assert.ok(source.includes('EL GUSTO DE CELEBRAR'), path);
  }
});
test('email and browser tickets share the approved A5 layout and produce a multipage PDF', () => {
  assert.deepEqual(TICKET_PAGE, [148, 210]);
  const server = readFileSync('api/scripts/render-ticket-pdf.mjs', 'utf8');
  const browser = readFileSync('assets/js/ticketing.js', 'utf8');
  assert.match(server, /ticket-pdf-layout\.mjs/);
  assert.match(browser, /ticket-pdf-layout\.mjs/);
  const input = { reference:'BRAND-REGRESSION', is_test:true, tickets:[1,2].map(n => ({
    event_title:'La Perigalla — Hora Cero', starts_at:'2026-12-31 20:00:00',
    attendee_name:`Muestra ${n}`, public_code:`SIN-VALIDEZ-${n}`,
    qr_value:`https://example.invalid/brand-test/${n}`,
  })) };
  const result = spawnSync(process.execPath, ['api/scripts/render-ticket-pdf.mjs'], { input:JSON.stringify(input) });
  assert.equal(result.status, 0, result.stderr.toString());
  const pdf = result.stdout.toString('latin1');
  assert.ok(pdf.startsWith('%PDF-'));
  assert.match(pdf, /\/Count 2\b/);
  const boxes = [...pdf.matchAll(/\/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]/g)];
  assert.equal(boxes.length, 2);
  for (const [, width, height] of boxes) {
    assert.ok(Math.abs(Number(width)*25.4/72-148)<0.01);
    assert.ok(Math.abs(Number(height)*25.4/72-210)<0.01);
  }
});

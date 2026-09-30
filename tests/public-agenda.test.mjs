import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { test } from 'node:test';
const context = vm.createContext({ Intl, Date });
vm.runInContext(readFileSync(new URL('../assets/js/public-agenda.js', import.meta.url), 'utf8'), context);
const { timestamp, upcoming } = context.PerigalloAgenda;
test('Madrid civil dates use summer and winter offsets independently of visitor timezone', () => {
 assert.equal(timestamp('2026-08-29 19:00:00'), Date.parse('2026-08-29T17:00:00Z'));
 assert.equal(timestamp('2026-12-29 19:00:00'), Date.parse('2026-12-29T18:00:00Z'));
 assert.equal(timestamp('2026-08-29T19:00:00+02:00'), Date.parse('2026-08-29T17:00:00Z'));
});
test('invalid and missing dates cannot enter the agenda', () => {
 for (const value of [null, '', '2026-02-30 19:00:00', '2026-03-29 02:30:00', '2026-09-30 25:00:00', 'tomorrow']) assert.ok(Number.isNaN(timestamp(value)));
});
test('agenda excludes past, started, cancelled and drafts; future editions sort by start', () => {
 const now = Date.parse('2026-09-30T12:00:00Z');
 const events = [
 {slug:'later', starts_at:'2026-12-01 19:00:00', status:'published'},
 {slug:'past', starts_at:'2026-08-29 19:00:00', status:'published'},
 {slug:'cancelled', starts_at:'2026-10-01 19:00:00', status:'cancelled'},
 {slug:'draft', starts_at:'2026-10-01 19:00:00', status:'draft'},
 {slug:'started', starts_at:'2026-09-30 14:00:00', status:'published'},
 {slug:'next', starts_at:'2026-10-01 19:00:00', status:'sold_out'},
 {slug:'invalid', starts_at:'bad', status:'published'}];
 assert.equal(JSON.stringify(upcoming(events, now).map(event=>event.slug)),JSON.stringify(['next','later']));
 assert.equal(upcoming(undefined, now).length,0);
});

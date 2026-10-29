import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { test } from 'node:test';
const context = vm.createContext({ Intl, Date });
for (const file of ['public-agenda', 'admin-events']) vm.runInContext(readFileSync(new URL('../assets/js/' + file + '.js', import.meta.url), 'utf8'), context);
const { isPast, select } = context.PerigalloAdminEvents;
const now = Date.parse('2026-10-29T12:00:00Z');
const old = { id: 1, title: 'La Perigalla 01', starts_at: '2026-08-29 19:00:00', status: 'sold_out', sold_tickets: 177 };
const next = { id: 2, title: 'Perigalla Comedy', starts_at: '2026-11-06 21:00:00', status: 'sold_out' };
const ids = rows => Array.from(rows, row => row.id);
test('old sold-out events appear only in history; future sold-out events remain current', () => {
  assert.deepEqual(ids(select([old, next], 'current', '', now)), [2]);
  assert.deepEqual(ids(select([old, next], 'past', '', now)), [1]);
  assert.equal(select([old], 'past', '', now)[0], old);
  assert.equal(old.sold_tickets, 177);
  assert.equal(old.status, 'sold_out');
});
test('in-progress events remain current until their actual end, including overnight', () => {
  const event = { starts_at: '2026-10-28 21:00:00', ends_at: '2026-10-29 14:00:00' };
  assert.equal(isPast(event, now), false);
  assert.equal(isPast(event, Date.parse('2026-10-29T13:00:00Z')), true);
});
test('missing, invalid or copied end dates stay current through the Madrid calendar day', () => {
  for (const ends_at of [null, 'invalid', '2026-10-29 10:00:00']) {
    const event = { starts_at: '2026-10-29 10:00:00', ends_at };
    assert.equal(isPast(event, Date.parse('2026-10-29T22:59:59Z')), false);
    assert.equal(isPast(event, Date.parse('2026-10-29T23:00:00Z')), true);
  }
});
test('undated drafts remain accessible; finished and archived events go to history', () => {
  assert.equal(isPast({ status: 'draft' }, now), false);
  assert.equal(isPast({ status: 'draft', starts_at: 'invalid' }, now), false);
  for (const status of ['finished', 'archived']) assert.equal(isPast({ ...next, status }, now), true);
  assert.equal(isPast({ ...next, status: 'cancelled' }, now), false);
});
test('search stays within its view, history sorts newest first, upcoming nearest first', () => {
  const later = { ...next, id: 3, starts_at: '2026-12-31 21:00:00' };
  const recent = { ...old, id: 4, starts_at: '2026-10-20 21:00:00' };
  const events = [later, old, recent, next];
  assert.deepEqual(ids(select(events, 'current', '', now)), [2, 3]);
  assert.deepEqual(ids(select(events, 'past', '', now)), [4, 1]);
  assert.deepEqual(ids(select(events, 'current', 'La Perigalla 01', now)), []);
  assert.deepEqual(ids(select(events, 'past', ' LA PERIGALLA 01 ', now)), [4, 1]);
  assert.deepEqual(ids(events), [3, 1, 4, 2]);
});

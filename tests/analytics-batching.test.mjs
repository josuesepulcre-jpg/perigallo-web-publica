import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { strict as assert } from 'node:assert';

const timers = new Map(), listeners = {}, intervals = [], batches = [];
let sequence = 0;
const storage = new Map([['perigallo-analytics-consent', 'granted']]);
const area = { getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value) };
const document = {
  readyState: 'complete', title: 'Fixture', referrer: '', visibilityState: 'visible',
  body: { scrollHeight: 2000 }, documentElement: { clientWidth: 1200, scrollHeight: 2000 },
  addEventListener: (name, callback) => { listeners[name] = callback; },
};
const window = {
  location: { pathname: '/', search: '' }, innerWidth: 1200, innerHeight: 800, scrollY: 0,
  crypto: { randomUUID: () => 'test-fixture-identifier' }, localStorage: area, sessionStorage: area,
  setTimeout: (callback, delay) => { timers.set(++sequence, { callback, delay }); return sequence; },
  clearTimeout: id => timers.delete(id), setInterval: callback => intervals.push(callback),
  addEventListener: (name, callback) => { listeners[name] = callback; },
  dispatchEvent: () => {}, requestAnimationFrame: callback => callback(),
};
runInNewContext(readFileSync(new URL('../assets/js/analytics.js', import.meta.url), 'utf8'), {
  window, document, navigator: { language: 'es', sendBeacon: (url, body) => { batches.push(JSON.parse(body.parts[0])); return true; } },
  Blob: class { constructor(parts) { this.parts = parts; } }, CustomEvent: class {}, URLSearchParams, URL, Set, Date, Math,
});
assert.equal(batches.length, 0);
assert.equal([...timers.values()][0].delay, 5000);
const click = { target: { closest: () => ({ dataset: { analyticsClick: 'fixture' } }) } };
listeners.click(click); listeners.click(click);
assert.equal(timers.size, 1, 'Clicks share one scheduled batch');
const pending = [...timers.entries()][0]; timers.delete(pending[0]); pending[1].callback();
assert.equal(batches.length, 1);
assert.equal(batches[0].events.length, 3, 'Page view and clicks stay in the same batch');
document.visibilityState = 'hidden'; intervals[0]();
assert.equal(timers.size, 0, 'Hidden tabs must not send session pings');
document.visibilityState = 'visible'; intervals[0]();
assert.equal(timers.size, 1);
document.visibilityState = 'hidden'; listeners.visibilitychange();
assert.equal(batches.length, 2, 'Flush pending events on background');
assert.equal(timers.size, 0, 'Cancel obsolete flush timer');
listeners.pagehide();
assert.equal(batches.length, 2, 'Do not send the same batch twice');
console.log('Analytics batching passed: grouped events, no hidden-tab pings, flush on leaving, no duplicate sends.');

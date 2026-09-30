/* API dates without an offset are civil dates in Europe/Madrid. */
(function (root) {
  'use strict';
  const zone = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Madrid', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
  function timestamp(value) {
    if (!value) return NaN;
    const text = String(value).replace(' ', 'T');
    if (/(Z|[+-]\d{2}:?\d{2})$/.test(text)) return Date.parse(text);
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(text);
    if (!match) return NaN;
    const numbers = match.slice(1).map(Number); const [year, month, day, hour, minute, second = 0] = numbers;
    const civil = Date.UTC(year, month - 1, day, hour, minute, second);
    const check = new Date(civil);
    if (check.getUTCFullYear() !== year || check.getUTCMonth() !== month - 1 || check.getUTCDate() !== day || hour > 23 || minute > 59 || second > 59) return NaN;
    let guess = civil;
    for (let i = 0; i < 3; i++) {
      const parts = Object.fromEntries(zone.formatToParts(new Date(guess)).map(part => [part.type, part.value]));
      const displayed = Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour, +parts.minute, +parts.second);
      guess += civil - displayed;
    }
    const parts = Object.fromEntries(zone.formatToParts(new Date(guess)).map(part => [part.type, part.value]));
    return +parts.hour === hour && +parts.day === day ? guess : NaN;
  }
  function upcoming(events, now = Date.now()) {
    return (Array.isArray(events) ? events : []).filter(event => !['cancelled', 'canceled', 'draft', 'archived', 'closed', 'finished'].includes(event.status) && timestamp(event.starts_at) > now).sort((a, b) => timestamp(a.starts_at) - timestamp(b.starts_at));
  }
  root.PerigalloAgenda = { timestamp, upcoming };
})(typeof window === 'undefined' ? globalThis : window);

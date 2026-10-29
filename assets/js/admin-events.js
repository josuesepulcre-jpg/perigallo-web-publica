/* Read-only event views. Dates from the API use Europe/Madrid. */
(function (root) {
  'use strict';
  const timestamp = root.PerigalloAgenda.timestamp;
  const calendar = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Madrid', year: 'numeric', month: '2-digit', day: '2-digit' });
  function day(value) {
    const parts = Object.fromEntries(calendar.formatToParts(new Date(value)).map(part => [part.type, part.value]));
    return parts.year + '-' + parts.month + '-' + parts.day;
  }
  function isPast(event, now = Date.now()) {
    if ([event.status, event.effective_status].some(status => ['finished', 'archived'].includes(status))) return true;
    const start = timestamp(event.starts_at);
    const end = timestamp(event.ends_at);
    // Legacy events may have their start copied into ends_at. Keep them
    // accessible throughout their Madrid calendar day when no real end exists.
    if (Number.isFinite(end) && (!Number.isFinite(start) || end > start)) return end <= now;
    return Number.isFinite(start) && day(start) < day(now);
  }
  function select(events, view, query = '', now = Date.now()) {
    const past = view === 'past';
    const search = String(query).trim().toLowerCase();
    return (Array.isArray(events) ? events : []).filter(event => isPast(event, now) === past &&
      (!search || [event.title, event.location, event.status, event.effective_status].join(' ').toLowerCase().includes(search)))
      .sort((a, b) => {
        const first = timestamp(a.starts_at), second = timestamp(b.starts_at);
        if (!Number.isFinite(first)) return Number.isFinite(second) ? 1 : 0;
        if (!Number.isFinite(second)) return -1;
        return past ? second - first : first - second;
      });
  }
  root.PerigalloAdminEvents = { isPast, select };
})(typeof window === 'undefined' ? globalThis : window);

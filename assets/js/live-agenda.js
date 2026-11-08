/* Presentation adapter. Availability, prices and sales remain owned by the existing API. */
(function () {
  'use strict';
  var targets = document.querySelectorAll('[data-live-agenda]');
  if (!targets.length) return;
  var date = new Intl.DateTimeFormat('es-ES', { dateStyle: 'long', timeStyle: 'short', timeZone: 'Europe/Madrid' });
  var money = new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' });
  function element(tag, text, className) {
    var node = document.createElement(tag);
    if (text) node.textContent = text;
    if (className) node.className = className;
    return node;
  }
  function card(event) {
    var article = element('article', '', 'event-card');
    var photo = element('div', '', 'event-photo');
    var image = element('img');
    var source;
    try { source = new URL(event.card_image_url || event.image_url || '/media/archivo/escena.webp', location.origin); } catch (_) { source = new URL('/media/archivo/escena.webp', location.origin); }
    if (source.protocol !== 'https:' && source.origin !== location.origin) source = new URL('/media/archivo/escena.webp', location.origin);
    image.src = source.href; image.alt = event.title || 'Experiencia Perigallo'; image.loading = 'lazy'; image.decoding = 'async';
    photo.append(image);
    var copy = element('div', '', 'event-copy');
    copy.append(element('p', 'Agenda / Perigallo', 'eyebrow'), element('h3', event.title));
    if (event.subtitle) copy.append(element('p', event.subtitle));
    var details = element('dl', '', 'event-data');
    [['Cuándo', date.format(new Date(window.PerigalloAgenda.timestamp(event.starts_at)))], ['Dónde', event.location || 'Perigallo']].forEach(function (pair) {
      var item = element('div'); item.append(element('dt', pair[0]), element('dd', pair[1])); details.append(item);
    });
    if (event.show_price_from !== false && Number.isFinite(event.price_from_cents)) {
      var price = element('div'); price.append(element('dt', 'Entrada'), element('dd', 'Desde ' + money.format(event.price_from_cents / 100))); details.append(price);
    }
    copy.append(details);
    if (event.status === 'sold_out') copy.append(element('p', 'Aforo completo', 'eyebrow'));
    var link = element('a', 'Descubrir la experiencia ↗', 'button light');
    link.href = '/experiencias/' + encodeURIComponent(event.slug) + '/';
    copy.append(link); article.append(photo, copy); return article;
  }
  async function load(target) {
    target.setAttribute('aria-busy', 'true');
    try {
      var response = await fetch('/api/events', { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(15000) });
      if (!response.ok) throw new Error('agenda');
      var data = await response.json();
      if (!data.ok || !Array.isArray(data.events)) throw new Error('agenda');
      var events = window.PerigalloAgenda.upcoming(data.events);
      target.replaceChildren();
      if (events.length) events.forEach(function (event) { target.append(card(event)); });
      else {
        var empty = element('div', '', 'agenda-message');
        empty.append(element('p', 'Próximamente', 'eyebrow'), element('h3', 'La próxima historia está en camino.'), element('p', 'Estamos preparando nuevas experiencias. Vuelve pronto para descubrir la siguiente edición.'));
        target.append(empty);
      }
    } catch (_) {
      target.replaceChildren(element('p', 'No se ha podido cargar la agenda. Inténtalo de nuevo.'));
      var retry = element('button', 'Volver a cargar', 'button light'); retry.type = 'button';
      retry.addEventListener('click', function () { load(target); }); target.append(retry);
    } finally { target.removeAttribute('aria-busy'); }
  }
  targets.forEach(load);
}());

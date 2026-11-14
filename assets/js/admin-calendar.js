(function () {
  "use strict";
  var zone = "Europe/Madrid";
  function dateKey(value) {
    if (!value) return "";
    // Database wall times are Madrid times. Offset-bearing ISO dates need conversion.
    var raw = String(value);
    if (!/(Z|[+-]\d\d:\d\d)$/.test(raw)) return /^\d{4}-\d{2}-\d{2}/.test(raw) ? raw.slice(0, 10) : "";
    var date = new Date(raw);
    if (Number.isNaN(date.getTime())) return "";
    return new Intl.DateTimeFormat("sv-SE", { timeZone: zone, year: "numeric", month: "2-digit", day: "2-digit" }).format(date);
  }
  function today() { return dateKey(new Date().toISOString()); }
  function monthDays(month) {
    var parts = month.split("-").map(Number), first = new Date(Date.UTC(parts[0], parts[1] - 1, 1));
    var offset = (first.getUTCDay() + 6) % 7;
    var count = new Date(Date.UTC(parts[0], parts[1], 0)).getUTCDate();
    return Array.from({ length: Math.ceil((offset + count) / 7) * 7 }, function (_, i) {
      return new Date(Date.UTC(parts[0], parts[1] - 1, 1 - offset + i)).toISOString().slice(0, 10);
    });
  }
  function shiftMonth(month, step) {
    var parts = month.split("-").map(Number);
    return new Date(Date.UTC(parts[0], parts[1] - 1 + step, 1)).toISOString().slice(0, 7);
  }
  function numbers(event) {
    var sold = Math.max(0, Number(event.sold) || 0), reserved = Math.max(0, Number(event.reserved) || 0), capacity = Math.max(0, Number(event.ticket_capacity == null ? event.capacity : event.ticket_capacity) || 0);
    return { sold: sold, reserved: reserved, capacity: capacity, available: Math.max(0, Number(event.available == null ? capacity - sold - reserved : event.available) || 0), percent: capacity ? Math.min(100, Math.round((sold + reserved) * 100 / capacity)) : 0 };
  }
  function mount(root, options) {
    var esc = options.escapeHtml, label = options.statusLabel;
    var events = [], month = today().slice(0, 7), selected = today(), query = "", loading = false, suiteSync = { status: "unavailable", generated_at: null };
    function status(event) { return event.effective_status || event.status || "draft"; }
    function sorted(items) { return items.slice().sort(function (a, b) { return String(a.starts_at || "").localeCompare(String(b.starts_at || "")) || String(a.id).localeCompare(String(b.id)); }); }
    function visibleEvents() { return sorted(events.filter(function (event) { return !Number(event.is_test) && [event.title, event.location].join(" ").toLocaleLowerCase("es").includes(query.toLocaleLowerCase("es")); })); }
    function active(event) { return !["cancelled", "archived"].includes(status(event)) && !Number(event.is_test); }
    function longDate(key) { return new Intl.DateTimeFormat("es-ES", { day: "numeric", month: "long", year: "numeric", timeZone: "UTC" }).format(new Date(key + "T12:00:00Z")); }
    function time(event) {
      if (event.source === "suite" && !event.time_defined) return "Hora por definir";
      var raw = String(event.starts_at || "");
      if (/(Z|[+-]\d\d:\d\d)$/.test(raw)) return new Intl.DateTimeFormat("es-ES", { timeZone: zone, hour: "2-digit", minute: "2-digit" }).format(new Date(raw));
      return raw.slice(11, 16) || "Hora por definir";
    }
    function progress(n) { return '<span class="calendar-progress" aria-label="' + n.percent + '% del aforo ocupado"><i style="width:' + (n.capacity ? Math.min(100, n.sold * 100 / n.capacity) : 0) + '%"></i><b style="width:' + (n.capacity ? Math.min(100 - Math.min(100, n.sold * 100 / n.capacity), n.reserved * 100 / n.capacity) : 0) + '%"></b></span>'; }
    function suiteLabel(event) { return event.status === "suite_confirmed" ? "Confirmado" : "Pendiente de confirmar"; }
    function suitePeople(event) { return event.people == null ? "Personas por definir" : event.people + (event.people === 1 ? " persona" : " personas") + (event.status === "suite_confirmed" ? " · confirmadas" : " · previstas"); }
    function suiteHref(event) { return "https://suite.perigallo.com" + event.suite_path; }
    function suiteCard(event) {
      return '<a class="calendar-event calendar-suite-event is-' + esc(event.status) + '" href="' + esc(suiteHref(event)) + '" aria-label="' + esc(event.title + '. ' + suiteLabel(event) + '. ' + suitePeople(event) + '. Abrir en Suite.') + '"><span class="calendar-event-time">Suite · ' + esc(time(event)) + '</span><strong>' + esc(event.title) + '</strong>' + (event.client_name ? '<span class="calendar-event-count">' + esc(event.client_name) + '</span>' : '') + '<span class="calendar-suite-status">' + suiteLabel(event) + '</span><span class="calendar-event-foot">' + esc(suitePeople(event)) + '</span></a>';
    }
    function suiteDetail(event) {
      return '<article class="calendar-suite-detail"><div><span class="status-pill">Suite · ' + suiteLabel(event) + '</span><h3>' + esc(event.title) + '</h3><p>' + esc([event.client_name, time(event), event.location].filter(Boolean).join(' · ')) + '</p></div><div class="calendar-detail-numbers"><strong>' + (event.people == null ? '—' : Number(event.people)) + '<small>' + (event.people == null ? 'Personas por definir' : event.status === 'suite_confirmed' ? 'personas confirmadas' : 'personas previstas') + '</small></strong></div><div class="calendar-detail-actions"><a class="ticket-btn" href="' + esc(suiteHref(event)) + '">Abrir ficha en Suite</a></div></article>';
    }
    function suiteSummary(monthly) {
      var suiteEvents = monthly.filter(function (event) { return event.source === "suite"; });
      var confirmed = suiteEvents.filter(function (event) { return event.status === "suite_confirmed"; }).length;
      var stamp = suiteSync.generated_at ? new Intl.DateTimeFormat('es-ES', { timeZone: zone, day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(suiteSync.generated_at)) : '';
      return '<div class="calendar-suite-summary"><div><strong>Suite este mes</strong><span>' + confirmed + ' confirmados · ' + (suiteEvents.length - confirmed) + ' pendientes de confirmar</span></div><small>' + (suiteSync.status === 'current' ? 'Sincronizado · ' + esc(stamp) : suiteSync.status === 'stale' ? 'Suite pendiente de actualizar · últimos datos: ' + esc(stamp) : 'No se ha podido actualizar Suite' + (stamp ? ' · últimos datos: ' + esc(stamp) : '')) + '</small></div>';
    }
    function card(event) {
      if (event.source === "suite") return suiteCard(event);
      var n = numbers(event), state = status(event);
      return '<a class="calendar-event is-' + esc(state) + '" href="/admin/ventas/?event=' + Number(event.id) + '&amp;vista=reservas#listado-reservas" aria-label="' + esc(event.title + ', ' + n.sold + ' vendidas, ' + n.reserved + ' reservadas, ' + n.available + ' disponibles. Ver reservas y ventas.') + '"><span class="calendar-event-time">' + esc(time(event)) + (Number(event.is_test) ? ' · Prueba' : '') + '</span><strong>' + esc(event.title) + '</strong><span class="calendar-event-count">' + n.sold + ' vendidas · ' + n.reserved + ' reservas</span>' + progress(n) + '<span class="calendar-event-foot">' + n.available + ' libres <span>' + n.percent + '%</span></span>' + (!["published", "scheduled"].includes(state) ? '<small>' + esc(label(state)) + '</small>' : '') + '</a>';
    }
    function render() {
      var all = visibleEvents(), monthly = all.filter(function (event) { return dateKey(event.starts_at).slice(0, 7) === month; });
      var totals = monthly.filter(function (event) { return event.source !== "suite" && active(event); }).reduce(function (total, event) { var n = numbers(event); ["sold", "reserved", "available"].forEach(function (key) { total[key] += n[key]; }); return total; }, { sold: 0, reserved: 0, available: 0 });
      var grouped = {};
      all.forEach(function (event) { var key = dateKey(event.starts_at); if (key) (grouped[key] || (grouped[key] = [])).push(event); });
      var monthTitle = new Intl.DateTimeFormat("es-ES", { month: "long", year: "numeric", timeZone: "UTC" }).format(new Date(month + "-01T12:00:00Z"));
      var next = all.find(function (event) { return dateKey(event.starts_at) >= today() && active(event); });
      root.innerHTML = '<section class="admin-page-heading"><div><span class="ticket-eyebrow">Agenda de experiencias</span><h1>El mes, <em>de un vistazo.</em></h1><p>Todos tus eventos y cómo van sus reservas, día a día.</p></div><a class="ticket-btn" href="/admin/eventos/?new=1">Crear evento</a></section>' +
        '<section class="calendar-stats" aria-label="Resumen del mes"><article><span>Eventos del mes</span><strong>' + monthly.length + '</strong></article><article><span>Entradas vendidas</span><strong>' + totals.sold + '</strong></article><article><span>Reservas</span><strong>' + totals.reserved + '</strong></article><article><span>Plazas disponibles</span><strong>' + totals.available + '</strong></article></section>' +
        suiteSummary(monthly) + '<section class="calendar-surface" aria-label="Calendario mensual"><div class="calendar-toolbar"><div class="calendar-month-nav"><button type="button" data-calendar-shift="-1" aria-label="Mes anterior">←</button><h2 aria-live="polite">' + esc(monthTitle) + '</h2><button type="button" data-calendar-shift="1" aria-label="Mes siguiente">→</button></div><div class="calendar-tools"><button type="button" data-calendar-today>Hoy</button><label class="calendar-month-picker"><span class="calendar-sr-only">Ir al mes</span><input type="month" data-calendar-month value="' + month + '" min="1900-01" max="9999-12"></label><button type="button" data-calendar-refresh ' + (loading ? 'disabled' : '') + '>' + (loading ? 'Actualizando…' : 'Actualizar') + '</button></div></div>' +
        '<div class="calendar-subtoolbar"><label><span class="calendar-sr-only">Buscar eventos</span><input type="search" data-calendar-search placeholder="Buscar evento o lugar…" value="' + esc(query) + '"></label><div class="calendar-legend"><span><i></i>Vendidas</span><span><i></i>Reservadas</span><span>Horario de Madrid</span></div></div>' +
        (!monthly.length ? '<div class="calendar-empty-month"><strong>' + (query ? 'No hay coincidencias este mes.' : 'Un mes por llenar de experiencias.') + '</strong><span>' + (query ? 'Prueba con otro nombre o cambia de mes.' : 'No hay eventos programados para este mes.') + '</span>' + (next ? '<button type="button" data-calendar-next-event>Ir al próximo evento →</button>' : '') + '</div>' : '') +
        '<div class="calendar-weekdays" aria-hidden="true">' + ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'].map(function (day) { return '<span>' + day + '</span>'; }).join('') + '</div><div class="calendar-grid">' + monthDays(month).map(function (key) {
          var items = grouped[key] || [], outside = key.slice(0, 7) !== month;
          return '<section class="calendar-day' + (outside ? ' is-outside' : '') + (key === today() ? ' is-today' : '') + (key === selected ? ' is-selected' : '') + (items.length ? ' has-events' : '') + '"><button type="button" class="calendar-day-number" data-calendar-day="' + key + '" aria-pressed="' + (key === selected) + '"' + (key === today() ? ' aria-current="date"' : '') + ' aria-label="' + esc(longDate(key)) + ', ' + items.length + (items.length === 1 ? ' evento' : ' eventos') + '"><time datetime="' + key + '">' + Number(key.slice(8)) + '</time><span class="calendar-mobile-date">' + esc(longDate(key)) + '</span></button>' + (items.length ? '<span class="calendar-mobile-count">' + items.length + (items.length === 1 ? ' evento' : ' eventos') + '</span>' : '') + items.map(card).join('') + '</section>';
        }).join('') + '</div><div class="calendar-note">Los contadores de entradas corresponden a experiencias con venta. Suite muestra sus propias personas y estado. Ocupación = vendidas + reservas activas. Se excluyen pruebas, cancelados y archivados.</div></section>' +
        '<section class="calendar-day-detail" aria-live="polite"><div><span class="ticket-eyebrow">Detalle del día</span><h2>' + esc(longDate(selected)) + '</h2></div>' + ((grouped[selected] || []).length ? '<div class="calendar-detail-events">' + grouped[selected].map(function (event) { if (event.source === 'suite') return suiteDetail(event); var n = numbers(event); return '<article><div><span class="status-pill">' + esc(label(status(event))) + '</span><h3>' + esc(event.title) + '</h3><p>' + esc(time(event) + ' · ' + (event.location || 'Ubicación por definir')) + '</p></div><div class="calendar-detail-numbers"><strong>' + n.sold + ' <small>vendidas</small></strong><strong>' + n.reserved + ' <small>reservas</small></strong><strong>' + n.available + ' <small>disponibles</small></strong><span>' + n.percent + '% ocupado · ' + n.capacity + ' plazas habilitadas</span></div><div class="calendar-detail-actions"><a class="ticket-btn" href="/admin/ventas/?event=' + Number(event.id) + '&amp;vista=reservas#listado-reservas">Ver reservas</a><a class="text-action" href="/admin/eventos/' + Number(event.id) + '/editar/">Ver evento</a></div></article>'; }).join('') + '</div>' : '<p class="ticket-copy">No hay eventos para este día' + (query ? ' con esta búsqueda' : '') + '.</p>') + '</section>' +
        (all.some(function (event) { return !dateKey(event.starts_at); }) ? '<p class="calendar-note">Hay eventos sin fecha. Puedes revisarlos en <a href="/admin/eventos/">Eventos</a>.</p>' : '');
      root.querySelectorAll('[data-calendar-shift]').forEach(function (button) { button.addEventListener('click', function () { month = shiftMonth(month, Number(button.dataset.calendarShift)); selected = month + '-01'; render(); root.querySelector('[data-calendar-shift="' + button.dataset.calendarShift + '"]').focus(); }); });
      root.querySelector('[data-calendar-today]').addEventListener('click', function () { selected = today(); month = selected.slice(0, 7); render(); });
      root.querySelector('[data-calendar-month]').addEventListener('change', function (event) { if (/^[1-9]\d{3}-\d{2}$/.test(event.target.value)) { month = event.target.value; selected = month + '-01'; render(); } });
      root.querySelector('[data-calendar-refresh]').addEventListener('click', load);
      var search = root.querySelector('[data-calendar-search]');
      search.addEventListener('input', function () { query = search.value; render(); var input = root.querySelector('[data-calendar-search]'); input.focus(); });
      root.querySelectorAll('[data-calendar-day]').forEach(function (button) { button.addEventListener('click', function () { selected = button.dataset.calendarDay; month = selected.slice(0, 7); render(); root.querySelector('[data-calendar-day="' + selected + '"]').focus(); if (window.matchMedia('(max-width:700px)').matches) root.querySelector('.calendar-day-detail').scrollIntoView({ behavior: 'smooth', block: 'start' }); }); });
      var nextButton = root.querySelector('[data-calendar-next-event]');
      if (nextButton) nextButton.addEventListener('click', function () { selected = dateKey(next.starts_at); month = selected.slice(0, 7); render(); });
    }
    function load(background) {
      if (loading) return;
      loading = true;
      if (events.length && !background) render();
      else if (!events.length) root.innerHTML = '<p class="ticket-copy" role="status">Cargando el calendario de eventos…</p>';
      Promise.all([options.request('/api/admin/events'), options.request('/api/admin/calendar/suite-events').catch(function () { return null; })]).then(function (result) {
        var suite = result[1];
        var previous = events.filter(function (event) { return event.source === 'suite'; });
        if (suite && suite.sync && suite.sync.status !== 'unavailable') {
          suiteSync = suite.sync;
          events = (result[0].events || []).concat(suite.events || []);
        } else {
          suiteSync.status = suiteSync.generated_at ? 'stale' : 'unavailable';
          events = (result[0].events || []).concat(previous);
        }
        loading = false; render();
        var error = document.querySelector('[data-admin-page-status]'); if (error) error.hidden = true;
      }).catch(function (error) {
        loading = false;
        if (events.length) render();
        else { root.innerHTML = '<div class="admin-empty"><strong>No se ha podido cargar el calendario.</strong><button class="ticket-btn" type="button" data-calendar-retry>Reintentar</button></div>'; root.querySelector('[data-calendar-retry]').addEventListener('click', load); }
        options.onError(error.message);
      });
    }
    load();
    window.setInterval(function () {
      var focused = document.activeElement;
      if (!document.hidden && !(root.contains(focused) && /^(INPUT|SELECT|TEXTAREA)$/.test(focused.tagName))) load(true);
    }, 65000);
  }
  window.PerigalloCalendar = { mount: mount, dateKey: dateKey, monthDays: monthDays, shiftMonth: shiftMonth, numbers: numbers };
})();

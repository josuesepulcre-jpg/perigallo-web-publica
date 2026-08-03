(function () {
  var api = "/api";
  var money = new Intl.NumberFormat("es-ES", { style: "currency", currency: "EUR" });

  function qs(name) {
    return new URLSearchParams(window.location.search).get(name);
  }

  function fmtDate(value) {
    if (!value) return "";
    return new Intl.DateTimeFormat("es-ES", { dateStyle: "full", timeStyle: "short" }).format(new Date(value.replace(" ", "T")));
  }

  function cents(value) {
    return money.format((Number(value || 0) / 100));
  }

  function request(url, options) {
    return fetch(url, options).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok || !data.ok) throw new Error(data.error || "No se pudo completar la solicitud.");
        return data;
      });
    });
  }

  function eventCard(event) {
    var href = "/eventos/" + encodeURIComponent(event.slug) + "/";
    return [
      '<article class="event-card">',
      '<a class="event-card-media" aria-label="' + escapeHtml(event.title) + '" href="' + href + '" style="background-image:url(' + escapeAttr(event.card_image_url || event.image_url || "/assets/images/finca-la-llaguna-principal.jpg") + ')"></a>',
      '<div class="event-card-body">',
      '<span class="ticket-eyebrow">' + escapeHtml(event.location || "Perigallo") + '</span>',
      '<h3>' + escapeHtml(event.title) + '</h3>',
      '<div class="event-meta"><span>' + escapeHtml(fmtDate(event.starts_at)) + '</span><span>' + escapeHtml(event.subtitle || "") + '</span></div>',
      '<span class="event-price">' + (event.show_price_from !== false && event.price_from_cents != null ? "Desde " + cents(event.price_from_cents) : "Precio por anunciar") + '</span>',
      '<a class="ticket-btn primary" href="' + href + '">Comprar entradas</a>',
      '</div>',
      '</article>'
    ].join("");
  }

  function initEventsList() {
    var target = document.querySelector("[data-events-list]");
    if (!target) return;
    request(api + "/events").then(function (data) {
      if (!data.events.length) {
        target.innerHTML = '<div class="ticket-panel"><h2>Próximas fechas por anunciar</h2><p class="ticket-copy">Estamos preparando nuevas experiencias. Vuelve pronto o escríbenos por WhatsApp.</p></div>';
        return;
      }
      target.innerHTML = data.events.map(eventCard).join("");
    }).catch(function (error) {
      target.innerHTML = '<div class="ticket-panel"><p class="ticket-status">' + escapeHtml(error.message) + '</p></div>';
    });
  }

  function initEventDetail() {
    var root = document.querySelector("[data-event-detail]");
    if (!root) return;
    var preview = root.hasAttribute("data-preview");
    var slug = root.dataset.slug || qs("slug");
    var previewId = qs("id");
    if (!preview && !slug) {
      root.innerHTML = '<p class="ticket-status">Evento no indicado.</p>';
      return;
    }
    if (preview && !previewId) {
      root.innerHTML = '<p class="ticket-status">Falta el evento de la vista previa.</p>';
      return;
    }
    var endpoint = preview ? api + "/admin/events/" + encodeURIComponent(previewId) + "/preview" : api + "/events/" + encodeURIComponent(slug);
    request(endpoint).then(function (data) {
      var event = data.event;
      if (!preview) document.title = (event.seo_title || event.title) + " | Entradas Perigallo";
      root.innerHTML = renderEventDetail(event, preview);
    }).catch(function (error) {
      root.innerHTML = '<p class="ticket-status">' + escapeHtml(error.message) + '</p>';
    });
  }

  function renderEventDetail(event, preview) {
      var types = event.ticket_types || [];
      var onSale = types.filter(function (type) { return (type.effective_status || type.status) === "on_sale"; });
      var gallery = (event.gallery || []).filter(Boolean).map(function (url) { return '<img loading="lazy" src="' + escapeAttr(url) + '" alt="Detalle de ' + escapeHtml(event.title) + '">'; }).join("");
      var action = preview ? '<button class="ticket-btn primary" type="button" disabled>Vista previa: compra desactivada</button>' : (onSale.length ? '<a class="ticket-btn primary" href="/entradas/checkout/?event=' + encodeURIComponent(event.slug) + '">Comprar entradas</a>' : '<span class="ticket-status">' + (types.length ? 'Las entradas no están disponibles en este momento.' : 'Próximamente anunciaremos las entradas.') + '</span>');
      return [
        '<div class="ticket-detail">',
        '<div class="ticket-detail-media" style="background-image:url(' + escapeAttr(event.image_url || "/assets/images/finca-la-llaguna-principal.jpg") + ')"></div>',
        '<div>',
        '<span class="ticket-eyebrow">' + escapeHtml(event.location) + '</span>',
        '<h1 class="ticket-title">' + escapeHtml(event.title) + '</h1>',
        event.subtitle ? '<p class="event-subtitle">' + escapeHtml(event.subtitle) + '</p>' : '',
        '<p class="ticket-copy">' + escapeHtml(event.short_description || event.description) + '</p>',
        '<div class="event-meta"><span>' + escapeHtml(fmtDate(event.starts_at)) + '</span><span>' + escapeHtml(event.doors_open_at ? 'Puertas: ' + fmtDate(event.doors_open_at) : '') + '</span><span>' + escapeHtml(event.address || "") + '</span><span>Promotor: ' + escapeHtml(event.promoter || "JYD Events, S.L.") + '</span></div>',
        '<div class="ticket-types">' + types.map(ticketTypeRow).join("") + '</div>',
        action,
        '</div>',
        '</div>',
        '<section class="event-story"><div><span class="ticket-eyebrow">La experiencia</span><h2>' + escapeHtml(event.title) + '</h2><p class="ticket-copy">' + escapeHtml(event.description || "") + '</p>' + (event.recommendations ? '<p class="ticket-copy">' + escapeHtml(event.recommendations) + '</p>' : '') + '</div>' + (event.video_url ? '<a class="ticket-btn" href="' + escapeAttr(event.video_url) + '" target="_blank" rel="noopener noreferrer">Ver vídeo</a>' : '') + '</section>',
        gallery ? '<section class="event-gallery">' + gallery + '</section>' : '',
        event.included_text || event.access_conditions || event.minor_policy || event.refund_policy || event.accessibility_info ? '<section class="event-info-grid">' + publicInfo(event) + '</section>' : '',
        event.parking_info || event.access_notes || event.maps_url ? '<section class="event-arrival"><h2>Llegar y disfrutar</h2><p>' + escapeHtml(event.access_notes || event.parking_info || "") + '</p>' + (event.maps_url ? '<a class="ticket-btn" href="' + escapeAttr(event.maps_url) + '" target="_blank" rel="noopener noreferrer">Abrir mapa</a>' : '') + '</section>' : '',
        (event.faq || []).length ? '<section class="event-faqs"><h2>Preguntas frecuentes</h2>' + publicFaq(event.faq) + '</section>' : ''
      ].join("");
  }

  function publicInfo(event) {
    var entries = [["Qué incluye", event.included_text], ["Acceso", event.access_conditions], ["Menores", event.minor_policy], ["Cambios y devoluciones", event.refund_policy], ["Accesibilidad", event.accessibility_info]];
    return entries.filter(function (item) { return item[1]; }).map(function (item) { return '<article class="event-info-card"><h3>' + escapeHtml(item[0]) + '</h3><p>' + escapeHtml(item[1]) + '</p></article>'; }).join("");
  }

  function publicFaq(items) {
    return items.map(function (item) { var question = typeof item === "object" ? item.question : item; var answer = typeof item === "object" ? item.answer : ""; return '<details class="event-faq"><summary>' + escapeHtml(question) + '</summary><p>' + escapeHtml(answer) + '</p></details>'; }).join("");
  }

  function ticketTypeRow(type) {
    var state = type.effective_status || type.status || "on_sale";
    var availability = state === "on_sale" ? (Number(type.available || 0) + " disponibles") : ({ upcoming: "Próximamente", sold_out: "Agotada", paused: "Venta pausada", closed: "Venta cerrada", code_required: "Acceso mediante código" }[state] || state);
    return [
      '<article class="ticket-type">',
      '<div><h3>' + escapeHtml(type.name) + '</h3><p>' + escapeHtml(type.description || "") + '</p><p>' + escapeHtml(availability) + (type.requires_promo ? ' · Código necesario' : '') + '</p></div>',
      '<strong class="ticket-type-price">' + cents(type.final_price_cents != null ? type.final_price_cents : type.price_cents) + '</strong>',
      '</article>'
    ].join("");
  }

  function initCheckout() {
    var form = document.querySelector("[data-ticket-checkout]");
    if (!form) return;
    var slug = qs("event");
    var typesBox = form.querySelector("[data-ticket-types]");
    var status = form.querySelector("[data-ticket-status]");
    var eventTitle = document.querySelector("[data-checkout-event-title]");
    if (!slug) {
      status.textContent = "Falta el evento.";
      return;
    }
    request(api + "/events/" + encodeURIComponent(slug)).then(function (data) {
      var event = data.event;
      if (eventTitle) eventTitle.textContent = event.title;
      var types = (event.ticket_types || []).filter(function (type) { return (type.effective_status || type.status) === "on_sale"; });
      if (!types.length) {
        status.textContent = "No hay entradas disponibles para comprar en este momento.";
        form.querySelector('button[type="submit"]').disabled = true;
        return;
      }
      var needsCode = types.some(function (type) { return type.requires_promo; });
      typesBox.innerHTML = (needsCode ? '<label class="ticket-field"><span>Código promocional</span><input name="promo_code" autocomplete="off" placeholder="Solo si una entrada lo requiere"></label>' : '') + types.map(function (type) {
        var price = type.final_price_cents != null ? type.final_price_cents : type.price_cents;
        return '<label class="ticket-type"><span><strong>' + escapeHtml(type.name) + '</strong><br><small>' + cents(price) + ' · ' + Number(type.available || 0) + ' disponibles</small></span><input min="0" max="' + Number(type.max_per_order || 10) + '" value="0" type="number" name="ticket_' + type.id + '" data-ticket-type="' + type.id + '"></label>';
      }).join("");
      form.dataset.eventSlug = event.slug;
    }).catch(function (error) {
      status.textContent = error.message;
    });

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      status.textContent = "Creando pedido seguro...";
      var payload = {
        event_slug: form.dataset.eventSlug,
        first_name: form.first_name.value,
        last_name: form.last_name.value,
        email: form.email.value,
        phone: form.phone.value,
        privacy_accepted: form.privacy_accepted.checked,
        terms_accepted: form.terms_accepted.checked,
        promo_code: form.promo_code ? form.promo_code.value : "",
        items: Array.from(form.querySelectorAll("[data-ticket-type]")).map(function (input) {
          return { ticket_type_id: Number(input.dataset.ticketType), quantity: Number(input.value || 0) };
        }).filter(function (item) { return item.quantity > 0; })
      };
      request(api + "/orders", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      }).then(function (data) {
        if (data.payment && data.payment.free) {
          window.location.assign(data.payment.url);
          return;
        }
        var redsysForm = document.createElement("form");
        redsysForm.method = "POST";
        redsysForm.action = data.payment.url;
        Object.keys(data.payment.fields).forEach(function (key) {
          var input = document.createElement("input");
          input.type = "hidden";
          input.name = key;
          input.value = data.payment.fields[key];
          redsysForm.appendChild(input);
        });
        document.body.appendChild(redsysForm);
        redsysForm.submit();
      }).catch(function (error) {
        status.textContent = error.message;
      });
    });
  }

  function initOrderStatus() {
    var root = document.querySelector("[data-order-status]");
    if (!root) return;
    var token = qs("token");
    if (!token) {
      root.innerHTML = '<p class="ticket-status">Falta el token del pedido.</p>';
      return;
    }
    request(api + "/orders/" + encodeURIComponent(token)).then(function (data) {
      var order = data.order;
      root.innerHTML = [
        '<div class="ticket-panel">',
        '<span class="ticket-eyebrow">Pedido ' + escapeHtml(order.status) + '</span>',
        '<h1 class="ticket-title">Tus <em>entradas</em></h1>',
        '<p class="ticket-copy">Pedido a nombre de ' + escapeHtml(order.name) + '. Importe: ' + cents(order.total_cents) + '.</p>',
        '<div class="ticket-list">' + (order.tickets || []).map(ticketPass).join("") + '</div>',
        '</div>'
      ].join("");
    }).catch(function (error) {
      root.innerHTML = '<p class="ticket-status">' + escapeHtml(error.message) + '</p>';
    });
  }

  function ticketPass(ticket) {
    return '<article class="ticket-pass"><div><h3>' + escapeHtml(ticket.event_title) + '</h3><p>' + escapeHtml(fmtDate(ticket.starts_at)) + ' · ' + escapeHtml(ticket.location || "") + '</p><p>' + escapeHtml(ticket.ticket_type_name || "") + '</p><p class="ticket-code">' + escapeHtml(ticket.public_code) + '</p></div><div class="ticket-qr">Código acceso<br>' + escapeHtml(ticket.public_code) + '</div></article>';
  }

  function escapeHtml(value) {
    return String(value || "").replace(/[&<>"']/g, function (char) {
      return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" })[char];
    });
  }

  function escapeAttr(value) {
    return String(value || "").replace(/[\"'()\\\\]/g, "");
  }

  initEventsList();
  initEventDetail();
  initCheckout();
  initOrderStatus();
})();

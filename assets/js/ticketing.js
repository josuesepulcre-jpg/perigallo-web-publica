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

  function previewAssetUrl(url, preview) {
    if (!url || !preview) return url;
    return url + (url.indexOf("?") === -1 ? "?" : "&") + "preview=" + Date.now();
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
    request(endpoint, preview ? { cache: "no-store" } : undefined).then(function (data) {
      var event = data.event;
      if (!preview) document.title = (event.seo_title || event.title) + " | Entradas Perigallo";
      root.innerHTML = renderEventDetail(event, preview);
      initExperienceAccordions(root);
    }).catch(function (error) {
      root.innerHTML = '<p class="ticket-status">' + escapeHtml(error.message) + '</p>';
    });
  }

  function renderEventDetail(event, preview) {
      var types = event.ticket_types || [];
      var onSale = types.filter(function (type) { return (type.effective_status || type.status) === "on_sale"; });
      var gallery = (event.gallery || []).filter(Boolean).map(function (url) { return '<img loading="lazy" src="' + escapeAttr(previewAssetUrl(url, preview)) + '" alt="Detalle de ' + escapeHtml(event.title) + '">'; }).join("");
      var logo = event.logo_url ? '<img class="event-logo" src="' + escapeAttr(previewAssetUrl(event.logo_url, preview)) + '" alt="Logotipo de ' + escapeHtml(event.title) + '">' : '';
      var imageUrl = previewAssetUrl(event.image_url || "/assets/images/finca-la-llaguna-principal.jpg", preview);
      var video = event.video_url ? '<div class="event-story-media"><section class="event-video"><video controls playsinline preload="metadata" poster="' + escapeAttr(imageUrl) + '" src="' + escapeAttr(previewAssetUrl(event.video_url, preview)) + '">Tu navegador no puede reproducir este vídeo.</video></section></div>' : '';
      var action = preview ? '<a class="ticket-btn primary" href="/entradas/checkout/?preview=1&amp;id=' + encodeURIComponent(event.id) + '">Probar recorrido de compra</a><p class="ticket-preview-note">Vista privada: no se crea ningún pedido ni se accede al pago.</p>' : (onSale.length ? '<a class="ticket-btn primary" href="/entradas/checkout/?event=' + encodeURIComponent(event.slug) + '">Comprar entradas</a>' : '<span class="ticket-status">' + (types.length ? 'Las entradas no están disponibles en este momento.' : 'Próximamente anunciaremos las entradas.') + '</span>');
      var storyClass = video ? "event-story event-story-layout event-story-has-media" : "event-story event-story-layout";
      return [
        '<div class="event-detail-layout">',
        '<section class="ticket-detail event-hero">',
        '<figure class="ticket-detail-media event-hero-media"><img src="' + escapeAttr(imageUrl) + '" alt="Cartel de ' + escapeHtml(event.title) + '"></figure>',
        '<div class="event-hero-copy">',
        logo,
        '<span class="ticket-eyebrow">' + escapeHtml(event.location || "Perigallo") + '</span>',
        '<h1 class="ticket-title">' + escapeHtml(event.title) + '</h1>',
        event.subtitle ? '<p class="event-subtitle">' + escapeHtml(event.subtitle) + '</p>' : '',
        '<p class="ticket-copy event-intro">' + escapeHtml(event.short_description || event.description) + '</p>',
        '<div class="event-meta event-metadata">' + eventMetadata(event) + '</div>',
        '<div class="ticket-types">' + types.map(ticketTypeRow).join("") + '</div>',
        '<div class="event-purchase-action">' + action + '</div>',
        '</div>',
        '</section>',
        '<section class="' + storyClass + '"><div class="event-story-copy"><span class="ticket-eyebrow">La experiencia</span><h2>' + escapeHtml(event.title) + '</h2><div class="ticket-copy event-story-text">' + textParagraphs(event.description || "") + '</div></div>' + video + '</section>',
        gallery ? '<section class="event-gallery">' + gallery + '</section>' : '',
        experienceAccordions(event) ? '<section class="event-public-information event-public-information-accordions"><div class="experience-accordions">' + experienceAccordions(event) + '</div></section>' : '',
        '</div>'
      ].join("");
  }

  function metadataItem(icon, label, value) {
    if (!value) return "";
    return '<span class="event-meta-item"><span class="event-meta-icon" aria-hidden="true">' + icon + '</span><span><small>' + escapeHtml(label) + '</small><strong>' + escapeHtml(value) + '</strong></span></span>';
  }

  function eventMetadata(event) {
    var calendar = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="5" width="18" height="16" rx="1"></rect><path d="M7 3v4M17 3v4M3 10h18"></path></svg>';
    var doors = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="8.5"></circle><path d="M12 7v5l3 2"></path></svg>';
    var place = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg>';
    var host = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 21c.8-4 3.1-6 7-6s6.2 2 7 6"></path></svg>';
    return metadataItem(calendar, "Fecha", fmtDate(event.starts_at)) + metadataItem(doors, "Puertas", event.doors_open_at ? fmtDate(event.doors_open_at) : "") + metadataItem(place, "Lugar", event.address || event.location) + metadataItem(host, "Promotor", event.promoter || "");
  }

  function textParagraphs(value) {
    return String(value || "").trim().split(/\n\s*\n/).filter(Boolean).map(function (paragraph) { return '<p>' + linkifyText(paragraph).replace(/\n/g, "<br>") + '</p>'; }).join("");
  }

  function linkifyText(value) {
    return escapeHtml(value)
      .replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>')
      .replace(/(^|[\s(])([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})(?=$|[\s).,;:])/gi, '$1<a href="mailto:$2">$2</a>')
      .replace(/(^|[\s(])((?:\+?\d[\s.-]?){8,}\d)(?=$|[\s).,;:])/g, function (_, prefix, phone) { return prefix + '<a href="tel:' + phone.replace(/[^+\d]/g, "") + '">' + phone + '</a>'; });
  }

  function locationInformation(event) {
    var details = [event.location, event.address, [event.postal_code, event.locality, event.province].filter(Boolean).join(" · "), event.access_notes, event.parking_info].filter(Boolean).join("\n\n");
    return textParagraphs(details) + (event.maps_url ? '<p><a class="ticket-btn" href="' + escapeAttr(event.maps_url) + '" target="_blank" rel="noopener noreferrer">Abrir mapa</a></p>' : '');
  }

  function scheduleInformation(event) {
    var rows = [];
    if (event.schedule_note) rows.push(event.schedule_note);
    if (event.doors_open_at) rows.push("Apertura de puertas: " + fmtDate(event.doors_open_at));
    if (event.starts_at) rows.push("Inicio de la experiencia: " + fmtDate(event.starts_at));
    if (event.ends_at) rows.push("Finalización prevista: " + fmtDate(event.ends_at));
    return textParagraphs(rows.join("\n\n"));
  }

  function accordionMarkup(item, index, nested) {
    var panelId = "experience-information-" + (nested ? "faq-" : "panel-") + index;
    return '<article class="experience-accordion' + (nested ? ' experience-accordion-nested' : '') + '" data-experience-accordion>' +
      '<button class="experience-accordion-trigger" type="button" data-experience-accordion-trigger aria-expanded="false" aria-controls="' + panelId + '">' +
      '<span class="experience-accordion-number">' + escapeHtml(String(index + 1).padStart(2, "0")) + '</span><span class="experience-accordion-copy"><span class="experience-accordion-title">' + escapeHtml(item.title) + '</span></span><span class="experience-accordion-icon" aria-hidden="true"></span></button>' +
      '<div class="experience-accordion-panel" id="' + panelId + '" role="region" aria-label="' + escapeHtml(item.title) + '" aria-hidden="true"><div class="experience-accordion-content">' + item.content + '</div></div>' +
      '</article>';
  }

  function faqAccordion(items) {
    var questions = items.filter(function (item) { return item && (typeof item === "object" ? item.question : item); }).map(function (item) {
      return { title: typeof item === "object" ? item.question : item, content: textParagraphs(typeof item === "object" ? item.answer : "") };
    });
    return questions.length ? '<div class="experience-accordion-nested-list">' + questions.map(function (item, index) { return accordionMarkup(item, index, true); }).join("") + '</div>' : '';
  }

  function experienceAccordions(event) {
    var entries = [
      { title: "Información de la experiencia", content: textParagraphs(event.included_text), visible: !!event.included_text },
      { title: "Horarios", content: scheduleInformation(event), visible: !!(event.schedule_note || event.doors_open_at || event.starts_at || event.ends_at) },
      { title: "Ubicación y cómo llegar", content: locationInformation(event), visible: !!(event.location || event.address || event.access_notes || event.parking_info || event.maps_url) },
      { title: "Condiciones de acceso", content: textParagraphs(event.access_conditions), visible: !!event.access_conditions },
      { title: "Código de vestimenta", content: textParagraphs(event.dress_code), visible: !!event.dress_code },
      { title: "Recomendaciones", content: textParagraphs(event.recommendations), visible: !!event.recommendations },
      { title: "Accesibilidad", content: textParagraphs(event.accessibility_info), visible: !!event.accessibility_info },
      { title: "Política de menores", content: textParagraphs(event.minor_policy), visible: !!event.minor_policy },
      { title: "Cancelaciones y devoluciones", content: textParagraphs(event.refund_policy), visible: !!event.refund_policy },
      { title: "Contacto", content: textParagraphs(event.contact_info), visible: !!event.contact_info },
      { title: "Preguntas frecuentes", content: faqAccordion(event.faq || []), visible: !!(event.faq || []).length }
    ];
    return entries.filter(function (item) { return item.visible && item.content; }).map(function (item, index) { return accordionMarkup(item, index, false); }).join("");
  }

  function initExperienceAccordions(root) {
    root.addEventListener("click", function (event) {
      var trigger = event.target.closest("[data-experience-accordion-trigger]");
      if (!trigger || !root.contains(trigger)) return;
      var accordion = trigger.closest("[data-experience-accordion]");
      var wasOpen = accordion.classList.contains("is-open");
      root.querySelectorAll("[data-experience-accordion]").forEach(function (item) {
        if (item === accordion) return;
        item.classList.remove("is-open");
        var itemTrigger = item.querySelector("[data-experience-accordion-trigger]");
        var itemPanel = item.querySelector(".experience-accordion-panel");
        if (itemTrigger) itemTrigger.setAttribute("aria-expanded", "false");
        if (itemPanel) itemPanel.setAttribute("aria-hidden", "true");
      });
      accordion.classList.toggle("is-open", !wasOpen);
      trigger.setAttribute("aria-expanded", String(!wasOpen));
      accordion.querySelector(".experience-accordion-panel").setAttribute("aria-hidden", String(wasOpen));
    });
  }

  function ticketTypeRow(type) {
    var state = type.effective_status || type.status || "on_sale";
    var availability = state === "on_sale" ? (Number(type.available || 0) + " disponibles") : ({ upcoming: "Próximamente", sold_out: "Agotada", paused: "Venta pausada", closed: "Venta cerrada", code_required: "Acceso mediante código" }[state] || state);
    return [
      '<article class="ticket-type">',
      '<span class="ticket-type-mark" aria-hidden="true">01</span>',
      '<div class="ticket-type-copy"><h3>' + escapeHtml(type.name) + '</h3><p>' + escapeHtml(type.description || "") + '</p><small>' + escapeHtml(availability) + (type.requires_promo ? ' · Código necesario' : '') + '</small></div>',
      '<div class="ticket-type-price"><strong>' + cents(type.final_price_cents != null ? type.final_price_cents : type.price_cents) + '</strong><span>por persona</span></div>',
      '</article>'
    ].join("");
  }

  function initCheckout() {
    var form = document.querySelector("[data-ticket-checkout]");
    if (!form) return;
    var preview = qs("preview") === "1";
    var slug = qs("event");
    var previewId = qs("id");
    var typesBox = form.querySelector("[data-ticket-types]");
    var status = form.querySelector("[data-ticket-status]");
    var eventTitle = document.querySelector("[data-checkout-event-title]");
    var eyebrow = document.querySelector("[data-checkout-eyebrow]");
    var title = document.querySelector("[data-checkout-title]");
    var copy = document.querySelector("[data-checkout-copy]");
    var safetyCopy = document.querySelector("[data-checkout-safety-copy]");
    var submit = form.querySelector('button[type="submit"]');
    if ((!preview && !slug) || (preview && !previewId)) {
      status.textContent = "Falta el evento.";
      return;
    }
    if (preview) {
      form.classList.add("ticket-form-preview");
      if (eyebrow) eyebrow.textContent = "Vista previa privada";
      if (title) title.innerHTML = "Así se completa <em>una compra</em>";
      if (copy) copy.textContent = "Recorre la selección de entradas, los datos y el resumen final sin crear pedidos ni abrir el pago.";
      if (safetyCopy) safetyCopy.textContent = "Esta demostración no reserva plazas, no guarda datos y no conecta con Redsys.";
      if (submit) submit.textContent = "Ver resumen de compra";
    }
    var endpoint = preview ? api + "/admin/events/" + encodeURIComponent(previewId) + "/preview" : api + "/events/" + encodeURIComponent(slug);
    request(endpoint, preview ? { cache: "no-store" } : undefined).then(function (data) {
      var event = data.event;
      if (eventTitle) eventTitle.textContent = event.title;
      var types = (event.ticket_types || []).filter(function (type) { return preview || (type.effective_status || type.status) === "on_sale"; });
      if (!types.length) {
        status.textContent = preview ? "Añade al menos un tipo de entrada en el editor para comprobar el recorrido de compra." : "No hay entradas disponibles para comprar en este momento.";
        submit.disabled = true;
        return;
      }
      var needsCode = types.some(function (type) { return type.requires_promo; });
      typesBox.innerHTML = (needsCode ? '<label class="ticket-field"><span>Código promocional</span><input name="promo_code" autocomplete="off" placeholder="Solo si una entrada lo requiere"></label>' : '') + types.map(function (type) {
        var price = type.final_price_cents != null ? type.final_price_cents : type.price_cents;
        var availability = preview ? 'Vista previa · ' + Number(type.available || 0) + ' disponibles' : cents(price) + ' · ' + Number(type.available || 0) + ' disponibles';
        return '<label class="ticket-type"><span><strong>' + escapeHtml(type.name) + '</strong><br><small>' + (preview ? escapeHtml(availability) + ' · ' + cents(price) : escapeHtml(availability)) + '</small></span><input min="0" max="' + Number(type.max_per_order || 10) + '" value="0" type="number" name="ticket_' + type.id + '" data-ticket-type="' + type.id + '" data-ticket-name="' + escapeAttr(type.name) + '" data-ticket-price="' + Number(price || 0) + '"></label>';
      }).join("");
      form.dataset.eventSlug = event.slug;
    }).catch(function (error) {
      status.textContent = error.message;
    });

    form.addEventListener("submit", function (event) {
      event.preventDefault();
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
      if (preview) {
        renderCheckoutPreview(form, payload, eventTitle ? eventTitle.textContent : "Este evento");
        return;
      }
      status.textContent = "Creando pedido seguro...";
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

  function renderCheckoutPreview(form, payload, eventTitle) {
    var selectedInputs = Array.from(form.querySelectorAll("[data-ticket-type]")).filter(function (input) { return Number(input.value || 0) > 0; });
    if (!selectedInputs.length) {
      form.querySelector("[data-ticket-status]").textContent = "Selecciona al menos una entrada para ver el resumen.";
      return;
    }
    var total = selectedInputs.reduce(function (sum, input) { return sum + Number(input.value || 0) * Number(input.dataset.ticketPrice || 0); }, 0);
    var itemRows = selectedInputs.map(function (input) {
      return '<li><span>' + Number(input.value) + ' × ' + escapeHtml(input.dataset.ticketName) + '</span><strong>' + cents(Number(input.value) * Number(input.dataset.ticketPrice || 0)) + '</strong></li>';
    }).join("");
    form.innerHTML = [
      '<div class="ticket-preview-confirmation">',
      '<span class="ticket-eyebrow">Vista previa de compra</span>',
      '<h2>Así vería <em>tu pedido</em> la persona asistente</h2>',
      '<p class="ticket-copy">Este es un resumen de demostración para <strong>' + escapeHtml(eventTitle) + '</strong>. No se ha creado ningún pedido, no se han guardado datos y no se ha abierto el pago.</p>',
      '<div class="ticket-preview-summary"><div><span>Contacto</span><strong>' + escapeHtml((payload.first_name + " " + payload.last_name).trim() || "Nombre de ejemplo") + '</strong><small>' + escapeHtml(payload.email || "correo@ejemplo.com") + '</small></div><div><span>Importe total</span><strong>' + cents(total) + '</strong><small>El pago seguro se abriría después de confirmar.</small></div></div>',
      '<ul class="ticket-preview-items">' + itemRows + '</ul>',
      '<div class="ticket-preview-actions"><button class="ticket-btn primary" type="button" data-restart-checkout-preview>Volver a editar la compra</button><a class="ticket-btn" href="/admin/entradas/">Volver al editor</a></div>',
      '</div>'
    ].join("");
    form.querySelector("[data-restart-checkout-preview]").addEventListener("click", function () { window.location.reload(); });
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

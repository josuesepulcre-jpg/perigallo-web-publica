(function () {
  var api = "/api";
  var money = new Intl.NumberFormat("es-ES", { style: "currency", currency: "EUR" });
  var LOW_AVAILABILITY_THRESHOLD = 8;

  function qs(name) {
    return new URLSearchParams(window.location.search).get(name);
  }

  function fmtDate(value) {
    if (!value) return "";
    return new Intl.DateTimeFormat("es-ES", { dateStyle: "full", timeStyle: "short" }).format(new Date(value.replace(" ", "T")));
  }

  function fmtDateOnly(value) {
    if (!value) return "";
    return new Intl.DateTimeFormat("es-ES", { dateStyle: "full" }).format(new Date(value.replace(" ", "T")));
  }

  function fmtTime(value) {
    if (!value) return "";
    return new Intl.DateTimeFormat("es-ES", { hour: "2-digit", minute: "2-digit" }).format(new Date(value.replace(" ", "T")));
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
      initIncludedInformationLink(root);
    }).catch(function (error) {
      root.innerHTML = '<p class="ticket-status">' + escapeHtml(error.message) + '</p>';
    });
  }

  function renderEventDetail(event, preview) {
      var types = event.ticket_types || [];
      var gallery = (event.gallery || []).filter(Boolean).map(function (url) { return '<img loading="lazy" src="' + escapeAttr(previewAssetUrl(url, preview)) + '" alt="Detalle de ' + escapeHtml(event.title) + '">'; }).join("");
      var brand = event.logo_url ? '<div class="event-brand-signature"><img class="event-logo" src="' + escapeAttr(previewAssetUrl(event.logo_url, preview)) + '" alt="Logotipo de ' + escapeHtml(event.title) + '"></div>' : '';
      var imageUrl = previewAssetUrl(event.image_url || "/assets/images/finca-la-llaguna-principal.jpg", preview);
      var video = event.video_url ? '<div class="event-story-media"><section class="event-video"><video controls playsinline preload="metadata" poster="' + escapeAttr(imageUrl) + '" src="' + escapeAttr(previewAssetUrl(event.video_url, preview)) + '">Tu navegador no puede reproducir este vídeo.</video></section></div>' : '';
      var ticketCards = types.length ? types.map(function (type) { return ticketTypeRow(type, event, preview); }).join("") : '<p class="ticket-status event-access-empty">Próximamente anunciaremos las entradas.</p>';
      var storyClass = video ? "event-story event-story-layout event-story-has-media" : "event-story event-story-layout";
      return [
        '<div class="event-detail-layout">',
        '<section class="ticket-detail event-hero">',
        brand,
        '<figure class="ticket-detail-media event-hero-media"><img src="' + escapeAttr(imageUrl) + '" alt="Cartel de ' + escapeHtml(event.title) + '"></figure>',
        '<div class="event-hero-copy">',
        '<div class="event-hero-introduction">',
        '<span class="ticket-eyebrow">Experiencia Perigallo</span>',
        '<h1 class="ticket-title">' + escapeHtml(event.title) + '</h1>',
        event.subtitle ? '<p class="event-subtitle">' + escapeHtml(event.subtitle) + '</p>' : '',
        '<p class="ticket-copy event-intro">' + escapeHtml(event.short_description || event.description) + '</p>',
        '</div>',
        '<section class="event-access">',
        '<div class="ticket-types">' + ticketCards + '</div>',
        '</section>',
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

  function initIncludedInformationLink(root) {
    root.addEventListener("click", function (event) {
      var link = event.target.closest("[data-open-included-information]");
      if (!link || !root.contains(link)) return;
      var accordion = Array.prototype.find.call(root.querySelectorAll("[data-experience-accordion]"), function (item) {
        var title = item.querySelector(".experience-accordion-title");
        return title && title.textContent.trim() === "Información de la experiencia";
      });
      if (!accordion) return;
      var trigger = accordion.querySelector("[data-experience-accordion-trigger]");
      if (trigger && trigger.getAttribute("aria-expanded") !== "true") trigger.click();
      accordion.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }

  function accessIcon(name) {
    var icons = {
      calendar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="5" width="18" height="16" rx="1"></rect><path d="M7 3v4M17 3v4M3 10h18"></path></svg>',
      clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="8.5"></circle><path d="M12 7v5l3 2"></path></svg>',
      duration: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 3v9l5.5 3.2"></path><circle cx="12" cy="12" r="8.5"></circle></svg>',
      place: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg>',
      dress: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m8 4 4 3 4-3 4 4-3 4v8H7v-8L4 8l4-4Z"></path><path d="M10 7h4"></path></svg>'
    };
    return icons[name] || "";
  }

  function durationText(startsAt, endsAt) {
    if (!startsAt || !endsAt) return "";
    var start = new Date(startsAt.replace(" ", "T")).getTime();
    var end = new Date(endsAt.replace(" ", "T")).getTime();
    if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) return "";
    var minutes = Math.round((end - start) / 60000);
    var hours = Math.floor(minutes / 60);
    var remainder = minutes % 60;
    if (!hours) return minutes + " min";
    if (!remainder) return hours + (hours === 1 ? " hora" : " horas");
    return hours + " h " + remainder + " min";
  }

  function shortDetail(value) {
    var text = String(value || "").replace(/\s+/g, " ").trim();
    if (text.length <= 72) return text;
    return text.slice(0, 69).replace(/\s+\S*$/, "") + "…";
  }

  function accessFact(icon, label, value, detail) {
    if (!value) return "";
    return '<li class="ticket-access-fact"><span class="ticket-access-icon" aria-hidden="true">' + accessIcon(icon) + '</span><span><small>' + escapeHtml(label) + '</small><strong>' + escapeHtml(value) + '</strong>' + (detail ? '<em>' + escapeHtml(detail) + '</em>' : '') + '</span></li>';
  }

  function accessFacts(event) {
    var locality = [event.locality, event.province].filter(Boolean).join(", ");
    var place = event.location || locality;
    var schedule = event.starts_at ? (event.ends_at ? fmtTime(event.starts_at) + " – " + fmtTime(event.ends_at) : fmtTime(event.starts_at)) : "";
    return [
      accessFact("calendar", "Fecha", fmtDateOnly(event.starts_at)),
      accessFact("clock", "Horario", schedule),
      accessFact("duration", "Duración", durationText(event.starts_at, event.ends_at)),
      accessFact("place", "Lugar", place, event.location ? locality : "")
    ].filter(Boolean).join("");
  }

  function availabilityText(type) {
    var state = type.effective_status || type.status || "on_sale";
    if (state === "on_sale") {
      if (Number(type.available || 0) <= 0) return "Entradas agotadas";
      if (Number(type.available || 0) <= LOW_AVAILABILITY_THRESHOLD) return "Últimas entradas";
      return "Entradas disponibles";
    }
    return ({ upcoming: "Próximamente", sold_out: "Entradas agotadas", paused: "Venta pausada", closed: "Venta finalizada", code_required: "Acceso con código" }[state] || "Consulta disponibilidad");
  }

  function ticketPurchaseAction(type, event, preview) {
    if (preview) {
      return '<div class="ticket-access-action"><a class="ticket-btn primary" href="/entradas/checkout/?preview=1&amp;id=' + encodeURIComponent(event.id) + '">Probar recorrido de compra <span aria-hidden="true">→</span></a><p class="ticket-preview-note"><span aria-hidden="true">◦</span> Vista privada: el pedido y el pago se ejecutan en modo de pruebas, sin cargos ni aforo real.</p></div>';
    }
    if ((type.effective_status || type.status) === "on_sale") {
      return '<div class="ticket-access-action"><a class="ticket-btn primary" href="/entradas/checkout/?event=' + encodeURIComponent(event.slug) + '">Seleccionar entradas <span aria-hidden="true">→</span></a></div>';
    }
    return '<p class="ticket-access-unavailable">' + escapeHtml(availabilityText(type)) + '</p>';
  }

  function ticketTypeRow(type, event, preview) {
    var availability = availabilityText(type);
    var facts = accessFacts(event);
    var includesLink = event.included_text ? '<button class="ticket-access-includes" type="button" data-open-included-information>Ver todo lo que incluye<span aria-hidden="true">→</span></button>' : "";
    var dress = shortDetail(event.dress_code);
    return [
      '<article class="ticket-type ticket-access-card">',
      '<div class="ticket-access-heading">',
      '<div class="ticket-access-copy">',
      '<span class="ticket-access-eyebrow">Tu acceso a la experiencia</span>',
      '<h3>' + escapeHtml(type.name) + '</h3>',
      type.description ? '<p>' + escapeHtml(type.description) + '</p>' : '',
      includesLink,
      '</div>',
      '<div class="ticket-access-decision"><div class="ticket-type-price"><strong>' + cents(type.final_price_cents != null ? type.final_price_cents : type.price_cents) + '</strong><span>por persona</span></div>' + ticketPurchaseAction(type, event, preview) + '</div>',
      '</div>',
      facts ? '<ul class="ticket-access-facts">' + facts + '</ul>' : '',
      '<div class="ticket-access-secondary">',
      dress ? '<p class="ticket-access-dress"><span class="ticket-access-icon" aria-hidden="true">' + accessIcon("dress") + '</span><span><small>Vestimenta</small><strong>' + escapeHtml(dress) + '</strong></span></p>' : '',
      '<p class="ticket-access-status"><span class="ticket-access-status-dot" aria-hidden="true"></span><span>' + escapeHtml(availability) + (type.requires_promo ? ' · Código necesario' : '') + '</span></p>',
      '</div>',
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
    var submit = form.querySelector("[data-checkout-submit]");
    var summary = form.querySelector("[data-checkout-summary]");
    var layout = form.querySelector("[data-checkout-layout]");
    var confirmation = form.querySelector("[data-checkout-preview-confirmation]");
    var isSubmitting = false;
    if ((!preview && !slug) || (preview && !previewId)) {
      status.textContent = "Falta el evento.";
      return;
    }
    if (preview) {
      form.classList.add("ticket-form-preview");
      if (eyebrow) eyebrow.textContent = "Modo de pruebas";
      if (title) title.innerHTML = "Así se completa <em>una compra</em>";
      if (copy) copy.textContent = "Completa el recorrido aislado: pedido de prueba, pago sandbox, entradas y comunicaciones de prueba.";
      if (safetyCopy) safetyCopy.textContent = "MODO DE PRUEBAS · No se realizará ningún cargo real ni se modificará el aforo.";
      if (submit) submit.innerHTML = "Ver resumen de prueba <span aria-hidden=\"true\">→</span>";
    }
    var endpoint = preview ? api + "/admin/events/" + encodeURIComponent(previewId) + "/preview" : api + "/events/" + encodeURIComponent(slug);
    request(endpoint, preview ? { cache: "no-store" } : undefined).then(function (data) {
      var event = data.event;
      if (eventTitle) eventTitle.textContent = event.title;
      var types = (event.ticket_types || []).filter(function (type) {
        if (!preview) return (type.effective_status || type.status) === "on_sale";
        return type.active !== false && type.visible !== false && type.status !== "archived";
      });
      if (!types.length) {
        status.textContent = preview ? "Añade al menos un tipo de entrada en el editor para comprobar el recorrido de compra." : "No hay entradas disponibles para comprar en este momento.";
        submit.disabled = true;
        return;
      }
      var needsCode = types.some(function (type) { return type.requires_promo; });
      typesBox.innerHTML = (needsCode ? '<div class="checkout-field checkout-promo"><label for="promo_code">Código promocional</label><input id="promo_code" name="promo_code" autocomplete="off" placeholder="Solo si alguna entrada lo requiere"></div>' : '') + types.map(checkoutTicketMarkup).join("");
      form.dataset.eventSlug = event.slug;
      form.dataset.eventTitle = event.title;
      form.dataset.previewEventId = event.id;
      refreshCheckout();
    }).catch(function (error) {
      status.textContent = error.message;
    });

    typesBox.addEventListener("click", function (event) {
      var button = event.target.closest("[data-quantity-action]");
      if (!button || button.disabled) return;
      var card = button.closest("[data-ticket-card]");
      var input = card.querySelector("[data-ticket-type]");
      var delta = button.dataset.quantityAction === "increase" ? 1 : -1;
      input.value = Math.max(0, Math.min(Number(input.max || 0), Number(input.value || 0) + delta));
      refreshCheckout();
    });

    form.querySelectorAll(".checkout-field input").forEach(function (input) {
      input.addEventListener("input", function () {
        if (input.closest(".checkout-field").classList.contains("has-error")) updateCheckoutField(input, true);
        refreshCheckout();
      });
      input.addEventListener("change", refreshCheckout);
      input.addEventListener("blur", function () { updateCheckoutField(input, true); refreshCheckout(); });
    });
    form.querySelectorAll(".checkout-check input").forEach(function (input) { input.addEventListener("change", refreshCheckout); });

    function refreshCheckout() {
      var inputs = Array.from(form.querySelectorAll("[data-ticket-type]"));
      var selected = inputs.filter(function (input) { return Number(input.value || 0) > 0; });
      inputs.forEach(function (input) {
        var card = input.closest("[data-ticket-card]");
        var quantity = Number(input.value || 0);
        var max = Number(input.max || 0);
        var output = card.querySelector("[data-quantity-output]");
        var decrease = card.querySelector('[data-quantity-action="decrease"]');
        var increase = card.querySelector('[data-quantity-action="increase"]');
        var subtotal = card.querySelector("[data-ticket-subtotal]");
        card.classList.toggle("is-selected", quantity > 0);
        if (output) output.textContent = quantity;
        if (decrease) decrease.disabled = quantity <= 0;
        if (increase) increase.disabled = quantity >= max || max <= 0;
        if (subtotal) subtotal.innerHTML = quantity ? "Subtotal: <strong>" + cents(quantity * Number(input.dataset.ticketPrice || 0)) + "</strong>" : "";
      });
      renderCheckoutSummary(summary, selected, form.dataset.eventTitle || "La experiencia");
      var total = selected.reduce(function (sum, input) { return sum + Number(input.value || 0) * Number(input.dataset.ticketPrice || 0); }, 0);
      var validation = checkoutValidation(form, selected);
      status.textContent = isSubmitting ? "Preparando el pago seguro..." : validation.message;
      submit.disabled = isSubmitting || !validation.valid;
      submit.innerHTML = isSubmitting
        ? 'Conectando con el TPV <span aria-hidden="true">→</span>'
        : selected.length
          ? 'Pagar ' + cents(total) + ' <span aria-hidden="true">→</span>'
          : 'Continuar al pago <span aria-hidden="true">→</span>';
    }

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var selected = Array.from(form.querySelectorAll("[data-ticket-type]")).filter(function (input) { return Number(input.value || 0) > 0; });
      var validation = checkoutValidation(form, selected);
      if (!validation.valid || isSubmitting) {
        form.querySelectorAll(".checkout-field input").forEach(function (input) { updateCheckoutField(input, true); });
        status.textContent = validation.message;
        return;
      }
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
        renderCheckoutPreview(form, payload, form.dataset.eventTitle || "Este evento", layout, confirmation);
        return;
      }
      isSubmitting = true;
      refreshCheckout();
      request(api + "/orders", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      }).then(function (data) {
        if (data.payment && data.payment.free) {
          window.location.assign(data.payment.url);
          return;
        }
        submitPaymentForm(data.payment);
      }).catch(function (error) {
        status.textContent = error.message;
        isSubmitting = false;
        refreshCheckout();
      });
    });
  }

  function submitPaymentForm(payment) {
    if (!payment || !payment.url || !payment.fields) throw new Error("No se pudo preparar la conexión segura con el TPV.");
    var redsysForm = document.createElement("form");
    redsysForm.method = "POST";
    redsysForm.action = payment.url;
    Object.keys(payment.fields).forEach(function (key) {
      var input = document.createElement("input");
      input.type = "hidden";
      input.name = key;
      input.value = payment.fields[key];
      redsysForm.appendChild(input);
    });
    document.body.appendChild(redsysForm);
    redsysForm.submit();
  }

  function checkoutTicketMarkup(type) {
    var price = Number(type.final_price_cents != null ? type.final_price_cents : type.price_cents || 0);
    var available = Math.max(0, Number(type.available || 0));
    var max = Math.max(0, Math.min(available, Number(type.max_per_order || available)));
    var unavailable = max === 0;
    var availability = unavailable ? "Sin disponibilidad" : "Plazas limitadas";
    return [
      '<article class="checkout-ticket' + (unavailable ? ' is-unavailable' : '') + '" data-ticket-card>',
      '<div class="checkout-ticket-copy"><h3>' + escapeHtml(type.name) + '</h3>',
      type.description ? '<p>' + escapeHtml(type.description) + '</p>' : '',
      '<div class="checkout-ticket-meta"><span>' + escapeHtml(availability) + '</span>' + (type.requires_promo ? '<span>Código necesario</span>' : '') + '</div></div>',
      '<div class="checkout-ticket-controls"><div class="checkout-ticket-price">' + cents(price) + '<small>por persona</small></div>',
      '<div class="quantity-stepper"><output data-quantity-output aria-live="polite">0</output><div class="quantity-stepper-actions"><button type="button" data-quantity-action="increase" aria-label="Añadir una entrada de ' + escapeAttr(type.name) + '"' + (unavailable ? ' disabled' : '') + '>+</button><button type="button" data-quantity-action="decrease" aria-label="Restar una entrada de ' + escapeAttr(type.name) + '" disabled>−</button></div></div>',
      '<input class="checkout-quantity-input" min="0" max="' + max + '" value="0" type="number" name="ticket_' + type.id + '" data-ticket-type="' + type.id + '" data-ticket-name="' + escapeAttr(type.name) + '" data-ticket-price="' + price + '">',
      '<div class="checkout-ticket-subtotal" data-ticket-subtotal></div></div></article>'
    ].join("");
  }

  function checkoutValidation(form, selected) {
    if (!selected.length) return { valid: false, message: "Selecciona al menos una entrada para continuar." };
    var requiredFields = [form.first_name, form.last_name, form.email, form.phone];
    if (requiredFields.some(function (input) { return !checkoutFieldValid(input); })) return { valid: false, message: "Completa los datos obligatorios para continuar." };
    if (!form.privacy_accepted.checked) return { valid: false, message: "Acepta la política de privacidad para continuar." };
    if (!form.terms_accepted.checked) return { valid: false, message: "Acepta las condiciones de compra, cancelación y acceso." };
    return { valid: true, message: "" };
  }

  function checkoutFieldValid(input) {
    return !!input && !!input.value.trim() && (input.type !== "email" || input.validity.valid);
  }

  function updateCheckoutField(input, touched) {
    var field = input.closest(".checkout-field");
    var error = field.querySelector(".checkout-field-error");
    if (!touched) return;
    var valid = checkoutFieldValid(input);
    field.classList.toggle("has-error", !valid);
    input.setAttribute("aria-invalid", String(!valid));
    if (error) error.textContent = valid ? "" : (input.type === "email" && input.value.trim() ? "Introduce un correo electrónico válido." : "Este campo es obligatorio.");
  }

  function renderCheckoutSummary(target, selected, eventTitle) {
    if (!target) return;
    if (!selected.length) {
      target.innerHTML = '<p>Aún no has seleccionado entradas.</p><small>Añade al menos una entrada para continuar con la reserva.</small>';
      return;
    }
    var total = selected.reduce(function (sum, input) { return sum + Number(input.value || 0) * Number(input.dataset.ticketPrice || 0); }, 0);
    target.innerHTML = '<p class="checkout-summary-event">' + escapeHtml(eventTitle) + '</p><ul class="checkout-summary-items">' + selected.map(function (input) {
      var quantity = Number(input.value || 0);
      var price = Number(input.dataset.ticketPrice || 0);
      return '<li class="checkout-summary-item"><span><strong>' + escapeHtml(input.dataset.ticketName) + '</strong><small>' + quantity + ' × ' + cents(price) + '</small></span><strong>' + cents(quantity * price) + '</strong></li>';
    }).join("") + '</ul><div class="checkout-summary-total"><span>Total</span><strong>' + cents(total) + '</strong></div>';
  }

  function renderCheckoutPreview(form, payload, eventTitle, layout, confirmation) {
    var selectedInputs = Array.from(form.querySelectorAll("[data-ticket-type]")).filter(function (input) { return Number(input.value || 0) > 0; });
    var total = selectedInputs.reduce(function (sum, input) { return sum + Number(input.value || 0) * Number(input.dataset.ticketPrice || 0); }, 0);
    var itemRows = selectedInputs.map(function (input) {
      return '<li><span>' + Number(input.value) + ' × ' + escapeHtml(input.dataset.ticketName) + '</span><strong>' + cents(Number(input.value) * Number(input.dataset.ticketPrice || 0)) + '</strong></li>';
    }).join("");
    layout.hidden = true;
    confirmation.hidden = false;
    confirmation.innerHTML = [
      '<span class="ticket-eyebrow">Modo de pruebas</span>',
      '<h2>Así vería <em>tu pedido</em> la persona asistente</h2>',
      '<p class="ticket-copy">Vas a continuar al TPV seguro de <strong>Redsys TEST</strong> para completar esta operación aislada. No se realizará ningún cargo real.</p>',
      '<div class="ticket-preview-summary"><div><span>Contacto</span><strong>' + escapeHtml((payload.first_name + " " + payload.last_name).trim() || "Nombre de ejemplo") + '</strong><small>' + escapeHtml(payload.email || "correo@ejemplo.com") + '</small></div><div><span>Importe total</span><strong>' + cents(total) + '</strong><small>Las entradas y los envíos se marcarán como prueba.</small></div></div>',
      '<ul class="ticket-preview-items">' + itemRows + '</ul>',
      '<div class="checkout-preview-actions"><button class="ticket-btn primary" type="button" data-start-test-payment>Pagar ' + cents(total) + ' <span aria-hidden="true">→</span></button><button class="ticket-btn" type="button" data-restart-checkout-preview>Volver a editar la compra</button><a class="checkout-preview-editor-link" href="/admin/entradas/">Volver al editor</a></div><p class="checkout-security">Pago seguro en entorno de pruebas · No se realizará ningún cargo real.</p>'
    ].join("");
    confirmation.querySelector("[data-restart-checkout-preview]").addEventListener("click", function () { layout.hidden = false; confirmation.hidden = true; form.querySelector("[data-quantity-action]").focus(); });
    confirmation.querySelector("[data-start-test-payment]").addEventListener("click", function (event) {
      var button = event.currentTarget;
      button.disabled = true;
      button.textContent = "Conectando con Redsys TEST…";
      var testSessionKey = "perigallo-test-checkout-" + String(form.dataset.previewEventId || "event");
      var testSessionId = sessionStorage.getItem(testSessionKey);
      if (!testSessionId) {
        testSessionId = (window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : String(Date.now()) + "-" + Math.random()).replace(/[^A-Za-z0-9_-]/g, "");
        sessionStorage.setItem(testSessionKey, testSessionId);
      }
      payload.test_session_id = testSessionId;
      adminRequest(api + "/admin/events/" + encodeURIComponent(form.dataset.previewEventId) + "/test-orders", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      }).then(function (data) {
        if (data.payment && data.payment.free) {
          window.location.assign(data.payment.url);
          return;
        }
        submitPaymentForm(data.payment);
      }).catch(function (error) {
        button.disabled = false;
        button.innerHTML = "Pagar " + cents(total) + ' <span aria-hidden="true">→</span>';
        var notice = document.createElement("p");
        notice.className = "ticket-status";
        notice.textContent = error.message;
        button.closest(".checkout-preview-actions").appendChild(notice);
      });
    });
  }

  function adminRequest(url, options) {
    options = options || {};
    return request(api + "/admin/session").then(function (session) {
      if (!session.authenticated || !session.csrf) throw new Error("La sesión del editor ha caducado. Vuelve a iniciar sesión para ejecutar la prueba.");
      options.headers = Object.assign({}, options.headers || {}, { "X-CSRF-Token": session.csrf });
      return request(url, options);
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
      var tickets = order.tickets || [];
      root.innerHTML = [
        '<div class="ticket-panel">',
        order.is_test ? '<p class="ticket-environment">Entorno de pruebas · No se realiza ningun cargo real</p>' : '',
        '<span class="ticket-eyebrow">Pedido ' + escapeHtml(order.reference || order.status) + '</span>',
        '<h1 class="ticket-title">Tus entradas están <em>listas</em></h1>',
        '<p class="ticket-copy">' + escapeHtml(order.name) + ', hemos preparado ' + tickets.length + ' ' + (tickets.length === 1 ? 'entrada' : 'entradas') + ' para tu experiencia. Guárdalas en tu teléfono o descárgalas ahora.</p>',
        '<dl class="ticket-order-summary"><div><dt>Pedido</dt><dd>' + escapeHtml(order.reference || '—') + '</dd></div><div><dt>Importe pagado</dt><dd>' + cents(order.total_cents) + '</dd></div><div><dt>Correo</dt><dd>' + escapeHtml(deliveryLabel(order, "email")) + '</dd></div><div><dt>WhatsApp</dt><dd>' + escapeHtml(deliveryLabel(order, "whatsapp")) + '</dd></div></dl>',
        '<div class="ticket-actions ticket-delivery-actions"><button class="ticket-btn primary" type="button" data-download-all>Descargar todas las entradas</button><button class="ticket-btn" type="button" data-resend-email>Enviar de nuevo por correo</button><button class="ticket-btn" type="button" data-resend-whatsapp>Enviar por WhatsApp</button><a class="ticket-btn" href="#entradas">Ver detalles del pedido</a></div>',
        '<p class="ticket-delivery-note">Las entradas se han preparado para <strong>' + escapeHtml(order.email) + '</strong>. Presenta el QR en el acceso: cada código es válido para una sola entrada.</p>',
        '<div class="ticket-list" id="entradas">' + tickets.map(function (ticket, index) { return ticketPass(ticket, order.is_test, index + 1); }).join("") + '</div>',
        '</div>'
      ].join("");
      bindOrderActions(root, order, token);
    }).catch(function (error) {
      root.innerHTML = '<p class="ticket-status">' + escapeHtml(error.message) + '</p>';
    });
  }

  function initPaymentResult() {
    var root = document.querySelector("[data-payment-result]");
    if (!root) return;
    var token = qs("token");
    var isFailureReturn = root.dataset.paymentResult === "error";
    if (!token) {
      root.innerHTML = '<p class="ticket-status">No hemos podido identificar el pedido. Vuelve a eventos o habla con Perigallo.</p>';
      return;
    }
    var attempts = 0;
    var maxAttempts = 12;
    function render(order) {
      var paid = order.payment_status === "paid" || order.status === "paid";
      var failed = order.payment_status === "failed" || order.payment_status === "cancelled" || order.status === "denied" || order.status === "cancelled";
      if (paid) {
        root.innerHTML = [
          '<span class="ticket-eyebrow">Pago validado por Redsys</span>',
          '<h1 class="ticket-title">Tu experiencia está <em>confirmada</em></h1>',
          '<p class="ticket-copy">El pago ha sido validado y tus entradas ya están preparadas. Referencia: <strong>' + escapeHtml(order.reference || order.status) + '</strong>.</p>',
          order.is_test ? '<p class="ticket-status">Operación de prueba: no se ha realizado ningún cargo real.</p>' : '',
          '<div class="ticket-actions"><a class="ticket-btn primary" href="/entradas/pedido/?token=' + encodeURIComponent(token) + '">Ver mis entradas</a><a class="ticket-btn" href="/eventos/">Volver a eventos</a></div>'
        ].join("");
        window.setTimeout(function () {
          window.location.replace("/entradas/pedido/?token=" + encodeURIComponent(token));
        }, 900);
        return true;
      }
      if (failed) {
        root.innerHTML = [
          '<span class="ticket-eyebrow">Pago no confirmado</span>',
          '<h1 class="ticket-title">No se ha confirmado <em>el pago</em></h1>',
          '<p class="ticket-copy">No se han generado entradas. Puedes volver a la experiencia e intentarlo de nuevo o hablar con Perigallo.</p>',
          '<div class="ticket-actions"><a class="ticket-btn primary" href="/eventos/">Volver a eventos</a><a class="ticket-btn" href="https://wa.me/34691499985" target="_blank" rel="noopener noreferrer">WhatsApp</a></div>'
        ].join("");
        return true;
      }
      root.innerHTML = [
        '<span class="ticket-eyebrow">' + (isFailureReturn ? 'Pago pendiente de confirmación' : 'Validando pago') + '</span>',
        '<h1 class="ticket-title">Estamos confirmando <em>tu pago</em></h1>',
        '<p class="ticket-copy">Esperamos la notificación oficial de Redsys antes de emitir las entradas. Esta página se actualizará automáticamente.</p>',
        '<p class="ticket-status">Conexión segura con Redsys en curso.</p>'
      ].join("");
      return false;
    }
    function check() {
      request(api + "/orders/" + encodeURIComponent(token)).then(function (data) {
        if (render(data.order)) return;
        if (attempts >= maxAttempts) {
          root.innerHTML = [
            '<span class="ticket-eyebrow">Confirmación pendiente</span>',
            '<h1 class="ticket-title">Estamos revisando <em>tu pago</em></h1>',
            '<p class="ticket-copy">No vuelvas a pagar. Aún no hemos recibido una confirmación válida del banco, pero puedes comprobar el estado de nuevo.</p>',
            '<div class="ticket-actions"><button class="ticket-btn primary" type="button" data-retry-payment-status>Comprobar de nuevo</button><a class="ticket-btn" href="/entradas/pedido/?token=' + encodeURIComponent(token) + '">Ver mi pedido</a><a class="ticket-btn" href="https://wa.me/34691499985" target="_blank" rel="noopener noreferrer">Contactar</a></div>'
          ].join("");
          root.querySelector("[data-retry-payment-status]").addEventListener("click", function () { attempts = 0; check(); });
          return;
        }
        attempts += 1;
        window.setTimeout(check, 2500);
      }).catch(function (error) {
        root.innerHTML = '<p class="ticket-status">' + escapeHtml(error.message) + '</p>';
      });
    }
    check();
  }

  function deliveryLabel(order, channel) {
    if (channel === "email" && order.email_delivery) {
      return ({ sent: "Envío solicitado", pending: "Pendiente de envío", failed: "No se pudo enviar" })[order.email_delivery.status] || "Pendiente";
    }
    var delivery = (order.deliveries || []).find(function (item) { return item.channel === channel; });
    if (!delivery) return channel === "whatsapp" ? "No configurado" : "Pendiente";
    return ({ sent: "Enviado", delivered: "Entregado", read: "Leído", queued: "Pendiente de envío", pending: "Pendiente de envío", failed: "No se pudo enviar", not_configured: "No configurado" })[delivery.status] || "Pendiente";
  }

  function bindOrderActions(root, order, token) {
    var all = root.querySelector("[data-download-all]");
    if (all) all.addEventListener("click", function () { downloadOrderPdf(order, all); });
    var resend = root.querySelector("[data-resend-email]");
    if (resend) resend.addEventListener("click", function () {
      resend.disabled = true;
      resend.textContent = "Preparando envío...";
      request(api + "/orders/" + encodeURIComponent(token) + "/resend-email", { method: "POST" })
        .then(function (data) { resend.textContent = "Correo solicitado"; root.querySelector(".ticket-delivery-note").textContent = data.message; })
        .catch(function (error) { resend.disabled = false; resend.textContent = "Enviar de nuevo por correo"; root.querySelector(".ticket-delivery-note").textContent = error.message; });
    });
    var whatsapp = root.querySelector("[data-resend-whatsapp]");
    if (whatsapp) whatsapp.addEventListener("click", function () {
      whatsapp.disabled = true;
      whatsapp.textContent = "Comprobando envío...";
      request(api + "/orders/" + encodeURIComponent(token) + "/resend-whatsapp", { method: "POST" })
        .then(function (data) { whatsapp.textContent = data.status === "sent" ? "WhatsApp enviado" : "WhatsApp no configurado"; root.querySelector(".ticket-delivery-note").textContent = data.message; })
        .catch(function (error) { whatsapp.disabled = false; whatsapp.textContent = "Enviar por WhatsApp"; root.querySelector(".ticket-delivery-note").textContent = error.message; });
    });
    root.querySelectorAll("[data-download-ticket]").forEach(function (button) {
      button.addEventListener("click", function () { downloadTicketPdf(order, Number(button.dataset.downloadTicket), button); });
    });
  }

  function ticketPass(ticket, isTest, number) {
    var status = ({ issued: "Válida", used: "Utilizada", cancelled: "Cancelada", refunded: "Reembolsada", blocked: "Bloqueada" })[ticket.status] || ticket.status;
    var qr = ticket.qr_url ? '<img src="' + escapeHtml(qrDataUrl(ticket.qr_url)) + '" alt="QR de acceso para la entrada ' + escapeHtml(ticket.public_code) + '">' : '<span>QR<br>pendiente</span>';
    return '<article class="ticket-pass"><div class="ticket-pass-main">' +
      '<span class="ticket-eyebrow">Entrada ' + String(number).padStart(2, "0") + ' · ' + escapeHtml(status) + '</span>' +
      '<h3>' + escapeHtml(ticket.event_title) + '</h3>' +
      '<dl class="ticket-pass-details"><div><dt>Fecha</dt><dd>' + escapeHtml(fmtDate(ticket.starts_at)) + '</dd></div>' + (ticket.doors_open_at ? '<div><dt>Apertura</dt><dd>' + escapeHtml(fmtTime(ticket.doors_open_at)) + '</dd></div>' : '') + '<div><dt>Lugar</dt><dd>' + escapeHtml([ticket.location, ticket.address, ticket.locality].filter(Boolean).join(", ") || "Por confirmar") + '</dd></div><div><dt>Tipo</dt><dd>' + escapeHtml(ticket.ticket_type_name || "Entrada") + '</dd></div><div><dt>Código</dt><dd class="ticket-code">' + escapeHtml(ticket.public_code) + '</dd></div></dl>' +
      '<p class="ticket-access-copy">Presenta este código en el acceso. El QR es válido para un único acceso.</p>' +
      '<button class="ticket-btn" type="button" data-download-ticket="' + (number - 1) + '">Descargar entrada</button></div>' +
      '<div class="ticket-qr">' + qr + '<small>' + escapeHtml(ticket.public_code) + '</small></div></article>';
  }

  function qrDataUrl(value) {
    if (typeof window.qrcode !== "function") return "";
    var qr = window.qrcode(0, "M");
    qr.addData(value);
    qr.make();
    return qr.createDataURL(6, 0);
  }

  function qrPng(value) {
    return new Promise(function (resolve, reject) {
      var source = qrDataUrl(value);
      if (!source) { reject(new Error("No se pudo preparar el QR.")); return; }
      var image = new Image();
      image.onload = function () {
        var canvas = document.createElement("canvas");
        canvas.width = image.width;
        canvas.height = image.height;
        canvas.getContext("2d").drawImage(image, 0, 0);
        resolve(canvas.toDataURL("image/png"));
      };
      image.onerror = function () { reject(new Error("No se pudo preparar el QR.")); };
      image.src = source;
    });
  }

  function imageDataUrl(source) {
    return new Promise(function (resolve, reject) {
      var image = new Image();
      image.onload = function () {
        var canvas = document.createElement("canvas");
        canvas.width = image.naturalWidth;
        canvas.height = image.naturalHeight;
        canvas.getContext("2d").drawImage(image, 0, 0);
        resolve(canvas.toDataURL("image/png"));
      };
      image.onerror = function () { reject(new Error("No se pudo cargar el logotipo.")); };
      image.src = source;
    });
  }

  function ticketLogo() {
    return imageDataUrl("/assets/images/perigallo-logo-original.png").catch(function () { return null; });
  }

  function downloadTicketPdf(order, index, button) {
    var ticket = (order.tickets || [])[index];
    if (!ticket || !ticket.qr_url || !window.jspdf) return;
    if (button) { button.disabled = true; button.textContent = "Generando PDF..."; }
    Promise.all([qrPng(ticket.qr_url), ticketLogo()]).then(function (assets) {
      var qr = assets[0];
      var logo = assets[1];
      var pdf = new window.jspdf.jsPDF({ orientation: "portrait", unit: "mm", format: "a4" });
      drawTicketPdf(pdf, order, ticket, qr, 1, 1, logo);
      savePdf(pdf, pdfName(ticket));
    }).catch(function (error) { window.alert(error.message); }).finally(function () { if (button) { button.disabled = false; button.textContent = "Descargar entrada"; } });
  }

  function downloadOrderPdf(order, button) {
    var tickets = (order.tickets || []).filter(function (ticket) { return !!ticket.qr_url; });
    if (!tickets.length || !window.jspdf) return;
    button.disabled = true;
    button.textContent = "Generando PDF...";
    Promise.all([Promise.all(tickets.map(function (ticket) { return qrPng(ticket.qr_url); })), ticketLogo()]).then(function (assets) {
      var qrs = assets[0];
      var logo = assets[1];
      var pdf = new window.jspdf.jsPDF({ orientation: "portrait", unit: "mm", format: "a4" });
      tickets.forEach(function (ticket, index) { if (index) pdf.addPage(); drawTicketPdf(pdf, order, ticket, qrs[index], index + 1, tickets.length, logo); });
      savePdf(pdf, "perigallo-pedido-" + safeName(order.reference || "entradas") + ".pdf");
    }).catch(function (error) { window.alert(error.message); }).finally(function () { button.disabled = false; button.textContent = "Descargar todas las entradas"; });
  }

  function drawTicketPdf(pdf, order, ticket, qr, index, total, logo) {
    var deepTeal = [27, 50, 55];
    var ivory = [246, 242, 230];
    var champagne = [210, 181, 150];
    var muted = [222, 212, 194];
    var title = ticket.event_title || "Perigallo";
    var subtitle = ticket.event_subtitle || "Una experiencia gastronómica de Perigallo.";
    var date = fmtDate(ticket.starts_at);
    var place = [ticket.location, ticket.address, ticket.locality].filter(Boolean).join(", ") || "Por confirmar";

    pdf.setFillColor.apply(pdf, deepTeal); pdf.rect(0, 0, 210, 297, "F");
    pdf.setDrawColor.apply(pdf, champagne); pdf.setLineWidth(0.25); pdf.rect(10, 10, 190, 277);

    if (logo) {
      pdf.addImage(logo, "PNG", 93, 18, 24, 22);
    } else {
      pdf.setTextColor.apply(pdf, ivory); pdf.setFont("times", "normal"); pdf.setFontSize(17); pdf.text("Perigallo", 105, 31, { align: "center" });
    }

    pdf.setTextColor.apply(pdf, champagne); pdf.setFont("helvetica", "normal"); pdf.setFontSize(7); pdf.setCharSpace(0.8);
    pdf.text("ENTRADA OFICIAL · EXPERIENCIA PERIGALLO", 105, 48, { align: "center" }); pdf.setCharSpace(0);

    pdf.setTextColor.apply(pdf, ivory); pdf.setFont("times", "normal"); pdf.setFontSize(27);
    var titleLines = pdf.splitTextToSize(title, 158).slice(0, 2);
    pdf.text(titleLines, 105, 61, { align: "center", lineHeightFactor: 1.02 });
    var titleBottom = 61 + ((titleLines.length - 1) * 9.5);

    pdf.setFont("helvetica", "normal"); pdf.setFontSize(9); pdf.setTextColor.apply(pdf, muted);
    var subtitleLines = pdf.splitTextToSize(subtitle, 148).slice(0, 2);
    pdf.text(subtitleLines, 105, titleBottom + 10, { align: "center", lineHeightFactor: 1.25 });
    var dateY = titleBottom + 10 + ((subtitleLines.length - 1) * 4.5) + 10;

    pdf.setDrawColor.apply(pdf, champagne); pdf.setLineWidth(0.2); pdf.roundedRect(44, dateY - 6, 122, 13, 2, 2, "S");
    pdf.setTextColor.apply(pdf, champagne); pdf.setFont("helvetica", "normal"); pdf.setFontSize(6.5); pdf.setCharSpace(0.7);
    pdf.text("FECHA Y HORA", 105, dateY - 0.5, { align: "center" }); pdf.setCharSpace(0);
    pdf.setTextColor.apply(pdf, ivory); pdf.setFontSize(9); pdf.text(date || "Fecha por confirmar", 105, dateY + 4, { align: "center" });

    var qrY = dateY + 16;
    pdf.setFillColor.apply(pdf, ivory); pdf.roundedRect(68, qrY, 74, 74, 3, 3, "F");
    pdf.addImage(qr, "PNG", 74, qrY + 6, 62, 62);
    pdf.setTextColor.apply(pdf, champagne); pdf.setFont("helvetica", "normal"); pdf.setFontSize(7); pdf.setCharSpace(0.7);
    pdf.text("PRESENTA ESTE CÓDIGO EN EL ACCESO", 105, qrY + 82, { align: "center" }); pdf.setCharSpace(0);
    pdf.setTextColor.apply(pdf, muted); pdf.setFontSize(8); pdf.text("Código válido para un único acceso", 105, qrY + 88, { align: "center" });
    pdf.setFont("courier", "normal"); pdf.setFontSize(8); pdf.setTextColor.apply(pdf, ivory); pdf.text(ticket.public_code || "—", 105, qrY + 95, { align: "center" });

    var infoY = qrY + 102;
    pdf.setDrawColor.apply(pdf, champagne); pdf.setLineWidth(0.15); pdf.line(25, infoY - 6, 185, infoY - 6);
    ticketPdfField(pdf, "FECHA Y HORA", date || "Por confirmar", 25, infoY, 72, champagne, ivory);
    ticketPdfField(pdf, "TIPO DE ENTRADA", ticket.ticket_type_name || "Entrada", 111, infoY, 74, champagne, ivory);
    ticketPdfField(pdf, "LUGAR", place, 25, infoY + 18, 160, champagne, ivory);
    ticketPdfField(pdf, "TITULAR", order.name || "Por confirmar", 25, infoY + 36, 72, champagne, ivory);
    ticketPdfField(pdf, "NÚMERO DE ENTRADA", "Entrada " + String(index).padStart(2, "0") + " de " + String(total).padStart(2, "0"), 111, infoY + 36, 74, champagne, ivory);

    pdf.setDrawColor.apply(pdf, champagne); pdf.line(25, 270, 185, 270);
    pdf.setTextColor.apply(pdf, muted); pdf.setFont("helvetica", "normal"); pdf.setFontSize(7);
    pdf.text("PEDIDO " + (order.reference || "—"), 25, 278);
    pdf.text("Perigallo · +34 691 499 985 · perigallo.com", 25, 284);
    if (order.is_test) {
      pdf.setTextColor.apply(pdf, champagne); pdf.setFontSize(6.5); pdf.text("ENTORNO DE PRUEBAS · SIN CARGO REAL", 185, 284, { align: "right" });
    }
  }

  function ticketPdfField(pdf, label, value, x, y, width, labelColor, valueColor) {
    pdf.setTextColor.apply(pdf, labelColor); pdf.setFont("helvetica", "normal"); pdf.setFontSize(6.5); pdf.setCharSpace(0.65);
    pdf.text(label, x, y); pdf.setCharSpace(0);
    pdf.setTextColor.apply(pdf, valueColor); pdf.setFontSize(9.5);
    pdf.text(pdf.splitTextToSize(String(value || "—"), width), x, y + 6, { lineHeightFactor: 1.2 });
  }

  function savePdf(pdf, filename) {
    var blob = new Blob([pdf.output("arraybuffer")], { type: "application/pdf" });
    var url = URL.createObjectURL(blob);
    var link = document.createElement("a");
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }

  function pdfName(ticket) { return "perigallo-" + safeName(ticket.event_title || "entrada") + "-" + safeName(ticket.public_code) + ".pdf"; }

  function safeName(value) { return String(value || "entrada").toLowerCase().replace(/[^a-z0-9]+/gi, "-").replace(/^-|-$/g, ""); }

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
  initPaymentResult();
})();

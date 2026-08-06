(function () {
  var api = "/api";
  var money = new Intl.NumberFormat("es-ES", { style: "currency", currency: "EUR" });
  var LOW_AVAILABILITY_THRESHOLD = 8;

  function qs(name) {
    return new URLSearchParams(window.location.search).get(name);
  }

  function publicEventSlug() {
    var fromQuery = qs("slug");
    if (fromQuery) return fromQuery;
    var match = window.location.pathname.match(/^\/(?:experiencias|eventos)\/([a-z0-9-]+)\/?$/i);
    return match ? decodeURIComponent(match[1]) : "";
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

  function fmtTicketDate(value) {
    if (!value) return "";
    var formatted = new Intl.DateTimeFormat("es-ES", { weekday: "short", day: "2-digit", month: "short", year: "numeric" }).format(new Date(value.replace(" ", "T")));
    return formatted.charAt(0).toUpperCase() + formatted.slice(1);
  }

  function fmtAgendaDate(value) {
    if (!value) return "";
    var date = new Date(value.replace(" ", "T"));
    var day = new Intl.DateTimeFormat("es-ES", { weekday: "long", day: "numeric", month: "long", year: "numeric" }).format(date);
    var time = new Intl.DateTimeFormat("es-ES", { hour: "2-digit", minute: "2-digit" }).format(date);
    return day.charAt(0).toUpperCase() + day.slice(1) + " · " + time + " h";
  }

  function cents(value) {
    return money.format((Number(value || 0) / 100));
  }

  function referencePrice(value, salePrice, enabled) {
    var reference = Number(value || 0);
    return enabled !== false && reference > Number(salePrice || 0) ? reference : 0;
  }

  function promotionalLabel(value) {
    return String(value || "Precio especial de lanzamiento");
  }

  function commercialPriceMarkup(type, salePrice, className) {
    var reference = referencePrice(type.reference_price_cents, salePrice, type.show_reference_price);
    return '<div class="' + className + '">' +
      (reference ? '<span class="ticket-reference-price"><span>Valor de la experiencia</span><del>' + cents(reference) + '</del></span>' : '') +
      '<strong>' + cents(salePrice) + '</strong>' +
      (reference ? '<span class="ticket-promo-label">' + escapeHtml(promotionalLabel(type.promotional_label)) + '</span>' : '') +
      '<span>por persona</span></div>';
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
    var href = "/experiencias/" + encodeURIComponent(event.slug) + "/";
    var salePrice = Number(event.price_from_cents || 0);
    var reference = referencePrice(event.reference_price_from_cents, salePrice, true);
    var eventPrice = event.show_price_from !== false && salePrice
      ? (reference ? '<del>' + cents(reference) + '</del><strong>Desde ' + cents(salePrice) + '</strong>' : '<strong>Desde ' + cents(salePrice) + '</strong>')
      : '<strong>Precio por anunciar</strong>';
    return [
      '<article class="event-card">',
      '<a class="event-card-shell" aria-label="Descubrir y comprar entradas para ' + escapeHtml(event.title) + '" href="' + href + '">',
      '<div class="event-card-media" style="background-image:url(' + escapeAttr(event.card_image_url || event.image_url || "/assets/images/finca-la-llaguna-principal.jpg") + ')"></div>',
      '<div class="event-card-body">',
      '<span class="ticket-eyebrow">' + escapeHtml(event.location || "Perigallo") + '</span>',
      '<h3>' + escapeHtml(event.title) + '</h3>',
      '<div class="event-meta"><span>' + escapeHtml(fmtAgendaDate(event.starts_at)) + '</span><span>' + escapeHtml(event.subtitle || "") + '</span></div>',
      '<span class="event-card-divider" aria-hidden="true"></span>',
      '<span class="event-availability">Plazas limitadas</span>',
      '<span class="event-price">' + eventPrice + '</span>',
      '<span class="ticket-btn primary event-card-action">Comprar entradas <b aria-hidden="true">→</b></span>',
      '<span class="event-card-discover">Descubrir la experiencia <b aria-hidden="true">→</b></span>',
      '</div>',
      '</a>',
      '</article>'
    ].join("");
  }

  function initEventsList() {
    var target = document.querySelector("[data-events-list]");
    if (!target) return;
    function loadEvents() {
      target.setAttribute("aria-busy", "true");
      request(api + "/events").then(function (data) {
        if (!data.events.length) {
          target.innerHTML = '<div class="ticket-panel experience-empty"><span class="ticket-eyebrow">Próximamente</span><h2>La próxima experiencia está en camino</h2><p class="ticket-copy">Estamos ultimando la siguiente edición Perigallo. Síguenos para descubrirla antes que nadie.</p><a class="ticket-btn primary" href="https://www.instagram.com/perigallo" target="_blank" rel="noopener noreferrer">Seguir novedades</a></div>';
          return;
        }
        target.innerHTML = data.events.map(eventCard).join("");
      }).catch(function (error) {
        target.innerHTML = '<div class="ticket-panel"><p class="ticket-status">' + escapeHtml(error.message) + '</p><button class="ticket-btn" type="button" data-retry-events>Reintentar</button></div>';
      }).finally(function () {
        target.removeAttribute("aria-busy");
      });
    }
    target.addEventListener("click", function (event) {
      if (event.target.closest("[data-retry-events]")) loadEvents();
    });
    loadEvents();
  }

  function initEventDetail() {
    var root = document.querySelector("[data-event-detail]");
    if (!root) return;
    var preview = root.hasAttribute("data-preview");
    var slug = root.dataset.slug || publicEventSlug();
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
      if (!preview) {
        var publicUrl = window.location.origin + "/experiencias/" + encodeURIComponent(event.slug) + "/";
        document.title = (event.seo_title || event.title) + " | Experiencias Perigallo";
        var canonical = document.querySelector('link[rel="canonical"]');
        if (canonical) canonical.href = publicUrl;
        var ogUrl = document.querySelector('meta[property="og:url"]');
        if (ogUrl) ogUrl.content = publicUrl;
        setEventSchema(event, publicUrl);
      }
      root.innerHTML = renderEventDetail(event, preview);
      initEventPurchaseControls(root);
      initExperienceAccordions(root);
      initIncludedInformationLink(root);
    }).catch(function (error) {
      root.innerHTML = '<p class="ticket-status">' + escapeHtml(error.message) + '</p>';
    });
  }

  function setEventSchema(event, publicUrl) {
    var existing = document.getElementById("event-structured-data");
    if (existing) existing.remove();
    var firstType = (event.ticket_types || [])[0] || null;
    var schema = {
      "@context": "https://schema.org",
      "@type": "Event",
      name: event.title,
      description: event.seo_description || event.short_description || event.description || "",
      url: publicUrl,
      image: event.image_url ? [new URL(event.image_url, window.location.origin).href] : undefined,
      startDate: event.starts_at ? event.starts_at.replace(" ", "T") : undefined,
      endDate: event.ends_at ? event.ends_at.replace(" ", "T") : undefined,
      location: event.location ? { "@type": "Place", name: event.location, address: event.address || undefined } : undefined,
      organizer: { "@type": "Organization", name: "Perigallo", url: window.location.origin },
      offers: firstType ? {
        "@type": "Offer",
        priceCurrency: "EUR",
        price: (Number(firstType.price_cents || firstType.price_final_cents || 0) / 100).toFixed(2),
        url: publicUrl,
        availability: "https://schema.org/InStock"
      } : undefined
    };
    Object.keys(schema).forEach(function (key) { if (schema[key] === undefined || schema[key] === "") delete schema[key]; });
    var script = document.createElement("script");
    script.id = "event-structured-data";
    script.type = "application/ld+json";
    script.textContent = JSON.stringify(schema);
    document.head.appendChild(script);
  }

  function renderEventDetail(event, preview) {
      var types = event.ticket_types || [];
      var gallery = (event.gallery || []).filter(Boolean).map(function (url) { return '<img loading="lazy" src="' + escapeAttr(previewAssetUrl(url, preview)) + '" alt="Detalle de ' + escapeHtml(event.title) + '">'; }).join("");
      var imageUrl = previewAssetUrl(event.image_url || "/assets/images/finca-la-llaguna-principal.jpg", preview);
      var isPerigalla01 = /la\s+perigalla\s*0?1/i.test(String(event.title || ""));
      var experienceName = isPerigalla01 ? "La Perigalla 01" : event.title;
      var experienceUrl = isPerigalla01 ? "/la-perigalla-01/" : "/experiencias/" + encodeURIComponent(event.slug) + "/";
      var storyImageUrl = previewAssetUrl(event.card_image_url || event.image_url || "/assets/images/finca-la-llaguna-principal.jpg", preview);
      var storyText = isPerigalla01
        ? '<p>La Perigalla 01 inaugura una nueva forma de celebrar en Finca La Llaguna.</p><p>Una boda ficticia de inspiración ibicenca que presenta el universo de Perigallo a través de la gastronomía, la música, la puesta en escena y una historia protagonizada por Sofía y Carlos.</p><p>Una noche en formato cóctel, bajo las estrellas y con un protocolo de vestimenta completamente blanca.</p>'
        : textParagraphs(event.short_description || event.description || "");
      var storyVisual = '<a class="event-story-poster" href="' + escapeAttr(experienceUrl) + '" aria-label="Descubrir la experiencia completa de ' + escapeHtml(experienceName) + '"><img loading="lazy" src="' + escapeAttr(storyImageUrl) + '" alt="Cartel de ' + escapeHtml(experienceName) + '"><span class="event-story-poster-cta">Descubrir la experiencia <span aria-hidden="true">→</span></span></a>';
      var ticketCards = types.length ? types.map(function (type) { return ticketTypeRow(type, event, preview); }).join("") : '<p class="ticket-status event-access-empty">Próximamente anunciaremos las entradas.</p>';
      var editionMatch = String(event.title || "").match(/(?:\s|^)0*(\d+)\s*$/);
      var edition = editionMatch ? " · Edición " + String(editionMatch[1]).padStart(2, "0") : "";
      return [
        '<div class="event-detail-layout">',
        '<section class="ticket-detail event-hero" id="experiencia">',
        '<figure class="ticket-detail-media event-hero-media"><img src="' + escapeAttr(imageUrl) + '" alt="Cartel de ' + escapeHtml(event.title) + '" width="1086" height="1448" loading="eager" fetchpriority="high" decoding="async"></figure>',
        '<section class="event-hero-summary">',
        '<div class="event-hero-introduction">',
        '<span class="ticket-eyebrow">Experiencia Perigallo' + edition + '</span>',
        '<h1 class="ticket-title">' + escapeHtml(event.title) + '</h1>',
        event.subtitle ? '<p class="event-subtitle">' + escapeHtml(event.subtitle) + '</p>' : '',
        '</div>',
        '<div class="ticket-copy event-intro event-hero-description">' + textParagraphs(event.short_description || event.description) + '</div>',
        '<ul class="ticket-access-facts event-hero-facts" id="detalles" aria-label="Datos principales de la experiencia">' + accessFacts(event) + '</ul>',
        '</section>',
        '<section class="event-access" id="reservar">',
        '<div class="ticket-types">' + ticketCards + '</div>',
        '</section>',
        '</section>',
        '<section class="event-story event-story-layout event-story-editorial" id="historia"><div class="event-story-copy"><span class="ticket-eyebrow">La experiencia</span><h2>' + escapeHtml(experienceName) + '</h2><div class="ticket-copy event-story-text">' + storyText + '</div></div>' + storyVisual + '</section>',
        gallery ? '<section class="event-gallery" id="galeria">' + gallery + '</section>' : '',
        experienceAccordions(event) ? '<section class="event-public-information event-public-information-accordions" id="faq"><div class="experience-accordions">' + experienceAccordions(event) + '</div></section>' : '',
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
    var destination = preview
      ? "/entradas/checkout/?preview=1&id=" + encodeURIComponent(event.id)
      : "/entradas/checkout/?event=" + encodeURIComponent(event.slug);
    var attributes = ' data-ticket-checkout-link data-ticket-type-id="' + escapeAttr(type.id) + '"';
    if (preview) {
      return '<div class="ticket-access-action"><a class="ticket-btn primary"' + attributes + ' href="' + escapeAttr(destination) + '">Probar recorrido de compra <span aria-hidden="true">→</span></a><p class="ticket-preview-note"><span aria-hidden="true">◦</span> Vista privada: el pedido y el pago se ejecutan en modo de pruebas, sin cargos ni aforo real.</p></div>';
    }
    if ((type.effective_status || type.status) === "on_sale") {
      return '<div class="ticket-access-action"><a class="ticket-btn primary"' + attributes + ' href="' + escapeAttr(destination) + '">Comprar entradas <span aria-hidden="true">→</span></a></div>';
    }
    return '<p class="ticket-access-unavailable">' + escapeHtml(availabilityText(type)) + '</p>';
  }

  function eventQuantityPicker(type, salePrice) {
    var available = Math.max(0, Number(type.available || 0));
    var maximum = Math.max(0, Math.min(available, Number(type.max_per_order || available)));
    var unavailable = maximum === 0;
    return [
      '<div class="event-quantity-picker" data-event-quantity-picker data-ticket-price="' + salePrice + '" data-ticket-maximum="' + maximum + '">',
      '<span class="event-quantity-label">Cantidad</span>',
      '<div class="event-quantity-controls">',
      '<button type="button" data-event-quantity-action="decrease" aria-label="Restar una entrada de ' + escapeAttr(type.name) + '" disabled>−</button>',
      '<output data-event-quantity-output aria-live="polite">' + (unavailable ? '0' : '1') + '</output>',
      '<button type="button" data-event-quantity-action="increase" aria-label="Añadir una entrada de ' + escapeAttr(type.name) + '"' + (unavailable ? ' disabled' : '') + '>+</button>',
      '</div>',
      '<span class="event-quantity-total">Total <strong data-event-quantity-total>' + cents(unavailable ? 0 : salePrice) + '</strong></span>',
      '</div>'
    ].join('');
  }

  function ticketTypeRow(type, event, preview) {
    var availability = availabilityText(type);
    var includesLink = event.included_text ? '<button class="ticket-access-includes" type="button" data-open-included-information>Ver todo lo que incluye<span aria-hidden="true">→</span></button>' : "";
    var dress = shortDetail(event.dress_code);
    var salePrice = Number(type.final_price_cents != null ? type.final_price_cents : type.price_cents || 0);
    return [
      '<article class="ticket-type ticket-access-card">',
      '<div class="ticket-access-heading">',
      '<div class="ticket-access-copy">',
      '<span class="ticket-access-eyebrow">Tu acceso a la experiencia</span>',
      '<h3>' + escapeHtml(type.name) + '</h3>',
      type.description ? '<p>' + escapeHtml(type.description) + '</p>' : '',
      includesLink,
      '</div>',
      '<div class="ticket-access-decision">' + commercialPriceMarkup(type, salePrice, 'ticket-type-price') + eventQuantityPicker(type, salePrice) + ticketPurchaseAction(type, event, preview) + '</div>',
      '</div>',
      '<div class="ticket-access-secondary">',
      dress ? '<p class="ticket-access-dress"><span class="ticket-access-icon" aria-hidden="true">' + accessIcon("dress") + '</span><span><small>Código de vestimenta</small><strong>' + escapeHtml(dress) + '</strong></span></p>' : '',
      '<p class="ticket-access-status"><span class="ticket-access-status-dot" aria-hidden="true"></span><span>' + escapeHtml(availability) + (type.requires_promo ? ' · Código necesario' : '') + '</span></p>',
      '</div>',
      '</article>'
    ].join("");
  }

  function initEventPurchaseControls(root) {
    function updatePicker(picker, quantity) {
      var maximum = Math.max(0, Number(picker.dataset.ticketMaximum || 0));
      var safeQuantity = Math.max(0, Math.min(maximum, quantity));
      var output = picker.querySelector("[data-event-quantity-output]");
      var price = Number(picker.dataset.ticketPrice || 0);
      if (output) output.textContent = safeQuantity;
      var total = picker.querySelector("[data-event-quantity-total]");
      if (total) total.textContent = cents(safeQuantity * price);
      var decrease = picker.querySelector('[data-event-quantity-action="decrease"]');
      var increase = picker.querySelector('[data-event-quantity-action="increase"]');
      if (decrease) decrease.disabled = safeQuantity <= 1;
      if (increase) increase.disabled = safeQuantity >= maximum || maximum === 0;
      var card = picker.closest(".ticket-access-card");
      var checkoutLink = card && card.querySelector("[data-ticket-checkout-link]");
      if (checkoutLink) {
        var url = new URL(checkoutLink.href, window.location.origin);
        url.searchParams.set("quantity", String(safeQuantity));
        url.searchParams.set("ticketType", checkoutLink.dataset.ticketTypeId || "");
        checkoutLink.href = url.pathname + url.search;
      }
    }

    root.querySelectorAll("[data-event-quantity-picker]").forEach(function (picker) {
      var output = picker.querySelector("[data-event-quantity-output]");
      updatePicker(picker, Number(output ? output.textContent : 0));
    });
    root.addEventListener("click", function (event) {
      var button = event.target.closest("[data-event-quantity-action]");
      if (!button || button.disabled || !root.contains(button)) return;
      var picker = button.closest("[data-event-quantity-picker]");
      if (!picker) return;
      var output = picker.querySelector("[data-event-quantity-output]");
      var current = Math.max(0, Number(output ? output.textContent : 0));
      updatePicker(picker, current + (button.dataset.eventQuantityAction === "increase" ? 1 : -1));
    });
  }

  function initCheckout() {
    var form = document.querySelector("[data-ticket-checkout]");
    if (!form) return;
    var preview = qs("preview") === "1";
    var slug = qs("event");
    var previewId = qs("id");
    var requestedQuantity = Math.max(0, Number(qs("quantity") || 0));
    var requestedTicketType = qs("ticketType");
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
    var paymentMethodSection = form.querySelector("[data-payment-method-section]");
    var paymentMethodsBox = form.querySelector("[data-payment-methods]");
    var discountInput = form.querySelector("[name=\"discount_code\"]");
    var applyDiscount = form.querySelector("[data-apply-discount]");
    var clearDiscountButton = form.querySelector("[data-clear-discount]");
    var discountStatus = form.querySelector("[data-discount-status]");
    var appliedDiscount = null;
    var discountFingerprint = "";
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
    Promise.all([
      request(endpoint, preview ? { cache: "no-store" } : undefined),
      request(api + "/payment-methods").catch(function () { return { methods: [{ id: "card", available: true }, { id: "bizum", available: false }] }; })
    ]).then(function (responses) {
      var data = responses[0];
      var paymentOptions = responses[1];
      var event = data.event;
      renderPaymentMethods(paymentMethodsBox, paymentOptions.methods || ["card"]);
      if (paymentMethodSection) paymentMethodSection.hidden = false;
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
      typesBox.innerHTML = (needsCode ? '<div class="checkout-field checkout-promo"><label for="promo_code">Código de acceso</label><input id="promo_code" name="promo_code" autocomplete="off" placeholder="Solo si alguna entrada lo requiere"></div>' : '') + types.map(checkoutTicketMarkup).join("");
      if (requestedQuantity) {
        var selectedInput = Array.prototype.find.call(typesBox.querySelectorAll("[data-ticket-type]"), function (input) {
          return String(input.dataset.ticketType) === String(requestedTicketType);
        }) || (types.length === 1 ? typesBox.querySelector("[data-ticket-type]") : null);
        if (selectedInput) selectedInput.value = Math.min(Number(selectedInput.max || 0), requestedQuantity);
      }
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
    if (paymentMethodsBox) paymentMethodsBox.addEventListener("change", refreshCheckout);
    if (discountInput) discountInput.addEventListener("input", function () {
      if (appliedDiscount && String(appliedDiscount.code || "").toUpperCase() !== discountInput.value.trim().toUpperCase()) clearDiscount("El código ha cambiado. Aplícalo de nuevo para actualizar el total.");
    });
    if (applyDiscount) applyDiscount.addEventListener("click", function () {
      var code = discountInput ? discountInput.value.trim() : "";
      var selected = selectedTicketInputs();
      if (!code) { clearDiscount("Introduce un código para comprobarlo."); return; }
      if (!selected.length) { clearDiscount("Selecciona al menos una entrada antes de aplicar un código."); return; }
      applyDiscount.disabled = true;
      applyDiscount.textContent = "Comprobando…";
      setDiscountStatus("Comprobando las condiciones del código…", false);
      var payload = {
        event_slug: form.dataset.eventSlug,
        email: form.email.value.trim(),
        phone: form.phone.value.trim(),
        discount_code: code,
        items: selectedItemsPayload()
      };
      var validator = preview
        ? adminRequest(api + "/admin/events/" + encodeURIComponent(form.dataset.previewEventId) + "/discounts/validate", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) })
        : request(api + "/discounts/validate", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
      validator.then(function (data) {
        appliedDiscount = data.discount;
        discountFingerprint = selectionFingerprint();
        if (discountInput) discountInput.value = appliedDiscount.code || code.toUpperCase();
        setDiscountStatus((appliedDiscount.message || "Código aplicado.") + " Descuento: " + cents(appliedDiscount.discount_cents) + ".", true);
        refreshCheckout();
      }).catch(function (error) { clearDiscount(error.message || "No se ha podido aplicar este código."); })
        .finally(function () { applyDiscount.disabled = false; applyDiscount.textContent = "Aplicar código"; });
    });
    if (clearDiscountButton) clearDiscountButton.addEventListener("click", function () {
      if (discountInput) discountInput.value = "";
      clearDiscount("Código eliminado.");
    });

    function selectedPaymentMethod() {
      var selected = form.querySelector('input[name="payment_method"]:checked');
      return selected ? selected.value : "card";
    }

    function selectedTicketInputs() {
      return Array.from(form.querySelectorAll("[data-ticket-type]")).filter(function (input) { return Number(input.value || 0) > 0; });
    }

    function selectedItemsPayload() {
      return selectedTicketInputs().map(function (input) { return { ticket_type_id: Number(input.dataset.ticketType), quantity: Number(input.value || 0) }; });
    }

    function selectionFingerprint() {
      return selectedItemsPayload().map(function (item) { return item.ticket_type_id + ":" + item.quantity; }).join("|");
    }

    function setDiscountStatus(message, applied) {
      if (!discountStatus) return;
      discountStatus.textContent = message || "";
      discountStatus.classList.toggle("is-applied", !!applied);
      if (clearDiscountButton) clearDiscountButton.hidden = !applied;
    }

    function clearDiscount(message) {
      appliedDiscount = null;
      discountFingerprint = "";
      setDiscountStatus(message || "", false);
      refreshCheckout();
    }

    function refreshCheckout() {
      var inputs = Array.from(form.querySelectorAll("[data-ticket-type]"));
      var selected = selectedTicketInputs();
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
        if (subtotal) {
          var lineTotal = quantity * Number(input.dataset.ticketPrice || 0);
          var lineReference = quantity * Number(input.dataset.ticketReferencePrice || 0);
          subtotal.innerHTML = quantity ? 'Subtotal especial: <strong>' + cents(lineTotal) + '</strong>' + (lineReference > lineTotal ? ' <span>Valor <del>' + cents(lineReference) + '</del></span>' : '') : '';
        }
      });
      var subtotal = selected.reduce(function (sum, input) { return sum + Number(input.value || 0) * Number(input.dataset.ticketPrice || 0); }, 0);
      if (appliedDiscount && discountFingerprint !== selectionFingerprint()) {
        appliedDiscount = null;
        discountFingerprint = "";
        setDiscountStatus("Has cambiado las entradas. Aplica el código de nuevo para actualizar el descuento.", false);
      }
      var discount = appliedDiscount && appliedDiscount.applied ? Math.min(subtotal, Number(appliedDiscount.discount_cents || 0)) : 0;
      var total = Math.max(0, subtotal - discount);
      renderCheckoutSummary(summary, selected, form.dataset.eventTitle || "La experiencia", { subtotal: subtotal, discount: discount, code: appliedDiscount && appliedDiscount.code, total: total });
      var validation = checkoutValidation(form, selected);
      var paymentMethod = selectedPaymentMethod();
      if (paymentMethodsBox) {
        paymentMethodsBox.querySelectorAll('input[name="payment_method"]').forEach(function (input) {
          input.disabled = isSubmitting || input.dataset.unavailable === "true";
        });
      }
      status.textContent = isSubmitting ? "Preparando el pago seguro..." : validation.message;
      submit.disabled = isSubmitting || !validation.valid;
      submit.innerHTML = isSubmitting
        ? 'Conectando con el TPV <span aria-hidden="true">→</span>'
        : selected.length
          ? 'Pagar ' + cents(total) + ' con ' + paymentMethodLabel(paymentMethod).toLowerCase() + ' <span aria-hidden="true">→</span>'
          : 'Continuar al pago <span aria-hidden="true">→</span>';
    }

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var selected = selectedTicketInputs();
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
        payment_method: selectedPaymentMethod(),
        promo_code: form.promo_code ? form.promo_code.value : "",
        discount_code: appliedDiscount && discountFingerprint === selectionFingerprint() ? (appliedDiscount.code || "") : "",
        items: selectedItemsPayload()
      };
      if (preview) {
        renderCheckoutPreview(form, payload, form.dataset.eventTitle || "Este evento", layout, confirmation, appliedDiscount);
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

  function paymentMethodLabel(method) {
    return method === "bizum" ? "Bizum" : "Tarjeta";
  }

  function renderPaymentMethods(container, methods) {
    if (!container) return;
    var received = Array.isArray(methods) ? methods : [];
    var options = ["card", "bizum"].map(function (id) {
      var option = received.find(function (method) {
        return typeof method === "string" ? method === id : method && method.id === id;
      });
      return {
        id: id,
        available: option ? (typeof option === "string" || option.available !== false) : id === "card"
      };
    });
    container.innerHTML = options.map(function (option) {
      var method = option.id;
      var isBizum = method === "bizum";
      var unavailable = !option.available;
      var title = isBizum ? "Bizum" : "Tarjeta de crédito o débito";
      var copy = unavailable
        ? "Próximamente. Estamos finalizando su activación con la entidad bancaria."
        : isBizum
        ? "Paga de forma rápida y segura con el número vinculado a tu cuenta Bizum."
        : "Pago seguro a través de la pasarela bancaria.";
      return [
        '<label class="checkout-payment-card' + (unavailable ? ' is-unavailable' : '') + (method === "card" ? ' is-selected' : '') + '">',
        '<input type="radio" name="payment_method" value="' + method + '"' + (method === "card" ? ' checked' : '') + (unavailable ? ' disabled data-unavailable="true"' : '') + '>',
        '<span class="checkout-payment-card-content">',
        '<span class="checkout-payment-card-icon" aria-hidden="true">' + (isBizum ? '◉' : '▭') + '</span>',
        '<span><strong' + (isBizum ? ' class="checkout-payment-bizum"' : '') + '>' + title + (unavailable ? '<span class="checkout-payment-unavailable">Próximamente</span>' : '') + '</strong><small>' + copy + '</small></span>',
        '</span>',
        '</label>'
      ].join("");
    }).join("");
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
    var reference = referencePrice(type.reference_price_cents, price, type.show_reference_price);
    var available = Math.max(0, Number(type.available || 0));
    var max = Math.max(0, Math.min(available, Number(type.max_per_order || available)));
    var unavailable = max === 0;
    var availability = unavailable ? "Sin disponibilidad" : "Plazas limitadas";
    return [
      '<article class="checkout-ticket' + (unavailable ? ' is-unavailable' : '') + '" data-ticket-card>',
      '<div class="checkout-ticket-copy"><h3>' + escapeHtml(type.name) + '</h3>',
      type.description ? '<p>' + escapeHtml(type.description) + '</p>' : '',
      '<div class="checkout-ticket-meta"><span>' + escapeHtml(availability) + '</span>' + (type.requires_promo ? '<span>Código necesario</span>' : '') + '</div></div>',
      '<div class="checkout-ticket-controls">' + commercialPriceMarkup(type, price, 'checkout-ticket-price'),
      '<div class="quantity-stepper"><output data-quantity-output aria-live="polite">0</output><div class="quantity-stepper-actions"><button type="button" data-quantity-action="increase" aria-label="Añadir una entrada de ' + escapeAttr(type.name) + '"' + (unavailable ? ' disabled' : '') + '>+</button><button type="button" data-quantity-action="decrease" aria-label="Restar una entrada de ' + escapeAttr(type.name) + '" disabled>−</button></div></div>',
      '<input class="checkout-quantity-input" min="0" max="' + max + '" value="0" type="number" name="ticket_' + type.id + '" data-ticket-type="' + type.id + '" data-ticket-name="' + escapeAttr(type.name) + '" data-ticket-price="' + price + '" data-ticket-reference-price="' + reference + '">',
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

  function renderCheckoutSummary(target, selected, eventTitle, totals) {
    if (!target) return;
    if (!selected.length) {
      target.innerHTML = '<p>Aún no has seleccionado entradas.</p><small>Añade al menos una entrada para continuar con la reserva.</small>';
      return;
    }
    var subtotal = totals && Number.isFinite(totals.subtotal) ? totals.subtotal : selected.reduce(function (sum, input) { return sum + Number(input.value || 0) * Number(input.dataset.ticketPrice || 0); }, 0);
    var discount = totals ? Number(totals.discount || 0) : 0;
    var total = totals && Number.isFinite(totals.total) ? totals.total : subtotal - discount;
    var referenceTotal = selected.reduce(function (sum, input) { return sum + Number(input.value || 0) * Number(input.dataset.ticketReferencePrice || 0); }, 0);
    var savings = referenceTotal > total ? referenceTotal - total : 0;
    target.innerHTML = '<p class="checkout-summary-event">' + escapeHtml(eventTitle) + '</p><ul class="checkout-summary-items">' + selected.map(function (input) {
      var quantity = Number(input.value || 0);
      var price = Number(input.dataset.ticketPrice || 0);
      var reference = Number(input.dataset.ticketReferencePrice || 0);
      return '<li class="checkout-summary-item"><span><strong>' + escapeHtml(input.dataset.ticketName) + '</strong><small>' + quantity + ' × ' + cents(price) + '</small>' + (reference ? '<small class="checkout-summary-reference">Valor: <del>' + cents(reference) + '</del></small>' : '') + '</span><strong>' + cents(quantity * price) + '</strong></li>';
    }).join("") + '</ul>' + (referenceTotal ? '<div class="checkout-summary-reference-total"><span>Valor de la experiencia</span><del>' + cents(referenceTotal) + '</del></div>' : '') + (discount ? '<div class="checkout-summary-subtotal"><span>Subtotal especial</span><strong>' + cents(subtotal) + '</strong></div><div class="checkout-summary-discount"><span>Descuento' + (totals.code ? ' · ' + escapeHtml(totals.code) : '') + '</span><strong>−' + cents(discount) + '</strong></div>' : '') + '<div class="checkout-summary-total"><span>Total a pagar</span><strong>' + cents(total) + '</strong></div>' + (savings ? '<div class="checkout-summary-saving">Ahorro en esta reserva: <strong>' + cents(savings) + '</strong></div>' : '');
  }

  function renderCheckoutPreview(form, payload, eventTitle, layout, confirmation, appliedDiscount) {
    var selectedInputs = Array.from(form.querySelectorAll("[data-ticket-type]")).filter(function (input) { return Number(input.value || 0) > 0; });
    var subtotal = selectedInputs.reduce(function (sum, input) { return sum + Number(input.value || 0) * Number(input.dataset.ticketPrice || 0); }, 0);
    var discount = Number(payload.discount_code && appliedDiscount ? appliedDiscount.discount_cents || 0 : 0);
    var total = Math.max(0, subtotal - discount);
    var referenceTotal = selectedInputs.reduce(function (sum, input) { return sum + Number(input.value || 0) * Number(input.dataset.ticketReferencePrice || 0); }, 0);
    var itemRows = selectedInputs.map(function (input) {
      var reference = Number(input.dataset.ticketReferencePrice || 0) * Number(input.value || 0);
      return '<li><span>' + Number(input.value) + ' × ' + escapeHtml(input.dataset.ticketName) + (reference ? '<small>Valor: <del>' + cents(reference) + '</del></small>' : '') + '</span><strong>' + cents(Number(input.value) * Number(input.dataset.ticketPrice || 0)) + '</strong></li>';
    }).join("");
    var paymentLabel = paymentMethodLabel(payload.payment_method);
    layout.hidden = true;
    confirmation.hidden = false;
    confirmation.innerHTML = [
      '<span class="ticket-eyebrow">Modo de pruebas</span>',
      '<h2>Así vería <em>tu pedido</em> la persona asistente</h2>',
      '<p class="ticket-copy">Vas a continuar al TPV seguro de <strong>Redsys TEST</strong> con <strong>' + escapeHtml(paymentLabel) + '</strong> para completar esta operación aislada. No se realizará ningún cargo real.</p>',
      '<div class="ticket-preview-summary"><div><span>Contacto</span><strong>' + escapeHtml((payload.first_name + " " + payload.last_name).trim() || "Nombre de ejemplo") + '</strong><small>' + escapeHtml(payload.email || "correo@ejemplo.com") + '</small></div><div><span>Importe total</span>' + (referenceTotal ? '<del class="ticket-preview-reference">' + cents(referenceTotal) + '</del>' : '') + '<strong>' + cents(total) + '</strong><small>' + (referenceTotal > total ? 'Ahorro: ' + cents(referenceTotal - total) + ' · ' : '') + (discount ? 'Descuento aplicado · ' : '') + escapeHtml(paymentLabel) + ' · Las entradas y los envíos se marcarán como prueba.</small></div></div>',
      '<ul class="ticket-preview-items">' + itemRows + '</ul>',
      '<div class="checkout-preview-actions"><button class="ticket-btn primary" type="button" data-start-test-payment>Pagar ' + cents(total) + ' con ' + escapeHtml(paymentLabel).toLowerCase() + ' <span aria-hidden="true">→</span></button><button class="ticket-btn" type="button" data-restart-checkout-preview>Volver a editar la compra</button><a class="checkout-preview-editor-link" href="/admin/entradas/">Volver al editor</a></div><p class="checkout-security">Pago seguro en entorno de pruebas · No se realizará ningún cargo real.</p>'
    ].join("");
    confirmation.querySelector("[data-restart-checkout-preview]").addEventListener("click", function () { layout.hidden = false; confirmation.hidden = true; form.querySelector("[data-quantity-action]").focus(); });
    confirmation.querySelector("[data-start-test-payment]").addEventListener("click", function (event) {
      var button = event.currentTarget;
      button.disabled = true;
      button.textContent = "Conectando con Redsys TEST…";
      var testSessionKey = "perigallo-test-checkout-" + String(form.dataset.previewEventId || "event") + "-" + String(payload.payment_method || "card");
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
        button.innerHTML = "Pagar " + cents(total) + " con " + escapeHtml(paymentLabel).toLowerCase() + ' <span aria-hidden="true">→</span>';
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
      renderOrderStatus(root, data.order, token, true);
    }).catch(function (error) {
      root.innerHTML = '<p class="ticket-status">' + escapeHtml(error.message) + '</p>';
    });
  }

  function renderOrderStatus(root, order, token, allowResend) {
      allowResend = allowResend !== false;
      var tickets = order.tickets || [];
      root.innerHTML = [
        '<div class="ticket-panel">',
        order.is_test ? '<p class="ticket-environment">Entorno de pruebas · No se realiza ningun cargo real</p>' : '',
        '<span class="ticket-eyebrow">Pedido ' + escapeHtml(order.reference || order.status) + '</span>',
        '<h1 class="ticket-title">Tus entradas están <em>listas</em></h1>',
        '<p class="ticket-copy">' + escapeHtml(order.name) + ', hemos preparado ' + tickets.length + ' ' + (tickets.length === 1 ? 'entrada' : 'entradas') + ' para tu experiencia. Guárdalas en tu teléfono o descárgalas ahora.</p>',
        '<dl class="ticket-order-summary"><div><dt>Pedido</dt><dd>' + escapeHtml(order.reference || '—') + '</dd></div>' + (Number(order.reference_total_cents || 0) > Number(order.total_cents || 0) ? '<div><dt>Valor de la experiencia</dt><dd><del>' + cents(order.reference_total_cents) + '</del></dd></div>' : '') + '<div><dt>Importe pagado</dt><dd>' + cents(order.total_cents) + '</dd></div><div><dt>Correo</dt><dd>' + escapeHtml(deliveryLabel(order, "email")) + '</dd></div><div><dt>WhatsApp</dt><dd>' + escapeHtml(deliveryLabel(order, "whatsapp")) + '</dd></div></dl>',
        '<div class="ticket-actions ticket-delivery-actions"><button class="ticket-btn primary" type="button" data-download-all>Descargar todas las entradas</button>' + (allowResend ? '<button class="ticket-btn" type="button" data-resend-email>Enviar de nuevo por correo</button><button class="ticket-btn" type="button" data-resend-whatsapp>Enviar por WhatsApp</button>' : '') + '<a class="ticket-btn" href="#entradas">Ver detalles del pedido</a></div>',
        '<p class="ticket-delivery-note">Las entradas se han preparado para <strong>' + escapeHtml(order.email) + '</strong>. Presenta el QR en el acceso: cada código es válido para una sola entrada.</p>',
        '<div class="ticket-list" id="entradas">' + tickets.map(function (ticket, index) { return ticketPass(ticket, order.is_test, index + 1); }).join("") + '</div>',
        '</div>'
      ].join("");
      bindOrderActions(root, order, token, allowResend);
  }

  function initMyTickets() {
    var recovery = document.querySelector("[data-order-recovery]");
    var root = document.querySelector("[data-order-status]");
    if (!recovery || !root) return;
    var token = qs("token");
    if (token) {
      recovery.hidden = true;
      root.hidden = false;
      root.innerHTML = '<div class="ticket-panel">Abriendo tus entradas...</div>';
      request(api + "/orders/access/" + encodeURIComponent(token)).then(function (data) {
        renderOrderStatus(root, data.order, null, false);
      }).catch(function (error) {
        root.innerHTML = '<div class="ticket-panel"><span class="ticket-eyebrow">Enlace no disponible</span><h1 class="ticket-title">Solicita un <em>nuevo acceso</em></h1><p class="ticket-copy">' + escapeHtml(error.message) + '</p><a class="ticket-btn primary" href="/mis-entradas/">Recuperar mis entradas</a></div>';
      });
      return;
    }
    var form = recovery.querySelector("[data-order-recovery-form]");
    var status = recovery.querySelector("[data-order-recovery-status]");
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var email = form.email.value.trim();
      var phone = form.phone.value.trim();
      if (!email && !phone) {
        status.textContent = "Introduce el correo electrónico o teléfono usado en la compra.";
        return;
      }
      var button = form.querySelector('button[type="submit"]');
      button.disabled = true;
      button.textContent = "Comprobando…";
      request(api + "/orders/recover", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ email: email, phone: phone, reference: form.reference.value.trim() }) })
        .then(function (data) { form.reset(); status.textContent = data.message; button.textContent = "Enlace solicitado"; })
        .catch(function (error) { status.textContent = error.message; button.disabled = false; button.textContent = "Enviar enlace seguro"; });
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
          '<p class="ticket-copy">El pago con <strong>' + escapeHtml(paymentMethodLabel(order.payment_method)) + '</strong> ha sido validado y tus entradas ya están preparadas. Referencia: <strong>' + escapeHtml(order.reference || order.status) + '</strong>.</p>',
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

  function bindOrderActions(root, order, token, allowResend) {
    var all = root.querySelector("[data-download-all]");
    if (all) all.addEventListener("click", function () { downloadOrderPdf(order, all); });
    var resend = allowResend === false ? null : root.querySelector("[data-resend-email]");
    if (resend) resend.addEventListener("click", function () {
      resend.disabled = true;
      resend.textContent = "Preparando envío...";
      request(api + "/orders/" + encodeURIComponent(token) + "/resend-email", { method: "POST" })
        .then(function (data) { resend.textContent = "Correo solicitado"; root.querySelector(".ticket-delivery-note").textContent = data.message; })
        .catch(function (error) { resend.disabled = false; resend.textContent = "Enviar de nuevo por correo"; root.querySelector(".ticket-delivery-note").textContent = error.message; });
    });
    var whatsapp = allowResend === false ? null : root.querySelector("[data-resend-whatsapp]");
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
    var place = [ticket.location, ticket.locality].filter(Boolean).join("\n") || "Por confirmar";
    var dressCode = ticketDressCode(ticket.dress_code);

    pdf.setFillColor.apply(pdf, deepTeal); pdf.rect(0, 0, 210, 297, "F");
    pdf.setDrawColor.apply(pdf, champagne); pdf.setLineWidth(0.25); pdf.rect(8, 7, 194, 283);

    if (logo) {
      pdf.addImage(logo, "PNG", 94, 15, 22, 20);
    } else {
      pdf.setTextColor.apply(pdf, ivory); pdf.setFont("times", "normal"); pdf.setFontSize(19); pdf.text("Perigallo", 105, 29, { align: "center" });
    }

    pdf.setDrawColor.apply(pdf, champagne); pdf.setLineWidth(0.3); pdf.line(98, 41, 112, 41);
    pdf.setTextColor.apply(pdf, champagne); pdf.setFont("helvetica", "normal"); pdf.setFontSize(8.2); pdf.setCharSpace(0);
    pdf.text("ENTRADA OFICIAL", 105, 49, { align: "center" });
    pdf.setFontSize(7.1); pdf.text("EXPERIENCIA PERIGALLO", 105, 55, { align: "center" });

    pdf.setTextColor.apply(pdf, ivory); pdf.setFont("times", "normal"); pdf.setFontSize(30);
    var titleLines = pdf.splitTextToSize(title, 158).slice(0, 2);
    pdf.text(titleLines, 105, 72, { align: "center", lineHeightFactor: 1.02 });
    var titleBottom = 72 + ((titleLines.length - 1) * 10.5);

    pdf.setFont("helvetica", "normal"); pdf.setFontSize(11.5); pdf.setTextColor.apply(pdf, muted);
    var subtitleLines = pdf.splitTextToSize(subtitle, 148).slice(0, 2);
    pdf.text(subtitleLines, 105, titleBottom + 12, { align: "center", lineHeightFactor: 1.2 });
    var qrY = Math.max(96, titleBottom + 12 + ((subtitleLines.length - 1) * 5) + 12);
    pdf.setFillColor.apply(pdf, ivory); pdf.roundedRect(68, qrY, 74, 74, 3, 3, "F");
    pdf.setDrawColor.apply(pdf, champagne); pdf.setLineWidth(0.15); pdf.roundedRect(68, qrY, 74, 74, 3, 3, "S");
    pdf.addImage(qr, "PNG", 74, qrY + 6, 62, 62);
    pdf.setTextColor.apply(pdf, champagne); pdf.setFont("helvetica", "normal"); pdf.setFontSize(8.5); pdf.setCharSpace(0);
    pdf.text("PRESENTA ESTE CÓDIGO", 105, qrY + 80, { align: "center" });
    pdf.text("EN EL ACCESO", 105, qrY + 86, { align: "center" }); pdf.setCharSpace(0);
    pdf.setTextColor.apply(pdf, muted); pdf.setFontSize(9.2); pdf.text("Código válido para un único acceso", 105, qrY + 93, { align: "center" });
    pdf.setFont("courier", "normal"); pdf.setFontSize(9.5); pdf.setTextColor.apply(pdf, ivory); pdf.text(ticket.public_code || "—", 105, qrY + 101, { align: "center" });

    var infoY = qrY + 104;
    var schedule = ticket.starts_at ? fmtTicketDate(ticket.starts_at) + "\n" + fmtTime(ticket.starts_at) + " h" + (ticket.ends_at ? " — " + fmtTime(ticket.ends_at) + " h" : "") : "Por confirmar";
    ticketPdfField(pdf, "calendar", "FECHA · HORARIO", schedule, 25, infoY, 76, 20, champagne, ivory);
    ticketPdfField(pdf, "ticket", "TIPO DE ENTRADA", ticket.ticket_type_name || "Entrada", 109, infoY, 76, 20, champagne, ivory);
    if (dressCode) {
      ticketPdfField(pdf, "dress", "CÓDIGO DE VESTIMENTA", dressCode, 25, infoY + 23, 76, 24, champagne, ivory);
      ticketPdfField(pdf, "pin", "LUGAR", place, 109, infoY + 23, 76, 24, champagne, ivory);
      ticketPdfField(pdf, "person", "TITULAR", order.name || "Por confirmar", 25, infoY + 50, 76, 20, champagne, ivory);
      ticketPdfField(pdf, "entry", "NÚMERO DE ENTRADA", "Entrada " + String(index).padStart(2, "0") + " de " + String(total).padStart(2, "0"), 109, infoY + 50, 76, 20, champagne, ivory);
    } else {
      ticketPdfField(pdf, "pin", "LUGAR", place, 25, infoY + 23, 160, 22, champagne, ivory);
      ticketPdfField(pdf, "person", "TITULAR", order.name || "Por confirmar", 25, infoY + 48, 76, 20, champagne, ivory);
      ticketPdfField(pdf, "entry", "NÚMERO DE ENTRADA", "Entrada " + String(index).padStart(2, "0") + " de " + String(total).padStart(2, "0"), 109, infoY + 48, 76, 20, champagne, ivory);
    }

    pdf.setDrawColor.apply(pdf, champagne); pdf.line(25, 274, 185, 274);
    pdf.setTextColor.apply(pdf, muted); pdf.setFont("helvetica", "normal"); pdf.setFontSize(8);
    pdf.text("PEDIDO " + (order.reference || "—"), 105, 278, { align: "center" });
    pdf.setFontSize(7.5); pdf.text("Perigallo · +34 691 499 985 · perigallo.com", 105, 283, { align: "center" });
    if (order.is_test) {
      pdf.setTextColor.apply(pdf, champagne); pdf.setFontSize(7); pdf.text("ENTORNO DE PRUEBAS · SIN CARGO REAL", 105, 287, { align: "center" });
    }
  }

  function ticketPdfField(pdf, icon, label, value, x, y, width, height, labelColor, valueColor) {
    pdf.setFillColor(31, 57, 62); pdf.setDrawColor.apply(pdf, labelColor); pdf.setLineWidth(0.35); pdf.roundedRect(x, y, width, height, 1.8, 1.8, "FD");
    pdf.setDrawColor.apply(pdf, labelColor); pdf.setLineWidth(0.22); pdf.line(x + 16, y + 4, x + 16, y + height - 4);
    ticketPdfIcon(pdf, icon, x + 4, y + (height / 2) - 4.5, labelColor);
    pdf.setTextColor.apply(pdf, labelColor); pdf.setFont("helvetica", "normal"); pdf.setFontSize(7.1); pdf.setCharSpace(0.32);
    pdf.text(label, x + 20, y + 7); pdf.setCharSpace(0);
    pdf.setTextColor.apply(pdf, valueColor); pdf.setFont("helvetica", "normal"); pdf.setFontSize(10.5);
    var lines = String(value || "—").split("\n").reduce(function (all, line) {
      return all.concat(pdf.splitTextToSize(line, width - 25));
    }, []).slice(0, 2);
    pdf.text(lines, x + 20, y + 14, { lineHeightFactor: 1.1 });
  }

  function ticketPdfIcon(pdf, icon, x, y, color) {
    pdf.setDrawColor.apply(pdf, color); pdf.setLineWidth(0.55);
    if (icon === "calendar") {
      pdf.roundedRect(x, y, 9, 9, 0.9, 0.9, "S"); pdf.line(x, y + 3, x + 9, y + 3); pdf.line(x + 2.3, y - 1.3, x + 2.3, y + 1.4); pdf.line(x + 6.7, y - 1.3, x + 6.7, y + 1.4);
    } else if (icon === "ticket") {
      pdf.roundedRect(x, y + 1, 10, 7, 0.9, 0.9, "S"); pdf.line(x + 3, y + 1, x + 3, y + 8); pdf.line(x + 5.2, y + 3.5, x + 8, y + 3.5); pdf.line(x + 5.2, y + 5.7, x + 8, y + 5.7);
    } else if (icon === "pin") {
      pdf.circle(x + 4.5, y + 3.3, 3.2, "S"); pdf.circle(x + 4.5, y + 3.3, 0.8, "S"); pdf.line(x + 2.2, y + 5.4, x + 4.5, y + 9); pdf.line(x + 6.8, y + 5.4, x + 4.5, y + 9);
    } else if (icon === "person") {
      pdf.circle(x + 4.5, y + 2.5, 2.2, "S"); pdf.line(x, y + 9, x + 9, y + 9); pdf.line(x, y + 9, x + 2.1, y + 5.8); pdf.line(x + 9, y + 9, x + 6.9, y + 5.8);
    } else if (icon === "entry") {
      pdf.roundedRect(x, y + 1, 10, 7, 0.9, 0.9, "S"); pdf.line(x + 3, y + 1, x + 3, y + 8); pdf.line(x + 5.2, y + 3.5, x + 8, y + 3.5); pdf.line(x + 5.2, y + 5.7, x + 8, y + 5.7);
    } else if (icon === "dress") {
      pdf.line(x + 2.2, y + 1.5, x + 4.5, y); pdf.line(x + 4.5, y, x + 6.8, y + 1.5); pdf.line(x + 2.2, y + 1.5, x, y + 4); pdf.line(x + 6.8, y + 1.5, x + 9, y + 4); pdf.line(x, y + 4, x + 1.5, y + 9); pdf.line(x + 9, y + 4, x + 7.5, y + 9); pdf.line(x + 1.5, y + 9, x + 7.5, y + 9);
    }
  }

  function ticketDressCode(value) {
    var source = String(value || "").trim();
    if (!source) return "";
    if (/total\s+white|blanco/i.test(source)) return "Total White\nObligatorio ir de blanco";
    return source;
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
  initMyTickets();
  initPaymentResult();
})();

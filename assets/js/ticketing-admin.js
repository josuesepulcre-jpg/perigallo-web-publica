(function () {
  var api = "/api";
  var state = { csrf: "", role: null, event: null, dirty: false, saving: false, publicDirty: {}, nonPublicDirty: false };
  var mediaState = { root: null, selected: {}, uploading: {}, messages: {}, previews: {}, dragIndex: null };
  var ticketDrawerState = { dirty: false, saving: false };
  var money = new Intl.NumberFormat("es-ES", { style: "currency", currency: "EUR" });

  function request(url, options) {
    options = options || {};
    options.credentials = "same-origin";
    return fetch(url, options).then(function (response) {
      return response.text().then(function (raw) {
        var data;
        try { data = raw ? JSON.parse(raw) : {}; } catch (error) { data = {}; }
        if (!response.ok || !data.ok) {
          var fallback = response.status === 413 ? "El servidor ha rechazado el contenido por tamaño. Aumenta post_max_size en Plesk." : "No se pudo completar la solicitud (HTTP " + response.status + ").";
          var requestError = new Error(data.error || fallback);
          requestError.status = response.status;
          requestError.statusText = response.statusText;
          requestError.responseBody = raw;
          throw requestError;
        }
        return data;
      });
    });
  }

  function jsonRequest(url, method, body) {
    return request(url, { method: method, headers: { "Content-Type": "application/json", "X-CSRF-Token": state.csrf }, body: body === undefined ? undefined : JSON.stringify(body) });
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (char) {
      return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" })[char];
    });
  }

  function cents(value) { return money.format(Number(value || 0) / 100); }
  function q(name) { return new URLSearchParams(window.location.search).get(name); }
  function dateInput(value) { return value ? String(value).replace(" ", "T").slice(0, 16) : ""; }
  function dateText(value) { return value ? new Intl.DateTimeFormat("es-ES", { dateStyle: "medium", timeStyle: "short" }).format(new Date(String(value).replace(" ", "T"))) : "Por definir"; }

  function session() {
    return request(api + "/admin/session").then(function (data) {
      state.csrf = data.csrf || "";
      state.role = data.role || null;
      return data;
    });
  }

  function requireSession(onReady) {
    return session().then(function (data) {
      if (!data.authenticated) {
        var login = document.querySelector("[data-admin-login-wrap]");
        if (login) login.hidden = false;
        return;
      }
      document.querySelectorAll("[data-admin-login-wrap]").forEach(function (node) { node.hidden = true; });
      onReady(data);
    }).catch(showFatal);
  }

  function showFatal(error) {
    var target = document.querySelector("[data-admin-status], [data-editor-notice]");
    if (target) target.textContent = error.message || "No se pudo cargar el panel.";
  }

  function initLogin() {
    var form = document.querySelector("[data-admin-login]");
    if (!form) return;
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var status = form.querySelector("[data-admin-status]");
      status.textContent = "Accediendo...";
      request(api + "/admin/login", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ username: form.username.value, password: form.password.value }) })
        .then(function () { window.location.reload(); })
        .catch(function (error) { status.textContent = error.message; });
    });
  }

  function initLogout() {
    document.querySelectorAll("[data-admin-logout]").forEach(function (button) {
      button.addEventListener("click", function () {
        jsonRequest(api + "/admin/logout", "POST").finally(function () { window.location.href = "/admin/entradas/"; });
      });
    });
  }

  function statusLabel(status) {
    return ({ draft: "Borrador", scheduled: "Programado", published: "Publicado", sold_out: "Agotado", finished: "Finalizado", cancelled: "Cancelado", archived: "Archivado", upcoming: "Próximamente", on_sale: "A la venta", paused: "Pausada", closed: "Cerrada", hidden: "Oculta" })[status] || status;
  }

  function renderEvents(events, filter) {
    var target = document.querySelector("[data-admin-event-list]");
    if (!target) return;
    var term = String(filter || "").toLowerCase().trim();
    var rows = events.filter(function (event) { return !term || [event.title, event.slug, event.status, event.location].join(" ").toLowerCase().includes(term); });
    if (!rows.length) {
      target.innerHTML = '<div class="admin-empty"><strong>No hay eventos todavía.</strong><span>Crea un borrador para empezar a diseñar una fecha.</span></div>';
      return;
    }
    target.innerHTML = rows.map(function (event) {
      var image = event.card_image_url || event.image_url || "/assets/images/perigallo-hero-original-01.jpg";
      return '<article class="admin-event-card" data-event-id="' + Number(event.id) + '">' +
        '<button class="admin-event-cover" data-open-event type="button" style="background-image:url(' + escapeHtml(image) + ')"><span class="status-pill status-' + escapeHtml(event.effective_status) + '">' + escapeHtml(statusLabel(event.effective_status)) + '</span></button>' +
        '<div class="admin-event-card-body"><div><span class="ticket-eyebrow">' + escapeHtml(dateText(event.starts_at)) + '</span><h3>' + escapeHtml(event.title) + '</h3><p>' + escapeHtml(event.location || "Ubicación por definir") + '</p></div>' +
        '<dl class="event-metrics"><div><dt>Vendidas</dt><dd>' + Number(event.sold || 0) + '</dd></div><div><dt>Disponibles</dt><dd>' + Number(event.available || 0) + '</dd></div><div><dt>Facturación</dt><dd>' + cents(event.revenue_cents) + '</dd></div></dl>' +
        '<div class="admin-card-actions"><button class="text-action" data-open-event type="button">Editar</button><button class="text-action" data-copy-link type="button">Copiar enlace</button><details class="action-menu"><summary aria-label="Más acciones">•••</summary><button data-event-action="preview" type="button">Vista previa</button><button data-event-action="publication" type="button">' + (event.visible ? "Despublicar" : "Publicar") + '</button><button data-event-action="duplicate" type="button">Duplicar</button><button data-event-action="archive" type="button">Archivar / eliminar</button></details></div></div></article>';
    }).join("");
  }

  function loadEvents() {
    return request(api + "/admin/events").then(function (data) {
      state.events = data.events || [];
      renderEvents(state.events);
    }).catch(showFatal);
  }

  function initList() {
    var root = document.querySelector("[data-admin-list]");
    if (!root) return;
    requireSession(function (sessionData) {
      if (sessionData.role === "control_acceso") {
        window.location.replace("/admin/entradas/acceso/");
        return;
      }
      root.hidden = false;
      loadEvents();
      document.querySelector("[data-event-search]").addEventListener("input", function (event) { renderEvents(state.events || [], event.target.value); });
      document.querySelector("[data-create-event]").addEventListener("click", function () {
        jsonRequest(api + "/admin/events", "POST", { title: "Nuevo evento" }).then(function (data) { window.location.href = "/admin/entradas/evento/?id=" + data.event.id; }).catch(showFatal);
      });
      root.addEventListener("click", function (event) {
        var card = event.target.closest("[data-event-id]");
        if (!card) return;
        var id = Number(card.dataset.eventId);
        if (event.target.closest("[data-open-event]")) { window.location.href = "/admin/entradas/evento/?id=" + id; return; }
        if (event.target.closest("[data-copy-link]")) {
          navigator.clipboard.writeText(window.location.origin + "/eventos/" + (state.events.find(function (row) { return Number(row.id) === id; }) || {}).slug + "/");
          event.target.textContent = "Enlace copiado";
          return;
        }
        var action = event.target.dataset.eventAction;
        if (!action) return;
        if (action === "preview") window.open("/admin/entradas/vista-previa/?id=" + id, "_blank", "noopener");
        if (action === "publication") jsonRequest(api + "/admin/events/" + id + "/" + ((state.events.find(function (row) { return Number(row.id) === id; }) || {}).visible ? "unpublish" : "publish"), "POST", {}).then(loadEvents).catch(showFatal);
        if (action === "duplicate") jsonRequest(api + "/admin/events/" + id + "/duplicate", "POST", {}).then(function (data) { window.location.href = "/admin/entradas/evento/?id=" + data.event.id; }).catch(showFatal);
        if (action === "archive" && window.confirm("¿Archivar o eliminar este evento? Los eventos con ventas se archivarán para proteger los pedidos.")) jsonRequest(api + "/admin/events/" + id, "DELETE").then(loadEvents).catch(showFatal);
      });
    });
  }

  function editorNotice(message, isError) {
    var target = document.querySelector("[data-editor-notice]");
    if (target) { target.textContent = message || ""; target.classList.toggle("is-error", !!isError); }
  }

  function setDirty(value) {
    state.dirty = value;
    if (!value) { state.publicDirty = {}; state.nonPublicDirty = false; }
    var node = document.querySelector("[data-save-state]");
    if (node) node.textContent = value ? "Cambios sin guardar" : "Guardado";
    refreshPublicInformation();
  }

  function input(form, name) { return form.querySelector('[name="' + name + '"]'); }

  function fillEventForm(eventData) {
    var form = document.querySelector("[data-event-form]");
    if (!form) return;
    Array.prototype.forEach.call(form.elements, function (field) {
      if (!field.name) return;
      var value = eventData[field.name];
      if (field.name === "gallery") value = JSON.stringify(eventData.gallery || []);
      if (field.name === "social_image_url" && !value) value = eventData.seo_image_url || "";
      if (field.name === "seo_image_url" && !value) value = eventData.social_image_url || "";
      if (field.name === "faq") value = (eventData.faq || []).map(function (row) { return typeof row === "object" ? (row.question || "") + " | " + (row.answer || "") : row; }).join("\n");
      if (field.type === "checkbox") field.checked = !!value;
      else if (field.type === "datetime-local") field.value = dateInput(value);
      else if (value != null) field.value = value;
    });
    document.querySelector("[data-editor-title]").textContent = eventData.title || "Nuevo evento";
    document.querySelector("[data-editor-status]").textContent = statusLabel(eventData.effective_status || eventData.status);
    document.querySelector("[data-editor-status]").className = "status-pill status-" + (eventData.effective_status || eventData.status);
    document.querySelector("[data-ticket-count]").textContent = (eventData.ticket_types || []).length;
    renderEventMediaManager();
    refreshPublicInformation();
    refreshPublicationEditor();
  }

  function publicationUrl(slug) {
    return window.location.origin + "/eventos/" + encodeURIComponent(String(slug || "").replace(/^\/+|\/+$/g, ""));
  }

  function normalizePublicationSlug(value) {
    return String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 140);
  }

  function refreshPublicationEditor() {
    var root = document.querySelector("[data-publication-editor]");
    var form = document.querySelector("[data-event-form]");
    if (!root || !form) return;
    var slug = input(form, "slug");
    var status = input(form, "status");
    var schedule = root.querySelector("[data-publication-schedule]");
    var date = input(form, "publication_at");
    var visible = input(form, "visible");
    var unlisted = input(form, "unlisted");
    var linkOnly = input(form, "link_only");
    var canonical = input(form, "canonical_url");
    var customCanonical = root.querySelector("[data-custom-canonical]");
    var url = publicationUrl(slug.value);
    if (!customCanonical.dataset.initialized) {
      customCanonical.checked = !!canonical.value && canonical.value !== url;
      customCanonical.dataset.initialized = "true";
    }
    var supportedStatus = ["draft", "scheduled", "published"].includes(status.value) ? status.value : "published";
    root.querySelectorAll('[name="publication-status-choice"]').forEach(function (choice) { choice.checked = choice.value === supportedStatus; });
    schedule.hidden = status.value !== "scheduled";
    date.disabled = status.value !== "scheduled";
    root.querySelector("[data-public-url-preview]").textContent = slug.value ? url : "Introduce un slug para generar la dirección pública.";
    root.querySelector("[data-open-public-url]").href = url;
    root.querySelector("[data-open-public-url]").toggleAttribute("aria-disabled", !slug.value);
    root.querySelector("[data-copy-public-url]").disabled = !slug.value;
    if (linkOnly.checked) { unlisted.checked = true; unlisted.disabled = true; }
    else unlisted.disabled = !visible.checked;
    linkOnly.disabled = !visible.checked;
    root.querySelectorAll(".publication-setting").forEach(function (row) { row.classList.toggle("is-disabled", !!row.querySelector("input").disabled); });
    if (!customCanonical.checked) canonical.value = url;
    canonical.readOnly = !customCanonical.checked;
    ["title", "description"].forEach(function (kind) {
      var field = input(form, kind === "title" ? "seo_title" : "seo_description");
      var count = root.querySelector('[data-seo-count="' + kind + '"]');
      if (count) count.textContent = field.value.length + " caracteres";
    });
    root.querySelector("[data-seo-preview-title]").textContent = input(form, "seo_title").value || "Título SEO de la experiencia";
    root.querySelector("[data-seo-preview-url]").textContent = url;
    root.querySelector("[data-seo-preview-description]").textContent = input(form, "seo_description").value || "La descripción SEO aparecerá aquí cuando la completes.";
  }

  function initPublicationEditor() {
    var root = document.querySelector("[data-publication-editor]");
    var form = document.querySelector("[data-event-form]");
    if (!root || !form) return;
    var slug = input(form, "slug");
    var status = input(form, "status");
    var canonical = input(form, "canonical_url");
    var customCanonical = root.querySelector("[data-custom-canonical]");
    root.addEventListener("input", function (event) {
      if (event.target === slug) {
        var normalized = normalizePublicationSlug(slug.value);
        slug.value = normalized;
        slug.setCustomValidity(normalized ? "" : "Introduce una dirección pública válida.");
      }
      refreshPublicationEditor();
    });
    root.addEventListener("change", function (event) {
      var target = event.target;
      if (target.name === "publication-status-choice") {
        if (status.value === "published" && target.value === "draft" && !window.confirm("La experiencia dejará de estar disponible públicamente. ¿Quieres pasarla a borrador?")) { refreshPublicationEditor(); return; }
        status.value = target.value;
      }
      if (target === input(form, "link_only") && target.checked) input(form, "unlisted").checked = true;
      if (target === customCanonical && !target.checked) canonical.value = publicationUrl(slug.value);
      refreshPublicationEditor();
    });
    root.querySelector("[data-copy-public-url]").addEventListener("click", function () {
      var url = publicationUrl(slug.value);
      if (navigator.clipboard) navigator.clipboard.writeText(url).then(function () { editorNotice("URL pública copiada."); }).catch(function () { editorNotice("No se pudo copiar la URL.", true); });
    });
    refreshPublicationEditor();
  }

  function parseFaq(value) {
    var rows = [];
    var invalidLines = [];
    String(value || "").split(/\r?\n/).forEach(function (line, index) {
      if (!line.trim()) return;
      var separator = line.indexOf("|");
      if (separator < 0) {
        // Preserve the line instead of discarding pasted content; the editor explains
        // that it will appear as a question without an answer until it is completed.
        rows.push({ question: line.trim(), answer: "" });
        invalidLines.push(index + 1);
        return;
      }
      rows.push({ question: line.slice(0, separator).trim(), answer: line.slice(separator + 1).trim() });
    });
    return { rows: rows.filter(function (row) { return row.question || row.answer; }), invalidLines: invalidLines };
  }

  function formData(form) {
    var data = {};
    Array.prototype.forEach.call(form.elements, function (field) {
      if (!field.name || field.type === "button" || field.type === "submit") return;
      data[field.name] = field.type === "checkbox" ? field.checked : field.value;
    });
    try { data.gallery = JSON.parse(String(data.gallery || "[]")); } catch (error) { data.gallery = []; }
    if (!Array.isArray(data.gallery)) data.gallery = [];
    data.gallery = data.gallery.filter(function (item) { return typeof item === "string" && item.trim(); });
    data.faq = parseFaq(data.faq).rows;
    return data;
  }

  var publicInformationFields = ["included_text", "access_conditions", "minor_policy", "refund_policy", "faq", "contact_info", "recommendations", "dress_code", "accessibility_info"];

  function publicInformationPayload(form) {
    var payload = {};
    publicInformationFields.forEach(function (name) {
      var field = input(form, name);
      payload[name] = field ? field.value : "";
    });
    payload.faq = parseFaq(payload.faq).rows;
    return payload;
  }

  function fillPublicInformation(eventData) {
    publicInformationFields.forEach(function (name) {
      var field = publicInput(name);
      if (!field) return;
      var value = name === "faq" ? (eventData.faq || []).map(function (row) { return typeof row === "object" ? (row.question || "") + " | " + (row.answer || "") : row; }).join("\n") : eventData[name];
      field.value = value == null ? "" : value;
    });
    refreshPublicInformation();
  }

  function publicInput(name) { return document.querySelector('[data-public-input][name="' + name + '"]'); }

  function autosizePublicInput(field) {
    if (!field) return;
    var maximum = field.closest(".public-field").classList.contains("is-expanded") ? Math.max(480, window.innerHeight - 180) : 560;
    field.style.height = "auto";
    field.style.height = Math.min(field.scrollHeight, maximum) + "px";
    field.classList.toggle("has-internal-scroll", field.scrollHeight > maximum);
  }

  function refreshFaqPreview() {
    var field = publicInput("faq");
    var preview = document.querySelector("[data-faq-preview]");
    var warning = document.querySelector("[data-faq-warning]");
    if (!field || !preview || !warning) return;
    var parsed = parseFaq(field.value);
    warning.hidden = !parsed.invalidLines.length;
    warning.textContent = parsed.invalidLines.length ? "Las líneas " + parsed.invalidLines.join(", ") + " no incluyen «|». Se conservarán como preguntas sin respuesta hasta que las completes." : "";
    preview.innerHTML = parsed.rows.length ? '<span class="ticket-eyebrow">Vista previa de preguntas frecuentes</span>' + parsed.rows.map(function (row) { return '<details><summary>' + escapeHtml(row.question || "Pregunta sin texto") + '</summary><p>' + escapeHtml(row.answer || "Respuesta pendiente") + '</p></details>'; }).join("") : '<p>La vista previa de las preguntas frecuentes aparecerá aquí.</p>';
  }

  function refreshPublicInformation() {
    var root = document.querySelector("[data-public-information]");
    if (!root) return;
    root.querySelectorAll("[data-public-input]").forEach(function (field) {
      var hasValue = !!field.value.trim();
      var stateNode = root.querySelector('[data-public-state="' + field.name + '"]');
      var lengthNode = root.querySelector('[data-public-length="' + field.name + '"]');
      if (stateNode) stateNode.textContent = !hasValue ? "Vacío" : (state.publicDirty[field.name] ? "Pendiente de guardar" : "Guardado");
      if (lengthNode) lengthNode.textContent = hasValue ? new Intl.NumberFormat("es-ES").format(field.value.length) + " caracteres" : "Sin contenido";
      autosizePublicInput(field);
    });
    refreshFaqPreview();
  }

  function initPublicInformation() {
    var root = document.querySelector("[data-public-information]");
    if (!root) return;
    root.addEventListener("click", function (event) {
      var button = event.target.closest("[data-expand-public-field]");
      if (!button) return;
      var field = publicInput(button.dataset.expandPublicField);
      if (!field) return;
      var wrapper = field.closest(".public-field");
      var expanded = wrapper.classList.toggle("is-expanded");
      button.textContent = expanded ? "Reducir editor" : "Ampliar editor";
      autosizePublicInput(field);
      field.focus();
    });
    root.addEventListener("input", function (event) {
      if (!event.target.matches("[data-public-input]")) return;
      state.publicDirty[event.target.name] = true;
      refreshPublicInformation();
    });
    refreshPublicInformation();
  }

  function setEditorSaving(isSaving) {
    state.saving = isSaving;
    ["[data-save-event]", "[data-publish-event]", "[data-preview-event]"].forEach(function (selector) {
      var button = document.querySelector(selector);
      if (button) button.disabled = isSaving;
    });
  }

  function saveEditorEvent(id, form) {
    if (state.saving) return Promise.reject(new Error("Ya se están guardando los cambios."));
    var payload = formData(form);
    setEditorSaving(true);
    return jsonRequest(api + "/admin/events/" + id, "PUT", payload).then(function (data) {
      state.event = data.event;
      fillEventForm(data.event);
      renderTicketTypes(data.event.ticket_types || []);
      setDirty(false);
      return data;
    }).finally(function () { setEditorSaving(false); });
  }

  function savePublicInformation(id, form) {
    if (state.saving) return Promise.reject(new Error("Ya se están guardando los cambios."));
    var payload = publicInformationPayload(form);
    var payloadJson = JSON.stringify(payload);
    setEditorSaving(true);
    return jsonRequest(api + "/admin/events/" + id + "/public-information", "PATCH", payload).then(function (data) {
      state.event = data.event;
      fillPublicInformation(data.event);
      state.publicDirty = {};
      setDirty(!!state.nonPublicDirty);
      return data;
    }).catch(function (error) {
      // No se registra el contenido; solo metadatos útiles para diagnosticar el servidor.
      console.error("Error guardando información pública", {
        status: error.status || 0,
        statusText: error.statusText || "",
        responseBody: error.responseBody || "",
        error: error.message,
        payloadKeys: Object.keys(payload),
        payloadSize: new Blob([payloadJson]).size
      });
      throw error;
    }).finally(function () { setEditorSaving(false); });
  }

  function isPublicSectionActive() {
    var section = document.querySelector("[data-editor-section].is-active");
    return !!section && section.dataset.editorSection === "public";
  }

  function saveActiveEditorSection(id, form) {
    return isPublicSectionActive() ? savePublicInformation(id, form) : saveEditorEvent(id, form);
  }

  function saveForPreview(id, form) {
    // La vista previa debe incluir recursos visuales u otros cambios pendientes,
    // aunque el usuario esté situado en Información pública.
    return state.nonPublicDirty ? saveEditorEvent(id, form) : saveActiveEditorSection(id, form);
  }

  function switchEditorSection(name) {
    document.querySelectorAll("[data-editor-section]").forEach(function (section) { section.classList.toggle("is-active", section.dataset.editorSection === name); });
    document.querySelectorAll("[data-editor-tab]").forEach(function (tab) { tab.classList.toggle("is-active", tab.dataset.editorTab === name); });
  }

  function ticketPayload(form) {
    var data = formData(form);
    data.price_cents = Math.round(Number(data.price || 0) * 100);
    data.fee_cents = Math.round(Number(data.fee || 0) * 100);
    data.capacity = Number(data.capacity || 0);
    data.min_quantity = Number(data.min_quantity || 1);
    data.max_per_order = Number(data.max_per_order || 1);
    data.sort_order = Number(data.sort_order || 100);
    data.tax_rate = Number(data.tax_rate || 0);
    data.active = !["draft", "paused", "closed", "hidden", "archived"].includes(data.status);
    return data;
  }

  function renderTicketTypes(types) {
    var target = document.querySelector("[data-ticket-type-list]");
    if (!target) return;
    if (!types.length) { target.innerHTML = '<div class="admin-empty"><strong>Aún no hay entradas.</strong><span>Crea al menos una antes de publicar el evento.</span></div>'; return; }
    target.innerHTML = types.map(function (type, index) {
      var salePeriod = type.sale_starts_at || type.sale_ends_at ? '<p class="ticket-sale-period">Venta: ' + escapeHtml(type.sale_starts_at ? dateText(type.sale_starts_at) : "inmediata") + ' · ' + escapeHtml(type.sale_ends_at ? dateText(type.sale_ends_at) : "sin fecha de cierre") + '</p>' : '';
      return '<article class="admin-ticket-card" data-ticket-id="' + Number(type.id) + '"><div class="ticket-card-content"><span class="status-pill status-' + escapeHtml(type.effective_status) + '">' + escapeHtml(statusLabel(type.effective_status)) + '</span><h3>' + escapeHtml(type.name) + '</h3><p class="ticket-card-description">' + escapeHtml(type.description || "Sin descripción") + '</p>' + salePeriod + '<dl class="ticket-metrics"><div><dt>Precio final</dt><dd>' + cents(type.final_price_cents) + '</dd></div><div><dt>Vendidas</dt><dd>' + Number(type.sold) + '</dd></div><div><dt>Reservadas</dt><dd>' + Number(type.reserved) + '</dd></div><div><dt>Restantes</dt><dd>' + Number(type.available) + '</dd></div><div><dt>Cupo total</dt><dd>' + Number(type.capacity) + '</dd></div></dl></div><details class="ticket-action-menu"><summary aria-label="Acciones para ' + escapeHtml(type.name) + '">•••</summary><div><button type="button" data-ticket-action="edit">Editar</button><button type="button" data-ticket-action="duplicate">Duplicar</button><button type="button" data-ticket-action="up" ' + (index ? "" : "disabled") + '>Subir</button><button type="button" data-ticket-action="down" ' + (index === types.length - 1 ? "disabled" : "") + '>Bajar</button><button class="danger" type="button" data-ticket-action="delete">Archivar</button></div></details></article>';
    }).join("");
  }

  function updateTicketPricePreview(form) {
    var target = form.querySelector("[data-ticket-final-price]");
    if (!target) return;
    var price = Math.max(0, Number(input(form, "price").value || 0));
    var fee = Math.max(0, Number(input(form, "fee").value || 0));
    var tax = Math.max(0, Number(input(form, "tax_rate").value || 0));
    target.textContent = money.format(price + Math.round(price * tax) / 100 + fee);
  }

  function fillTicketForm(ticket) {
    var form = document.querySelector("[data-ticket-type-form]");
    if (!form) return;
    form.reset();
    Object.keys(ticket || {}).forEach(function (key) {
      var field = input(form, key);
      if (!field) return;
      if (field.type === "checkbox") field.checked = !!ticket[key];
      else if (field.type === "datetime-local") field.value = dateInput(ticket[key]);
      else field.value = ticket[key] == null ? "" : ticket[key];
    });
    input(form, "price").value = ticket ? (Number(ticket.price_cents || 0) / 100).toFixed(2) : "";
    input(form, "fee").value = ticket ? (Number(ticket.fee_cents || 0) / 100).toFixed(2) : "0";
    input(form, "ticket_type_id").value = ticket ? ticket.id : "";
    document.querySelector("[data-ticket-form-title]").textContent = ticket ? "Editar entrada" : "Nueva entrada";
    document.querySelector("[data-ticket-submit]").textContent = ticket ? "Guardar cambios" : "Crear entrada";
    updateTicketPricePreview(form);
    ticketDrawerState.dirty = false;
  }

  function openTicketDrawer(ticket) {
    var drawer = document.querySelector("[data-ticket-drawer]");
    if (!drawer) return;
    fillTicketForm(ticket || null);
    drawer.hidden = false;
    document.body.classList.add("has-ticket-drawer");
    window.setTimeout(function () { input(document.querySelector("[data-ticket-type-form]"), "name").focus(); }, 0);
  }

  function closeTicketDrawer(force) {
    var drawer = document.querySelector("[data-ticket-drawer]");
    if (!drawer || drawer.hidden) return true;
    if (!force && ticketDrawerState.dirty && !window.confirm("Tienes cambios sin guardar. ¿Quieres cerrar el formulario?")) return false;
    drawer.hidden = true;
    document.body.classList.remove("has-ticket-drawer");
    ticketDrawerState.dirty = false;
    return true;
  }

  function validateTicketForm(form) {
    var minimum = Number(input(form, "min_quantity").value || 1);
    var maximum = Number(input(form, "max_per_order").value || 1);
    var capacity = Number(input(form, "capacity").value || 0);
    var tax = Number(input(form, "tax_rate").value || 0);
    var start = input(form, "sale_starts_at").value;
    var end = input(form, "sale_ends_at").value;
    var ticketId = Number(input(form, "ticket_type_id").value || 0);
    var existing = (state.event && state.event.ticket_types || []).find(function (type) { return Number(type.id) === ticketId; });
    if (maximum < minimum) return "El máximo por compra no puede ser inferior al mínimo.";
    if (tax < 0 || tax > 100) return "El IVA debe estar entre 0 % y 100 %.";
    if (existing && capacity < Number(existing.sold || 0) + Number(existing.reserved || 0)) return "El cupo no puede ser inferior a las entradas vendidas o reservadas.";
    if (start && end && end < start) return "El fin de venta no puede ser anterior al inicio de venta.";
    return "";
  }

  function loadEditor(eventId) {
    return request(api + "/admin/events/" + eventId).then(function (data) {
      state.event = data.event;
      fillEventForm(state.event);
      renderTicketTypes(state.event.ticket_types || []);
      setDirty(false);
      return state.event;
    });
  }

  function initEditor() {
    var root = document.querySelector("[data-event-editor]");
    if (!root) return;
    var id = Number(q("id") || 0);
    if (!id) { editorNotice("Falta el identificador del evento.", true); return; }
    requireSession(function () {
      loadEditor(id).catch(showFatal);
      var form = document.querySelector("[data-event-form]");
      form.addEventListener("input", function (event) { if (!event.target.matches("[data-public-input]")) state.nonPublicDirty = true; setDirty(true); });
      form.addEventListener("change", function (event) { if (!event.target.matches("[data-public-input]")) state.nonPublicDirty = true; setDirty(true); });
      document.querySelectorAll("[data-editor-tab]").forEach(function (tab) { tab.addEventListener("click", function () { switchEditorSection(tab.dataset.editorTab); }); });
      document.querySelector("[data-save-event]").addEventListener("click", function () {
        editorNotice("Guardando...");
        saveActiveEditorSection(id, form).then(function () { editorNotice(isPublicSectionActive() ? "Información pública guardada correctamente." : "Cambios guardados correctamente."); }).catch(function (error) { editorNotice("No se han podido guardar los cambios. El contenido permanece en el editor. " + error.message, true); });
      });
      document.querySelector("[data-publish-event]").addEventListener("click", function () {
        editorNotice("Guardando antes de publicar...");
        saveEditorEvent(id, form).then(function () { return jsonRequest(api + "/admin/events/" + id + "/publish", "POST", {}); }).then(function (data) { state.event = data.event; fillEventForm(data.event); setDirty(false); editorNotice("Evento publicado."); }).catch(function (error) { editorNotice("No se ha podido publicar. " + error.message, true); });
      });
      document.querySelector("[data-preview-event]").addEventListener("click", function () {
        // Open the tab while this click still has user activation. Opening it after
        // an async save is treated as a popup and can be blocked by the browser.
        var previewWindow = window.open("about:blank", "_blank");
        if (!previewWindow) {
          editorNotice("El navegador ha bloqueado la vista previa. Permite las ventanas emergentes para este sitio e inténtalo de nuevo.", true);
          return;
        }
        previewWindow.opener = null;
        var showPreview = function () { previewWindow.location.replace("/admin/entradas/vista-previa/?id=" + id); };
        if (!state.dirty) return showPreview();
        editorNotice("Guardando cambios y abriendo vista previa...");
        saveForPreview(id, form).then(showPreview).catch(function (error) { if (!previewWindow.closed) previewWindow.close(); editorNotice("No se ha podido guardar para abrir la vista previa. El contenido permanece en el editor. " + error.message, true); });
      });
      document.querySelector("[data-archive-event]").addEventListener("click", function () {
        if (window.confirm("¿Archivar o eliminar este evento? Las ventas existentes quedarán protegidas.")) jsonRequest(api + "/admin/events/" + id, "DELETE").then(function () { window.location.href = "/admin/entradas/"; }).catch(function (error) { editorNotice(error.message, true); });
      });
      initTicketForm(id);
      initEventMediaManager();
      initPublicInformation();
      initPublicationEditor();
      document.querySelector(".editor-back").addEventListener("click", function (event) {
        if (state.dirty && !window.confirm("Tienes cambios sin guardar. Si abandonas esta página, se perderán.")) event.preventDefault();
      });
      window.addEventListener("beforeunload", function (event) { if (state.dirty || ticketDrawerState.dirty) { event.preventDefault(); event.returnValue = ""; } });
    });
  }

  function initTicketForm(eventId) {
    var form = document.querySelector("[data-ticket-type-form]");
    if (!form) return;
    document.querySelector("[data-open-ticket-form]").addEventListener("click", function () { openTicketDrawer(null); });
    document.querySelectorAll("[data-close-ticket-drawer]").forEach(function (button) { button.addEventListener("click", function () { closeTicketDrawer(false); }); });
    document.addEventListener("keydown", function (event) { if (event.key === "Escape") closeTicketDrawer(false); });
    form.addEventListener("input", function () { ticketDrawerState.dirty = true; updateTicketPricePreview(form); });
    form.addEventListener("change", function () { ticketDrawerState.dirty = true; updateTicketPricePreview(form); });
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var validationError = validateTicketForm(form);
      if (validationError) { editorNotice(validationError, true); return; }
      var ticketId = Number(input(form, "ticket_type_id").value || 0);
      var url = api + "/admin/events/" + eventId + "/ticket-types" + (ticketId ? "/" + ticketId : "");
      var submit = form.querySelector("[data-ticket-submit]");
      ticketDrawerState.saving = true;
      submit.disabled = true;
      submit.textContent = "Guardando...";
      jsonRequest(url, ticketId ? "PUT" : "POST", ticketPayload(form)).then(function () { return loadEditor(eventId); }).then(function () { closeTicketDrawer(true); editorNotice("Entrada guardada."); switchEditorSection("tickets"); }).catch(function (error) { editorNotice(error.message, true); }).finally(function () { ticketDrawerState.saving = false; submit.disabled = false; submit.textContent = ticketId ? "Guardar cambios" : "Crear entrada"; });
    });
    document.querySelector("[data-ticket-type-list]").addEventListener("click", function (event) {
      var card = event.target.closest("[data-ticket-id]");
      var action = event.target.dataset.ticketAction;
      if (!card || !action) return;
      var ticketId = Number(card.dataset.ticketId);
      var types = state.event.ticket_types || [];
      var index = types.findIndex(function (row) { return Number(row.id) === ticketId; });
      if (action === "edit") openTicketDrawer(types[index]);
      if (action === "duplicate") jsonRequest(api + "/admin/events/" + eventId + "/ticket-types/" + ticketId + "/duplicate", "POST", {}).then(function () { return loadEditor(eventId); }).catch(function (error) { editorNotice(error.message, true); });
      if ((action === "up" || action === "down") && index >= 0) {
        var swap = action === "up" ? index - 1 : index + 1;
        if (types[swap]) { var moved = types.slice(); var current = moved[index]; moved[index] = moved[swap]; moved[swap] = current; jsonRequest(api + "/admin/events/" + eventId + "/ticket-types/reorder", "POST", { ids: moved.map(function (row) { return row.id; }) }).then(function () { return loadEditor(eventId); }).catch(function (error) { editorNotice(error.message, true); }); }
      }
      if (action === "delete" && window.confirm("Esta acción eliminará la entrada sin ventas o la archivará si ya tiene pedidos.")) jsonRequest(api + "/admin/events/" + eventId + "/ticket-types/" + ticketId, "DELETE").then(function () { return loadEditor(eventId); }).catch(function (error) { editorNotice(error.message, true); });
    });
  }

  function mediaConfigs() {
    return [
      { field: "image_url", kind: "image", label: "Imagen de portada", description: "Imagen principal de la página pública del evento.", accept: "image/jpeg,image/png,image/webp,image/avif", recommendation: "Panorámica · ideal 1600 × 900 · máximo 5 MB", preview: "cover" },
      { field: "card_image_url", kind: "card", label: "Imagen para tarjeta", description: "Aparece en listados y tarjetas de próximas experiencias.", accept: "image/jpeg,image/png,image/webp,image/avif", recommendation: "Horizontal · ideal 1200 × 900 · máximo 5 MB", preview: "card" },
      { field: "social_image_url", kind: "social", label: "Imagen social", description: "Se reserva para compartir la experiencia y sus metadatos sociales.", accept: "image/jpeg,image/png,image/webp,image/avif", recommendation: "1200 × 630 px · formato Open Graph · máximo 5 MB", preview: "social" },
      { field: "video_url", kind: "video", label: "Vídeo promocional", description: "Una pieza breve para acompañar la historia pública del evento.", accept: "video/mp4,video/webm,video/quicktime", recommendation: "MP4, WebM o MOV · ideal 1920 × 1080 · máximo 50 MB", preview: "video" },
      { field: "logo_url", kind: "logo", label: "Logotipo del evento", description: "Identidad visual del evento; se muestra sobre fondo neutro.", accept: "image/png,image/webp,image/jpeg,image/avif", recommendation: "PNG o WebP preferentemente · fondo transparente · máximo 5 MB", preview: "logo" }
    ];
  }

  function mediaForm() { return document.querySelector("[data-event-form]"); }
  function mediaValue(field) { var node = input(mediaForm(), field); return node ? node.value : ""; }
  function setMediaValue(field, value) { var node = input(mediaForm(), field); if (node) node.value = value || ""; }
  function galleryValue() { try { var parsed = JSON.parse(mediaValue("gallery") || "[]"); return Array.isArray(parsed) ? parsed.filter(Boolean) : []; } catch (error) { return []; } }
  function setGalleryValue(values) { setMediaValue("gallery", JSON.stringify(values)); }
  function mediaId(field) { return "event-media-" + field.replace(/[^a-z0-9_-]/gi, "-"); }
  function releasePreview(field) { if (mediaState.previews[field]) { URL.revokeObjectURL(mediaState.previews[field]); delete mediaState.previews[field]; } }
  function selectedPreview(field, file) { releasePreview(field); if (file) mediaState.previews[field] = URL.createObjectURL(file); return mediaState.previews[field] || ""; }
  function isAllowedFile(file, config) {
    var max = config.kind === "video" ? 50 * 1024 * 1024 : 5 * 1024 * 1024;
    var allowed = config.accept.split(",");
    if (!file || !allowed.includes(file.type)) return "El formato del archivo no es válido para este recurso.";
    if (!file.size || file.size > max) return "El archivo supera el límite de " + (config.kind === "video" ? "50" : "5") + " MB.";
    return "";
  }

  function previewMarkup(config, url, label) {
    if (!url) return '<div class="event-media-preview event-media-preview-empty event-media-preview-' + config.preview + '"><span>Vista previa disponible al subir el archivo</span></div>';
    if (config.kind === "video") return '<div class="event-media-preview event-media-preview-video"><video controls preload="metadata" src="' + escapeHtml(url) + '">Tu navegador no puede reproducir este vídeo.</video></div>';
    return '<div class="event-media-preview event-media-preview-' + config.preview + '"><img src="' + escapeHtml(url) + '" alt="Vista previa de ' + escapeHtml(label) + '"></div>';
  }

  function mediaCardMarkup(config) {
    var file = mediaState.selected[config.field];
    var current = mediaValue(config.field);
    var preview = file ? (mediaState.previews[config.field] || selectedPreview(config.field, file)) : current;
    var status = mediaState.messages[config.field] || "";
    var uploading = !!mediaState.uploading[config.field];
    return '<article class="event-media-card event-media-card-' + config.preview + '">' +
      '<div class="event-media-card-head"><div><span class="ticket-eyebrow">' + escapeHtml(config.label) + '</span><p>' + escapeHtml(config.description) + '</p></div><span class="event-media-status' + (status.indexOf("Error") === 0 ? ' is-error' : '') + '">' + escapeHtml(status) + '</span></div>' +
      previewMarkup(config, preview, config.label) +
      '<p class="event-media-recommendation">' + escapeHtml(config.recommendation) + '</p>' +
      '<input id="' + mediaId(config.field) + '" data-media-input data-media-field="' + config.field + '" type="file" accept="' + config.accept + '" hidden>' +
      '<div class="event-media-file-name">' + escapeHtml(file ? file.name : (current ? "Archivo guardado" : "Ningún archivo seleccionado")) + '</div>' +
      '<div class="event-media-actions"><button class="ticket-btn" type="button" data-media-action="choose" data-media-field="' + config.field + '">' + (current ? "Reemplazar" : "Seleccionar archivo") + '</button><button class="ticket-btn primary" type="button" data-media-action="upload" data-media-field="' + config.field + '"' + (!file || uploading ? " disabled" : "") + '>' + (uploading ? "Subiendo..." : "Subir archivo") + '</button>' + (current ? '<button class="text-action danger" type="button" data-media-action="remove" data-media-field="' + config.field + '">Eliminar</button>' : '') + '</div>' +
      '</article>';
  }

  function galleryMarkup() {
    var saved = galleryValue();
    var selected = mediaState.selected.gallery || [];
    var status = mediaState.messages.gallery || "";
    var uploading = !!mediaState.uploading.gallery;
    var savedItems = saved.map(function (url, index) {
      return '<article class="event-gallery-item" draggable="true" data-gallery-index="' + index + '"><img src="' + escapeHtml(url) + '" alt="Imagen ' + (index + 1) + ' de la galería"><span class="event-gallery-order">' + (index + 1) + '</span><div><button class="text-action" type="button" data-media-action="gallery-replace" data-gallery-index="' + index + '">Reemplazar</button><button class="text-action danger" type="button" data-media-action="gallery-remove" data-gallery-index="' + index + '">Eliminar</button></div></article>';
    }).join("");
    var pendingItems = selected.map(function (file, index) { var key = "gallery-" + index; return '<article class="event-gallery-item is-pending"><img src="' + escapeHtml(mediaState.previews[key] || selectedPreview(key, file)) + '" alt="Pendiente: ' + escapeHtml(file.name) + '"><span class="event-gallery-pending">Pendiente</span><div><button class="text-action danger" type="button" data-media-action="gallery-remove-pending" data-gallery-index="' + index + '">Quitar</button></div></article>'; }).join("");
    return '<article class="event-media-card event-media-card-gallery"><div class="event-media-card-head"><div><span class="ticket-eyebrow">Galería de imágenes</span><p>Añade las imágenes en el orden en que quieres mostrarlas. Arrastra las imágenes guardadas para reordenarlas.</p></div><span class="event-media-status' + (status.indexOf("Error") === 0 ? ' is-error' : '') + '">' + escapeHtml(status) + '</span></div><p class="event-media-recommendation">JPG, PNG, WebP o AVIF · ideal 1200 × 900 · máximo 5 MB por archivo</p><input id="event-media-gallery" data-media-input data-media-field="gallery" type="file" accept="image/jpeg,image/png,image/webp,image/avif" multiple hidden><div class="event-media-actions"><button class="ticket-btn" type="button" data-media-action="choose" data-media-field="gallery">Seleccionar imágenes</button><button class="ticket-btn primary" type="button" data-media-action="upload" data-media-field="gallery"' + (!selected.length || uploading ? " disabled" : "") + '>' + (uploading ? "Subiendo..." : "Subir imágenes") + '</button></div><div class="event-gallery-grid" data-gallery-grid>' + (savedItems || pendingItems ? savedItems + pendingItems : '<div class="event-gallery-empty">Todavía no hay imágenes en la galería.</div>') + '</div></article>';
  }

  function renderEventMediaManager() {
    var root = mediaState.root || document.querySelector("[data-event-media-manager]");
    if (!root || !mediaForm()) return;
    mediaState.root = root;
    root.innerHTML = mediaConfigs().map(mediaCardMarkup).join("") + galleryMarkup();
  }

  function uploadMedia(file, kind) {
    var body = new FormData();
    body.append("file", file);
    body.append("kind", kind);
    return request(api + "/admin/media", { method: "POST", headers: { "X-CSRF-Token": state.csrf }, body: body }).then(function (data) {
      if (!data.media || !data.media.url) throw new Error("El servidor no devolvió la ruta del archivo.");
      return data.media;
    });
  }

  function uploadSingle(config) {
    var file = mediaState.selected[config.field];
    var error = isAllowedFile(file, config);
    if (error) { mediaState.messages[config.field] = "Error: " + error; renderEventMediaManager(); return; }
    mediaState.uploading[config.field] = true;
    mediaState.messages[config.field] = "Subiendo archivo...";
    renderEventMediaManager();
    uploadMedia(file, config.kind).then(function (media) {
      setMediaValue(config.field, media.url);
      if (config.field === "social_image_url") setMediaValue("seo_image_url", media.url);
      releasePreview(config.field);
      delete mediaState.selected[config.field];
      mediaState.messages[config.field] = "Archivo subido. Guarda el evento para confirmar el cambio.";
      state.nonPublicDirty = true;
      setDirty(true);
    }).catch(function (error) { mediaState.messages[config.field] = "Error: " + error.message; }).finally(function () { delete mediaState.uploading[config.field]; renderEventMediaManager(); });
  }

  function uploadGallery() {
    var files = (mediaState.selected.gallery || []).slice();
    if (!files.length) return;
    mediaState.uploading.gallery = true;
    var saved = galleryValue();
    var index = 0;
    var failures = [];
    function next() {
      if (index >= files.length) {
        files.forEach(function (file, itemIndex) { releasePreview("gallery-" + itemIndex); });
        mediaState.selected.gallery = failures;
        failures.forEach(function (file, itemIndex) { selectedPreview("gallery-" + itemIndex, file); });
        setGalleryValue(saved);
        mediaState.messages.gallery = failures.length ? "Error: " + failures.length + " archivo(s) no se han subido. Corrige esos archivos y vuelve a intentarlo." : "Galería actualizada. Guarda el evento para confirmar el cambio.";
        delete mediaState.uploading.gallery;
        if (files.length !== failures.length) { state.nonPublicDirty = true; setDirty(true); }
        renderEventMediaManager();
        return;
      }
      var file = files[index];
      var error = isAllowedFile(file, { kind: "image", accept: "image/jpeg,image/png,image/webp,image/avif" });
      if (error) { failures.push(file); mediaState.messages.gallery = "Error: " + file.name + ". " + error; index += 1; next(); return; }
      mediaState.messages.gallery = "Subiendo " + (index + 1) + " de " + files.length + "...";
      renderEventMediaManager();
      uploadMedia(file, "gallery").then(function (media) { if (!saved.includes(media.url)) saved.push(media.url); }).catch(function (uploadError) { failures.push(file); mediaState.messages.gallery = "Error: " + file.name + ". " + uploadError.message; }).finally(function () { index += 1; next(); });
    }
    next();
  }

  function replaceGallery(index) {
    var picker = document.createElement("input");
    picker.type = "file";
    picker.accept = "image/jpeg,image/png,image/webp,image/avif";
    picker.addEventListener("change", function () {
      var file = picker.files[0];
      var error = isAllowedFile(file, { kind: "image", accept: picker.accept });
      if (error) { mediaState.messages.gallery = "Error: " + error; renderEventMediaManager(); return; }
      mediaState.uploading.gallery = true;
      mediaState.messages.gallery = "Reemplazando imagen...";
      renderEventMediaManager();
      uploadMedia(file, "gallery").then(function (media) { var saved = galleryValue(); saved[index] = media.url; setGalleryValue(saved); mediaState.messages.gallery = "Imagen reemplazada. Guarda el evento para confirmar el cambio."; state.nonPublicDirty = true; setDirty(true); }).catch(function (uploadError) { mediaState.messages.gallery = "Error: " + uploadError.message; }).finally(function () { delete mediaState.uploading.gallery; renderEventMediaManager(); });
    });
    picker.click();
  }

  function initEventMediaManager() {
    var root = document.querySelector("[data-event-media-manager]");
    if (!root) return;
    mediaState.root = root;
    root.addEventListener("change", function (event) {
      var target = event.target;
      if (!target.matches("[data-media-input]")) return;
      var field = target.dataset.mediaField;
      var config = mediaConfigs().find(function (item) { return item.field === field; });
      var files = Array.from(target.files || []);
      if (field === "gallery") {
        mediaState.selected.gallery = (mediaState.selected.gallery || []).concat(files).filter(function (file, index, rows) { return rows.findIndex(function (item) { return item.name === file.name && item.size === file.size && item.lastModified === file.lastModified; }) === index; });
        mediaState.selected.gallery.forEach(function (file, index) { selectedPreview("gallery-" + index, file); });
      } else if (config && files[0]) {
        mediaState.selected[field] = files[0];
        selectedPreview(field, files[0]);
      }
      mediaState.messages[field] = "";
      renderEventMediaManager();
    });
    root.addEventListener("click", function (event) {
      var button = event.target.closest("[data-media-action]");
      if (!button) return;
      var action = button.dataset.mediaAction;
      var field = button.dataset.mediaField;
      if (action === "choose") { var picker = root.querySelector('#' + mediaId(field)); if (picker) picker.click(); return; }
      if (action === "upload") { if (field === "gallery") uploadGallery(); else { var config = mediaConfigs().find(function (item) { return item.field === field; }); if (config) uploadSingle(config); } return; }
      if (action === "remove" && window.confirm("¿Quitar este recurso del evento? El archivo seguirá protegido en el servidor, pero dejará de mostrarse al guardar.")) { releasePreview(field); delete mediaState.selected[field]; setMediaValue(field, ""); if (field === "social_image_url") setMediaValue("seo_image_url", ""); mediaState.messages[field] = "Recurso eliminado del evento. Guarda para confirmar el cambio."; state.nonPublicDirty = true; setDirty(true); renderEventMediaManager(); return; }
      var index = Number(button.dataset.galleryIndex);
      if (action === "gallery-remove" && window.confirm("¿Quitar esta imagen de la galería del evento?")) { var saved = galleryValue(); saved.splice(index, 1); setGalleryValue(saved); mediaState.messages.gallery = "Imagen eliminada de la galería. Guarda para confirmar el cambio."; state.nonPublicDirty = true; setDirty(true); renderEventMediaManager(); }
      if (action === "gallery-remove-pending") { var pending = mediaState.selected.gallery || []; releasePreview("gallery-" + index); pending.splice(index, 1); mediaState.selected.gallery = pending; renderEventMediaManager(); }
      if (action === "gallery-replace") replaceGallery(index);
    });
    root.addEventListener("dragstart", function (event) { var item = event.target.closest("[data-gallery-index]"); if (!item) return; mediaState.dragIndex = Number(item.dataset.galleryIndex); item.classList.add("is-dragging"); });
    root.addEventListener("dragover", function (event) { if (event.target.closest("[data-gallery-index]")) event.preventDefault(); });
    root.addEventListener("drop", function (event) { var item = event.target.closest("[data-gallery-index]"); if (!item || mediaState.dragIndex == null) return; event.preventDefault(); var from = mediaState.dragIndex; var to = Number(item.dataset.galleryIndex); var saved = galleryValue(); if (from !== to) { var moved = saved.splice(from, 1)[0]; saved.splice(to, 0, moved); setGalleryValue(saved); mediaState.messages.gallery = "Orden actualizado. Guarda el evento para confirmar el cambio."; state.nonPublicDirty = true; setDirty(true); } mediaState.dragIndex = null; renderEventMediaManager(); });
    root.addEventListener("dragend", function () { mediaState.dragIndex = null; root.querySelectorAll(".is-dragging").forEach(function (item) { item.classList.remove("is-dragging"); }); });
  }

  function initScanner() {
    var form = document.querySelector("[data-ticket-scan]");
    if (!form) return;
    requireSession(function () {
      var wrap = document.querySelector("[data-ticket-scan-wrap]");
      var status = form.querySelector("[data-scan-status]");
      var cameraWrap = form.querySelector("[data-ticket-camera-wrap]");
      var video = form.querySelector("[data-ticket-camera]");
      var attendeesRoot = document.querySelector("[data-ticket-attendees]");
      var stream = null;
      var scanning = false;
      var locked = false;
      if (wrap) wrap.hidden = false;

      function resultCopy(result) {
        return ({ valida: "Entrada válida. Acceso registrado.", ya_utilizada: "Esta entrada ya se utilizó.", cancelada: "Esta entrada está cancelada.", reembolsada: "Esta entrada está reembolsada.", bloqueada: "Esta entrada está bloqueada.", otro_evento: "Esta entrada corresponde a otra experiencia.", inexistente: "No encontramos una entrada válida." })[result] || "No se pudo validar la entrada.";
      }
      function resultDetails(result) {
        if (!result || !result.ticket) return "";
        return " " + [result.ticket.attendee_name, result.ticket.ticket_type_name, result.ticket.public_code].filter(Boolean).join(" · ");
      }
      function stopCamera() {
        scanning = false;
        if (stream) stream.getTracks().forEach(function (track) { track.stop(); });
        stream = null;
        if (video) video.srcObject = null;
        if (cameraWrap) cameraWrap.hidden = true;
      }
      function attendeeStatus(value) { return ({ issued: "Pendiente", used: "Acceso realizado", cancelled: "Cancelada", refunded: "Reembolsada", blocked: "Bloqueada" })[value] || value; }
      function loadAttendees() {
        var eventId = Number(form.event_id.value || 0);
        if (!eventId || !attendeesRoot) { if (attendeesRoot) attendeesRoot.hidden = true; return; }
        request(api + "/admin/events/" + eventId + "/attendees").then(function (data) {
          var metrics = data.metrics || {};
          attendeesRoot.hidden = false;
          var attendees = data.attendees || [];
          attendeesRoot.innerHTML = '<div class="ticket-attendee-head"><div><span class="ticket-eyebrow">Asistentes</span><h2>Control de acceso</h2></div><div class="ticket-attendee-metrics"><span>' + Number(metrics.total || 0) + ' emitidas</span><span>' + Number(metrics.used || 0) + ' dentro</span><span>' + Number(metrics.pending || 0) + ' pendientes</span><span>' + Number(metrics.access_percent || 0) + '% acceso</span></div></div><div class="ticket-attendee-filters"><label>Buscar<input type="search" data-attendee-search placeholder="Nombre, teléfono, pedido o código"></label><label>Estado<select data-attendee-filter><option value="all">Todas</option><option value="issued">Pendientes</option><option value="used">Acceso realizado</option><option value="cancelled">Canceladas</option><option value="refunded">Reembolsadas</option><option value="blocked">Bloqueadas</option></select></label></div><div class="ticket-attendee-table" data-attendee-table></div>';
          function drawAttendees() {
            var search = (attendeesRoot.querySelector("[data-attendee-search]").value || "").toLowerCase().trim();
            var filter = attendeesRoot.querySelector("[data-attendee-filter]").value;
            var rows = attendees.filter(function (attendee) {
              var searchable = [attendee.name, attendee.email, attendee.phone, attendee.public_code, attendee.order_reference, attendee.ticket_type_name].join(" ").toLowerCase();
              return (!search || searchable.includes(search)) && (filter === "all" || attendee.status === filter);
            });
            attendeesRoot.querySelector("[data-attendee-table]").innerHTML = '<div class="ticket-attendee-row ticket-attendee-labels"><span>Asistente</span><span>Entrada</span><span>Estado</span><span>Acción</span></div>' + rows.map(function (attendee) { return '<div class="ticket-attendee-row"><span><strong>' + escapeHtml(attendee.name) + '</strong><small>' + escapeHtml(attendee.email) + ' · ' + escapeHtml(attendee.phone || "sin teléfono") + '</small></span><span><strong>' + escapeHtml(attendee.ticket_type_name) + '</strong><small>' + escapeHtml(attendee.public_code) + ' · ' + escapeHtml(attendee.order_reference || "") + '</small></span><span class="attendee-status attendee-' + escapeHtml(attendee.status) + '">' + escapeHtml(attendeeStatus(attendee.status)) + (attendee.used_at ? '<small>' + escapeHtml(dateText(attendee.used_at)) + '</small>' : '') + '</span><span>' + (attendee.status === "used" && state.role === "admin" ? '<button class="text-action" type="button" data-revert-ticket="' + escapeHtml(attendee.public_code) + '">Revertir</button>' : '') + '</span></div>'; }).join("") + (rows.length ? "" : '<p class="ticket-status">No hay asistentes que coincidan con ese filtro.</p>');
          }
          attendeesRoot.querySelector("[data-attendee-search]").addEventListener("input", drawAttendees);
          attendeesRoot.querySelector("[data-attendee-filter]").addEventListener("change", drawAttendees);
          drawAttendees();
        }).catch(function (error) { attendeesRoot.hidden = false; attendeesRoot.textContent = error.message; });
      }
      function validate(value) {
        if (!value || locked) return;
        locked = true;
        status.textContent = "Comprobando entrada...";
        status.className = "ticket-status";
        jsonRequest(api + "/admin/tickets/scan", "POST", {
          event_id: Number(form.event_id.value || 0),
          code: value,
          device_reference: navigator.userAgent.slice(0, 190),
          access_point: form.access_point ? form.access_point.value.trim() : "",
        }).then(function (data) {
          status.textContent = resultCopy(data.result) + resultDetails(data);
          status.className = "ticket-status scan-" + data.result;
          if (navigator.vibrate) navigator.vibrate(data.result === "valida" ? [80] : [80, 60, 80]);
          form.code.value = "";
          form.code.focus();
          loadAttendees();
        }).catch(function (error) { status.textContent = error.message; status.className = "ticket-status is-error"; }).finally(function () { locked = false; });
      }
      function scanFrame(detector) {
        if (!scanning || !video || locked) { if (scanning) window.requestAnimationFrame(function () { scanFrame(detector); }); return; }
        detector.detect(video).then(function (codes) {
          if (codes.length && codes[0].rawValue) { form.code.value = codes[0].rawValue; stopCamera(); validate(codes[0].rawValue); return; }
          window.requestAnimationFrame(function () { scanFrame(detector); });
        }).catch(function () { window.requestAnimationFrame(function () { scanFrame(detector); }); });
      }
      request(api + "/admin/events").then(function (data) {
        form.event_id.innerHTML = '<option value="">Selecciona evento</option>' + data.events.map(function (event) { return '<option value="' + Number(event.id) + '">' + escapeHtml(event.title) + '</option>'; }).join("");
        var ticket = q("ticket");
        if (ticket) form.code.value = ticket;
      });
      form.event_id.addEventListener("change", loadAttendees);
      form.addEventListener("submit", function (event) { event.preventDefault(); validate(form.code.value.trim()); });
      form.querySelector("[data-open-camera]").addEventListener("click", function () {
        if (!form.event_id.value) { status.textContent = "Selecciona primero la experiencia."; return; }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.BarcodeDetector) { status.textContent = "Este navegador no permite abrir el lector. Introduce el código de la entrada manualmente."; return; }
        navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: "environment" } }, audio: false }).then(function (media) {
          stream = media; video.srcObject = media; cameraWrap.hidden = false; scanning = true;
          return video.play();
        }).then(function () { scanFrame(new window.BarcodeDetector({ formats: ["qr_code"] })); }).catch(function () { status.textContent = "No se pudo abrir la cámara. Revisa los permisos y vuelve a intentarlo."; stopCamera(); });
      });
      form.querySelector("[data-close-camera]").addEventListener("click", stopCamera);
      if (attendeesRoot) attendeesRoot.addEventListener("click", function (event) {
        var button = event.target.closest("[data-revert-ticket]");
        if (state.role !== "admin" || !button || !window.confirm("¿Revertir este acceso? La entrada volverá a estar disponible y se registrará la corrección.")) return;
        jsonRequest(api + "/admin/events/" + Number(form.event_id.value) + "/tickets/" + encodeURIComponent(button.dataset.revertTicket) + "/revert", "POST", { reason: "Corrección desde control de acceso" })
          .then(function () { status.textContent = "Acceso revertido. La entrada vuelve a estar disponible."; status.className = "ticket-status"; loadAttendees(); })
          .catch(function (error) { status.textContent = error.message; status.className = "ticket-status is-error"; });
      });
    });
  }

  initLogin();
  initLogout();
  initList();
  initEditor();
  initScanner();
})();

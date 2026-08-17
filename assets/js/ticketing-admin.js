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
  function editorEventId() {
    var legacyId = Number(q("id") || 0);
    if (legacyId) return legacyId;
    var match = window.location.pathname.match(/^\/admin\/eventos\/(\d+)\/editar\/?$/);
    return match ? Number(match[1]) : 0;
  }
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
        // The central login keeps expired sessions out of the editor and the
        // access desk instead of leaving a confusing "No autorizado" state.
        var next = window.location.pathname + window.location.search;
        window.location.replace("/admin/login/?next=" + encodeURIComponent(next));
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
    if (/^\/admin\/entradas\/?$/.test(window.location.pathname)) {
      window.location.replace("/admin/eventos/");
      return;
    }
    requireSession(function (sessionData) {
      if (sessionData.role === "control_acceso") {
        window.location.replace("/admin/entradas/acceso/");
        return;
      }
      root.hidden = false;
      loadEvents();
      document.querySelector("[data-event-search]").addEventListener("input", function (event) { renderEvents(state.events || [], event.target.value); });
      document.querySelector("[data-create-event]").addEventListener("click", function () {
        jsonRequest(api + "/admin/events", "POST", { title: "Nuevo evento" }).then(function (data) { window.location.href = "/admin/eventos/" + data.event.id + "/editar/"; }).catch(showFatal);
      });
      root.addEventListener("click", function (event) {
        var card = event.target.closest("[data-event-id]");
        if (!card) return;
        var id = Number(card.dataset.eventId);
        if (event.target.closest("[data-open-event]")) { window.location.href = "/admin/eventos/" + id + "/editar/"; return; }
        if (event.target.closest("[data-copy-link]")) {
          navigator.clipboard.writeText(window.location.origin + "/eventos/" + (state.events.find(function (row) { return Number(row.id) === id; }) || {}).slug + "/");
          event.target.textContent = "Enlace copiado";
          return;
        }
        var action = event.target.dataset.eventAction;
        if (!action) return;
        if (action === "preview") window.open("/admin/entradas/vista-previa/?id=" + id, "_blank", "noopener");
        if (action === "publication") jsonRequest(api + "/admin/events/" + id + "/" + ((state.events.find(function (row) { return Number(row.id) === id; }) || {}).visible ? "unpublish" : "publish"), "POST", {}).then(loadEvents).catch(showFatal);
        if (action === "duplicate") jsonRequest(api + "/admin/events/" + id + "/duplicate", "POST", {}).then(function (data) { window.location.href = "/admin/eventos/" + data.event.id + "/editar/"; }).catch(showFatal);
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
    data.reference_price_cents = data.reference_price === "" ? null : Math.round(Number(data.reference_price || 0) * 100);
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
      var hasReference = !!type.has_reference_price && Number(type.reference_price_cents || 0) > Number(type.final_price_cents || 0);
      var commercialPrice = hasReference ? '<div><dt>Valor</dt><dd><del>' + cents(type.reference_price_cents) + '</del></dd></div><div><dt>Precio especial</dt><dd>' + cents(type.final_price_cents) + '</dd><small>' + escapeHtml(type.promotional_label || 'Precio especial de lanzamiento') + '</small></div>' : '<div><dt>Precio final</dt><dd>' + cents(type.final_price_cents) + '</dd></div>';
      return '<article class="admin-ticket-card" data-ticket-id="' + Number(type.id) + '"><div class="ticket-card-content"><span class="status-pill status-' + escapeHtml(type.effective_status) + '">' + escapeHtml(statusLabel(type.effective_status)) + '</span><h3>' + escapeHtml(type.name) + '</h3><p class="ticket-card-description">' + escapeHtml(type.description || "Sin descripción") + '</p>' + salePeriod + '<dl class="ticket-metrics">' + commercialPrice + '<div><dt>Vendidas</dt><dd>' + Number(type.sold) + '</dd></div><div><dt>Reservadas</dt><dd>' + Number(type.reserved) + '</dd></div><div><dt>Restantes</dt><dd>' + Number(type.available) + '</dd></div><div><dt>Cupo total</dt><dd>' + Number(type.capacity) + '</dd></div></dl></div><details class="ticket-action-menu"><summary aria-label="Acciones para ' + escapeHtml(type.name) + '">•••</summary><div><button type="button" data-ticket-action="edit">Editar</button><button type="button" data-ticket-action="duplicate">Duplicar</button><button type="button" data-ticket-action="up" ' + (index ? "" : "disabled") + '>Subir</button><button type="button" data-ticket-action="down" ' + (index === types.length - 1 ? "disabled" : "") + '>Bajar</button><button class="danger" type="button" data-ticket-action="delete">Archivar</button></div></details></article>';
    }).join("");
  }

  function updateTicketPricePreview(form) {
    var target = form.querySelector("[data-ticket-final-price]");
    if (!target) return;
    var price = Math.max(0, Number(input(form, "price").value || 0));
    var fee = Math.max(0, Number(input(form, "fee").value || 0));
    var tax = Math.max(0, Number(input(form, "tax_rate").value || 0));
    var taxAmount = Math.round(price * tax) / 100;
    target.innerHTML = money.format(price + taxAmount + fee) + '<small>Base ' + money.format(price) + (taxAmount ? ' · IVA ' + money.format(taxAmount) : '') + (fee ? ' · Gestión ' + money.format(fee) : '') + '</small>';
  }

  function fillTicketForm(ticket) {
    var form = document.querySelector("[data-ticket-type-form]");
    if (!form) return;
    form.reset();
    if (!ticket) input(form, "tax_rate").value = "10";
    Object.keys(ticket || {}).forEach(function (key) {
      var field = input(form, key);
      if (!field) return;
      if (field.type === "checkbox") field.checked = !!ticket[key];
      else if (field.type === "datetime-local") field.value = dateInput(ticket[key]);
      else field.value = ticket[key] == null ? "" : ticket[key];
    });
    input(form, "price").value = ticket ? (Number(ticket.price_cents || 0) / 100).toFixed(2) : "";
    input(form, "reference_price").value = ticket && ticket.reference_price_cents != null ? (Number(ticket.reference_price_cents) / 100).toFixed(2) : "";
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
    var price = Math.max(0, Number(input(form, "price").value || 0));
    var fee = Math.max(0, Number(input(form, "fee").value || 0));
    var reference = Math.max(0, Number(input(form, "reference_price").value || 0));
    var start = input(form, "sale_starts_at").value;
    var end = input(form, "sale_ends_at").value;
    var ticketId = Number(input(form, "ticket_type_id").value || 0);
    var existing = (state.event && state.event.ticket_types || []).find(function (type) { return Number(type.id) === ticketId; });
    if (maximum < minimum) return "El máximo por compra no puede ser inferior al mínimo.";
    if (tax < 0 || tax > 100) return "El IVA debe estar entre 0 % y 100 %.";
    if (input(form, "show_reference_price").checked && reference && reference <= price + Math.round(price * tax) / 100 + fee) return "El valor de la experiencia debe ser superior al precio final de venta.";
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
    var id = editorEventId();
    if (!id) { editorNotice("Falta el identificador del evento.", true); return; }
    if (/^\/admin\/entradas\/evento\/?$/.test(window.location.pathname)) {
      window.location.replace("/admin/eventos/" + id + "/editar/");
      return;
    }
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
        if (window.confirm("¿Archivar o eliminar este evento? Las ventas existentes quedarán protegidas.")) jsonRequest(api + "/admin/events/" + id, "DELETE").then(function () { window.location.href = "/admin/eventos/"; }).catch(function (error) { editorNotice(error.message, true); });
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
      var modal = document.querySelector("[data-access-modal]");
      var modalContent = document.querySelector("[data-access-modal-content]");
      // El escáner puede abrirse desde una página aún cacheada durante un
      // despliegue. Creamos el contenedor si falta para no bloquear el acceso.
      if (!modal || !modalContent) {
        modal = document.createElement("div");
        modal.className = "ticket-access-modal";
        modal.hidden = true;
        modal.setAttribute("data-access-modal", "");
        modal.setAttribute("role", "dialog");
        modal.setAttribute("aria-modal", "true");
        modal.setAttribute("aria-labelledby", "access-modal-title");
        modalContent = document.createElement("section");
        modalContent.className = "ticket-access-modal-card";
        modalContent.setAttribute("data-access-modal-content", "");
        modal.appendChild(modalContent);
        document.body.appendChild(modal);
      }
      var manualCodePanel = form.querySelector("[data-manual-code-panel]");
      var cameraWrap = form.querySelector("[data-ticket-camera-wrap]");
      var video = form.querySelector("[data-ticket-camera]");
      var flashButton = form.querySelector("[data-toggle-flash]");
      var switchCameraButton = form.querySelector("[data-switch-camera]");
      var mobileEventTitle = document.querySelector("[data-mobile-event-title]");
      var attendeesRoot = document.querySelector("[data-ticket-attendees]");
      var stream = null;
      var scanning = false;
      var locked = false;
      var proposal = null;
      var resumeCamera = false;
      var facingMode = "environment";
      var torchOn = false;
      var wakeLock = null;
      var printableAttendees = [];
      document.body.classList.add("has-ticket-access-control");
      if (wrap) wrap.hidden = false;

      function updateConnection() {
        document.querySelectorAll("[data-connection-status]").forEach(function (item) {
          item.textContent = navigator.onLine ? "Conectado" : "Sin conexión";
          item.className = "ticket-access-connection " + (navigator.onLine ? "is-online" : "is-offline");
        });
      }
      updateConnection();
      window.addEventListener("online", updateConnection);
      window.addEventListener("offline", updateConnection);

      function accessStatus(value) {
        return ({ not_entered: "Sin acceder", inside: "Dentro", outside: "Fuera" })[value] || "Sin estado";
      }
      function administrativeStatus(value) {
        return ({ issued: "Activa", cancelled: "Cancelada", refunded: "Reembolsada", blocked: "Bloqueada" })[value] || value;
      }
      function actionLabel(action) {
        return ({ entry: "Confirmar primera entrada", exit: "Confirmar salida", reentry: "Confirmar reentrada" })[action] || "Confirmar movimiento";
      }
      function actionDetail(action) {
        return ({ entry: "La entrada es válida y está pendiente de acceder.", exit: "La persona está actualmente dentro del recinto.", reentry: "La persona había salido. Se comprobarán las reglas de reentrada." })[action] || "";
      }
      function clearProposal() {
        proposal = null;
        if (modalContent) modalContent.innerHTML = "";
        if (modal) modal.hidden = true;
      }
      function stopCamera() {
        scanning = false;
        if (stream) stream.getTracks().forEach(function (track) { track.stop(); });
        stream = null;
        torchOn = false;
        if (flashButton) { flashButton.hidden = true; flashButton.textContent = "Linterna"; }
        if (video) video.srcObject = null;
        if (cameraWrap) cameraWrap.hidden = true;
      }
      function releaseWakeLock() {
        if (wakeLock && wakeLock.release) wakeLock.release().catch(function () {});
        wakeLock = null;
      }
      function requestWakeLock() {
        if (!navigator.wakeLock || !navigator.wakeLock.request) return;
        navigator.wakeLock.request("screen").then(function (lock) { wakeLock = lock; }).catch(function () {});
      }
      function setMode() {
        if (!form.access_mode) return;
        form.access_mode.value = "automatic";
        clearProposal();
      }
      function updateEventTitle() {
        if (!mobileEventTitle || !form.event_id) return;
        var selected = form.event_id.options[form.event_id.selectedIndex];
        mobileEventTitle.textContent = selected && selected.value ? selected.textContent : "Selecciona una experiencia";
      }
      function attendeeAction(attendee) {
        if (attendee.status !== "issued") return "";
        var next = attendee.access_status === "not_entered" ? "entry" : (attendee.access_status === "inside" ? "exit" : "reentry");
        return '<button class="text-action" type="button" data-propose-ticket="' + escapeHtml(attendee.public_code) + '" data-propose-action="' + next + '">' + escapeHtml(actionLabel(next)) + '</button>';
      }
      function printGuestList() {
        var selected = form.event_id && form.event_id.options[form.event_id.selectedIndex];
        var eventName = selected && selected.value ? selected.textContent.trim() : "Experiencia";
        var guests = printableAttendees.filter(function (attendee) { return attendee.status === "issued"; });
        var excluded = printableAttendees.length - guests.length;
        var printWindow = window.open("", "_blank");
        if (!printWindow) {
          status.textContent = "El navegador ha bloqueado la ventana de impresión. Permite las ventanas emergentes e inténtalo de nuevo.";
          status.className = "ticket-status is-error";
          return;
        }
        var printedAt = new Intl.DateTimeFormat("es-ES", { dateStyle: "long", timeStyle: "short" }).format(new Date());
        var rows = guests.map(function (attendee, index) {
          var contact = [attendee.email, attendee.phone].filter(Boolean).join(" · ") || "Sin contacto";
          var presence = accessStatus(attendee.access_status);
          return '<tr><td class="number">' + (index + 1) + '</td><td><strong>' + escapeHtml(attendee.name || "Sin nombre") + '</strong><small>' + escapeHtml(contact) + '</small></td><td><strong>' + escapeHtml(attendee.ticket_type_name || "Entrada") + '</strong><small>' + escapeHtml(attendee.order_reference || "Sin referencia") + '</small></td><td><code>' + escapeHtml(attendee.public_code || "") + '</code></td><td>' + escapeHtml(presence) + '</td><td class="manual"><span class="check"></span><span class="line"></span></td></tr>';
        }).join("");
        printWindow.document.open();
        printWindow.document.write('<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Lista de invitados · ' + escapeHtml(eventName) + '</title><style>@page{size:A4 portrait;margin:12mm}*{box-sizing:border-box}body{margin:0;color:#17252a;font-family:Arial,sans-serif;font-size:9pt}header{display:flex;justify-content:space-between;gap:18px;padding-bottom:10px;border-bottom:2px solid #17252a}h1{margin:0 0 4px;font:700 22pt Georgia,serif}p{margin:0;color:#536166;line-height:1.4}.summary{margin:12px 0 10px;padding:8px 10px;background:#f0f3f1;font-size:8.5pt}.summary strong{color:#17252a}table{width:100%;border-collapse:collapse;table-layout:fixed}th{padding:7px 5px;border-bottom:1.5px solid #17252a;text-align:left;color:#405158;font-size:7pt;letter-spacing:.08em;text-transform:uppercase}td{padding:7px 5px;border-bottom:1px solid #b7c1c2;vertical-align:top;line-height:1.3}td strong,td small{display:block}td small{margin-top:2px;color:#5f6d71;font-size:7.5pt;overflow-wrap:anywhere}td code{font-family:"Courier New",monospace;font-size:7.5pt;overflow-wrap:anywhere}.number{width:4%;text-align:center}.manual{width:13%;white-space:nowrap}.check{display:inline-block;width:13px;height:13px;margin-right:8px;border:1px solid #17252a;vertical-align:middle}.line{display:inline-block;width:52px;border-bottom:1px solid #17252a;vertical-align:middle}footer{margin-top:10px;color:#617074;font-size:7.5pt}@media print{thead{display:table-header-group}tr{break-inside:avoid}footer{position:fixed;bottom:0;left:0;right:0}}</style></head><body><header><div><h1>Lista de invitados</h1><p>' + escapeHtml(eventName) + '</p></div><p>Impreso el<br><strong>' + escapeHtml(printedAt) + '</strong></p></header><div class="summary"><strong>' + guests.length + ' entradas activas</strong> · Marca la casilla y anota la hora al validar manualmente.' + (excluded ? ' Se han excluido ' + excluded + ' entrada' + (excluded === 1 ? '' : 's') + ' anulada, reembolsada o bloqueada.' : '') + '</div><table><thead><tr><th class="number">#</th><th>Asistente</th><th>Entrada / pedido</th><th>Código</th><th>Estado actual</th><th class="manual">Control manual</th></tr></thead><tbody>' + rows + '</tbody></table><footer>Lista operativa de Perigallo · La validación definitiva debe registrarse después en el control de acceso.</footer></body></html>');
        printWindow.document.close();
        printWindow.focus();
        window.setTimeout(function () { printWindow.print(); }, 180);
      }
      function loadAttendees() {
        var eventId = Number(form.event_id.value || 0);
        if (!eventId || !attendeesRoot) { if (attendeesRoot) attendeesRoot.hidden = true; return; }
        request(api + "/admin/events/" + eventId + "/attendees").then(function (data) {
          var metrics = data.metrics || {};
          attendeesRoot.hidden = false;
          var attendees = data.attendees || [];
          var history = data.history || [];
          printableAttendees = attendees;
          attendeesRoot.innerHTML = '<div class="ticket-attendee-head"><div><span class="ticket-eyebrow">Estado en directo</span><h2>Control de acceso</h2></div><div class="ticket-attendee-tools"><button class="ticket-btn ticket-print-guests" type="button" data-print-guests>Imprimir lista de invitados</button><div class="ticket-attendee-metrics"><span>' + Number(metrics.not_entered || 0) + ' sin acceder</span><span class="is-inside">' + Number(metrics.inside || 0) + ' dentro</span><span>' + Number(metrics.outside || 0) + ' fuera</span><span>' + Number(metrics.entries || 0) + ' entradas</span><span>' + Number(metrics.exits || 0) + ' salidas</span></div></div></div><div class="ticket-attendee-filters"><label>Buscar<input type="search" data-attendee-search placeholder="Nombre, teléfono, pedido o código"></label><label>Presencia<select data-attendee-filter><option value="all">Todas</option><option value="not_entered">Sin acceder</option><option value="inside">Dentro</option><option value="outside">Fuera</option><option value="incidents">Incidencias</option></select></label></div><div class="ticket-attendee-table" data-attendee-table></div><details class="ticket-access-history"><summary>Historial de movimientos</summary><div data-access-history></div></details>';
          function drawAttendees() {
            var search = (attendeesRoot.querySelector("[data-attendee-search]").value || "").toLowerCase().trim();
            var filter = attendeesRoot.querySelector("[data-attendee-filter]").value;
            var rows = attendees.filter(function (attendee) {
              var searchable = [attendee.name, attendee.email, attendee.phone, attendee.public_code, attendee.order_reference, attendee.ticket_type_name].join(" ").toLowerCase();
              var isIncident = attendee.status !== "issued";
              return (!search || searchable.includes(search)) && (filter === "all" || (filter === "incidents" ? isIncident : attendee.access_status === filter));
            });
            attendeesRoot.querySelector("[data-attendee-table]").innerHTML = '<div class="ticket-attendee-row ticket-attendee-labels"><span>Asistente</span><span>Entrada</span><span>Presencia</span><span>Acción</span></div>' + rows.map(function (attendee) { return '<div class="ticket-attendee-row"><span><strong>' + escapeHtml(attendee.name) + '</strong><small>' + escapeHtml(attendee.email) + ' · ' + escapeHtml(attendee.phone || "sin teléfono") + '</small></span><span><strong>' + escapeHtml(attendee.ticket_type_name) + '</strong><small>' + escapeHtml(attendee.public_code) + ' · ' + escapeHtml(attendee.order_reference || "") + '</small></span><span class="attendee-status attendee-' + escapeHtml(attendee.access_status || attendee.status) + '"><strong>' + escapeHtml(attendee.status === "issued" ? accessStatus(attendee.access_status) : administrativeStatus(attendee.status)) + '</strong><small>' + (attendee.last_entry_at ? 'Último movimiento: ' + escapeHtml(dateText(attendee.last_entry_at)) : 'Sin movimientos') + '</small></span><span>' + attendeeAction(attendee) + (state.role === "admin" && attendee.last_access_action && attendee.last_access_action !== "reversal" ? '<button class="text-action danger" type="button" data-revert-ticket="' + escapeHtml(attendee.public_code) + '">Revertir último</button>' : '') + '</span></div>'; }).join("") + (rows.length ? "" : '<p class="ticket-status">No hay asistentes que coincidan con ese filtro.</p>');
          }
          attendeesRoot.querySelector("[data-access-history]").innerHTML = history.length ? history.map(function (movement) { return '<div class="ticket-access-history-row"><strong>' + escapeHtml(({ entry: "Entrada", exit: "Salida", reentry: "Reentrada", reversal: "Corrección" })[movement.action] || movement.action) + '</strong><span>' + escapeHtml(movement.name || "Entrada") + ' · ' + escapeHtml(dateText(movement.created_at)) + '</span><small>' + escapeHtml(movement.performed_by || "Equipo Perigallo") + (movement.notes ? ' · ' + escapeHtml(movement.notes) : "") + '</small></div>'; }).join("") : '<p class="ticket-status">Todavía no hay movimientos registrados.</p>';
          attendeesRoot.querySelector("[data-attendee-search]").addEventListener("input", drawAttendees);
          attendeesRoot.querySelector("[data-attendee-filter]").addEventListener("change", drawAttendees);
          drawAttendees();
        }).catch(function (error) { attendeesRoot.hidden = false; attendeesRoot.textContent = error.message; });
      }
      function renderProposal(data, value) {
        if (!data.ticket || !data.action) {
          status.textContent = data.message || "No se puede registrar ningún movimiento.";
          status.className = "ticket-status is-error";
          if (modalContent && modal) {
            modalContent.innerHTML = '<section class="ticket-access-decision is-error"><button class="ticket-access-modal-close" type="button" data-cancel-access aria-label="Cerrar">×</button><span class="ticket-eyebrow">Acceso no autorizado</span><h2 id="access-modal-title">No se puede continuar</h2><p>' + escapeHtml(data.message || "No se puede registrar ningún movimiento.") + '</p><div class="ticket-actions"><button class="ticket-btn" type="button" data-cancel-access>Cerrar</button></div></section>';
            modal.hidden = false;
          }
          if (navigator.vibrate) navigator.vibrate([80, 60, 80]);
          return;
        }
        proposal = { value: value, action: data.action, ticket: data.ticket, method: data.method || "qr" };
        status.textContent = "Entrada identificada. Revisa y confirma el movimiento.";
        status.className = "ticket-status is-ready";
        var heading = ({ entry: "Confirmar entrada", exit: "Confirmar salida", reentry: "Confirmar reentrada" })[data.action] || "Confirmar movimiento";
        var button = ({ entry: "Validar entrada", exit: "Registrar salida", reentry: "Validar reentrada" })[data.action] || actionLabel(data.action);
        modalContent.innerHTML = '<section class="ticket-access-decision is-' + escapeHtml(data.action) + '"><button class="ticket-access-modal-close" type="button" data-cancel-access aria-label="Cancelar">×</button><span class="ticket-eyebrow">' + escapeHtml(heading) + '</span><h2 id="access-modal-title" class="ticket-holder-name">' + escapeHtml(data.ticket.attendee_name) + '</h2><p>' + escapeHtml(actionDetail(data.action)) + '</p><div class="ticket-access-ticket-data"><strong>' + escapeHtml(data.ticket.ticket_type_name) + '</strong><span>Código de entrada: ' + escapeHtml(data.ticket.public_code) + '</span><span>Pedido: ' + escapeHtml(data.ticket.order_reference || "No disponible") + '</span><span>Estado actual: ' + escapeHtml(accessStatus(data.ticket.access_status)) + '</span>' + (data.ticket.last_entry_at ? '<span>Última entrada: ' + escapeHtml(dateText(data.ticket.last_entry_at)) + '</span>' : '') + (data.ticket.last_exit_at ? '<span>Última salida: ' + escapeHtml(dateText(data.ticket.last_exit_at)) + '</span>' : '') + (data.ticket.last_access_by ? '<span>Registrado por: ' + escapeHtml(data.ticket.last_access_by) + '</span>' : '') + '</div><div class="ticket-access-decision-meta"><span>' + Number(data.ticket.entry_count || 0) + ' entradas</span><span>' + Number(data.ticket.exit_count || 0) + ' salidas</span></div><div class="ticket-actions"><button class="ticket-btn primary" type="button" data-confirm-access>' + escapeHtml(button) + '</button><button class="ticket-btn" type="button" data-cancel-access>Cancelar</button></div></section>';
        modal.hidden = false;
        if (navigator.vibrate) navigator.vibrate(60);
      }
      function inspect(value, method) {
        if (!value || locked) return;
        if (!navigator.onLine) { status.textContent = "No hay conexión. Por seguridad no se ha registrado ningún acceso."; status.className = "ticket-status is-error"; return; }
        locked = true;
        clearProposal();
        status.textContent = "Consultando entrada...";
        status.className = "ticket-status";
        jsonRequest(api + "/admin/tickets/access-preview", "POST", {
          event_id: Number(form.event_id.value || 0),
          code: value,
          mode: form.access_mode ? form.access_mode.value : "automatic",
          method: method || "manual",
          device_reference: navigator.userAgent.slice(0, 190),
        }).then(function (data) {
          data.method = method || "manual";
          renderProposal(data, value);
          form.code.value = "";
          if (manualCodePanel && !manualCodePanel.hidden) form.code.focus();
          loadAttendees();
        }).catch(function (error) { status.textContent = error.message; status.className = "ticket-status is-error"; }).finally(function () { locked = false; });
      }
      function confirmProposal() {
        if (!proposal || locked) return;
        if (!navigator.onLine) { status.textContent = "No hay conexión. El movimiento no se ha registrado."; status.className = "ticket-status is-error"; return; }
        locked = true;
        status.textContent = proposal.action === "entry" ? "Validando acceso..." : "Registrando movimiento...";
        status.className = "ticket-status";
        if (modalContent) modalContent.querySelectorAll("button").forEach(function (button) { button.disabled = true; });
        jsonRequest(api + "/admin/tickets/access-movement", "POST", {
          event_id: Number(form.event_id.value || 0), token: proposal.value, action: proposal.action,
          method: proposal.method, device_reference: navigator.userAgent.slice(0, 190),
        }).then(function (data) {
          var ticket = data.ticket || proposal.ticket;
          status.textContent = data.message || "Movimiento registrado.";
          status.className = "ticket-status is-success";
          proposal = null;
          if (modalContent && modal) {
            modalContent.innerHTML = '<section class="ticket-access-decision is-success"><span class="ticket-eyebrow">' + escapeHtml(data.action === "entry" ? "Acceso validado" : data.action === "exit" ? "Salida registrada" : "Reentrada validada") + '</span><h2 id="access-modal-title" class="ticket-holder-name">' + escapeHtml(ticket.attendee_name) + '</h2><p><strong>' + escapeHtml(ticket.ticket_type_name) + '</strong></p><div class="ticket-access-ticket-data"><span>Código de entrada: ' + escapeHtml(ticket.public_code) + '</span><span>Estado actual: ' + escapeHtml(accessStatus(ticket.access_status)) + '</span><span>Hora: ' + escapeHtml(dateText(data.action === "exit" ? ticket.last_exit_at : ticket.last_entry_at)) + '</span></div><div class="ticket-actions"><button class="ticket-btn primary" type="button" data-scan-next>Escanear siguiente</button></div></section>';
            modal.hidden = false;
          }
          if (navigator.vibrate) navigator.vibrate([80, 45, 120]);
          loadAttendees();
        }).catch(function (error) { status.textContent = error.message; status.className = "ticket-status is-error"; if (modalContent) { var errorTarget = modalContent.querySelector(".ticket-access-decision p"); if (errorTarget) errorTarget.textContent = error.message; modalContent.querySelectorAll("button").forEach(function (button) { button.disabled = false; }); } }).finally(function () { locked = false; });
      }
      function scanFrame(detector) {
        if (!scanning || !video || locked) { if (scanning) window.requestAnimationFrame(function () { scanFrame(detector); }); return; }
        detector.detect(video).then(function (codes) {
          if (codes.length && codes[0].rawValue) { form.code.value = codes[0].rawValue; resumeCamera = true; stopCamera(); inspect(codes[0].rawValue, "qr"); return; }
          window.requestAnimationFrame(function () { scanFrame(detector); });
        }).catch(function () { window.requestAnimationFrame(function () { scanFrame(detector); }); });
      }
      function startCamera() {
        if (!form.event_id.value) { status.textContent = "Selecciona primero la experiencia."; return; }
        if (manualCodePanel) manualCodePanel.hidden = true;
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.BarcodeDetector) { status.textContent = "Este navegador no permite abrir el lector. Introduce el código de la entrada manualmente."; return; }
        status.textContent = "Preparando cámara...";
        status.className = "ticket-status is-ready";
        navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: facingMode }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false }).then(function (media) {
          stream = media; video.srcObject = media; cameraWrap.hidden = false; scanning = true; requestWakeLock();
          var track = media.getVideoTracks()[0];
          var capabilities = track && track.getCapabilities ? track.getCapabilities() : {};
          if (flashButton) flashButton.hidden = !capabilities.torch;
          return video.play();
        }).then(function () { status.textContent = "Cámara lista. Enfoca el código QR."; status.className = "ticket-status is-ready"; scanFrame(new window.BarcodeDetector({ formats: ["qr_code"] })); }).catch(function (error) { status.textContent = error && error.name === "NotAllowedError" ? "Necesitamos permiso para usar la cámara. Puedes permitirlo en el navegador o introducir el código manualmente." : "No se pudo abrir la cámara. Revisa los permisos y vuelve a intentarlo."; status.className = "ticket-status is-error"; stopCamera(); });
      }
      function toggleFlash() {
        var track = stream && stream.getVideoTracks ? stream.getVideoTracks()[0] : null;
        if (!track || !track.applyConstraints) return;
        torchOn = !torchOn;
        track.applyConstraints({ advanced: [{ torch: torchOn }] }).then(function () {
          if (flashButton) flashButton.textContent = torchOn ? "Apagar linterna" : "Linterna";
        }).catch(function () { torchOn = false; if (flashButton) flashButton.hidden = true; status.textContent = "La linterna no está disponible en esta cámara."; });
      }
      function switchCamera() { facingMode = facingMode === "environment" ? "user" : "environment"; stopCamera(); startCamera(); }
      function cancelProposal() {
        if (locked) return;
        clearProposal();
        status.textContent = "Movimiento cancelado. No se ha modificado la entrada.";
        status.className = "ticket-status";
        form.code.value = "";
        if (manualCodePanel && !manualCodePanel.hidden) form.code.focus();
        if (resumeCamera) { resumeCamera = false; startCamera(); }
      }
      function scanNext() {
        if (locked) return;
        clearProposal();
        status.textContent = "Escáner listo para la siguiente entrada.";
        status.className = "ticket-status is-success";
        form.code.value = "";
        if (manualCodePanel && !manualCodePanel.hidden) form.code.focus();
        if (resumeCamera) { resumeCamera = false; startCamera(); }
      }
      request(api + "/admin/events").then(function (data) {
        form.event_id.innerHTML = '<option value="">Selecciona evento</option>' + data.events.map(function (event) { return '<option value="' + Number(event.id) + '">' + escapeHtml(event.title) + '</option>'; }).join("");
        setMode();
        var selectedEvent = Number(q("event") || 0);
        if (selectedEvent && Array.prototype.some.call(form.event_id.options, function (option) { return Number(option.value) === selectedEvent; })) {
          form.event_id.value = String(selectedEvent);
          updateEventTitle();
          loadAttendees();
        }
        var ticket = q("ticket");
        if (ticket) { if (manualCodePanel) manualCodePanel.hidden = false; form.code.value = ticket; }
      });
      form.event_id.addEventListener("change", function () { clearProposal(); updateEventTitle(); loadAttendees(); });
      form.addEventListener("submit", function (event) { event.preventDefault(); inspect(form.code.value.trim(), "manual"); });
      form.querySelector("[data-open-camera]").addEventListener("click", startCamera);
      var manualButton = form.querySelector("[data-open-manual]");
      if (manualButton) manualButton.addEventListener("click", function () { stopCamera(); if (manualCodePanel) manualCodePanel.hidden = false; form.code.focus(); status.textContent = "Introduce el código de la entrada y pulsa Comprobar código."; status.className = "ticket-status is-ready"; });
      form.querySelector("[data-close-camera]").addEventListener("click", function () { resumeCamera = false; stopCamera(); releaseWakeLock(); });
      if (flashButton) flashButton.addEventListener("click", toggleFlash);
      if (switchCameraButton) switchCameraButton.addEventListener("click", switchCamera);
      if (modal) modal.addEventListener("click", function (event) {
        if (event.target === modal) { if (proposal) cancelProposal(); else scanNext(); return; }
        if (event.target.closest("[data-cancel-access]")) { cancelProposal(); return; }
        if (event.target.closest("[data-confirm-access]")) { confirmProposal(); return; }
        if (event.target.closest("[data-scan-next]")) { scanNext(); }
      });
      document.addEventListener("keydown", function (event) { if (event.key === "Escape" && modal && !modal.hidden) { if (proposal) cancelProposal(); else scanNext(); } });
      document.addEventListener("visibilitychange", function () { if (document.visibilityState === "visible" && stream) requestWakeLock(); });
      window.addEventListener("beforeunload", releaseWakeLock);
      if (attendeesRoot) attendeesRoot.addEventListener("click", function (event) {
        if (event.target.closest("[data-print-guests]")) { printGuestList(); return; }
        var propose = event.target.closest("[data-propose-ticket]");
        if (propose) { form.code.value = propose.dataset.proposeTicket; inspect(propose.dataset.proposeTicket, "manual"); return; }
        var button = event.target.closest("[data-revert-ticket]");
        if (state.role !== "admin" || !button || !window.confirm("¿Revertir el último movimiento? La corrección quedará registrada en el historial.")) return;
        jsonRequest(api + "/admin/events/" + Number(form.event_id.value) + "/tickets/" + encodeURIComponent(button.dataset.revertTicket) + "/revert", "POST", { reason: "Corrección desde control de acceso" })
          .then(function () { status.textContent = "Último movimiento revertido y registrado en el historial."; status.className = "ticket-status is-success"; loadAttendees(); })
          .catch(function (error) { status.textContent = error.message; status.className = "ticket-status is-error"; });
      });
      window.setInterval(function () { if (document.visibilityState === "visible" && form.event_id.value) loadAttendees(); }, 20000);
    });
  }

  initLogin();
  initLogout();
  initList();
  initEditor();
  initScanner();
})();

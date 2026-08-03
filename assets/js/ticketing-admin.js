(function () {
  var api = "/api";
  var state = { csrf: "", event: null, dirty: false };
  var money = new Intl.NumberFormat("es-ES", { style: "currency", currency: "EUR" });

  function request(url, options) {
    return fetch(url, options || {}).then(function (response) {
      return response.json().catch(function () { return { ok: false, error: "Respuesta no valida del servidor." }; }).then(function (data) {
        if (!response.ok || !data.ok) throw new Error(data.error || "No se pudo completar la solicitud.");
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
      onReady();
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
    requireSession(function () {
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
    var node = document.querySelector("[data-save-state]");
    if (node) node.textContent = value ? "Cambios sin guardar" : "Guardado";
  }

  function input(form, name) { return form.querySelector('[name="' + name + '"]'); }

  function fillEventForm(eventData) {
    var form = document.querySelector("[data-event-form]");
    if (!form) return;
    Array.prototype.forEach.call(form.elements, function (field) {
      if (!field.name) return;
      var value = eventData[field.name];
      if (field.name === "gallery") value = (eventData.gallery || []).join("\n");
      if (field.name === "faq") value = (eventData.faq || []).map(function (row) { return typeof row === "object" ? (row.question || "") + " | " + (row.answer || "") : row; }).join("\n");
      if (field.type === "checkbox") field.checked = !!value;
      else if (field.type === "datetime-local") field.value = dateInput(value);
      else if (value != null) field.value = value;
    });
    document.querySelector("[data-editor-title]").textContent = eventData.title || "Nuevo evento";
    document.querySelector("[data-editor-status]").textContent = statusLabel(eventData.effective_status || eventData.status);
    document.querySelector("[data-editor-status]").className = "status-pill status-" + (eventData.effective_status || eventData.status);
    document.querySelector("[data-ticket-count]").textContent = (eventData.ticket_types || []).length;
  }

  function formData(form) {
    var data = {};
    Array.prototype.forEach.call(form.elements, function (field) {
      if (!field.name || field.type === "button" || field.type === "submit") return;
      data[field.name] = field.type === "checkbox" ? field.checked : field.value;
    });
    data.gallery = String(data.gallery || "").split(/\r?\n/).map(function (item) { return item.trim(); }).filter(Boolean);
    data.faq = String(data.faq || "").split(/\r?\n/).map(function (line) {
      var parts = line.split("|");
      return { question: (parts.shift() || "").trim(), answer: parts.join("|").trim() };
    }).filter(function (row) { return row.question || row.answer; });
    return data;
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
      return '<article class="admin-ticket-card" data-ticket-id="' + Number(type.id) + '"><div><span class="status-pill status-' + escapeHtml(type.effective_status) + '">' + escapeHtml(statusLabel(type.effective_status)) + '</span><h3>' + escapeHtml(type.name) + '</h3><p>' + escapeHtml(type.description || "Sin descripción") + '</p><dl class="ticket-metrics"><div><dt>Precio final</dt><dd>' + cents(type.final_price_cents) + '</dd></div><div><dt>Vendidas</dt><dd>' + Number(type.sold) + '</dd></div><div><dt>Reservadas</dt><dd>' + Number(type.reserved) + '</dd></div><div><dt>Restantes</dt><dd>' + Number(type.available) + '</dd></div></dl></div><div class="ticket-card-actions"><button class="text-action" type="button" data-ticket-action="edit">Editar</button><button class="text-action" type="button" data-ticket-action="duplicate">Duplicar</button><button class="text-action" type="button" data-ticket-action="up" ' + (index ? "" : "disabled") + '>↑</button><button class="text-action" type="button" data-ticket-action="down" ' + (index === types.length - 1 ? "disabled" : "") + '>↓</button><button class="text-action danger" type="button" data-ticket-action="delete">Archivar</button></div></article>';
    }).join("");
  }

  function fillTicketForm(ticket) {
    var form = document.querySelector("[data-ticket-type-form]");
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
    document.querySelector("[data-ticket-form-title]").textContent = ticket ? "Editar tipo de entrada" : "Crear tipo de entrada";
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
      form.addEventListener("input", function () { setDirty(true); });
      form.addEventListener("change", function () { setDirty(true); });
      document.querySelectorAll("[data-editor-tab]").forEach(function (tab) { tab.addEventListener("click", function () { switchEditorSection(tab.dataset.editorTab); }); });
      document.querySelector("[data-save-event]").addEventListener("click", function () {
        editorNotice("Guardando...");
        jsonRequest(api + "/admin/events/" + id, "PUT", formData(form)).then(function (data) { state.event = data.event; fillEventForm(data.event); renderTicketTypes(data.event.ticket_types || []); setDirty(false); editorNotice("Cambios guardados."); }).catch(function (error) { editorNotice(error.message, true); });
      });
      document.querySelector("[data-publish-event]").addEventListener("click", function () {
        jsonRequest(api + "/admin/events/" + id, "PUT", formData(form)).then(function () { return jsonRequest(api + "/admin/events/" + id + "/publish", "POST", {}); }).then(function (data) { state.event = data.event; fillEventForm(data.event); setDirty(false); editorNotice("Evento publicado."); }).catch(function (error) { editorNotice(error.message, true); });
      });
      document.querySelector("[data-preview-event]").addEventListener("click", function () {
        var open = function () { window.open("/admin/entradas/vista-previa/?id=" + id, "_blank", "noopener"); };
        if (!state.dirty) return open();
        jsonRequest(api + "/admin/events/" + id, "PUT", formData(form)).then(function (data) { state.event = data.event; setDirty(false); open(); }).catch(function (error) { editorNotice(error.message, true); });
      });
      document.querySelector("[data-archive-event]").addEventListener("click", function () {
        if (window.confirm("¿Archivar o eliminar este evento? Las ventas existentes quedarán protegidas.")) jsonRequest(api + "/admin/events/" + id, "DELETE").then(function () { window.location.href = "/admin/entradas/"; }).catch(function (error) { editorNotice(error.message, true); });
      });
      initTicketForm(id);
      initMediaUpload();
      window.addEventListener("beforeunload", function (event) { if (state.dirty) { event.preventDefault(); event.returnValue = ""; } });
    });
  }

  function initTicketForm(eventId) {
    var form = document.querySelector("[data-ticket-type-form]");
    if (!form) return;
    fillTicketForm(null);
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var ticketId = Number(input(form, "ticket_type_id").value || 0);
      var url = api + "/admin/events/" + eventId + "/ticket-types" + (ticketId ? "/" + ticketId : "");
      jsonRequest(url, ticketId ? "PUT" : "POST", ticketPayload(form)).then(function () { return loadEditor(eventId); }).then(function () { fillTicketForm(null); editorNotice("Entrada guardada."); switchEditorSection("tickets"); }).catch(function (error) { editorNotice(error.message, true); });
    });
    document.querySelector("[data-reset-ticket-form]").addEventListener("click", function () { fillTicketForm(null); });
    document.querySelector("[data-ticket-type-list]").addEventListener("click", function (event) {
      var card = event.target.closest("[data-ticket-id]");
      var action = event.target.dataset.ticketAction;
      if (!card || !action) return;
      var ticketId = Number(card.dataset.ticketId);
      var types = state.event.ticket_types || [];
      var index = types.findIndex(function (row) { return Number(row.id) === ticketId; });
      if (action === "edit") { fillTicketForm(types[index]); form.scrollIntoView({ behavior: "smooth", block: "start" }); }
      if (action === "duplicate") jsonRequest(api + "/admin/events/" + eventId + "/ticket-types/" + ticketId + "/duplicate", "POST", {}).then(function () { return loadEditor(eventId); }).catch(function (error) { editorNotice(error.message, true); });
      if ((action === "up" || action === "down") && index >= 0) {
        var swap = action === "up" ? index - 1 : index + 1;
        if (types[swap]) { var moved = types.slice(); var current = moved[index]; moved[index] = moved[swap]; moved[swap] = current; jsonRequest(api + "/admin/events/" + eventId + "/ticket-types/reorder", "POST", { ids: moved.map(function (row) { return row.id; }) }).then(function () { return loadEditor(eventId); }).catch(function (error) { editorNotice(error.message, true); }); }
      }
      if (action === "delete" && window.confirm("Esta acción eliminará la entrada sin ventas o la archivará si ya tiene pedidos.")) jsonRequest(api + "/admin/events/" + eventId + "/ticket-types/" + ticketId, "DELETE").then(function () { return loadEditor(eventId); }).catch(function (error) { editorNotice(error.message, true); });
    });
  }

  function initMediaUpload() {
    var button = document.querySelector("[data-upload-media]");
    if (!button) return;
    button.addEventListener("click", function () {
      var file = document.querySelector("[data-media-file]").files[0];
      var status = document.querySelector("[data-upload-state]");
      if (!file) { status.textContent = "Selecciona una imagen primero."; return; }
      status.textContent = "Subiendo...";
      var body = new FormData(); body.append("file", file);
      request(api + "/admin/media", { method: "POST", headers: { "X-CSRF-Token": state.csrf }, body: body }).then(function (data) { input(document.querySelector("[data-event-form]"), "image_url").value = data.media.url; setDirty(true); status.textContent = "Imagen subida. Se ha asignado como portada."; }).catch(function (error) { status.textContent = error.message; });
    });
  }

  function initScanner() {
    var form = document.querySelector("[data-ticket-scan]");
    if (!form) return;
    requireSession(function () {
      request(api + "/admin/events").then(function (data) { form.event_id.innerHTML = '<option value="">Selecciona evento</option>' + data.events.map(function (event) { return '<option value="' + Number(event.id) + '">' + escapeHtml(event.title) + '</option>'; }).join(""); });
      form.addEventListener("submit", function (event) { event.preventDefault(); var status = form.querySelector("[data-scan-status]"); jsonRequest(api + "/admin/tickets/scan", "POST", { event_id: Number(form.event_id.value || 0), code: form.code.value.trim() }).then(function (data) { status.textContent = "Resultado: " + data.result.replace("_", " "); form.code.select(); }).catch(function (error) { status.textContent = error.message; }); });
    });
  }

  initLogin();
  initLogout();
  initList();
  initEditor();
  initScanner();
})();

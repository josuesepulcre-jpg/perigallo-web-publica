(function () {
  "use strict";

  var api = "/api";
  var state = { csrf: "", session: null, events: [], orders: [], users: [], discountCodes: [], discountMeta: null };
  var money = new Intl.NumberFormat("es-ES", { style: "currency", currency: "EUR" });

  function escapeHtml(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (char) {
      return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" })[char];
    });
  }

  function request(url, options) {
    options = options || {};
    options.credentials = "same-origin";
    return fetch(url, options).then(function (response) {
      return response.text().then(function (raw) {
        var data = {};
        try { data = raw ? JSON.parse(raw) : {}; } catch (error) { /* API errors remain readable below. */ }
        if (!response.ok || !data.ok) {
          var error = new Error(data.error || "No se pudo completar la solicitud.");
          error.status = response.status;
          throw error;
        }
        return data;
      });
    });
  }

  function jsonRequest(url, method, body) {
    return request(url, {
      method: method,
      headers: { "Content-Type": "application/json", "X-CSRF-Token": state.csrf },
      body: body === undefined ? undefined : JSON.stringify(body)
    });
  }

  function formatMoney(cents) { return money.format(Number(cents || 0) / 100); }
  function formatDate(value, withTime) {
    if (!value) return "Por definir";
    var date = new Date(String(value).replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return "Por definir";
    return new Intl.DateTimeFormat("es-ES", withTime ? { dateStyle: "medium", timeStyle: "short" } : { dateStyle: "medium" }).format(date);
  }
  function nextUrl() {
    var value = new URLSearchParams(window.location.search).get("next") || "/admin/";
    return value.charAt(0) === "/" && value.charAt(1) !== "/" ? value : "/admin/";
  }
  function loginUrl() { return "/admin/login/?next=" + encodeURIComponent(window.location.pathname + window.location.search); }
  function statusLabel(status) {
    return ({ draft: "Borrador", scheduled: "Programado", published: "Publicado", sold_out: "Agotado", finished: "Finalizado", cancelled: "Cancelado", archived: "Archivado", paid: "Pagado", pending: "Pendiente", failed: "Fallido", rejected: "Rechazado", refunded: "Reembolsado", blocked: "Pendiente de cobro" })[status] || status || "Sin estado";
  }

  function currentNav() {
    var path = window.location.pathname;
    if (path.indexOf("/admin/eventos") === 0) return "events";
    if (path.indexOf("/admin/ventas") === 0) return "sales";
    if (path.indexOf("/admin/contactos") === 0) return "contacts";
    if (path.indexOf("/admin/facturacion") === 0) return "billing";
    if (path.indexOf("/admin/descuentos") === 0) return "discounts";
    if (path.indexOf("/admin/formulario") === 0) return "lead_form";
    if (path.indexOf("/admin/analitica") === 0) return "analytics";
    if (path.indexOf("/admin/acceso") === 0) return "access";
    if (path.indexOf("/admin/usuarios") === 0) return "users";
    return "dashboard";
  }

  function roleLabel(role) { return role === "control_acceso" ? "Control de acceso" : "Administrador"; }

  function setAdminDrawer(open) {
    document.body.classList.toggle("admin-drawer-open", !!open);
    document.querySelectorAll("[data-admin-drawer-toggle]").forEach(function (button) {
      button.setAttribute("aria-expanded", String(!!open));
    });
  }

  function ensureAdminMobileStyles() {
    if (document.querySelector('link[data-admin-mobile-styles]')) return;
    var stylesheet = document.createElement("link");
    stylesheet.rel = "stylesheet";
    stylesheet.href = "/assets/css/admin-mobile.css?v=20260819-event-test-mode-v1";
    stylesheet.setAttribute("data-admin-mobile-styles", "");
    document.head.appendChild(stylesheet);
  }

  function installMobileAdminHeader(sidebar, sectionTitle) {
    var shell = sidebar.closest(".admin-app-shell");
    if (!shell || shell.querySelector("[data-admin-mobile-header]")) return;
    var header = document.createElement("header");
    header.className = "admin-mobile-header";
    header.setAttribute("data-admin-mobile-header", "");
    header.innerHTML = '<button class="admin-mobile-menu" type="button" data-admin-drawer-toggle aria-expanded="false" aria-controls="adminSidebar">Menú <span aria-hidden="true">☰</span></button><div><span>Perigallo</span><strong>' + escapeHtml(sectionTitle || "Administración") + '</strong></div>';
    var backdrop = document.createElement("button");
    backdrop.className = "admin-drawer-backdrop";
    backdrop.type = "button";
    backdrop.tabIndex = -1;
    backdrop.setAttribute("aria-label", "Cerrar menú");
    backdrop.setAttribute("data-admin-drawer-backdrop", "");
    sidebar.id = "adminSidebar";
    shell.insertBefore(header, sidebar);
    shell.insertBefore(backdrop, sidebar);
    header.querySelector("[data-admin-drawer-toggle]").addEventListener("click", function () { setAdminDrawer(!document.body.classList.contains("admin-drawer-open")); });
    backdrop.addEventListener("click", function () { setAdminDrawer(false); });
    sidebar.addEventListener("click", function (event) { if (event.target.closest("a, [data-admin-drawer-close]")) setAdminDrawer(false); });
  }

  if (!window.__perigalloAdminDrawerEscape) {
    window.__perigalloAdminDrawerEscape = true;
    document.addEventListener("keydown", function (event) { if (event.key === "Escape") setAdminDrawer(false); });
  }

  function injectShell(sessionData) {
    ensureAdminMobileStyles();
    document.querySelectorAll("[data-admin-user-name]").forEach(function (node) { node.textContent = sessionData.operator || "Perigallo"; });
    document.querySelectorAll("[data-admin-user-role]").forEach(function (node) { node.textContent = roleLabel(sessionData.role); });
    document.querySelectorAll("[data-admin-nav]").forEach(function (node) {
      var active = currentNav();
      var navigation = sessionData.role === "control_acceso"
        ? '<nav class="admin-side-nav" aria-label="Navegación de control de acceso">' +
            '<span class="admin-nav-label">Operativa</span>' +
            '<a href="/admin/" data-admin-nav-item="dashboard">Mi acceso</a>' +
            '<a href="/admin/acceso/" data-admin-nav-item="access">Abrir escáner</a>' +
          '</nav>'
        : '<nav class="admin-side-nav" aria-label="Navegación de administración">' +
            '<span class="admin-nav-label">Inicio</span>' +
            '<a href="/admin/" data-admin-nav-item="dashboard">Panel principal</a>' +
            '<span class="admin-nav-label">Gestión</span>' +
            '<a href="/admin/eventos/" data-admin-nav-item="events">Eventos</a>' +
            '<a href="/admin/ventas/" data-admin-nav-item="sales">Pedidos y ventas</a>' +
            (sessionData.is_owner ? '<a href="/admin/facturacion/" data-admin-nav-item="billing">Facturación</a>' : '') +
            '<a href="/admin/contactos/" data-admin-nav-item="contacts">Base de datos</a>' +
            '<a href="/admin/formulario/" data-admin-nav-item="lead_form">Formulario</a>' +
            '<a href="/admin/descuentos/" data-admin-nav-item="discounts">Códigos de descuento</a>' +
            '<a href="/admin/analitica/" data-admin-nav-item="analytics">Analítica</a>' +
            '<span class="admin-nav-label">Operativa</span>' +
            '<a href="/admin/acceso/" data-admin-nav-item="access">Control de acceso</a>' +
            (sessionData.is_owner ? '<span class="admin-nav-label">Configuración</span><a href="/admin/usuarios/" data-admin-nav-item="users">Equipo y permisos</a>' : '') +
          '</nav>';
      node.innerHTML =
        '<div class="admin-drawer-header"><a class="admin-brand" href="/admin/" aria-label="Administración Perigallo"><img src="/assets/images/perigallo-logo-original.png" alt="Perigallo"><span>Administración</span></a><button class="admin-drawer-close" type="button" data-admin-drawer-close aria-label="Cerrar menú">×</button></div>' +
        navigation +
        '<div class="admin-account"><span data-admin-user-name></span><small data-admin-user-role></small><button type="button" data-admin-logout>Cerrar sesión</button></div>';
      var activeNode = node.querySelector('[data-admin-nav-item="' + active + '"]');
      if (activeNode) activeNode.classList.add("is-active");
      installMobileAdminHeader(node, activeNode ? activeNode.textContent : "Administración");
    });
    document.querySelectorAll("[data-admin-user-name]").forEach(function (node) { node.textContent = sessionData.operator || "Perigallo"; });
    document.querySelectorAll("[data-admin-user-role]").forEach(function (node) { node.textContent = roleLabel(sessionData.role); });
    document.querySelectorAll("[data-admin-logout]").forEach(function (button) {
      button.addEventListener("click", function () {
        jsonRequest(api + "/admin/logout", "POST").finally(function () { window.location.replace("/admin/login/"); });
      });
    });
  }

  function requireSession(onReady) {
    return request(api + "/admin/session").then(function (data) {
      if (!data.authenticated) { window.location.replace(loginUrl()); return; }
      state.csrf = data.csrf || "";
      state.session = data;
      injectShell(data);
      onReady(data);
    }).catch(function (error) { renderPageError(error.message || "No se pudo comprobar la sesión."); });
  }

  function renderPageError(message) {
    document.querySelectorAll("[data-admin-page-status]").forEach(function (node) {
      node.textContent = message;
      node.hidden = false;
    });
  }

  function initLogin() {
    var form = document.querySelector("[data-admin-login-page]");
    if (!form) return;
    request(api + "/admin/session").then(function (data) {
      if (data.authenticated) window.location.replace(nextUrl());
    }).catch(function () { /* The credentials form remains available. */ });
    var password = form.querySelector('[name="password"]');
    var reveal = form.querySelector("[data-toggle-password]");
    if (reveal) reveal.addEventListener("click", function () {
      var visible = password.type === "text";
      password.type = visible ? "password" : "text";
      reveal.setAttribute("aria-pressed", String(!visible));
      reveal.textContent = visible ? "Mostrar" : "Ocultar";
    });
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var submit = form.querySelector('[type="submit"]');
      var status = form.querySelector("[data-admin-login-status]");
      submit.disabled = true;
      status.textContent = "Comprobando acceso…";
      request(api + "/admin/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ username: form.username.value.trim(), password: password.value })
      }).then(function () { window.location.replace(nextUrl()); })
        .catch(function (error) { status.textContent = error.message || "No se ha podido acceder."; })
        .finally(function () { submit.disabled = false; });
    });
  }

  function metrics(events, orders) {
    var now = new Date();
    var upcoming = events.filter(function (event) { return event.status !== "archived" && new Date(String(event.starts_at).replace(" ", "T")) >= now; });
    var paid = orders.filter(function (order) { return order.payment_status === "paid" || order.status === "paid"; });
    return {
      upcoming: upcoming,
      drafts: events.filter(function (event) { return event.effective_status === "draft" || event.status === "draft"; }).length,
      published: events.filter(function (event) { return event.visible; }).length,
      sold: events.reduce(function (total, event) { return total + Number(event.sold || 0); }, 0),
      pending: events.reduce(function (total, event) { return total + Number(event.available || 0); }, 0),
      revenue: paid.reduce(function (total, order) { return total + Number(order.total_cents || 0); }, 0)
    };
  }

  function eventCard(event, featured) {
    var image = event.card_image_url || event.image_url || "/assets/images/perigallo-hero-original-01.jpg";
    var occupancy = event.capacity ? Math.min(100, Math.round(Number(event.sold || 0) * 100 / Number(event.capacity))) : 0;
    var archived = (event.effective_status || event.status) === "archived";
    var testMode = Number(event.is_test) === 1;
    var deleteControl = state.session && state.session.is_owner
      ? (testMode ? '<button class="text-action danger" type="button" data-event-action="delete" data-event-id="' + Number(event.id) + '">Eliminar definitivamente</button>' : '<span class="admin-event-protected">Protegido · solo se puede archivar</span>')
      : '';
    var lifecycleActions = !featured
      ? '<div class="admin-event-lifecycle-actions" aria-label="Acciones de ciclo de vida para ' + escapeHtml(event.title) + '"><button class="text-action" type="button" data-event-action="' + (archived ? 'restore' : 'archive') + '" data-event-id="' + Number(event.id) + '">' + (archived ? 'Restaurar evento' : 'Archivar evento') + '</button>' + deleteControl + '</div>'
      : '';
    return '<article class="admin-featured-event' + (featured ? " is-featured" : "") + '">' +
      '<div class="admin-featured-image" style="background-image:url(' + escapeHtml(image) + ')"></div>' +
      '<div class="admin-featured-copy"><span class="status-pill status-' + escapeHtml(event.effective_status || event.status) + '">' + escapeHtml(statusLabel(event.effective_status || event.status)) + '</span>' + (testMode ? '<span class="status-pill status-scheduled">Modo prueba</span>' : '') +
      '<h2>' + escapeHtml(event.title) + '</h2><p>' + escapeHtml(formatDate(event.starts_at, true)) + ' · ' + escapeHtml(event.location || "Ubicación por definir") + '</p>' +
      '<div class="admin-progress"><span><strong>' + Number(event.sold || 0) + '</strong> entradas vendidas</span><span>' + Number(event.capacity || 0) + ' aforo · ' + occupancy + '%</span><i><b style="width:' + occupancy + '%"></b></i></div>' +
      '<div class="admin-inline-actions"><a class="ticket-btn primary" href="/admin/eventos/' + Number(event.id) + '/editar/">Editar evento</a><a class="ticket-btn" href="/admin/ventas/?event=' + Number(event.id) + '">Ver ventas</a><a class="ticket-btn" href="/admin/acceso/?event=' + Number(event.id) + '">Control de acceso</a><a class="text-action" href="/eventos/' + encodeURIComponent(event.slug) + '/" target="_blank" rel="noopener noreferrer">Página pública</a></div>' + lifecycleActions + '</div></article>';
  }

  function renderDashboard(summary, orders) {
    var root = document.querySelector("[data-admin-dashboard]");
    if (!root) return;
    var data = metrics(summary.events || [], orders || []);
    var featured = data.upcoming[0] || (summary.events || [])[0];
    var holdedReview = (summary.holded || []).reduce(function (total, row) { return total + (row.holded_status === "requires_review" ? Number(row.total || 0) : 0); }, 0);
    root.innerHTML =
      '<section class="admin-page-heading"><div><span class="ticket-eyebrow">Administración Perigallo</span><h1>Todo lo que ocurre, <em>conectado.</em></h1><p>Gestiona experiencias, ventas y acceso desde un único lugar.</p></div><a class="ticket-btn primary" href="/admin/eventos/?new=1">Crear evento</a></section>' +
      '<section class="admin-stat-grid" aria-label="Resumen de actividad">' +
        '<article><span>Próximos eventos</span><strong>' + data.upcoming.length + '</strong><small>' + data.drafts + ' borradores</small></article>' +
        '<article><span>Entradas vendidas</span><strong>' + data.sold + '</strong><small>En todos los eventos</small></article>' +
        '<article><span>Ingresos cobrados</span><strong>' + formatMoney(data.revenue) + '</strong><small>Pedidos confirmados</small></article>' +
        '<article><span>Capacidad pendiente</span><strong>' + data.pending + '</strong><small>Plazas aún disponibles</small></article>' +
        '<article><span>Holded · revisión</span><strong>' + holdedReview + '</strong><small>' + (holdedReview ? 'Pedidos que requieren revisión' : 'Sin alertas fiscales') + '</small></article>' +
      '</section>' +
      (featured ? '<section class="admin-dashboard-section"><div class="admin-section-label"><span class="ticket-eyebrow">Próximo evento</span><a class="text-action" href="/admin/eventos/">Ver todos los eventos</a></div>' + eventCard(featured, true) + '</section>' : '<section class="admin-empty"><strong>Todavía no hay eventos.</strong><span>Crea la primera experiencia para empezar a vender entradas.</span><a class="ticket-btn primary" href="/admin/eventos/?new=1">Crear evento</a></section>') +
      '<section class="admin-dashboard-section"><div class="admin-section-label"><div><span class="ticket-eyebrow">Actividad reciente</span><h2>Últimos pedidos</h2></div><a class="text-action" href="/admin/ventas/">Ver ventas</a></div><div class="admin-recent-orders">' + renderOrdersRows(orders.slice(0, 6), true) + '</div></section>';
  }

  function isClosedOrder(order) { return order.display_status === "cancelled" || order.display_status === "refunded"; }

  function cashWhatsAppUrl(order) {
    var phone = String(order.phone || "").replace(/\D/g, "");
    if (phone.length === 9 && /^[67]/.test(phone)) phone = "34" + phone;
    var manualCard = order.sales_channel === "manual_card";
    var link = window.location.origin + "/entradas/pedido/?token=" + encodeURIComponent(order.public_token) + (manualCard ? "&pay=1" : "");
    var message = manualCard
      ? "Hola " + (order.name || "") + ",\n\nHemos reservado tus entradas para " + (order.event_title || "Perigallo") + ". Completa el pago seguro con tarjeta aquí:\n" + link + "\n\nLas entradas se emitirán automáticamente al confirmarse el pago."
      : "Hola " + (order.name || "") + ",\n\nAquí tienes tus entradas para " + (order.event_title || "Perigallo") + ".\n" + link;
    return "https://wa.me/" + encodeURIComponent(phone) + "?text=" + encodeURIComponent(message);
  }

  function manualCardPaymentUrl(order) {
    return window.location.origin + "/entradas/pedido/?token=" + encodeURIComponent(order.public_token) + "&pay=1";
  }

  function deliveryStatusLabel(status) {
    return ({sent:"Enviado",delivered:"Entregado",read:"Leído",manual_sent:"Enviado manualmente",queued:"En cola",pending:"Pendiente",blocked:"Bloqueado",failed:"Fallido",not_authorized:"No autorizado"})[status] || "Pendiente";
  }

  function deliveryStatusClass(status) {
    if (["sent", "delivered", "read", "manual_sent"].indexOf(status) !== -1) return "is-success";
    if (status === "failed") return "is-error";
    return "is-neutral";
  }

  function deliveryStatusMarkup(channel, status) {
    return '<span class="admin-delivery-status ' + deliveryStatusClass(status) + '">' + escapeHtml(channel + ' ' + deliveryStatusLabel(status)) + '</span>';
  }

  function orderActions(order) {
    if (!state.session || state.session.role !== "admin") return "";
    var actions = '<div class="admin-order-actions"><a class="text-action" href="/entradas/pedido/?token=' + encodeURIComponent(order.public_token) + '" target="_blank" rel="noopener noreferrer">Ver pedido</a><button type="button" class="text-action" data-order-action="edit" data-order-id="' + Number(order.id) + '">Editar</button>';
    if (Number(order.allergy_attendee_count || 0)) actions += '<button type="button" class="text-action" data-order-allergies data-order-id="' + Number(order.id) + '">Alergias (' + Number(order.allergy_attendee_count) + ')</button>';
    if (order.sales_channel === "cash" && order.cash_payment_status !== "paid" && !isClosedOrder(order)) actions += '<button type="button" class="text-action" data-order-action="cash-payment" data-order-id="' + Number(order.id) + '">Registrar cobro</button>';
    if (order.sales_channel === "cash" && !isClosedOrder(order)) actions += '<button type="button" class="text-action" data-order-action="send-cash" data-order-id="' + Number(order.id) + '">' + (order.cash_payment_status === "paid" ? "Abrir WhatsApp" : "Enviar reserva") + '</button>';
    if (order.sales_channel === "manual_card" && order.payment_status !== "paid" && !isClosedOrder(order)) actions += '<button type="button" class="text-action" data-order-action="copy-payment-link" data-order-id="' + Number(order.id) + '">Copiar enlace de pago</button><button type="button" class="text-action" data-order-action="send-manual-card" data-order-id="' + Number(order.id) + '">Enviar link por WhatsApp</button>';
    if (!isClosedOrder(order)) actions += '<button type="button" class="text-action" data-order-action="cancel" data-order-id="' + Number(order.id) + '">Cancelar</button>';
    if (!isClosedOrder(order) && (order.payment_status === "paid" || order.status === "paid")) actions += '<button type="button" class="text-action" data-order-action="refund" data-order-id="' + Number(order.id) + '">Registrar devolución</button>';
    if ((order.payment_status === "paid" || order.status === "paid") && order.sales_channel !== "cash") {
      if (order.email_delivery_status !== "sent") actions += '<button type="button" class="text-action" data-order-action="delivery-email-confirm" data-order-id="' + Number(order.id) + '">Marcar correo enviado</button>';
      actions += '<button type="button" class="text-action" data-order-action="delivery-email" data-order-id="' + Number(order.id) + '">Reenviar correo</button>';
      if (Number(order.whatsapp_consent)) actions += '<button type="button" class="text-action" data-order-action="delivery-whatsapp" data-order-id="' + Number(order.id) + '">Reenviar WhatsApp</button>';
    }
    if (state.session.is_owner && order.sales_channel !== "cash" && !Number(order.is_test) && (order.payment_status === "paid" || order.status === "paid") && order.holded_status && order.holded_status !== "synced") actions += '<button type="button" class="text-action" data-order-action="holded-retry" data-order-id="' + Number(order.id) + '">Reintentar Holded</button>';
    if (state.session.is_owner && Number(order.is_test)) actions += '<button type="button" class="text-action danger" data-order-action="purge-test" data-order-id="' + Number(order.id) + '">Eliminar prueba</button>';
    return actions + '</div>';
  }

  function renderOrdersRows(orders, compact) {
    if (!orders.length) return '<div class="admin-empty"><strong>No se han registrado pedidos.</strong><span>Los pedidos aparecerán aquí cuando se complete una compra.</span></div>';
    return orders.map(function (order) {
      var reference = order.redsys_order || order.test_reference || ("Pedido " + order.id);
      var displayStatus = order.display_status || order.payment_status || order.status;
      var paymentMethod = order.payment_method === "cash" ? "Efectivo" : (order.payment_method === "bizum" ? "Bizum" : "Tarjeta");
      var cashStatus = order.sales_channel === "cash" ? ' · ' + (order.cash_payment_status === "paid" ? "Cobrado" : "Reserva pendiente") : (order.sales_channel === "manual_card" ? ' · ' + (order.payment_status === "paid" ? "Cobrado con tarjeta" : "Pago con tarjeta pendiente") : "");
      var discount = Number(order.discount_amount_cents || 0);
      var amount = discount
        ? '<strong><s>' + formatMoney(order.subtotal_cents) + '</s> ' + formatMoney(order.total_cents) + '</strong><small class="admin-order-discount">' + (order.discount_code ? 'Cupón ' + escapeHtml(order.discount_code) : 'Descuento manual') + ' · −' + formatMoney(discount) + '</small>'
        : '<strong>' + formatMoney(order.total_cents) + '</strong>';
      var allergySummary = Number(order.allergy_attendee_count || 0) ? ' · ' + Number(order.allergy_attendee_count) + ' alergia' + (Number(order.allergy_attendee_count) === 1 ? '' : 's') + ' comunicada' + (Number(order.allergy_attendee_count) === 1 ? '' : 's') : '';
      var holded = order.sales_channel === "cash" ? '<small class="admin-order-holded admin-order-cash">Registro web · sin Holded</small>' : !Number(order.is_test) && order.holded_status ? '<small class="admin-order-holded">Holded: ' + escapeHtml({not_required:"No requerido",pending:"Pendiente",processing:"Procesando",synced:"Sincronizado",error:"Reintento programado",requires_review:"Revisión necesaria"}[order.holded_status] || order.holded_status) + (order.holded_document_number ? ' · ' + escapeHtml(order.holded_document_number) : '') + '</small>' : '';
      var paymentNote = order.sales_channel === "cash" && order.cash_payment_notes ? '<small class="admin-order-cash-note">Efectivo: ' + escapeHtml(order.cash_payment_notes) + '</small>' : '';
      var emailDeliveryStatus = ["sent", "queued", "pending", "failed"].indexOf(order.email_delivery_status) !== -1 ? order.email_delivery_status : "pending";
      var whatsAppDeliveryStatus = !Number(order.whatsapp_consent) ? "not_authorized" : (["sent", "delivered", "read", "queued", "pending", "blocked", "failed", "not_authorized"].indexOf(order.whatsapp_delivery_status) !== -1 ? order.whatsapp_delivery_status : "queued");
      var isPaidCashOrder = order.sales_channel === "cash" && order.cash_payment_status === "paid";
      var delivery = isPaidCashOrder
        ? '<small class="admin-order-delivery"><span><strong>Entrega:</strong> ' + deliveryStatusMarkup("WhatsApp", "manual_sent") + '<span class="admin-delivery-meta"> · envío desde WhatsApp Business</span></span></small>'
        : '<small class="admin-order-delivery"><span><strong>Entrega:</strong> ' + deliveryStatusMarkup("Email", emailDeliveryStatus) + (order.email_delivery_error ? '<span class="admin-delivery-error"> · ' + escapeHtml(order.email_delivery_error) + '</span>' : '') + '</span><span>' + deliveryStatusMarkup("WhatsApp", whatsAppDeliveryStatus) + (order.whatsapp_recipient ? '<span class="admin-delivery-meta"> · ' + escapeHtml(order.whatsapp_recipient) + '</span>' : '') + (order.whatsapp_template_name ? '<span class="admin-delivery-meta"> · ' + escapeHtml(order.whatsapp_template_name) + '</span>' : '') + (order.whatsapp_delivery_error ? '<span class="admin-delivery-error"> · ' + escapeHtml(order.whatsapp_delivery_error) + '</span>' : '') + '</span></small>';
      return '<article class="admin-order-row"><div><strong>' + escapeHtml(order.name || "Comprador sin nombre") + '</strong><small>' + escapeHtml(order.event_title || "Evento por asignar") + ' · ' + escapeHtml(reference) + ' · ' + paymentMethod + cashStatus + allergySummary + (Number(order.is_test) ? ' · Prueba' : '') + '</small>' + paymentNote + holded + delivery + '</div><span>' + Number(order.ticket_quantity || 0) + ' entrada' + (Number(order.ticket_quantity || 0) === 1 ? "" : "s") + '</span><span class="status-pill status-' + escapeHtml(displayStatus) + '">' + escapeHtml(statusLabel(displayStatus)) + '</span><span class="admin-order-amount">' + amount + '</span>' + (compact ? "" : orderActions(order)) + '</article>';
    }).join("");
  }

  function showOrderAllergies(data) {
    var rows = (data.attendees || []).map(function (attendee) {
      var hasAllergies = Number(attendee.has_allergies) === 1;
      return '<div class="ticket-access-ticket-data"><strong>' + escapeHtml(attendee.attendee_name) + '</strong><span>' + escapeHtml(attendee.ticket_type_name || 'Entrada') + (attendee.public_code ? ' · ' + escapeHtml(attendee.public_code) : '') + '</span>' +
        (hasAllergies
          ? '<span><strong>Alergias:</strong> ' + escapeHtml(attendee.allergens || 'Sin detalle') + '</span><span><strong>Gravedad:</strong> ' + (Number(attendee.severe_allergy) ? 'Grave' : 'No grave') + '</span>' + (attendee.allergy_notes ? '<span><strong>Notas:</strong> ' + escapeHtml(attendee.allergy_notes) + '</span>' : '')
          : '<span>Sin alergias comunicadas.</span>') + '</div>';
    }).join("");
    var modal = document.createElement("div");
    modal.className = "ticket-access-modal";
    modal.innerHTML = '<div class="ticket-access-modal-card" role="dialog" aria-modal="true" aria-label="Alergias del pedido"><button type="button" class="ticket-access-modal-close" aria-label="Cerrar">×</button><section class="ticket-access-decision"><span class="ticket-eyebrow">Información sensible · pedido ' + escapeHtml(data.order && data.order.reference || '') + '</span><h2 class="ticket-holder-name">Alergias de los asistentes</h2><p>Consulta esta información únicamente para coordinar la atención del evento.</p>' + (rows || '<p>No hay asistentes registrados para este pedido.</p>') + '</section></div>';
    function close() { modal.remove(); }
    modal.addEventListener("click", function (event) { if (event.target === modal || event.target.closest(".ticket-access-modal-close")) close(); });
    document.body.appendChild(modal);
    modal.querySelector(".ticket-access-modal-close").focus();
  }

  function initDashboard() {
    if (!document.querySelector("[data-admin-dashboard]")) return;
    requireSession(function (sessionData) {
      if (sessionData.role === "control_acceso") {
        document.querySelector("[data-admin-dashboard]").innerHTML =
          '<section class="admin-page-heading"><div><span class="ticket-eyebrow">Operativa de puerta</span><h1>Control de <em>acceso.</em></h1><p>Esta cuenta está preparada para validar entradas y consultar el estado de acceso durante la experiencia.</p></div></section>' +
          '<section class="admin-empty"><strong>¿Vas a gestionar la puerta?</strong><span>Selecciona el evento y abre el escáner desde tu teléfono. La configuración, publicación y ventas están disponibles únicamente para la cuenta de administración.</span><a class="ticket-btn primary" href="/admin/acceso/">Abrir escáner</a></section>';
        return;
      }
      Promise.all([request(api + "/admin/summary"), request(api + "/admin/orders")]).then(function (result) {
        state.events = result[0].summary.events || [];
        state.orders = result[1].orders || [];
        renderDashboard(result[0].summary, state.orders);
      }).catch(function (error) { renderPageError(error.message); });
    });
  }

  function renderEvents(events, filter) {
    var target = document.querySelector("[data-admin-events-list]");
    if (!target) return;
    var query = String(filter || "").trim().toLowerCase();
    var rows = events.filter(function (event) { return !query || [event.title, event.location, event.status, event.effective_status].join(" ").toLowerCase().includes(query); });
    if (!rows.length) {
      target.innerHTML = '<div class="admin-empty"><strong>No se han encontrado eventos.</strong><span>Prueba con otro nombre o crea una nueva experiencia.</span></div>';
      return;
    }
    target.innerHTML = rows.map(function (event) { return eventCard(event, false); }).join("");
  }

  function initEvents() {
    var root = document.querySelector("[data-admin-events-page]");
    if (!root) return;
    requireSession(function () {
      var create = root.querySelector("[data-admin-create-event]");
      var createTest = root.querySelector("[data-admin-create-test-event]");
      var search = root.querySelector("[data-admin-event-search]");
      var status = root.querySelector("[data-admin-page-status]");
      function showEventsStatus(message, isError) {
        if (!status) return;
        status.textContent = message;
        status.hidden = false;
        status.className = "ticket-status " + (isError ? "is-error" : "is-success");
        status.scrollIntoView({ block: "nearest", behavior: "smooth" });
      }
      function refreshEvents(message) {
        return request(api + "/admin/events").then(function (data) {
          state.events = data.events || [];
          renderEvents(state.events, search.value);
          if (message) showEventsStatus(message, false);
        });
      }
      function createEvent(isTest, button) {
        button.disabled = true;
        var originalLabel = button.textContent;
        button.textContent = "Creando…";
        jsonRequest(api + "/admin/events", "POST", { title: isTest ? "Nuevo evento de prueba" : "Nuevo evento", is_test: !!isTest }).then(function (data) {
          window.location.assign("/admin/eventos/" + data.event.id + "/editar/");
        }).catch(function (error) { renderPageError(error.message); button.disabled = false; button.textContent = originalLabel; });
      }
      create.addEventListener("click", function () { createEvent(false, create); });
      createTest.addEventListener("click", function () { createEvent(true, createTest); });
      refreshEvents().then(function () {
        search.addEventListener("input", function () { renderEvents(state.events, search.value); });
        var newMode = new URLSearchParams(window.location.search).get("new");
        if (newMode === "1") createEvent(false, create);
        if (newMode === "test") createEvent(true, createTest);
      }).catch(function (error) { renderPageError(error.message); });
      root.addEventListener("click", function (event) {
        var button = event.target.closest("[data-event-action]");
        if (!button) return;
        var id = Number(button.getAttribute("data-event-id"));
        var action = button.getAttribute("data-event-action");
        var item = state.events.find(function (row) { return Number(row.id) === id; });
        if (!id || !item) return;
        if (action === "archive" && !window.confirm('Archivarás “' + item.title + '”. Dejará de estar a la venta y conservará todos sus datos. ¿Continuar?')) return;
        if (action === "restore" && !window.confirm('Restaurarás “' + item.title + '” como borrador. Tendrás que revisarlo y publicarlo de nuevo cuando corresponda. ¿Continuar?')) return;
        if (action === "delete" && !window.confirm('¿Eliminar definitivamente la prueba “' + item.title + '”? Se borrarán también todos sus pedidos, entradas y registros técnicos. Esta acción no se puede deshacer.')) return;
        button.disabled = true;
        var url = api + "/admin/events/" + id + (action === "delete" ? "" : "/" + action);
        jsonRequest(url, action === "delete" ? "DELETE" : "POST", {}).then(function () {
          var message = action === "delete" ? "Evento eliminado definitivamente." : action === "archive" ? "Evento archivado. Sus ventas y datos se han conservado." : "Evento restaurado como borrador.";
          return refreshEvents(message);
        }).catch(function (error) { showEventsStatus(error.message || "No se ha podido completar la acción.", true); }).finally(function () { button.disabled = false; });
      });
    });
  }

  function initSales() {
    var root = document.querySelector("[data-admin-sales-page]");
    if (!root) return;
    requireSession(function (sessionData) {
      var search = root.querySelector("[data-admin-sales-search]");
      var filters = root.querySelectorAll("[data-admin-order-filter]");
      var activeFilter = "active";
      var status = root.querySelector("[data-admin-orders-status]");
      var modal = root.querySelector("[data-admin-cash-modal]");
      var form = root.querySelector("[data-admin-cash-order-form]");
      var editModal = root.querySelector("[data-admin-order-edit-modal]");
      var editForm = root.querySelector("[data-admin-order-edit-form]");
      var cashMeta = { events: [] };
      var attendeeExportEvent = root.querySelector("[data-attendee-export-event]");
      var attendeeExportSort = root.querySelector("[data-attendee-export-sort]");
      var attendeeExportButton = root.querySelector("[data-download-attendee-list]");
      var attendeeExportStatus = root.querySelector("[data-attendee-export-status]");
      function renderSalesSummary() {
        var summary = root.querySelector("[data-admin-sales-summary]");
        if (!summary) return;
        var paid = state.orders.filter(function (order) { return Number(order.is_test) !== 1 && !isClosedOrder(order) && (order.payment_status === "paid" || order.status === "paid"); });
        var cashPaid = paid.filter(function (order) { return order.sales_channel === "cash"; });
        var cashReserved = state.orders.filter(function (order) { return Number(order.is_test) !== 1 && !isClosedOrder(order) && order.sales_channel === "cash" && order.cash_payment_status === "reserved"; });
        function tickets(rows) { return rows.reduce(function (total, order) { return total + Number(order.ticket_quantity || 0); }, 0); }
        summary.innerHTML = '<article><span>Entradas vendidas · global</span><strong>' + tickets(paid) + '</strong><small>Web, tarjeta y efectivo cobrados</small></article>' +
          '<article><span>Ingresos cobrados · global</span><strong>' + formatMoney(paid.reduce(function (total, order) { return total + Number(order.total_cents || 0); }, 0)) + '</strong><small>Web, tarjeta y efectivo cobrados</small></article>' +
          '<article><span>Efectivo cobrado</span><strong>' + tickets(cashPaid) + '</strong><small>' + formatMoney(cashPaid.reduce(function (total, order) { return total + Number(order.total_cents || 0); }, 0)) + '</small></article>' +
          '<article><span>Reservas en efectivo</span><strong>' + tickets(cashReserved) + '</strong><small>Pendientes de cobro</small></article>';
      }
      function matchesFilter(order) {
        var displayStatus = order.display_status || order.payment_status || order.status;
        if (activeFilter === "active") return Number(order.is_test) !== 1 && !isClosedOrder(order) && (displayStatus === "paid" || order.status === "paid" || order.payment_status === "paid");
        if (activeFilter === "pending") return !isClosedOrder(order) && !(displayStatus === "paid" || order.status === "paid" || order.payment_status === "paid");
        if (activeFilter === "cancelled") return displayStatus === "cancelled";
        if (activeFilter === "refunded") return displayStatus === "refunded";
        if (activeFilter === "tests") return Number(order.is_test) === 1;
        if (activeFilter === "with-notes") return String(order.cash_payment_notes || "").trim() !== "";
        if (activeFilter === "email-failed") return order.email_delivery_status === "failed";
        if (activeFilter === "whatsapp-failed") return order.whatsapp_delivery_status === "failed";
        if (activeFilter === "partial-delivery") return (order.email_delivery_status === "sent") !== ["sent", "delivered", "read", "not_authorized"].includes(order.whatsapp_delivery_status || (Number(order.whatsapp_consent) ? "pending" : "not_authorized"));
        if (activeFilter === "no-delivery") return order.email_delivery_status !== "sent" && !["sent", "delivered", "read"].includes(order.whatsapp_delivery_status || "");
        return true;
      }
      function render(filter) {
        var term = String(filter || "").trim().toLowerCase();
        var rows = state.orders.filter(function (order) {
          return matchesFilter(order) && (!term || [order.name, order.email, order.phone, order.redsys_order, order.test_reference, order.event_title, order.payment_status, order.display_status].join(" ").toLowerCase().includes(term));
        });
        root.querySelector("[data-admin-orders-list]").innerHTML = renderOrdersRows(rows, false);
        if (status) status.textContent = rows.length + (rows.length === 1 ? " pedido mostrado" : " pedidos mostrados");
        renderSalesSummary();
      }
      function reload() {
        request(api + "/admin/orders").then(function (data) { state.orders = data.orders || []; render(search.value); }).catch(function (error) { renderPageError(error.message); });
      }
      function setCashStatus(message) {
        var target = form && form.querySelector("[data-cash-order-status]");
        if (target) target.textContent = message || "";
      }
      function closeCashModal() {
        if (modal) modal.hidden = true;
        setCashStatus("");
      }
      function setEditStatus(message, stateName) {
        var target = editForm && editForm.querySelector("[data-order-edit-status]");
        if (!target) return;
        target.textContent = message || "";
        if (stateName) target.setAttribute("data-state", stateName); else target.removeAttribute("data-state");
      }
      function closeEditModal() {
        if (editModal) editModal.hidden = true;
        setEditStatus("");
      }
      function openEditModal(order) {
        if (!editModal || !editForm || !order) return;
        var fullName = String(order.name || "").trim();
        var parts = fullName ? fullName.split(/\s+/) : [];
        var fallbackFirstName = parts.shift() || "";
        var fallbackLastName = parts.join(" ");
        var firstName = String(order.first_name || fallbackFirstName);
        var lastName = String(order.last_name || fallbackLastName);
        editForm.order_id.value = String(order.id);
        editForm.first_name.value = firstName;
        editForm.last_name.value = lastName;
        editForm.email.value = String(order.email || "");
        editForm.phone.value = String(order.phone || "");
        var cashNotes = editForm.querySelector("[data-order-edit-cash-note]");
        if (cashNotes) {
          cashNotes.hidden = order.sales_channel !== "cash";
          editForm.cash_payment_notes.value = String(order.cash_payment_notes || "");
        }
        setEditStatus("");
        editModal.hidden = false;
        editForm.first_name.focus();
      }
      function setDefaultExpiry() {
        var input = form && form.querySelector("[data-cash-expiry]");
        if (!input || input.value) return;
        var date = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000);
        date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
        input.value = date.toISOString().slice(0, 16);
      }
      function renderCashTicketLines() {
        var eventId = Number(form && form.event_id.value);
        var event = cashMeta.events.filter(function (row) { return Number(row.id) === eventId; })[0];
        var lines = form && form.querySelector("[data-cash-ticket-lines]");
        var manualReserve = !!(form && form.inventory_mode && form.inventory_mode.value === "manual_reserve");
        if (!lines) return;
        if (!event || !(event.ticket_types || []).length) {
          lines.innerHTML = '<p>No hay tipos de entrada disponibles para esta emisión interna.</p>';
          renderCashAttendees();
          updateCashOrderTotal();
          return;
        }
        lines.innerHTML = (event.ticket_types || []).map(function (type) {
          var available = Number(manualReserve ? type.manual_available : type.available) || 0;
          var manualOnly = ["paused", "closed", "hidden"].includes(type.status);
          var availabilityLabel = manualReserve ? ' plazas del cupo manual' : ' disponibles';
          return '<label><span><strong>' + escapeHtml(type.name) + '</strong><small>' + formatMoney(type.final_price_cents) + ' · ' + available + availabilityLabel + (manualOnly ? ' · solo venta manual' : '') + '</small></span><input type="number" min="0" max="' + Math.min(available, Number(type.max_per_order || available)) + '" value="0" data-cash-ticket-quantity data-ticket-type-id="' + Number(type.id) + '" ' + (available ? '' : 'disabled') + '></label>';
        }).join("");
        renderCashAttendees();
        updateCashOrderTotal();
      }
      function cashBuyerName() {
        return [form && form.first_name ? form.first_name.value : "", form && form.last_name ? form.last_name.value : ""].map(function (value) { return String(value || "").trim(); }).filter(Boolean).join(" ");
      }
      function renderCashAttendees() {
        var section = form && form.querySelector("[data-cash-attendees]");
        var fields = section && section.querySelector("[data-cash-attendee-fields]");
        if (!section || !fields) return;
        var previous = Array.prototype.slice.call(fields.querySelectorAll("[data-cash-attendee-name]")).map(function (input) { return { name: input.value, automatic: input.getAttribute("data-cash-attendee-auto") === "true" }; });
        var eventId = Number(form.event_id.value);
        var event = cashMeta.events.filter(function (row) { return Number(row.id) === eventId; })[0];
        var tickets = [];
        Array.prototype.slice.call(form.querySelectorAll("[data-cash-ticket-quantity]")).forEach(function (input) {
          var quantity = Math.max(0, Math.floor(Number(input.value) || 0));
          var type = event && (event.ticket_types || []).filter(function (row) { return Number(row.id) === Number(input.getAttribute("data-ticket-type-id")); })[0];
          for (var index = 0; index < quantity; index++) tickets.push(type || {});
        });
        section.hidden = !tickets.length;
        if (!tickets.length) { fields.innerHTML = ""; return; }
        var buyer = cashBuyerName();
        fields.innerHTML = tickets.map(function (type, index) {
          var position = index + 1;
          var defaultName = position === 1
            ? buyer
            : "Acompañante " + position + (buyer ? " de " + buyer : "");
          var saved = previous[index] || null;
          var name = saved ? saved.name : defaultName;
          var automatic = saved ? saved.automatic : true;
          var label = position === 1 ? "Titular" : "Acompañante " + position;
          return '<label><span>' + label + (type.name ? ' · ' + escapeHtml(type.name) : '') + '</span><input type="text" maxlength="190" required data-cash-attendee-name data-cash-attendee-auto="' + automatic + '" value="' + escapeHtml(name) + '"></label>';
        }).join("");
      }
      function cashAttendeesPayload() {
        return Array.prototype.slice.call(form.querySelectorAll("[data-cash-attendee-name]")).map(function (input) { return { name: input.value.trim() }; });
      }
      function updateCashOrderTotal() {
        var eventId = Number(form && form.event_id.value);
        var event = cashMeta.events.filter(function (row) { return Number(row.id) === eventId; })[0];
        var total = form && form.querySelector("[data-cash-order-total]");
        var amount = total && total.querySelector("[data-cash-order-total-amount]");
        var label = total && total.querySelector("[data-cash-order-total-label]");
        if (!total || !amount || !label) return;
        if (!event) { total.hidden = true; return; }
        var subtotalCents = Array.prototype.slice.call(form.querySelectorAll("[data-cash-ticket-quantity]")).reduce(function (sum, input) {
          var typeId = Number(input.getAttribute("data-ticket-type-id"));
          var type = (event.ticket_types || []).filter(function (row) { return Number(row.id) === typeId; })[0];
          var quantity = Math.max(0, Math.floor(Number(input.value) || 0));
          return sum + (type ? quantity * Number(type.final_price_cents || 0) : 0);
        }, 0);
        var discountInput = form.querySelector("[data-cash-discount]");
        var discountCents = Math.max(0, Math.round(Number((discountInput && discountInput.value) || 0) * 100) || 0);
        if (discountInput) discountInput.max = (subtotalCents / 100).toFixed(2);
        var totalCents = Math.max(0, subtotalCents - discountCents);
        label.textContent = form.payment_flow.value === "manual_card" ? "Total a cobrar con tarjeta" : (form.cash_payment_status.value === "paid" ? "Total cobrado en efectivo" : "Total pendiente de cobro");
        amount.textContent = formatMoney(totalCents);
        total.hidden = false;
      }
      function populateCashEvents() {
        var select = form && form.querySelector("[data-cash-event]");
        if (!select) return;
        select.innerHTML = '<option value="">Selecciona un evento</option>' + cashMeta.events.map(function (event) { return '<option value="' + Number(event.id) + '">' + escapeHtml(event.title) + ' · ' + escapeHtml(formatDate(event.starts_at, true)) + '</option>'; }).join("");
      }
      function setAttendeeExportStatus(message, stateName) {
        if (!attendeeExportStatus) return;
        attendeeExportStatus.textContent = message || "";
        if (stateName) attendeeExportStatus.setAttribute("data-state", stateName); else attendeeExportStatus.removeAttribute("data-state");
      }
      function populateAttendeeExportEvents() {
        if (!attendeeExportEvent) return;
        var requestedEventId = Number(new URLSearchParams(window.location.search).get("event"));
        attendeeExportEvent.innerHTML = '<option value="">Selecciona un evento</option>' + cashMeta.events.map(function (event) {
          return '<option value="' + Number(event.id) + '">' + escapeHtml(event.title) + ' · ' + escapeHtml(formatDate(event.starts_at, true)) + '</option>';
        }).join("");
        if (requestedEventId && cashMeta.events.some(function (event) { return Number(event.id) === requestedEventId; })) attendeeExportEvent.value = String(requestedEventId);
      }
      function attendeeHasAllergies(attendee) { return Number(attendee.has_allergies) === 1 || String(attendee.allergens || "").trim() !== ""; }
      function attendeeObservations(attendee) {
        return [
          { label: "Alergia", value: attendee.allergy_notes },
          { label: "Menú", value: attendee.dietary_notes },
          { label: "Nota manual", value: attendee.order_notes }
        ].map(function (note) {
          var value = String(note.value || "").trim();
          return value ? note.label + ": " + value : "";
        }).filter(Boolean).join(" · ");
      }
      function attendeeDietaryPreference(attendee) {
        return ({ vegetarian: "Vegetariano", vegan: "Vegano", pescatarian: "Pescetariano", other: "Otro (ver notas)" })[attendee.dietary_preference] || "";
      }
      function attendeeHasSpecialMenu(attendee) { return String(attendee.dietary_preference || "none") !== "none"; }
      function attendeePriority(attendee) {
        if (attendeeHasAllergies(attendee)) return 0;
        return attendeeHasSpecialMenu(attendee) ? 1 : 2;
      }
      function attendeeFileName(event) {
        return String((event && event.title) || "evento").normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]+/gi, "-").replace(/^-+|-+$/g, "").toLowerCase() || "evento";
      }
      function attendeePdfText(value) {
        return String(value == null ? "" : value).replace(/[\u2013\u2014]/g, "-").replace(/[\u2018\u2019]/g, "'").replace(/[\u201C\u201D]/g, '"').replace(/\u2026/g, "...");
      }
      function createAttendeePdf(attendees, event, sort) {
        if (!window.jspdf || !window.jspdf.jsPDF) throw new Error("No se ha podido cargar el generador de PDF. Recarga la página e inténtalo de nuevo.");
        var pdf = new window.jspdf.jsPDF({ orientation: "landscape", unit: "mm", format: "a4" });
        var pageWidth = 297;
        var pageHeight = 210;
        var margin = 12;
        var columns = [
          { label: "#", width: 8, key: "number" },
          { label: "ASISTENTE", width: 62, key: "name" },
          { label: "ALERGIAS", width: 64, key: "allergies" },
          { label: "GRAVE", width: 16, key: "severe" },
          { label: "MENÚ ESPECIAL", width: 38, key: "dietary" },
          { label: "NOTAS", width: 73, key: "notes" }
        ];
        var orderLabel = ({ priority: "Alergias y menús especiales primero", allergies: "Alergias primero", notes: "Observaciones primero", name: "Nombre" })[sort] || "Alergias y menús especiales primero";
        function addHeading() {
          pdf.setDrawColor(0);
          pdf.setTextColor(0);
          pdf.setFont("helvetica", "bold");
          pdf.setFontSize(15);
          pdf.text("LISTADO DE ASISTENTES", margin, 13);
          pdf.setFont("helvetica", "normal");
          pdf.setFontSize(9);
          pdf.text(attendeePdfText((event && event.title) || "Evento"), margin, 19);
          pdf.text("Orden: " + orderLabel + " · " + attendees.length + (attendees.length === 1 ? " asistente" : " asistentes"), pageWidth - margin, 19, { align: "right" });
          pdf.setFontSize(7);
          pdf.text("Uso interno · Alergias, menús especiales y notas manuales · Impresión en blanco y negro", margin, 24);
          var headerY = 29;
          var x = margin;
          pdf.setFont("helvetica", "bold");
          pdf.setFontSize(7);
          columns.forEach(function (column) {
            pdf.setFillColor(235);
            pdf.rect(x, headerY, column.width, 8, "FD");
            pdf.setTextColor(0);
            pdf.text(column.label, x + 2, headerY + 5);
            x += column.width;
          });
          return headerY + 8;
        }
        function rowValues(attendee, number) {
          return {
            number: String(number),
            name: attendeePdfText(attendee.name),
            allergies: attendeePdfText(attendeeHasAllergies(attendee) ? (String(attendee.allergens || "").trim() || "Indicada sin detalle") : "Sin alergias indicadas"),
            severe: Number(attendee.severe_allergy) === 1 ? "SI" : "No",
            dietary: attendeePdfText(attendeeDietaryPreference(attendee) || "-"),
            notes: attendeePdfText(attendeeObservations(attendee))
          };
        }
        function addFooter() {
          var pages = pdf.getNumberOfPages();
          for (var page = 1; page <= pages; page++) {
            pdf.setPage(page);
            pdf.setTextColor(0);
            pdf.setFont("helvetica", "normal");
            pdf.setFontSize(7);
            pdf.text("Perigallo · listado confidencial para la operativa del evento", margin, pageHeight - 8);
            pdf.text("Página " + page + " de " + pages, pageWidth - margin, pageHeight - 8, { align: "right" });
          }
        }
        var y = addHeading();
        pdf.setFont("helvetica", "normal");
        pdf.setFontSize(8);
        attendees.forEach(function (attendee, index) {
          var values = rowValues(attendee, index + 1);
          var cells = columns.map(function (column) { return pdf.splitTextToSize(values[column.key], Math.max(4, column.width - 4)); });
          var lines = cells.reduce(function (largest, cell) { return Math.max(largest, cell.length); }, 1);
          var rowHeight = Math.max(8, lines * 3.6 + 3);
          if (y + rowHeight > pageHeight - 15) {
            pdf.addPage();
            y = addHeading();
            pdf.setFont("helvetica", "normal");
            pdf.setFontSize(8);
          }
          var x = margin;
          columns.forEach(function (column, columnIndex) {
            pdf.rect(x, y, column.width, rowHeight);
            pdf.text(cells[columnIndex], x + 2, y + 4);
            x += column.width;
          });
          y += rowHeight;
        });
        addFooter();
        pdf.save("listado-asistentes-" + attendeeFileName(event) + ".pdf");
      }
      function downloadAttendeeList() {
        var eventId = Number(attendeeExportEvent && attendeeExportEvent.value);
        if (!eventId) { setAttendeeExportStatus("Selecciona el evento del que quieres descargar el listado.", "error"); return; }
        var event = cashMeta.events.find(function (item) { return Number(item.id) === eventId; });
        var sort = attendeeExportSort ? attendeeExportSort.value : "priority";
        attendeeExportButton.disabled = true;
        setAttendeeExportStatus("Preparando listado…");
        request(api + "/admin/events/" + eventId + "/attendees/print-list").then(function (data) {
          var attendees = (data.attendees || []).filter(function (attendee) { return attendee.status === "issued"; });
          attendees.sort(function (left, right) {
            var leftAllergies = attendeeHasAllergies(left) ? 1 : 0;
            var rightAllergies = attendeeHasAllergies(right) ? 1 : 0;
            var leftNotes = attendeeObservations(left) ? 1 : 0;
            var rightNotes = attendeeObservations(right) ? 1 : 0;
            var priority = sort === "priority" ? attendeePriority(left) - attendeePriority(right) : sort === "notes" ? rightNotes - leftNotes : sort === "allergies" ? rightAllergies - leftAllergies : 0;
            if (priority) return priority;
            if (sort === "allergies") {
              var severity = Number(right.severe_allergy) - Number(left.severe_allergy);
              if (severity) return severity;
            }
            return String(left.name || "").localeCompare(String(right.name || ""), "es");
          });
          createAttendeePdf(attendees, event, sort);
          setAttendeeExportStatus(attendees.length + (attendees.length === 1 ? " asistente incluido en el PDF." : " asistentes incluidos en el PDF."), "success");
        }).catch(function (error) {
          setAttendeeExportStatus(error.message || "No se ha podido preparar el listado.", "error");
        }).finally(function () { attendeeExportButton.disabled = false; });
      }
      function openCashModal(mode) {
        if (!modal) return;
        var manualReserve = mode === "manual_reserve";
        form.inventory_mode.value = manualReserve ? "manual_reserve" : "cash";
        form.payment_flow.value = manualReserve ? "manual_card" : "cash";
        form.cash_payment_status.value = "reserved";
        var title = modal.querySelector("[data-cash-order-title]");
        var description = modal.querySelector("[data-cash-order-description]");
        if (title) title.textContent = manualReserve ? "Entrada manual con pago por tarjeta" : "Entrada en efectivo";
        if (description) description.textContent = manualReserve
          ? "Emite entradas desde el cupo manual adicional y genera un enlace seguro de pago con tarjeta para compartir por WhatsApp."
          : "Marca si ya ha pagado o si queda pendiente. Solo una reserva pendiente tiene fecha de caducidad. Las entradas se abren en WhatsApp para enviarlas manualmente.";
        var submit = form.querySelector('[type="submit"]');
        if (submit) submit.textContent = manualReserve ? "Generar enlace y abrir WhatsApp" : "Generar y abrir WhatsApp";
        modal.hidden = false;
        renderCashTicketLines();
        updateCashPaymentFields();
        updateCashOrderTotal();
        form.querySelector('[name="first_name"]').focus();
      }
      function updateCashPaymentFields() {
        var expiry = form.querySelector("[data-cash-expiry-wrap]");
        var manualCard = form.payment_flow.value === "manual_card";
        var reserved = manualCard || form.cash_payment_status.value === "reserved";
        var paymentWrap = form.querySelector("[data-cash-payment-wrap]");
        var cardNote = form.querySelector("[data-manual-card-payment-note]");
        if (paymentWrap) paymentWrap.hidden = manualCard;
        if (cardNote) cardNote.hidden = !manualCard;
        expiry.hidden = !reserved;
        form.reservation_expires_at.required = reserved;
        if (reserved) setDefaultExpiry();
        updateCashOrderTotal();
      }
      function actionMessage(action) {
        if (action === "cancel") return "Cancelar las entradas de este pedido impedirá su acceso. No realiza ningún abono. ¿Continuar?";
        if (action === "refund") return "Registra la devolución solo cuando el abono ya se haya realizado en Redsys/TPV. Esta acción revoca las entradas, pero no devuelve dinero automáticamente. ¿Confirmar?";
        if (action === "holded-retry") return "Se volverá a dejar el pedido en cola para Holded. No se emitirá ningún documento desde el navegador. ¿Continuar?";
        if (action === "cash-payment") return "¿Confirmas que ya se ha recibido el pago en efectivo? Se activarán las entradas y se sumará a las ventas cobradas.";
        if (action === "send-cash") return "Se abrirá WhatsApp con el enlace de las entradas preparado. ¿Continuar?";
        if (action === "send-manual-card") return "Se abrirá WhatsApp con el enlace seguro de pago por tarjeta. ¿Continuar?";
        if (action === "copy-payment-link") return "Se copiará el enlace seguro de pago por tarjeta. ¿Continuar?";
        if (action === "delivery-email") return "Se programará un nuevo correo con el mismo PDF de entradas. ¿Continuar?";
        if (action === "delivery-email-confirm") return "Confirma esto solo si sabes que el correo ya llegó. No se enviará ningún mensaje nuevo. ¿Marcar como enviado?";
        if (action === "delivery-whatsapp") return "Se programará un nuevo WhatsApp con el mismo PDF de entradas. ¿Continuar?";
        return "Eliminarás definitivamente este pedido de prueba y todas sus entradas. No se puede deshacer. ¿Continuar?";
      }
      Promise.all([request(api + "/admin/orders"), request(api + "/admin/cash-orders/meta"), request(api + "/admin/whatsapp/template-status")]).then(function (data) {
        state.orders = data[0].orders || [];
        cashMeta = data[1] || { events: [] };
        var templateStatus = root.querySelector("[data-admin-whatsapp-template-status]");
        if (templateStatus) {
          var template = data[2] && data[2].template || {};
          var labels = {not_configured:"No configurada",not_created:"No creada",in_review:"En revisión",approved:"Aprobada",rejected:"Rechazada",paused:"Pausada",disabled:"Deshabilitada",unknown:"Estado desconocido"};
          templateStatus.textContent = "Plantilla de WhatsApp · " + (template.template || "entradas_perigallo_confirmadas_v1") + ": " + (labels[template.status] || template.status || "Sin estado") + (template.reason ? " · " + template.reason : "");
        }
        populateCashEvents();
        populateAttendeeExportEvents();
        render();
        search.addEventListener("input", function () { render(search.value); });
        filters.forEach(function (button) {
          button.addEventListener("click", function () {
            activeFilter = button.getAttribute("data-admin-order-filter") || "active";
            filters.forEach(function (node) { node.classList.toggle("is-active", node === button); });
            render(search.value);
          });
        });
        root.querySelectorAll("[data-open-cash-order]").forEach(function (button) { button.addEventListener("click", function () { openCashModal("cash"); }); });
        root.querySelectorAll("[data-open-manual-order]").forEach(function (button) { button.addEventListener("click", function () { openCashModal("manual_reserve"); }); });
        if (attendeeExportButton) attendeeExportButton.addEventListener("click", downloadAttendeeList);
        root.querySelectorAll("[data-close-cash-order]").forEach(function (button) { button.addEventListener("click", closeCashModal); });
        modal.addEventListener("click", function (event) { if (event.target === modal) closeCashModal(); });
        root.querySelectorAll("[data-close-order-edit]").forEach(function (button) { button.addEventListener("click", closeEditModal); });
        if (editModal) editModal.addEventListener("click", function (event) { if (event.target === editModal) closeEditModal(); });
        form.event_id.addEventListener("change", renderCashTicketLines);
        form.cash_payment_status.addEventListener("change", updateCashPaymentFields);
        form.addEventListener("input", function (event) {
          if (event.target.matches("[data-cash-ticket-quantity]")) {
            renderCashAttendees();
            updateCashOrderTotal();
          } else if (event.target.matches("[data-cash-discount]")) {
            updateCashOrderTotal();
          } else if (event.target.matches('[name="first_name"], [name="last_name"]')) {
            var buyer = cashBuyerName();
            var attendeeInputs = Array.prototype.slice.call(form.querySelectorAll("[data-cash-attendee-name]"));
            attendeeInputs.filter(function (input) { return input.getAttribute("data-cash-attendee-auto") === "true"; }).forEach(function (input) {
              var position = attendeeInputs.indexOf(input) + 1;
              input.value = position === 1 ? buyer : "Acompañante " + position + (buyer ? " de " + buyer : "");
            });
          } else if (event.target.matches("[data-cash-attendee-name]")) {
            event.target.setAttribute("data-cash-attendee-auto", "false");
          }
        });
        updateCashPaymentFields();
        form.addEventListener("submit", function (event) {
          event.preventDefault();
          var items = Array.prototype.slice.call(form.querySelectorAll("[data-cash-ticket-quantity]")).map(function (input) { return { ticket_type_id: Number(input.getAttribute("data-ticket-type-id")), quantity: Number(input.value || 0) }; }).filter(function (item) { return item.quantity > 0; });
          if (!items.length) { setCashStatus("Selecciona al menos una entrada."); return; }
          var submit = form.querySelector('[type="submit"]');
          var payload = { event_id: Number(form.event_id.value), first_name: form.first_name.value.trim(), last_name: form.last_name.value.trim(), phone: form.phone.value.trim(), cash_payment_status: form.cash_payment_status.value, payment_flow: form.payment_flow.value, reservation_expires_at: form.reservation_expires_at.value, cash_discount_euros: form.cash_discount_euros.value, cash_payment_notes: form.cash_payment_notes.value.trim(), inventory_mode: form.inventory_mode.value, attendees: cashAttendeesPayload(), items: items };
          var popup = window.open("about:blank", "perigallo-cash-whatsapp");
          submit.disabled = true;
          setCashStatus("Generando pedido…");
          jsonRequest(api + "/admin/cash-orders", "POST", payload).then(function (result) {
            if (popup) popup.location.replace(result.whatsapp_url); else window.open(result.whatsapp_url, "_blank", "noopener");
            form.reset(); renderCashTicketLines(); closeCashModal(); reload();
          }).catch(function (error) { if (popup) popup.close(); setCashStatus(error.message || "No se ha podido generar el pedido."); }).finally(function () { submit.disabled = false; });
        });
        if (editForm) editForm.addEventListener("submit", function (event) {
          event.preventDefault();
          var orderId = Number(editForm.order_id.value);
          if (!orderId) return;
          var submit = editForm.querySelector('[type="submit"]');
          var payload = {
            first_name: editForm.first_name.value.trim(),
            last_name: editForm.last_name.value.trim(),
            email: editForm.email.value.trim(),
            phone: editForm.phone.value.trim(),
            cash_payment_notes: editForm.cash_payment_notes.value.trim()
          };
          submit.disabled = true;
          setEditStatus("Guardando corrección…");
          jsonRequest(api + "/admin/orders/" + orderId + "/contact", "PUT", payload).then(function () {
            closeEditModal();
            reload();
          }).catch(function (error) { setEditStatus(error.message || "No se han podido guardar los cambios.", "error"); }).finally(function () { submit.disabled = false; });
        });
        root.addEventListener("click", function (event) {
          var allergiesButton = event.target.closest("[data-order-allergies]");
          if (allergiesButton) {
            var allergiesOrderId = Number(allergiesButton.getAttribute("data-order-id"));
            if (!allergiesOrderId) return;
            allergiesButton.disabled = true;
            request(api + "/admin/orders/" + allergiesOrderId + "/attendees").then(showOrderAllergies).catch(function (error) { renderPageError(error.message); }).finally(function () { allergiesButton.disabled = false; });
            return;
          }
          var button = event.target.closest("[data-order-action]");
          if (!button) return;
          var action = button.getAttribute("data-order-action");
          var id = Number(button.getAttribute("data-order-id"));
          if (action === "edit") {
            var editableOrder = (state.orders || []).find(function (order) { return Number(order.id) === id; });
            if (editableOrder) openEditModal(editableOrder);
            return;
          }
          if (!id || !window.confirm(actionMessage(action))) return;
          if (action === "send-cash") {
            var cashOrder = (state.orders || []).find(function (order) { return Number(order.id) === id; });
            if (cashOrder) window.open(cashWhatsAppUrl(cashOrder), "_blank", "noopener");
            return;
          }
          if (action === "send-manual-card") {
            var manualCardOrder = (state.orders || []).find(function (order) { return Number(order.id) === id; });
            if (manualCardOrder) window.open(cashWhatsAppUrl(manualCardOrder), "_blank", "noopener");
            return;
          }
          if (action === "copy-payment-link") {
            var orderWithLink = (state.orders || []).find(function (order) { return Number(order.id) === id; });
            if (!orderWithLink) return;
            navigator.clipboard.writeText(manualCardPaymentUrl(orderWithLink)).then(function () {
              button.textContent = "Enlace copiado";
            }).catch(function () { renderPageError("No se ha podido copiar el enlace de pago."); });
            return;
          }
          var holdedConfirmation = false;
          if (action === "holded-retry") {
            var holdedOrder = (state.orders || []).find(function (order) { return Number(order.id) === id; });
            if (holdedOrder && holdedOrder.holded_status === "requires_review" && !holdedOrder.holded_document_id) {
              holdedConfirmation = window.confirm("Este pedido pudo quedarse interrumpido mientras Holded creaba el documento. Busca primero la referencia Redsys en Holded. Pulsa Aceptar solo si has comprobado que NO existe documento ni pago; de lo contrario, cancela.");
              if (!holdedConfirmation) return;
            }
          }
          button.disabled = true;
          var url = api + "/admin/orders/" + id + (action === "cancel" ? "/cancel" : action === "refund" ? "/record-refund" : action === "holded-retry" ? "/holded/retry" : action === "cash-payment" ? "/cash-payment" : action === "send-cash" ? "/send-cash" : action === "delivery-email" ? "/delivery/email/retry" : action === "delivery-email-confirm" ? "/delivery/email/confirm" : action === "delivery-whatsapp" ? "/delivery/whatsapp/retry" : "/test");
          var method = action === "purge-test" ? "DELETE" : "POST";
          var note = action === "cash-payment" ? window.prompt("Apunte del cobro en efectivo (opcional):", "") : null;
          if (action === "cash-payment" && note === null) { button.disabled = false; return; }
          var body = action === "refund" ? { confirmed: true } : action === "cash-payment" ? { cash_payment_notes: note || "" } : action === "holded-retry" ? { confirm_no_external_document: holdedConfirmation } : {};
          jsonRequest(url, method, body).then(function () { reload(); }).catch(function (error) { renderPageError(error.message); }).finally(function () { button.disabled = false; });
        });
      }).catch(function (error) { renderPageError(error.message); });
    });
  }

  function holdedStatus(status) {
    return ({
      not_required: { label: "No requerido", tone: "neutral" },
      pending: { label: "Pendiente", tone: "pending" },
      processing: { label: "Procesando", tone: "pending" },
      synced: { label: "Sincronizado", tone: "synced" },
      error: { label: "Reintento programado", tone: "error" },
      requires_review: { label: "Revisión necesaria", tone: "review" }
    })[status] || { label: status || "Sin estado", tone: "neutral" };
  }

  function isFiscalOrder(order) {
    return Number(order.is_test) !== 1 && order.sales_channel !== "cash" && order.environment === "production" && (order.payment_status === "paid" || order.status === "paid");
  }

  function billingOrderRow(order) {
    var fiscal = holdedStatus(order.holded_status);
    var reference = order.redsys_order || order.test_reference || ("Pedido " + order.id);
    var documentType = order.holded_document_type === "invoice" ? "Factura" : order.holded_document_type === "salesreceipt" ? "Recibo simplificado" : "Pendiente de determinar";
    var document = order.holded_document_number ? documentType + " · " + order.holded_document_number : documentType;
    var detail = order.holded_last_error
      ? '<small class="admin-billing-detail is-warning">' + escapeHtml(order.holded_last_error) + '</small>'
      : order.holded_synced_at
        ? '<small class="admin-billing-detail">Sincronizado ' + escapeHtml(formatDate(order.holded_synced_at, true)) + '</small>'
        : order.holded_next_attempt_at
          ? '<small class="admin-billing-detail">Próximo intento ' + escapeHtml(formatDate(order.holded_next_attempt_at, true)) + '</small>'
          : '';
    var retry = order.holded_status !== "synced"
      ? '<button type="button" class="text-action" data-billing-retry="' + Number(order.id) + '">Preparar reintento</button>'
      : '';
    return '<article class="admin-billing-row">' +
      '<div><strong>' + escapeHtml(order.name || "Comprador sin nombre") + '</strong><small>' + escapeHtml(order.event_title || "Evento por asignar") + ' · ' + escapeHtml(reference) + ' · Cobrado ' + escapeHtml(formatDate(order.paid_at, true)) + '</small>' + detail + '</div>' +
      '<span>' + escapeHtml(document) + (Number(order.billing_requested) ? '<small>Datos fiscales solicitados</small>' : '<small>Venta de entrada</small>') + '</span>' +
      '<span class="admin-billing-amount">' + escapeHtml(formatMoney(order.total_cents)) + '</span>' +
      '<span class="admin-billing-status is-' + escapeHtml(fiscal.tone) + '">' + escapeHtml(fiscal.label) + '</span>' +
      '<div class="admin-billing-actions"><a class="text-action" href="/admin/ventas/?order=' + Number(order.id) + '">Ver venta</a>' + retry + '</div>' +
    '</article>';
  }

  function renderBilling(root, health, orders, filter) {
    var fiscalOrders = orders.filter(isFiscalOrder);
    var counts = fiscalOrders.reduce(function (total, order) {
      var key = order.holded_status || "not_required";
      total[key] = (total[key] || 0) + 1;
      return total;
    }, {});
    var activeFilter = filter || "all";
    var rows = fiscalOrders.filter(function (order) { return activeFilter === "all" || order.holded_status === activeFilter; });
    var integration = health.configuration || {};
    var integrationState = integration.enabled && !integration.dry_run && integration.configured
      ? "Conexión operativa"
      : integration.dry_run ? "Modo seguro · sin emisión" : "Configuración pendiente";
    var missing = integration.missing && integration.missing.length
      ? '<p class="admin-billing-warning">Faltan variables: ' + escapeHtml(integration.missing.join(", ")) + '</p>'
      : '';
    var filters = [["all", "Todas"], ["pending", "Pendientes"], ["synced", "Sincronizadas"], ["requires_review", "Revisión"], ["error", "Errores"]];
    root.innerHTML =
      '<header class="admin-page-heading admin-page-heading-compact"><div><span class="ticket-eyebrow">Administración financiera</span><h1>Facturación y <em>Holded.</em></h1><p>Cada venta real de entradas se refleja aquí y se sincroniza de forma asíncrona con Holded. El cobro en Redsys no depende de esta sincronización.</p></div><a class="ticket-btn" href="/admin/ventas/">Ver pedidos y ventas</a></header>' +
      '<section class="admin-billing-health"><div><span class="ticket-eyebrow">Estado de integración</span><strong>' + escapeHtml(integrationState) + '</strong><p>Entorno Holded: ' + escapeHtml(integration.environment || "production") + ' · ' + (integration.configured ? "Configuración completa" : "Revisión de configuración requerida") + '</p>' + missing + '</div><div><span class="ticket-eyebrow">Flujo de venta</span><p>Redsys confirma el pago → Perigallo registra el pedido → la cola crea el documento en Holded → el cron registra el pago.</p><small>Los reintentos no emiten documentos desde el navegador.</small></div></section>' +
      '<section class="admin-stat-grid admin-billing-stats" aria-label="Resumen de facturación"><article><span>Ventas cobradas</span><strong>' + fiscalOrders.length + '</strong><small>Pedidos reales en producción</small></article><article><span>Sincronizadas</span><strong>' + Number(counts.synced || 0) + '</strong><small>Documento y pago registrados</small></article><article><span>Pendientes</span><strong>' + (Number(counts.pending || 0) + Number(counts.processing || 0)) + '</strong><small>Esperan al cron de Holded</small></article><article><span>Revisión</span><strong>' + (Number(counts.requires_review || 0) + Number(counts.error || 0)) + '</strong><small>Requieren atención administrativa</small></article></section>' +
      '<section class="admin-dashboard-section"><div class="admin-section-label"><div><span class="ticket-eyebrow">Documentos de ventas</span><h2>Seguimiento fiscal</h2></div><button type="button" class="text-action" data-billing-refresh>Actualizar estado</button></div>' +
      '<div class="admin-filter-group admin-billing-filters">' + filters.map(function (item) { return '<button type="button" data-billing-filter="' + item[0] + '"' + (activeFilter === item[0] ? ' class="is-active"' : '') + '>' + item[1] + ' <span>' + (item[0] === "all" ? fiscalOrders.length : Number(counts[item[0]] || 0)) + '</span></button>'; }).join("") + '</div>' +
      '<div class="admin-billing-list">' + (rows.length ? rows.map(billingOrderRow).join("") : '<div class="admin-empty"><strong>No hay ventas en este estado.</strong><span>Las ventas reales cobradas mediante el checkout aparecerán aquí cuando Redsys las confirme.</span></div>') + '</div></section>';
  }

  function initBilling() {
    var root = document.querySelector("[data-admin-billing-page]");
    if (!root) return;
    requireSession(function (sessionData) {
      if (!sessionData.is_owner) {
        root.innerHTML = '<section class="admin-empty"><strong>Acceso restringido.</strong><span>La facturación y la integración con Holded solo están disponibles para la cuenta propietaria.</span></section>';
        return;
      }
      var activeFilter = "all";
      function reload() {
        root.setAttribute("aria-busy", "true");
        Promise.all([request(api + "/admin/holded/health"), request(api + "/admin/orders")]).then(function (result) {
          state.orders = result[1].orders || [];
          renderBilling(root, result[0].holded || {}, state.orders, activeFilter);
        }).catch(function (error) { renderPageError(error.message || "No se ha podido cargar la facturación."); })
          .finally(function () { root.removeAttribute("aria-busy"); });
      }
      root.addEventListener("click", function (event) {
        var filter = event.target.closest("[data-billing-filter]");
        if (filter) { activeFilter = filter.getAttribute("data-billing-filter") || "all"; reload(); return; }
        if (event.target.closest("[data-billing-refresh]")) { reload(); return; }
        var retry = event.target.closest("[data-billing-retry]");
        if (!retry) return;
        var orderId = Number(retry.getAttribute("data-billing-retry"));
        if (!orderId || !window.confirm("El pedido se volverá a dejar en cola para Holded. No se emitirá ningún documento desde el navegador. ¿Continuar?")) return;
        var order = (state.orders || []).find(function (item) { return Number(item.id) === orderId; });
        var holdedConfirmation = false;
        if (order && order.holded_status === "requires_review" && !order.holded_document_id) {
          holdedConfirmation = window.confirm("Busca primero la referencia Redsys en Holded. Confirma solo si has comprobado que no existe documento ni pago. Si existe, cancela y conserva el pedido para revisión.");
          if (!holdedConfirmation) return;
        }
        retry.disabled = true;
        jsonRequest(api + "/admin/orders/" + orderId + "/holded/retry", "POST", { confirm_no_external_document: holdedConfirmation }).then(reload).catch(function (error) { renderPageError(error.message); }).finally(function () { retry.disabled = false; });
      });
      reload();
    });
  }

  function leadStatusLabel(status) {
    return ({ new: "Nueva", contacted: "Contactada", follow_up: "En seguimiento", proposal_sent: "Propuesta enviada", closed: "Cerrada", discarded: "Descartada" })[status] || status;
  }

  function renderLeadFormAdmin(root, settings, requests, selected) {
    var selectedDetail = selected ? '<section class="admin-dashboard-section"><div class="admin-section-label"><div><span class="ticket-eyebrow">Solicitud ' + escapeHtml(selected.public_reference) + '</span><h2>' + escapeHtml(selected.name) + (selected.partner_name ? ' y ' + escapeHtml(selected.partner_name) : '') + '</h2></div><button class="text-action" type="button" data-lead-close-detail>Cerrar ficha</button></div><article class="admin-empty" style="justify-items:stretch"><p><strong>Contacto</strong><br>' + escapeHtml(selected.email || 'Sin email') + ' · ' + escapeHtml(selected.phone || 'Sin teléfono') + '</p><p><strong>Evento</strong><br>' + escapeHtml(selected.event_type) + ' · ' + escapeHtml(selected.event_date || 'Fecha por definir') + ' · ' + escapeHtml(selected.guest_count || 'Asistentes por definir') + '</p><p><strong>Notificación</strong><br>' + escapeHtml(selected.email_status) + (selected.email_error ? ' · ' + escapeHtml(selected.email_error) : '') + '</p><label class="admin-search" style="width:100%"><span>Estado comercial</span><select data-lead-detail-status>' + ["new", "contacted", "follow_up", "proposal_sent", "closed", "discarded"].map(function (status) { return '<option value="' + status + '"' + (selected.status === status ? ' selected' : '') + '>' + leadStatusLabel(status) + '</option>'; }).join('') + '</select></label><pre style="overflow:auto;max-height:58vh;white-space:pre-wrap;color:var(--pg-cream);font:400 .75rem/1.6 monospace">' + escapeHtml(JSON.stringify(selected.answers || {}, null, 2)) + '</pre></article></section>' : '';
    var rows = requests.length ? requests.map(function (requestItem) {
      return '<article class="admin-order-row"><div><strong>' + escapeHtml(requestItem.name) + (requestItem.partner_name ? ' y ' + escapeHtml(requestItem.partner_name) : '') + '</strong><small>Recibida ' + escapeHtml(formatDate(requestItem.created_at, true)) + ' · ' + escapeHtml(requestItem.event_type) + ' · ' + escapeHtml(requestItem.event_date || 'Fecha por definir') + ' · ' + escapeHtml(requestItem.public_reference) + '</small></div><span>' + escapeHtml(requestItem.guest_count || '—') + ' invitados</span><span class="status-pill status-' + escapeHtml(requestItem.status) + '">' + escapeHtml(leadStatusLabel(requestItem.status)) + '</span><span>' + (requestItem.email_status === 'sent' ? 'Email enviado' : requestItem.email_status === 'failed' ? 'Email fallido' : 'Email pendiente') + '</span><button type="button" class="text-action" data-lead-open="' + Number(requestItem.id) + '">Ver solicitud</button></article>';
    }).join('') : '<div class="admin-empty"><strong>Aún no hay solicitudes.</strong><span>Las respuestas recibidas desde /formulario/ aparecerán aquí.</span></div>';
    root.innerHTML = '<section class="admin-page-heading admin-page-heading-compact"><div><span class="ticket-eyebrow">Captación</span><h1>Formulario y <em>solicitudes.</em></h1><p>La base de datos es la fuente de verdad: cada solicitud queda guardada aunque el correo de aviso falle.</p></div><a class="ticket-btn" href="/formulario/" target="_blank" rel="noopener noreferrer">Ver formulario público</a></section>' +
      '<section class="admin-user-create"><span class="ticket-eyebrow">Configuración</span><h2>Formulario público</h2><form class="admin-user-form" data-lead-settings><label><span>Estado</span><select name="enabled"><option value="1"' + (Number(settings.enabled) ? ' selected' : '') + '>Activo</option><option value="0"' + (!Number(settings.enabled) ? ' selected' : '') + '>Pausado</option></select></label><label><span>URL pública</span><input value="https://perigallo.com/formulario/" readonly></label><label><span>Email de destino</span><input name="recipient_email" type="email" value="' + escapeHtml(settings.recipient_email || 'hola@perigallo.com') + '"></label><label><span>Título</span><input name="title" value="' + escapeHtml(settings.title || '') + '"></label><label><span>Subtítulo</span><input name="subtitle" value="' + escapeHtml(settings.subtitle || '') + '"></label><label style="grid-column:span 2"><span>Mensaje de confirmación</span><input name="confirmation_message" value="' + escapeHtml(settings.confirmation_message || '') + '"></label><button class="ticket-btn primary" type="submit">Guardar configuración</button></form><p class="ticket-status" data-lead-settings-status></p></section>' +
      '<section class="admin-dashboard-section"><div class="admin-section-label"><div><span class="ticket-eyebrow">Solicitudes recibidas</span><h2>Seguimiento comercial</h2></div></div><section class="admin-order-toolbar"><div class="admin-filter-group" data-lead-status-tabs>' + ["all", "new", "contacted", "follow_up", "proposal_sent", "closed", "discarded"].map(function (status) { return '<button type="button" data-lead-status="' + status + '" class="' + ((status === "all" ? !settings._leadStatus : settings._leadStatus === status) ? 'is-active' : '') + '">' + (status === 'all' ? 'Todas' : leadStatusLabel(status)) + '</button>'; }).join('') + '</div><label class="admin-search"><span>Tipo de evento</span><input type="search" data-lead-event-type value="' + escapeHtml(settings._leadEventType || '') + '" placeholder="Boda, celebración…"></label><label class="admin-search"><span>Fecha de solicitud</span><input type="date" data-lead-created-date value="' + escapeHtml(settings._leadCreatedDate || '') + '"></label><label class="admin-search"><span>Fecha prevista</span><input type="search" data-lead-event-date value="' + escapeHtml(settings._leadEventDate || '') + '" placeholder="2027-06-20"></label><label class="admin-search"><span>Buscar</span><input type="search" data-lead-search value="' + escapeHtml(settings._leadSearch || '') + '" placeholder="Nombre, email, teléfono o referencia"></label></section><section class="admin-orders-list" data-lead-requests>' + rows + '</section></section>' + selectedDetail;
  }

  function initLeadForms() {
    var root = document.querySelector("[data-admin-lead-form-page]");
    if (!root) return;
    requireSession(function (session) {
      if (session.role !== "admin") { renderPageError("Esta sección está reservada para administración."); return; }
      var filters = { status: "", q: "", event_type: "", event_date: "", created_date: "" };
      var selectedId = Number(new URLSearchParams(window.location.search).get("request") || 0);
      function load(selected) {
        var params = new URLSearchParams();
        if (filters.status) params.set("status", filters.status);
        if (filters.q) params.set("q", filters.q);
        if (filters.event_type) params.set("event_type", filters.event_type);
        if (filters.event_date) params.set("event_date", filters.event_date);
        if (filters.created_date) params.set("created_date", filters.created_date);
        return Promise.all([request(api + "/admin/formulario/settings"), request(api + "/admin/formulario/solicitudes?" + params.toString()), selected ? request(api + "/admin/formulario/solicitudes/" + selected) : Promise.resolve({ request: null })]).then(function (result) {
          result[0].settings._leadStatus = filters.status;
          result[0].settings._leadSearch = filters.q;
          result[0].settings._leadEventType = filters.event_type;
          result[0].settings._leadEventDate = filters.event_date;
          result[0].settings._leadCreatedDate = filters.created_date;
          renderLeadFormAdmin(root, result[0].settings || {}, result[1].requests || [], result[2].request);
          bind();
        });
      }
      function bind() {
        var settings = root.querySelector("[data-lead-settings]");
        if (settings) settings.addEventListener("submit", function (event) {
          event.preventDefault();
          var status = root.querySelector("[data-lead-settings-status]");
          status.textContent = "Guardando…";
          jsonRequest(api + "/admin/formulario/settings", "PUT", { enabled: settings.enabled.value === "1", recipient_email: settings.recipient_email.value.trim(), title: settings.title.value.trim(), subtitle: settings.subtitle.value.trim(), confirmation_message: settings.confirmation_message.value.trim() }).then(function () { status.textContent = "Configuración guardada."; }).catch(function (error) { status.textContent = error.message; });
        });
        root.querySelectorAll("[data-lead-status]").forEach(function (button) { button.addEventListener("click", function () { filters.status = button.dataset.leadStatus === "all" ? "" : button.dataset.leadStatus; load(selectedId).catch(function (error) { renderPageError(error.message); }); }); });
        var search = root.querySelector("[data-lead-search]");
        if (search) search.addEventListener("input", function () { filters.q = search.value.trim(); window.clearTimeout(search._leadTimer); search._leadTimer = window.setTimeout(function () { load(selectedId).catch(function (error) { renderPageError(error.message); }); }, 250); });
        root.querySelectorAll("[data-lead-event-type], [data-lead-event-date], [data-lead-created-date]").forEach(function (field) { field.addEventListener("input", function () { var filterName = field.hasAttribute("data-lead-event-type") ? "event_type" : field.hasAttribute("data-lead-event-date") ? "event_date" : "created_date"; filters[filterName] = field.value.trim(); window.clearTimeout(field._leadTimer); field._leadTimer = window.setTimeout(function () { load(selectedId).catch(function (error) { renderPageError(error.message); }); }, 250); }); });
        root.querySelectorAll("[data-lead-open]").forEach(function (button) { button.addEventListener("click", function () { selectedId = Number(button.dataset.leadOpen); load(selectedId).catch(function (error) { renderPageError(error.message); }); }); });
        var close = root.querySelector("[data-lead-close-detail]");
        if (close) close.addEventListener("click", function () { selectedId = 0; load(0).catch(function (error) { renderPageError(error.message); }); });
        var statusSelect = root.querySelector("[data-lead-detail-status]");
        if (statusSelect) statusSelect.addEventListener("change", function () { jsonRequest(api + "/admin/formulario/solicitudes/" + selectedId + "/estado", "PUT", { status: statusSelect.value }).then(function () { load(selectedId); }).catch(function (error) { renderPageError(error.message); }); });
      }
      load(selectedId).catch(function (error) { renderPageError(error.message); });
    });
  }

  function renderManagedUsers(root) {
    var list = root.querySelector("[data-admin-users-list]");
    if (!state.users.length) {
      list.innerHTML = '<div class="admin-empty"><strong>No hay cuentas adicionales.</strong><span>Crea una cuenta para administración o control de acceso.</span></div>';
      return;
    }
    list.innerHTML = state.users.map(function (user) {
      return '<article class="admin-user-row" data-admin-user-row data-user-id="' + Number(user.id) + '">' +
        '<div><strong>' + escapeHtml(user.username) + '</strong><small>' + (Number(user.is_active) ? 'Acceso activo' : 'Acceso desactivado') + (user.last_login_at ? ' · Último acceso ' + escapeHtml(formatDate(user.last_login_at, true)) : '') + '</small></div>' +
        '<label><span>Permiso</span><select data-user-role><option value="admin"' + (user.role === 'admin' ? ' selected' : '') + '>Administrador</option><option value="control_acceso"' + (user.role === 'control_acceso' ? ' selected' : '') + '>Control de acceso</option></select></label>' +
        '<label class="admin-user-active"><input type="checkbox" data-user-active' + (Number(user.is_active) ? ' checked' : '') + '><span>Acceso activo</span></label>' +
        '<div class="admin-user-actions"><button type="button" class="ticket-btn" data-save-user>Guardar permisos</button><button type="button" class="text-action" data-change-user-password>Cambiar contraseña</button></div>' +
      '</article>';
    }).join("");
  }

  function initUsers() {
    var root = document.querySelector("[data-admin-users-page]");
    if (!root) return;
    requireSession(function (sessionData) {
      if (!sessionData.is_owner) { renderPageError("Esta sección está reservada para la cuenta propietaria."); return; }
      var form = root.querySelector("[data-admin-create-user]");
      var status = root.querySelector("[data-admin-users-status]");
      function loadUsers() {
        request(api + "/admin/users").then(function (data) { state.users = data.users || []; renderManagedUsers(root); }).catch(function (error) { renderPageError(error.message); });
      }
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        var submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        jsonRequest(api + "/admin/users", "POST", { username: form.username.value.trim(), password: form.password.value, role: form.role.value })
          .then(function () { form.reset(); status.textContent = "Cuenta creada."; loadUsers(); })
          .catch(function (error) { status.textContent = error.message; })
          .finally(function () { submit.disabled = false; });
      });
      root.addEventListener("click", function (event) {
        var row = event.target.closest("[data-admin-user-row]");
        if (!row) return;
        var id = Number(row.getAttribute("data-user-id"));
        if (event.target.closest("[data-save-user]")) {
          jsonRequest(api + "/admin/users/" + id, "PUT", { username: row.querySelector("strong").textContent, role: row.querySelector("[data-user-role]").value, is_active: row.querySelector("[data-user-active]").checked })
            .then(function () { status.textContent = "Permisos actualizados."; loadUsers(); })
            .catch(function (error) { status.textContent = error.message; });
        }
        if (event.target.closest("[data-change-user-password]")) {
          var password = window.prompt("Nueva contraseña (mínimo 12 caracteres):");
          if (!password) return;
          jsonRequest(api + "/admin/users/" + id + "/password", "POST", { password: password })
            .then(function () { status.textContent = "Contraseña actualizada."; })
            .catch(function (error) { status.textContent = error.message; });
        }
      });
      loadUsers();
    });
  }

  function centsFromInput(value) {
    var normalized = String(value || "").trim().replace(/\s/g, "").replace(/\./g, "").replace(",", ".");
    if (!normalized) return null;
    var amount = Number(normalized);
    return Number.isFinite(amount) && amount > 0 ? Math.round(amount * 100) : null;
  }

  function percentToBasisPoints(value) {
    var amount = Number(String(value || "").replace(",", "."));
    return Number.isFinite(amount) && amount > 0 ? Math.round(amount * 100) : 0;
  }

  function inputMoney(cents) {
    return cents == null || cents === "" ? "" : (Number(cents) / 100).toFixed(2).replace(".", ",");
  }

  function inputDate(value) {
    return value ? String(value).replace(" ", "T").slice(0, 16) : "";
  }

  function selectedOptionValues(select) {
    return Array.from(select.options).filter(function (option) { return option.selected; }).map(function (option) { return Number(option.value); });
  }

  function setSelectedOptions(select, values) {
    var selected = new Set((values || []).map(Number));
    Array.from(select.options).forEach(function (option) { option.selected = selected.has(Number(option.value)); });
  }

  function discountValueLabel(code) {
    if (code.discount_type === "fixed") return formatMoney(code.fixed_amount_cents);
    return (Number(code.percent_basis_points || 0) / 100).toLocaleString("es-ES", { maximumFractionDigits: 2 }) + "%";
  }

  function discountStatusLabel(code) {
    if (Number(code.is_archived)) return "Archivado";
    if (!Number(code.is_active)) return "Inactivo";
    if (code.discount_type === "percent" && Number(code.percent_basis_points || 0) < 100) return "Revisar valor";
    if (code.starts_at && new Date(String(code.starts_at).replace(" ", "T")) > new Date()) return "Programado";
    if (code.expires_at && new Date(String(code.expires_at).replace(" ", "T")) < new Date()) return "Caducado";
    if (code.maximum_total_uses != null && Number(code.consumed_uses || 0) >= Number(code.maximum_total_uses)) return "Agotado";
    return "Activo";
  }

  function renderDiscountList(root, query) {
    var target = root.querySelector("[data-admin-discount-list]");
    var status = root.querySelector("[data-admin-discount-list-status]");
    var term = String(query || "").trim().toLowerCase();
    var selectedEvent = Number((root.querySelector("[data-discount-list-event]") || {}).value || 0);
    var selectedType = String((root.querySelector("[data-discount-list-type]") || {}).value || "");
    var selectedDate = String((root.querySelector("[data-discount-list-date]") || {}).value || "");
    var rows = state.discountCodes.filter(function (code) {
      var matchesTerm = !term || [code.code, code.internal_description, code.event_names].join(" ").toLowerCase().includes(term);
      var eventIds = (code.event_ids || []).map(Number);
      var matchesEvent = !selectedEvent || code.event_scope === "all" || (code.event_scope === "included" ? eventIds.includes(selectedEvent) : !eventIds.includes(selectedEvent));
      var matchesType = !selectedType || code.discount_type === selectedType;
      var matchesDate = !selectedDate || (!code.starts_at || String(code.starts_at).slice(0, 10) <= selectedDate) && (!code.expires_at || String(code.expires_at).slice(0, 10) >= selectedDate);
      return matchesTerm && matchesEvent && matchesType && matchesDate;
    });
    if (status) status.textContent = rows.length + (rows.length === 1 ? " código mostrado" : " códigos mostrados");
    if (!rows.length) {
      target.innerHTML = '<div class="admin-empty"><strong>No hay códigos en este estado.</strong><span>Crea una campaña nueva o cambia los filtros.</span></div>';
      return;
    }
    target.innerHTML = rows.map(function (code) {
      var usage = Number(code.consumed_uses || 0) + (code.maximum_total_uses != null ? " / " + Number(code.maximum_total_uses) : "");
      var needsReview = code.discount_type === "percent" && Number(code.percent_basis_points || 0) < 100;
      return '<article class="admin-discount-row' + (needsReview ? ' is-needs-review' : '') + '" data-discount-id="' + Number(code.id) + '">' +
        '<div><span class="ticket-eyebrow">' + escapeHtml(discountStatusLabel(code)) + '</span><strong>' + escapeHtml(code.code) + '</strong><small>' + escapeHtml(code.internal_description || "Sin descripción interna") + '</small>' + (needsReview ? '<small class="admin-discount-warning">El porcentaje es inferior al 1 % y no producirá descuento.</small>' : '') + '</div>' +
        '<div><small>Descuento</small><strong>' + escapeHtml(discountValueLabel(code)) + '</strong><small>Usos: ' + escapeHtml(String(usage)) + '</small></div>' +
        '<div><small>Alcance</small><strong>' + escapeHtml(code.application_scope === "ticket_types" ? "Tipos seleccionados" : code.application_scope === "per_ticket" ? "Cada entrada" : "Pedido completo") + '</strong><small>' + escapeHtml(code.event_scope === "all" ? "Todas las experiencias" : (code.event_names || "Experiencias seleccionadas")) + '</small></div>' +
        '<div class="admin-discount-actions"><button type="button" class="text-action" data-discount-action="edit">Editar</button><button type="button" class="text-action" data-discount-action="history">Historial</button><button type="button" class="text-action" data-discount-action="duplicate">Duplicar</button>' + (Number(code.total_uses || 0) === 0 ? '<button type="button" class="text-action danger" data-discount-action="delete">Eliminar</button>' : (!Number(code.is_archived) ? '<button type="button" class="text-action danger" data-discount-action="archive">Archivar</button>' : '')) + '</div>' +
      '</article>';
    }).join("");
  }

  function syncDiscountFormVisibility(form) {
    var fixed = form.discount_type.value === "fixed";
    var percentField = form.querySelector("[data-discount-percent]");
    var fixedField = form.querySelector("[data-discount-fixed]");
    var percentInput = form.discount_percent;
    var fixedInput = form.fixed_amount;
    percentField.hidden = fixed;
    fixedField.hidden = !fixed;
    percentInput.disabled = fixed;
    percentInput.required = !fixed;
    fixedInput.disabled = !fixed;
    fixedInput.required = fixed;
    form.querySelector("[data-discount-value-hint]").hidden = fixed;
    form.querySelector("[data-discount-fixed-help]").hidden = !fixed;
    form.querySelector("[data-discount-events]").hidden = form.event_scope.value === "all";
    form.querySelector("[data-discount-ticket-types]").hidden = form.application_scope.value !== "ticket_types";
    syncDiscountValueHint(form);
  }

  function syncDiscountValueHint(form) {
    var hint = form.querySelector("[data-discount-value-hint]");
    if (!hint) return;
    var value = Number(form.discount_percent.value || 0);
    if (!value) {
      hint.textContent = "Escribe 18 para aplicar un 18 %.";
      return;
    }
    if (value < 1) {
      hint.textContent = "Un valor inferior al 1 % no genera descuento en las entradas actuales.";
      return;
    }
    hint.textContent = "Se aplicará un " + value.toLocaleString("es-ES", { maximumFractionDigits: 2 }) + " % de descuento.";
  }

  function setDiscountFormStatus(status, message, kind) {
    status.textContent = message;
    status.dataset.state = kind || "";
  }

  function hasAdvancedDiscountSettings(code) {
    return Boolean(
      code.internal_description ||
      code.event_scope && code.event_scope !== "all" ||
      code.application_scope && code.application_scope !== "order" ||
      code.maximum_discount_cents != null ||
      code.minimum_order_cents != null ||
      code.minimum_ticket_quantity != null ||
      code.maximum_discounted_ticket_quantity != null ||
      code.maximum_total_uses != null ||
      code.maximum_uses_per_customer != null ||
      code.starts_at ||
      code.expires_at ||
      Number(code.is_combinable)
    );
  }

  function populateDiscountOptions(form) {
    var meta = state.discountMeta || { events: [], ticket_types: [] };
    form.event_ids.innerHTML = (meta.events || []).map(function (event) { return '<option value="' + Number(event.id) + '">' + escapeHtml(event.title) + ' · ' + escapeHtml(formatDate(event.starts_at, false)) + '</option>'; }).join("");
    form.ticket_type_ids.innerHTML = (meta.ticket_types || []).map(function (type) { return '<option value="' + Number(type.id) + '">' + escapeHtml(type.event_title) + ' — ' + escapeHtml(type.name) + '</option>'; }).join("");
  }

  function resetDiscountForm(form) {
    form.reset();
    form.discount_id.value = "";
    form.discount_type.value = "percent";
    form.event_scope.value = "all";
    form.application_scope.value = "order";
    form.is_active.checked = true;
    form.querySelector("[data-discount-form-title]").textContent = "Nuevo código";
    form.querySelector("[data-discount-advanced]").open = false;
    setSelectedOptions(form.event_ids, []);
    setSelectedOptions(form.ticket_type_ids, []);
    syncDiscountFormVisibility(form);
  }

  function fillDiscountForm(form, code) {
    form.discount_id.value = code.id;
    form.code.value = code.code || "";
    form.internal_description.value = code.internal_description || "";
    form.discount_type.value = code.discount_type || "percent";
    form.discount_percent.value = code.percent_basis_points ? (Number(code.percent_basis_points) / 100).toFixed(2).replace(/\.00$/, "") : "";
    form.fixed_amount.value = inputMoney(code.fixed_amount_cents);
    form.maximum_discount.value = inputMoney(code.maximum_discount_cents);
    form.minimum_order.value = inputMoney(code.minimum_order_cents);
    form.minimum_ticket_quantity.value = code.minimum_ticket_quantity || "";
    form.maximum_discounted_ticket_quantity.value = code.maximum_discounted_ticket_quantity || "";
    form.maximum_total_uses.value = code.maximum_total_uses || "";
    form.maximum_uses_per_customer.value = code.maximum_uses_per_customer || "";
    form.starts_at.value = inputDate(code.starts_at);
    form.expires_at.value = inputDate(code.expires_at);
    form.event_scope.value = code.event_scope || "all";
    form.application_scope.value = code.application_scope || "order";
    form.is_active.checked = Number(code.is_active) === 1;
    form.is_combinable.checked = Number(code.is_combinable) === 1;
    setSelectedOptions(form.event_ids, code.event_ids || []);
    setSelectedOptions(form.ticket_type_ids, code.ticket_type_ids || []);
    form.querySelector("[data-discount-form-title]").textContent = "Editar " + (code.code || "código");
    form.querySelector("[data-discount-advanced]").open = hasAdvancedDiscountSettings(code);
    syncDiscountFormVisibility(form);
    form.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  function formDiscountPayload(form) {
    return {
      code: form.code.value.trim(),
      internal_description: form.internal_description.value.trim(),
      discount_type: form.discount_type.value,
      percent_basis_points: percentToBasisPoints(form.discount_percent.value),
      fixed_amount_cents: centsFromInput(form.fixed_amount.value),
      maximum_discount_cents: centsFromInput(form.maximum_discount.value),
      application_scope: form.application_scope.value,
      event_scope: form.event_scope.value,
      event_ids: selectedOptionValues(form.event_ids),
      ticket_type_ids: selectedOptionValues(form.ticket_type_ids),
      minimum_order_cents: centsFromInput(form.minimum_order.value),
      minimum_ticket_quantity: form.minimum_ticket_quantity.value,
      maximum_discounted_ticket_quantity: form.maximum_discounted_ticket_quantity.value,
      maximum_total_uses: form.maximum_total_uses.value,
      maximum_uses_per_customer: form.maximum_uses_per_customer.value,
      starts_at: form.starts_at.value,
      expires_at: form.expires_at.value,
      is_active: form.is_active.checked,
      is_combinable: form.is_combinable.checked
    };
  }

  function renderDiscountHistory(root, code, usages) {
    var target = root.querySelector("[data-admin-discount-history]");
    target.hidden = false;
    target.innerHTML = '<div class="admin-section-label"><div><span class="ticket-eyebrow">Trazabilidad</span><h2>Historial · ' + escapeHtml(code.code) + '</h2></div><button type="button" class="text-action" data-close-discount-history>Cerrar</button></div>' +
      (!usages.length ? '<p class="ticket-copy">Este código aún no se ha aplicado a ningún pedido.</p>' : '<div class="admin-history-list">' + usages.map(function (usage) {
        return '<article><div><strong>' + escapeHtml(usage.name || "Pedido") + '</strong><small>' + escapeHtml(usage.event_title || "") + ' · ' + escapeHtml(usage.redsys_order || usage.test_reference || "") + '</small></div><div><strong>' + formatMoney(usage.discount_cents) + '</strong><small>' + escapeHtml(usage.status) + ' · ' + escapeHtml(formatDate(usage.consumed_at || usage.reserved_at, true)) + '</small></div></article>';
      }).join("") + '</div>');
  }

  function initDiscountCodes() {
    var root = document.querySelector("[data-admin-discounts-page]");
    if (!root) return;
    requireSession(function (session) {
      if (session.role !== "admin") { renderPageError("Esta sección está reservada para administración."); return; }
      var form = root.querySelector("[data-admin-discount-form]");
      var search = root.querySelector("[data-discount-search]");
      var stateFilter = "active";
      var formStatus = root.querySelector("[data-admin-discount-status]");
      function loadCodes() {
        return request(api + "/admin/discount-codes?state=" + encodeURIComponent(stateFilter)).then(function (data) { state.discountCodes = data.discount_codes || []; renderDiscountList(root, search.value); });
      }
      Promise.all([request(api + "/admin/discount-codes/meta"), loadCodes()]).then(function (result) {
        state.discountMeta = result[0];
        populateDiscountOptions(form);
        var eventFilter = root.querySelector("[data-discount-list-event]");
        eventFilter.innerHTML = '<option value="">Todas las experiencias</option>' + (state.discountMeta.events || []).map(function (event) { return '<option value="' + Number(event.id) + '">' + escapeHtml(event.title) + '</option>'; }).join("");
        resetDiscountForm(form);
      }).catch(function (error) { renderPageError(error.message); });
      form.discount_type.addEventListener("change", function () { syncDiscountFormVisibility(form); });
      form.discount_percent.addEventListener("input", function () { syncDiscountValueHint(form); });
      form.event_scope.addEventListener("change", function () { syncDiscountFormVisibility(form); });
      form.application_scope.addEventListener("change", function () { syncDiscountFormVisibility(form); });
      search.addEventListener("input", function () { renderDiscountList(root, search.value); });
      root.querySelectorAll("[data-discount-list-event], [data-discount-list-type], [data-discount-list-date]").forEach(function (filter) { filter.addEventListener("change", function () { renderDiscountList(root, search.value); }); });
      root.querySelectorAll("[data-discount-filter]").forEach(function (button) {
        button.addEventListener("click", function () { stateFilter = button.getAttribute("data-discount-filter") || "active"; root.querySelectorAll("[data-discount-filter]").forEach(function (item) { item.classList.toggle("is-active", item === button); }); loadCodes().catch(function (error) { renderPageError(error.message); }); });
      });
      root.querySelector("[data-discount-form-reset]").addEventListener("click", function () { resetDiscountForm(form); setDiscountFormStatus(formStatus, "", ""); });
      form.addEventListener("invalid", function (event) {
        if (event.target === form.discount_percent && Number(form.discount_percent.value || 0) > 0 && Number(form.discount_percent.value) < 1) {
          setDiscountFormStatus(formStatus, "El descuento mínimo es del 1 %. Escribe 18 para un 18 %.", "error");
          return;
        }
        setDiscountFormStatus(formStatus, "Revisa los campos marcados antes de guardar.", "error");
      }, true);
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        var submit = form.querySelector('[type="submit"]');
        var id = Number(form.discount_id.value || 0);
        var percentage = Number(form.discount_percent.value || 0);
        if (form.discount_type.value === "percent" && (!Number.isFinite(percentage) || percentage < 1)) {
          setDiscountFormStatus(formStatus, "El descuento mínimo es del 1 %. Escribe 18 para un 18 %.", "error");
          form.discount_percent.focus();
          return;
        }
        submit.disabled = true;
        setDiscountFormStatus(formStatus, "Guardando…", "");
        jsonRequest(api + "/admin/discount-codes" + (id ? "/" + id : ""), id ? "PUT" : "POST", formDiscountPayload(form))
          .then(function (data) { fillDiscountForm(form, data.discount_code); setDiscountFormStatus(formStatus, "Código " + data.discount_code.code + " guardado" + (Number(data.discount_code.is_active) ? " y activo en checkout." : "."), "success"); return loadCodes(); })
          .catch(function (error) { setDiscountFormStatus(formStatus, error.message || "No se ha podido guardar el código.", "error"); })
          .finally(function () { submit.disabled = false; });
      });
      root.addEventListener("click", function (event) {
        if (event.target.closest("[data-close-discount-history]")) { root.querySelector("[data-admin-discount-history]").hidden = true; return; }
        var action = event.target.closest("[data-discount-action]");
        if (!action) return;
        var row = action.closest("[data-discount-id]");
        var id = Number(row.getAttribute("data-discount-id"));
        var code = state.discountCodes.find(function (item) { return Number(item.id) === id; });
        var actionName = action.getAttribute("data-discount-action");
        if (!code) return;
        if (actionName === "edit") { fillDiscountForm(form, code); return; }
        if (actionName === "history") { request(api + "/admin/discount-codes/" + id + "/history").then(function (data) { renderDiscountHistory(root, code, data.usages || []); }).catch(function (error) { renderPageError(error.message); }); return; }
        if (actionName === "duplicate") {
          if (!window.confirm("Se creará una copia inactiva del código " + code.code + ".")) return;
          jsonRequest(api + "/admin/discount-codes/" + id + "/duplicate", "POST", {}).then(function (data) { stateFilter = "inactive"; root.querySelectorAll("[data-discount-filter]").forEach(function (item) { item.classList.toggle("is-active", item.getAttribute("data-discount-filter") === "inactive"); }); return loadCodes().then(function () { fillDiscountForm(form, data.discount_code); }); }).catch(function (error) { renderPageError(error.message); });
          return;
        }
        if (actionName === "archive") {
          if (!window.confirm("Archivará " + code.code + ". Sus pedidos históricos se conservarán. ¿Continuar?")) return;
          jsonRequest(api + "/admin/discount-codes/" + id + "/archive", "POST", {}).then(function () { if (Number(form.discount_id.value) === id) resetDiscountForm(form); return loadCodes(); }).catch(function (error) { renderPageError(error.message); });
          return;
        }
        if (actionName === "delete") {
          if (!window.confirm("Eliminará definitivamente el código " + code.code + ". Esta acción solo es posible porque no tiene usos. ¿Continuar?")) return;
          jsonRequest(api + "/admin/discount-codes/" + id, "DELETE", {}).then(function () { if (Number(form.discount_id.value) === id) resetDiscountForm(form); return loadCodes(); }).catch(function (error) { renderPageError(error.message); });
        }
      });
    });
  }

  function analyticsNumber(value) { return new Intl.NumberFormat("es-ES").format(Number(value || 0)); }
  function analyticsDuration(seconds) {
    seconds = Number(seconds || 0);
    return String(Math.floor(seconds / 60)).padStart(2, "0") + ":" + String(seconds % 60).padStart(2, "0");
  }
  function analyticsChange(item, unit) {
    if (item.change === null || item.change === undefined) return '<small class="analytics-change">Sin periodo anterior</small>';
    var up = Number(item.change) >= 0;
    var suffix = unit === "points" ? " puntos" : " %";
    return '<small class="analytics-change ' + (up ? "is-positive" : "is-negative") + '">' + (up ? "↑ +" : "↓ ") + escapeHtml(String(item.change)) + suffix + ' vs. periodo anterior</small>';
  }
  function analyticsValue(key, item) {
    if (key === "conversion_rate" || key === "engagement_rate") return Number(item.value || 0).toLocaleString("es-ES", { maximumFractionDigits: 2 }) + " %";
    if (key === "revenue_cents") return formatMoney(item.value);
    if (key === "average_session_seconds") return analyticsDuration(item.value);
    return analyticsNumber(item.value);
  }
  function analyticsTableEmpty(message) { return '<div class="analytics-empty">' + escapeHtml(message) + '</div>'; }

  function renderAnalytics(root, data, stateData) {
    var kpis = data.kpis || {};
    var range = stateData.range;
    var primaryKeys = ["visitors", "sessions", "pageviews", "conversion_rate", "conversions", "average_session_seconds", "pages_per_session", "engagement_rate"];
    var cards = primaryKeys.map(function (key) {
      var item = kpis[key] || { label: key, value: 0, change: null };
      return '<article class="analytics-kpi"><span>' + escapeHtml(item.label) + '</span><strong>' + analyticsValue(key, item) + '</strong>' + analyticsChange(item, item.unit) + '</article>';
    }).join("");
    var timeline = data.timeline || [];
    var metric = stateData.metric;
    var maximum = Math.max.apply(Math, [1].concat(timeline.map(function (item) { return Number(item[metric] || 0); })));
    var bars = timeline.length ? timeline.map(function (item) {
      var value = Number(item[metric] || 0);
      return '<div class="analytics-bar-wrap" title="' + escapeHtml(item.bucket + ': ' + value) + '"><i style="height:' + Math.max(value ? 6 : 0, Math.round((value / maximum) * 100)) + '%"></i><span>' + escapeHtml(String(item.bucket).slice(range === "today" ? 11 : 5)) + '</span></div>';
    }).join("") : '<p class="ticket-copy">Los datos empezarán a aparecer cuando se registren nuevas visitas consentidas.</p>';
    var funnel = (data.funnel || []).map(function (step) { return '<div class="analytics-funnel-step"><div><span>' + escapeHtml(step.label) + '</span><strong>' + analyticsNumber(step.value) + '</strong></div><b style="width:' + Math.min(100, Number(step.rate || 0)) + '%"></b><small>' + Number(step.rate || 0).toLocaleString("es-ES") + ' %</small></div>'; }).join("");
    var pages = (data.pages || []).length ? '<div class="analytics-table">' + data.pages.map(function (page) { return '<a href="' + escapeHtml(page.path) + '" target="_blank" rel="noopener noreferrer"><span><strong>' + escapeHtml(page.title) + '</strong><small>' + escapeHtml(page.path) + '</small></span><span>' + analyticsNumber(page.visitors) + '</span><span>' + analyticsNumber(page.views) + '</span><span>' + analyticsNumber(page.average_scroll) + ' %</span></a>'; }).join("") + '</div>' : analyticsTableEmpty('Aún no hay páginas con datos.');
    var experiences = (data.experiences || []).length ? '<div class="analytics-table analytics-experience-table">' + data.experiences.map(function (item) { return '<article><span><strong>' + escapeHtml(item.title) + '</strong><small>/' + escapeHtml(item.slug) + '</small></span><span>' + analyticsNumber(item.visitors) + '<small>visitas</small></span><span>' + analyticsNumber(item.ticket_clicks) + '<small>clics</small></span><span>' + analyticsNumber(item.checkouts) + '<small>checkout</small></span><span>' + analyticsNumber(item.purchases) + '<small>compras</small></span><span>' + Number(item.conversion_rate).toLocaleString("es-ES") + ' %</span></article>'; }).join("") + '</div>' : analyticsTableEmpty('Las experiencias aparecerán cuando reciban visitas consentidas.');
    var list = function (items, label, value) { return items && items.length ? '<div class="analytics-ranking">' + items.map(function (item) { return '<div><span>' + escapeHtml(item[label]) + '</span><strong>' + analyticsNumber(item[value]) + '</strong></div>'; }).join("") + '</div>' : analyticsTableEmpty('Aún no hay datos.'); };
    var sectionRetention = (data.sections || []).length ? '<div class="analytics-ranking">' + data.sections.map(function (section) { return '<div><span>' + escapeHtml(section.id) + '<small>' + analyticsNumber(section.sessions) + ' sesiones</small></span><strong>' + Number(section.retention_rate || 0).toLocaleString("es-ES") + ' %</strong></div>'; }).join("") + '</div>' : analyticsTableEmpty('Las secciones aparecerán cuando reciban visitas consentidas.');
    var scrollDepths = (data.scroll_depths || []).some(function (item) { return Number(item.visitors || 0) > 0; }) ? '<div class="analytics-ranking">' + data.scroll_depths.map(function (item) { return '<div><span>Scroll ' + analyticsNumber(item.depth) + ' %</span><strong>' + Number(item.rate || 0).toLocaleString("es-ES") + ' %</strong></div>'; }).join("") + '</div>' : analyticsTableEmpty('Aún no hay hitos de scroll registrados.');
    var insights = (data.insights || []).length ? '<div class="analytics-insights">' + data.insights.map(function (item) { return '<p class="' + escapeHtml(item.tone || "") + '">' + escapeHtml(item.text) + '</p>'; }).join("") + '</div>' : analyticsTableEmpty('Los insights aparecerán al acumular datos comparables.');
    var realtime = data.realtime || { active_sessions: 0, pages: [] };
    root.innerHTML =
      '<section class="admin-page-heading analytics-heading"><div><span class="ticket-eyebrow">Analítica</span><h1>Comportamiento y <em>conversión.</em></h1><p>Datos first-party y agregados para entender qué ocurre en Perigallo.com.</p></div><div class="analytics-live"><span>Ahora mismo</span><strong>' + analyticsNumber(realtime.active_sessions) + '</strong><small>sesiones activas</small></div></section>' +
      '<section class="analytics-controls" aria-label="Intervalo de analítica"><div class="analytics-range-tabs"><button type="button" data-analytics-range="today" class="' + (range === "today" ? "is-active" : "") + '">Hoy</button><button type="button" data-analytics-range="7d" class="' + (range === "7d" ? "is-active" : "") + '">7 días</button><button type="button" data-analytics-range="30d" class="' + (range === "30d" ? "is-active" : "") + '">30 días</button></div><form data-analytics-custom-range><label>Desde<input type="date" name="from" value="' + escapeHtml(stateData.from || "") + '"></label><label>Hasta<input type="date" name="to" value="' + escapeHtml(stateData.to || "") + '"></label><button class="ticket-btn" type="submit">Personalizado</button></form></section>' +
      '<section class="analytics-kpis">' + cards + '</section>' +
      '<section class="analytics-grid analytics-grid-main"><article class="analytics-panel analytics-timeline"><div class="analytics-panel-heading"><div><span class="ticket-eyebrow">Evolución</span><h2>Actividad en el tiempo</h2></div><div class="analytics-metric-tabs">' + ["visitors", "sessions", "pageviews", "conversions"].map(function (key) { return '<button type="button" data-analytics-metric="' + key + '" class="' + (metric === key ? "is-active" : "") + '">' + escapeHtml((kpis[key] || {}).label || key) + '</button>'; }).join("") + '</div></div><div class="analytics-bars">' + bars + '</div></article><article class="analytics-panel"><span class="ticket-eyebrow">Embudo de conversión</span><h2>De la visita a la compra</h2><div class="analytics-funnel">' + funnel + '</div></article></section>' +
      '<section class="analytics-grid"><article class="analytics-panel analytics-wide"><div class="analytics-panel-heading"><div><span class="ticket-eyebrow">Páginas</span><h2>Las más visitadas</h2></div><small>Visitantes · Vistas · Scroll medio</small></div>' + pages + '</article><article class="analytics-panel"><span class="ticket-eyebrow">Insights</span><h2>Qué merece atención</h2>' + insights + '</article></section>' +
      '<section class="analytics-grid"><article class="analytics-panel analytics-wide"><div class="analytics-panel-heading"><div><span class="ticket-eyebrow">Experiencias</span><h2>Interés y compra</h2></div><small>Visitas · Clics · Checkout · Compras</small></div>' + experiences + '</article><article class="analytics-panel"><span class="ticket-eyebrow">En este momento</span><h2>Páginas activas</h2>' + list(realtime.pages || [], "path", "sessions") + '</article></section>' +
      '<section class="analytics-grid analytics-grid-three"><article class="analytics-panel"><span class="ticket-eyebrow">Secciones</span><h2>Retención por bloque</h2>' + sectionRetention + '</article><article class="analytics-panel"><span class="ticket-eyebrow">Profundidad</span><h2>Hasta dónde llegan</h2>' + scrollDepths + '</article><article class="analytics-panel"><span class="ticket-eyebrow">Tráfico</span><h2>De dónde llegan</h2>' + list(data.sources || [], "label", "value") + '</article></section>' +
      '<section class="analytics-grid"><article class="analytics-panel"><span class="ticket-eyebrow">Dispositivos</span><h2>Cómo navegan</h2>' + list(data.devices || [], "label", "value") + '</article></section>' +
      '<section class="analytics-grid"><article class="analytics-panel"><span class="ticket-eyebrow">Acciones</span><h2>Clics relevantes</h2>' + ((data.actions || []).length ? '<div class="analytics-ranking">' + data.actions.map(function (action) { return '<div><span>' + escapeHtml(action.id) + '</span><strong>' + analyticsNumber(action.clicks) + '</strong></div>'; }).join("") + '</div>' : analyticsTableEmpty('Aún no hay acciones registradas.')) + '</article>' + (stateData.owner ? '<article class="analytics-panel analytics-settings"><span class="ticket-eyebrow">Informes</span><h2>Resumen por correo</h2><form data-analytics-settings><label>Destinatario<input type="email" name="report_email" value="' + escapeHtml(stateData.settings.report_email || "") + '" placeholder="correo@ejemplo.com"></label><div class="analytics-setting-checks"><label><input type="checkbox" name="daily_enabled"' + (stateData.settings.daily_enabled ? " checked" : "") + '> Diario</label><label><input type="checkbox" name="weekly_enabled"' + (stateData.settings.weekly_enabled ? " checked" : "") + '> Semanal</label><label><input type="checkbox" name="monthly_enabled"' + (stateData.settings.monthly_enabled ? " checked" : "") + '> Mensual</label></div><label>Hora<input type="number" min="0" max="23" name="report_hour" value="' + Number(stateData.settings.report_hour || 8) + '"></label><label>Zona horaria<input type="text" name="timezone" value="' + escapeHtml(stateData.settings.timezone || "Europe/Madrid") + '"></label><div class="analytics-settings-actions"><button class="ticket-btn primary" type="submit">Guardar configuración</button><button class="ticket-btn" type="button" data-analytics-test-report>Enviar informe de prueba</button></div><p class="ticket-status" data-analytics-settings-status></p></form></article>' : '<article class="analytics-panel"><span class="ticket-eyebrow">Privacidad</span><h2>Datos con consentimiento</h2><p class="ticket-copy">Sin direcciones IP, nombres, emails, teléfonos ni contenido de formularios. La compra se confirma solo desde el backend.</p></article>') + '</section>';
  }

  function initAnalytics() {
    var root = document.querySelector("[data-admin-analytics-page]");
    if (!root) return;
    requireSession(function (session) {
      if (session.role !== "admin") { renderPageError("Esta sección está reservada para administración."); return; }
      var stateData = { range: "7d", metric: "visitors", from: "", to: "", owner: !!session.is_owner, settings: {} };
      function query() {
        var params = new URLSearchParams({ range: stateData.range });
        if (stateData.range === "custom") { params.set("from", stateData.from); params.set("to", stateData.to); }
        return request(api + "/admin/analytics?" + params.toString()).then(function (result) { return result.analytics; });
      }
      function load() {
        var settingsRequest = stateData.owner ? request(api + "/admin/analytics/settings") : Promise.resolve({ settings: {} });
        return Promise.all([query(), settingsRequest]).then(function (result) { stateData.settings = result[1].settings || {}; renderAnalytics(root, result[0], stateData); bind(); });
      }
      function bind() {
        root.querySelectorAll("[data-analytics-range]").forEach(function (button) { button.addEventListener("click", function () { stateData.range = button.dataset.analyticsRange; stateData.from = ""; stateData.to = ""; load().catch(function (error) { renderPageError(error.message); }); }); });
        root.querySelectorAll("[data-analytics-metric]").forEach(function (button) { button.addEventListener("click", function () { stateData.metric = button.dataset.analyticsMetric; load().catch(function (error) { renderPageError(error.message); }); }); });
        var custom = root.querySelector("[data-analytics-custom-range]");
        if (custom) custom.addEventListener("submit", function (event) { event.preventDefault(); stateData.range = "custom"; stateData.from = custom.from.value; stateData.to = custom.to.value; load().catch(function (error) { renderPageError(error.message); }); });
        var settings = root.querySelector("[data-analytics-settings]");
        if (settings) settings.addEventListener("submit", function (event) { event.preventDefault(); var status = root.querySelector("[data-analytics-settings-status]"); status.textContent = "Guardando…"; jsonRequest(api + "/admin/analytics/settings", "PUT", { report_email: settings.report_email.value.trim(), daily_enabled: settings.daily_enabled.checked, weekly_enabled: settings.weekly_enabled.checked, monthly_enabled: settings.monthly_enabled.checked, report_hour: settings.report_hour.value, timezone: settings.timezone.value.trim() }).then(function () { status.textContent = "Configuración guardada."; }).catch(function (error) { status.textContent = error.message; }); });
        var test = root.querySelector("[data-analytics-test-report]");
        if (test) test.addEventListener("click", function () { var status = root.querySelector("[data-analytics-settings-status]"); test.disabled = true; status.textContent = "Enviando informe de prueba…"; jsonRequest(api + "/admin/analytics/send-test-report", "POST", {}).then(function (result) { status.textContent = result.report.status === "sent" ? "Informe de prueba enviado." : "No se pudo enviar el informe."; }).catch(function (error) { status.textContent = error.message; }).finally(function () { test.disabled = false; }); });
      }
      load().catch(function (error) { renderPageError(error.message); });
    });
  }

  function initContacts() {
    var root = document.querySelector("[data-admin-contacts-page]");
    if (!root) return;
    requireSession(function (session) {
      if (session.role !== "admin") { renderPageError("Esta sección está reservada para administración."); return; }
      var consent = "all";
      var query = "";
      function consentLabel(value) { return value === "granted" ? "✓ Autorizado" : value === "revoked" ? "Revocado" : "Sin información"; }
      function consentClass(value) { return value === "granted" ? "is-granted" : value === "revoked" ? "is-revoked" : ""; }
      function render(items) {
        root.innerHTML = '<section class="admin-page-heading admin-page-heading-compact"><div><span class="ticket-eyebrow">Gestión</span><h1>Base de <em>datos.</em></h1><p>Contactos centralizados. Los consentimientos comerciales son independientes de la compra y la privacidad.</p></div><div class="admin-inline-actions"><a class="ticket-btn" href="/api/admin/contactos/export?consent=' + encodeURIComponent(consent) + '">Exportar CSV</a>' + (session.is_owner ? '<button class="ticket-btn" type="button" data-contacts-backfill>Actualizar históricos</button>' : '') + '</div></section><section class="contacts-toolbar"><input type="search" data-contacts-search placeholder="Buscar nombre, email o teléfono" value="' + escapeHtml(query) + '"><div class="contacts-tabs">' + [["all","Todos"],["email","Email autorizado"],["whatsapp","WhatsApp autorizado"],["marketing","Marketing autorizado"],["none","Sin consentimiento"],["revoked","Revocado"]].map(function (item) { return '<button type="button" data-contacts-filter="' + item[0] + '" class="' + (consent === item[0] ? 'is-active' : '') + '">' + item[1] + '</button>'; }).join('') + '</div></section><section class="contacts-list">' + (items.length ? items.map(function (contact) { return '<button type="button" class="contact-row" data-contact-id="' + Number(contact.id) + '"><span><strong>' + escapeHtml(contact.full_name) + '</strong><small>' + escapeHtml(contact.initial_source) + ' · ' + escapeHtml(contact.last_purchase_at || contact.updated_at) + '</small></span><span>' + escapeHtml(contact.email || '—') + '<small>' + escapeHtml(contact.phone || '—') + '</small></span><span class="contact-consent ' + consentClass(contact.email_marketing) + '">Email · ' + consentLabel(contact.email_marketing) + '</span><span class="contact-consent ' + consentClass(contact.whatsapp_marketing) + '">WhatsApp · ' + consentLabel(contact.whatsapp_marketing) + '</span><span>' + Number(contact.order_count || 0) + ' pedidos</span></button>'; }).join('') : '<div class="admin-empty"><strong>No hay contactos todavía.</strong><span>Los pedidos pagados y formularios válidos se sincronizarán aquí.</span></div>') + '</section><section data-contact-detail></section>';
        bind();
      }
      function load() { return request(api + "/admin/contactos?" + new URLSearchParams({ consent: consent, q: query }).toString()).then(function (data) { render(data.contacts || []); }); }
      function detail(id) { return request(api + "/admin/contactos/" + id).then(function (data) { var c = data.contact; var rows = (data.orders || []).map(function (o) { return '<li>' + escapeHtml(o.event_title || 'Evento') + ' · ' + Number(o.ticket_quantity || 0) + ' entradas · ' + formatMoney(o.total_cents) + '</li>'; }).join('') || '<li>Sin compras</li>'; var consents = (data.consents || []).map(function (item) { return '<li>' + escapeHtml(item.channel) + ' · ' + escapeHtml(item.status) + ' · ' + escapeHtml(item.created_at) + '</li>'; }).join('') || '<li>Sin información</li>'; root.querySelector("[data-contact-detail]").innerHTML = '<section class="contact-detail"><span class="ticket-eyebrow">Ficha de contacto</span><h2>' + escapeHtml(c.full_name) + '</h2><div class="contact-detail-grid"><article><h3>Contacto</h3><p>' + escapeHtml(c.email || 'Sin email') + '<br>' + escapeHtml(c.phone || 'Sin teléfono') + '</p></article><article><h3>Actividad</h3><ul>' + rows + '</ul></article><article><h3>Consentimientos</h3><ul>' + consents + '</ul><button class="contact-action" data-contact-revoke="email" data-contact-id="' + Number(c.id) + '">Revocar email</button> <button class="contact-action" data-contact-revoke="whatsapp" data-contact-id="' + Number(c.id) + '">Revocar WhatsApp</button></article></div></section>'; }); }
      function bind() {
        var search = root.querySelector("[data-contacts-search]"); search.addEventListener("input", function () { query = search.value.trim(); load().catch(function (error) { renderPageError(error.message); }); });
        root.querySelectorAll("[data-contacts-filter]").forEach(function (button) { button.addEventListener("click", function () { consent = button.dataset.contactsFilter; load().catch(function (error) { renderPageError(error.message); }); }); });
        root.querySelectorAll("[data-contact-id]").forEach(function (button) { button.addEventListener("click", function () { detail(button.dataset.contactId).catch(function (error) { renderPageError(error.message); }); }); });
        var backfill = root.querySelector("[data-contacts-backfill]"); if (backfill) backfill.addEventListener("click", function () { if (!window.confirm("Importará pedidos pagados y formularios existentes sin conceder consentimiento comercial. ¿Continuar?")) return; backfill.disabled = true; jsonRequest(api + "/admin/contactos/backfill", "POST", {}).then(load).catch(function (error) { renderPageError(error.message); }).finally(function () { backfill.disabled = false; }); });
        root.querySelectorAll("[data-contact-revoke]").forEach(function (button) { button.addEventListener("click", function () { if (!window.confirm("Se revocará este canal de marketing. Los pedidos no se borrarán.")) return; jsonRequest(api + "/admin/contactos/" + button.dataset.contactId + "/" + button.dataset.contactRevoke + "/revocar", "POST", {}).then(function () { return detail(button.dataset.contactId); }).catch(function (error) { renderPageError(error.message); }); }); });
      }
      load().catch(function (error) { renderPageError(error.message); });
    });
  }

  function initAdminChrome() {
    if (!document.querySelector("[data-admin-nav]") || document.querySelector("[data-admin-dashboard]")) return;
    requireSession(function () { /* The scanner shares the authenticated administrative shell. */ });
  }

  initLogin();
  initAdminChrome();
  initDashboard();
  initEvents();
  initSales();
  initBilling();
  initLeadForms();
  initUsers();
  initDiscountCodes();
  initAnalytics();
  initContacts();
})();

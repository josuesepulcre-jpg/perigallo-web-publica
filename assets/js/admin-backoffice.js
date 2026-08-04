(function () {
  "use strict";

  var api = "/api";
  var state = { csrf: "", session: null, events: [], orders: [], users: [] };
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
    return ({ draft: "Borrador", scheduled: "Programado", published: "Publicado", sold_out: "Agotado", finished: "Finalizado", cancelled: "Cancelado", archived: "Archivado", paid: "Pagado", pending: "Pendiente", failed: "Fallido", rejected: "Rechazado", refunded: "Reembolsado" })[status] || status || "Sin estado";
  }

  function currentNav() {
    var path = window.location.pathname;
    if (path.indexOf("/admin/eventos") === 0) return "events";
    if (path.indexOf("/admin/ventas") === 0) return "sales";
    if (path.indexOf("/admin/acceso") === 0) return "access";
    if (path.indexOf("/admin/usuarios") === 0) return "users";
    return "dashboard";
  }

  function roleLabel(role) { return role === "control_acceso" ? "Control de acceso" : "Administrador"; }

  function injectShell(sessionData) {
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
            '<span class="admin-nav-label">Operativa</span>' +
            '<a href="/admin/acceso/" data-admin-nav-item="access">Control de acceso</a>' +
            (sessionData.is_owner ? '<span class="admin-nav-label">Configuración</span><a href="/admin/usuarios/" data-admin-nav-item="users">Equipo y permisos</a>' : '') +
          '</nav>';
      node.innerHTML =
        '<a class="admin-brand" href="/admin/" aria-label="Administración Perigallo"><img src="/assets/images/perigallo-logo-original.png" alt="Perigallo"><span>Administración</span></a>' +
        navigation +
        '<div class="admin-account"><span data-admin-user-name></span><small data-admin-user-role></small><button type="button" data-admin-logout>Cerrar sesión</button></div>';
      var activeNode = node.querySelector('[data-admin-nav-item="' + active + '"]');
      if (activeNode) activeNode.classList.add("is-active");
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
    return '<article class="admin-featured-event' + (featured ? " is-featured" : "") + '">' +
      '<div class="admin-featured-image" style="background-image:url(' + escapeHtml(image) + ')"></div>' +
      '<div class="admin-featured-copy"><span class="status-pill status-' + escapeHtml(event.effective_status || event.status) + '">' + escapeHtml(statusLabel(event.effective_status || event.status)) + '</span>' +
      '<h2>' + escapeHtml(event.title) + '</h2><p>' + escapeHtml(formatDate(event.starts_at, true)) + ' · ' + escapeHtml(event.location || "Ubicación por definir") + '</p>' +
      '<div class="admin-progress"><span><strong>' + Number(event.sold || 0) + '</strong> entradas vendidas</span><span>' + Number(event.capacity || 0) + ' aforo · ' + occupancy + '%</span><i><b style="width:' + occupancy + '%"></b></i></div>' +
      '<div class="admin-inline-actions"><a class="ticket-btn primary" href="/admin/eventos/' + Number(event.id) + '/editar/">Editar evento</a><a class="ticket-btn" href="/admin/ventas/?event=' + Number(event.id) + '">Ver ventas</a><a class="ticket-btn" href="/admin/acceso/?event=' + Number(event.id) + '">Control de acceso</a><a class="text-action" href="/eventos/' + encodeURIComponent(event.slug) + '/" target="_blank" rel="noopener noreferrer">Página pública</a></div></div></article>';
  }

  function renderDashboard(summary, orders) {
    var root = document.querySelector("[data-admin-dashboard]");
    if (!root) return;
    var data = metrics(summary.events || [], orders || []);
    var featured = data.upcoming[0] || (summary.events || [])[0];
    root.innerHTML =
      '<section class="admin-page-heading"><div><span class="ticket-eyebrow">Administración Perigallo</span><h1>Todo lo que ocurre, <em>conectado.</em></h1><p>Gestiona experiencias, ventas y acceso desde un único lugar.</p></div><a class="ticket-btn primary" href="/admin/eventos/?new=1">Crear evento</a></section>' +
      '<section class="admin-stat-grid" aria-label="Resumen de actividad">' +
        '<article><span>Próximos eventos</span><strong>' + data.upcoming.length + '</strong><small>' + data.drafts + ' borradores</small></article>' +
        '<article><span>Entradas vendidas</span><strong>' + data.sold + '</strong><small>En todos los eventos</small></article>' +
        '<article><span>Ingresos cobrados</span><strong>' + formatMoney(data.revenue) + '</strong><small>Pedidos confirmados</small></article>' +
        '<article><span>Capacidad pendiente</span><strong>' + data.pending + '</strong><small>Plazas aún disponibles</small></article>' +
      '</section>' +
      (featured ? '<section class="admin-dashboard-section"><div class="admin-section-label"><span class="ticket-eyebrow">Próximo evento</span><a class="text-action" href="/admin/eventos/">Ver todos los eventos</a></div>' + eventCard(featured, true) + '</section>' : '<section class="admin-empty"><strong>Todavía no hay eventos.</strong><span>Crea la primera experiencia para empezar a vender entradas.</span><a class="ticket-btn primary" href="/admin/eventos/?new=1">Crear evento</a></section>') +
      '<section class="admin-dashboard-section"><div class="admin-section-label"><div><span class="ticket-eyebrow">Actividad reciente</span><h2>Últimos pedidos</h2></div><a class="text-action" href="/admin/ventas/">Ver ventas</a></div><div class="admin-recent-orders">' + renderOrdersRows(orders.slice(0, 6), true) + '</div></section>';
  }

  function isClosedOrder(order) { return order.display_status === "cancelled" || order.display_status === "refunded"; }

  function orderActions(order) {
    if (!state.session || state.session.role !== "admin") return "";
    var actions = '<div class="admin-order-actions"><a class="text-action" href="/entradas/pedido/?token=' + encodeURIComponent(order.public_token) + '" target="_blank" rel="noopener noreferrer">Ver pedido</a>';
    if (!isClosedOrder(order)) actions += '<button type="button" class="text-action" data-order-action="cancel" data-order-id="' + Number(order.id) + '">Cancelar</button>';
    if (!isClosedOrder(order) && (order.payment_status === "paid" || order.status === "paid")) actions += '<button type="button" class="text-action" data-order-action="refund" data-order-id="' + Number(order.id) + '">Registrar devolución</button>';
    if (state.session.is_owner && Number(order.is_test)) actions += '<button type="button" class="text-action danger" data-order-action="purge-test" data-order-id="' + Number(order.id) + '">Eliminar prueba</button>';
    return actions + '</div>';
  }

  function renderOrdersRows(orders, compact) {
    if (!orders.length) return '<div class="admin-empty"><strong>No se han registrado pedidos.</strong><span>Los pedidos aparecerán aquí cuando se complete una compra.</span></div>';
    return orders.map(function (order) {
      var reference = order.redsys_order || order.test_reference || ("Pedido " + order.id);
      var displayStatus = order.display_status || order.payment_status || order.status;
      var paymentMethod = order.payment_method === "bizum" ? "Bizum" : "Tarjeta";
      return '<article class="admin-order-row"><div><strong>' + escapeHtml(order.name || "Comprador sin nombre") + '</strong><small>' + escapeHtml(order.event_title || "Evento por asignar") + ' · ' + escapeHtml(reference) + ' · ' + paymentMethod + (Number(order.is_test) ? ' · Prueba' : '') + '</small></div><span>' + Number(order.ticket_quantity || 0) + ' entrada' + (Number(order.ticket_quantity || 0) === 1 ? "" : "s") + '</span><span class="status-pill status-' + escapeHtml(displayStatus) + '">' + escapeHtml(statusLabel(displayStatus)) + '</span><strong>' + formatMoney(order.total_cents) + '</strong>' + (compact ? "" : orderActions(order)) + '</article>';
    }).join("");
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
      var search = root.querySelector("[data-admin-event-search]");
      function createEvent() {
        create.disabled = true;
        create.textContent = "Creando…";
        jsonRequest(api + "/admin/events", "POST", { title: "Nuevo evento" }).then(function (data) {
          window.location.assign("/admin/eventos/" + data.event.id + "/editar/");
        }).catch(function (error) { renderPageError(error.message); create.disabled = false; create.textContent = "Crear evento"; });
      }
      create.addEventListener("click", createEvent);
      request(api + "/admin/events").then(function (data) {
        state.events = data.events || [];
        renderEvents(state.events);
        search.addEventListener("input", function () { renderEvents(state.events, search.value); });
        if (new URLSearchParams(window.location.search).get("new") === "1") createEvent();
      }).catch(function (error) { renderPageError(error.message); });
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
      function matchesFilter(order) {
        var displayStatus = order.display_status || order.payment_status || order.status;
        if (activeFilter === "active") return Number(order.is_test) !== 1 && !isClosedOrder(order) && (displayStatus === "paid" || order.status === "paid" || order.payment_status === "paid");
        if (activeFilter === "pending") return !isClosedOrder(order) && !(displayStatus === "paid" || order.status === "paid" || order.payment_status === "paid");
        if (activeFilter === "cancelled") return displayStatus === "cancelled";
        if (activeFilter === "refunded") return displayStatus === "refunded";
        if (activeFilter === "tests") return Number(order.is_test) === 1;
        return true;
      }
      function render(filter) {
        var term = String(filter || "").trim().toLowerCase();
        var rows = state.orders.filter(function (order) {
          return matchesFilter(order) && (!term || [order.name, order.email, order.phone, order.redsys_order, order.test_reference, order.event_title, order.payment_status, order.display_status].join(" ").toLowerCase().includes(term));
        });
        root.querySelector("[data-admin-orders-list]").innerHTML = renderOrdersRows(rows, false);
        if (status) status.textContent = rows.length + (rows.length === 1 ? " pedido mostrado" : " pedidos mostrados");
      }
      function reload() {
        request(api + "/admin/orders").then(function (data) { state.orders = data.orders || []; render(search.value); }).catch(function (error) { renderPageError(error.message); });
      }
      function actionMessage(action) {
        if (action === "cancel") return "Cancelar las entradas de este pedido impedirá su acceso. No realiza ningún abono. ¿Continuar?";
        if (action === "refund") return "Registra la devolución solo cuando el abono ya se haya realizado en Redsys/TPV. Esta acción revoca las entradas, pero no devuelve dinero automáticamente. ¿Confirmar?";
        return "Eliminarás definitivamente este pedido de prueba y todas sus entradas. No se puede deshacer. ¿Continuar?";
      }
      request(api + "/admin/orders").then(function (data) {
        state.orders = data.orders || [];
        render();
        search.addEventListener("input", function () { render(search.value); });
        filters.forEach(function (button) {
          button.addEventListener("click", function () {
            activeFilter = button.getAttribute("data-admin-order-filter") || "active";
            filters.forEach(function (node) { node.classList.toggle("is-active", node === button); });
            render(search.value);
          });
        });
        root.addEventListener("click", function (event) {
          var button = event.target.closest("[data-order-action]");
          if (!button) return;
          var action = button.getAttribute("data-order-action");
          var id = Number(button.getAttribute("data-order-id"));
          if (!id || !window.confirm(actionMessage(action))) return;
          button.disabled = true;
          var url = api + "/admin/orders/" + id + (action === "cancel" ? "/cancel" : action === "refund" ? "/record-refund" : "/test");
          var method = action === "purge-test" ? "DELETE" : "POST";
          var body = action === "refund" ? { confirmed: true } : {};
          jsonRequest(url, method, body).then(function () { reload(); }).catch(function (error) { renderPageError(error.message); }).finally(function () { button.disabled = false; });
        });
      }).catch(function (error) { renderPageError(error.message); });
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

  function initAdminChrome() {
    if (!document.querySelector("[data-admin-nav]") || document.querySelector("[data-admin-dashboard]")) return;
    requireSession(function () { /* The scanner shares the authenticated administrative shell. */ });
  }

  initLogin();
  initAdminChrome();
  initDashboard();
  initEvents();
  initSales();
  initUsers();
})();

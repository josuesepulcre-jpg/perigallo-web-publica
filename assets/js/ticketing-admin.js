(function () {
  var api = "/api";
  var state = { csrf: "", events: [] };
  var money = new Intl.NumberFormat("es-ES", { style: "currency", currency: "EUR" });

  function request(url, options) {
    return fetch(url, options).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok || !data.ok) throw new Error(data.error || "No se pudo completar la solicitud.");
        return data;
      });
    });
  }

  function post(url, body) {
    return request(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": state.csrf },
      body: JSON.stringify(body)
    });
  }

  function cents(value) {
    return money.format((Number(value || 0) / 100));
  }

  function escapeHtml(value) {
    return String(value || "").replace(/[&<>"']/g, function (char) {
      return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" })[char];
    });
  }

  function initLogin() {
    var form = document.querySelector("[data-admin-login]");
    if (!form) return;
    var status = form.querySelector("[data-admin-status]");
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      status.textContent = "Accediendo...";
      request(api + "/admin/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ username: form.username.value, password: form.password.value })
      }).then(function () {
        window.location.reload();
      }).catch(function (error) {
        status.textContent = error.message;
      });
    });
  }

  function initDashboard() {
    var root = document.querySelector("[data-admin-dashboard]");
    if (!root) return;
    var login = document.querySelector("[data-admin-login-wrap]");
    request(api + "/admin/session").then(function (session) {
      if (!session.authenticated) {
        if (login) login.hidden = false;
        root.hidden = true;
        return;
      }
      state.csrf = session.csrf;
      if (login) login.hidden = true;
      root.hidden = false;
      loadDashboard();
    }).catch(function (error) {
      root.innerHTML = '<p class="ticket-status">' + escapeHtml(error.message) + '</p>';
    });
  }

  function loadDashboard() {
    Promise.all([request(api + "/admin/summary"), request(api + "/admin/orders")]).then(function (results) {
      renderSummary(results[0].summary);
      renderOrders(results[1].orders);
    }).catch(function (error) {
      var status = document.querySelector("[data-admin-errors]");
      if (status) status.textContent = error.message;
    });
  }

  function renderSummary(summary) {
    state.events = summary.events || [];
    var orders = document.querySelector("[data-admin-order-summary]");
    var events = document.querySelector("[data-admin-events]");
    if (orders) {
      orders.innerHTML = (summary.orders || []).map(function (row) {
        return '<div class="admin-row"><strong>' + escapeHtml(row.status) + '</strong><br>' + Number(row.total || 0) + ' pedidos · ' + cents(row.amount) + '</div>';
      }).join("") || '<div class="admin-row">Sin pedidos todavía.</div>';
    }
    if (events) {
      events.innerHTML = state.events.map(function (event) {
        return '<div class="admin-row"><strong>' + escapeHtml(event.title) + '</strong><br>' + escapeHtml(event.slug) + ' · ' + escapeHtml(event.status) + ' · ID ' + Number(event.id) + '</div>';
      }).join("") || '<div class="admin-row">No hay eventos creados.</div>';
    }
    var select = document.querySelector("[data-admin-event-select]");
    if (select) {
      select.innerHTML = '<option value="">Selecciona evento</option>' + state.events.map(function (event) {
        return '<option value="' + Number(event.id) + '">' + escapeHtml(event.title) + '</option>';
      }).join("");
    }
  }

  function renderOrders(rows) {
    var target = document.querySelector("[data-admin-orders]");
    if (!target) return;
    target.innerHTML = rows.map(function (order) {
      return '<div class="admin-row"><strong>' + escapeHtml(order.name) + '</strong><br>' + escapeHtml(order.status) + ' · ' + cents(order.total_cents) + ' · ' + escapeHtml(order.email) + '<br><small>' + escapeHtml(order.redsys_order) + '</small></div>';
    }).join("") || '<div class="admin-row">Sin pedidos todavía.</div>';
  }

  function initEventForms() {
    var eventForm = document.querySelector("[data-admin-event-form]");
    var typeForm = document.querySelector("[data-admin-ticket-type-form]");
    var status = document.querySelector("[data-admin-errors]");
    if (eventForm) {
      eventForm.addEventListener("submit", function (event) {
        event.preventDefault();
        post(api + "/admin/events", {
          slug: eventForm.slug.value,
          title: eventForm.title.value,
          subtitle: eventForm.subtitle.value,
          description: eventForm.description.value,
          image_url: eventForm.image_url.value,
          location: eventForm.location.value,
          address: eventForm.address.value,
          starts_at: eventForm.starts_at.value.replace("T", " ") + ":00",
          sale_starts_at: eventForm.sale_starts_at.value.replace("T", " ") + ":00",
          sale_ends_at: eventForm.sale_ends_at.value.replace("T", " ") + ":00",
          capacity: Number(eventForm.capacity.value || 0),
          status: eventForm.status.value,
          visible: eventForm.visible.checked,
          promoter: eventForm.promoter.value
        }).then(function () {
          eventForm.reset();
          loadDashboard();
        }).catch(function (error) {
          if (status) status.textContent = error.message;
        });
      });
    }
    if (typeForm) {
      typeForm.addEventListener("submit", function (event) {
        event.preventDefault();
        var eventId = Number(typeForm.event_id.value || 0);
        if (!eventId) {
          if (status) status.textContent = "Selecciona un evento.";
          return;
        }
        post(api + "/admin/events/" + eventId + "/ticket-types", {
          name: typeForm.name.value,
          description: typeForm.description.value,
          price_cents: Math.round(Number(typeForm.price.value || 0) * 100),
          capacity: Number(typeForm.capacity.value || 0),
          min_quantity: Number(typeForm.min_quantity.value || 1),
          max_per_order: Number(typeForm.max_per_order.value || 10),
          active: typeForm.active.checked,
          sort_order: Number(typeForm.sort_order.value || 100)
        }).then(function () {
          typeForm.reset();
          loadDashboard();
        }).catch(function (error) {
          if (status) status.textContent = error.message;
        });
      });
    }
  }

  function initScanner() {
    var form = document.querySelector("[data-ticket-scan]");
    if (!form) return;
    request(api + "/admin/session").then(function (session) {
      if (!session.authenticated) {
        window.location.href = "/admin/entradas/";
        return;
      }
      state.csrf = session.csrf;
      return request(api + "/admin/summary");
    }).then(function (data) {
      var select = form.event_id;
      select.innerHTML = '<option value="">Selecciona evento</option>' + (data.summary.events || []).map(function (event) {
        return '<option value="' + Number(event.id) + '">' + escapeHtml(event.title) + '</option>';
      }).join("");
    }).catch(function (error) {
      form.querySelector("[data-scan-status]").textContent = error.message;
    });
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var status = form.querySelector("[data-scan-status]");
      post(api + "/admin/tickets/scan", {
        event_id: Number(form.event_id.value || 0),
        code: form.code.value.trim()
      }).then(function (data) {
        status.className = "ticket-status scan-" + data.result;
        status.textContent = "Resultado: " + data.result.replace("_", " ");
        form.code.select();
      }).catch(function (error) {
        status.textContent = error.message;
      });
    });
  }

  initLogin();
  initDashboard();
  initEventForms();
  initScanner();
})();

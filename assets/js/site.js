(function () {
  var body = document.body;
  var toggle = document.querySelector("[data-mobile-toggle]");
  var panelLinks = document.querySelectorAll("[data-mobile-panel] a");

  if (toggle) {
    toggle.addEventListener("click", function () {
      var isOpen = body.classList.toggle("nav-open");
      toggle.setAttribute("aria-expanded", String(isOpen));
    });
  }

  panelLinks.forEach(function (link) {
    link.addEventListener("click", function () {
      body.classList.remove("nav-open");
      if (toggle) toggle.setAttribute("aria-expanded", "false");
    });
  });

  var config = {
    reservationEndpoint: window.PERIGALLO_RESERVATION_ENDPOINT || "/api/reservas/pop-up",
    leadEndpoint: window.PERIGALLO_LEAD_ENDPOINT || "/api/solicitudes/celebraciones",
    contactEndpoint: window.PERIGALLO_CONTACT_ENDPOINT || "/api/contactos",
    whatsappPhone: "34691499985"
  };

  function payloadFromForm(form) {
    var data = new FormData(form);
    var payload = {};
    data.forEach(function (value, key) {
      if (payload[key]) {
        payload[key] = [].concat(payload[key], value);
      } else {
        payload[key] = value;
      }
    });
    payload.source = form.dataset.source || "web";
    payload.page = window.location.pathname;
    payload.createdAt = new Date().toISOString();
    return payload;
  }

  function endpointFor(form) {
    if (form.dataset.endpoint) return form.dataset.endpoint;
    if (form.dataset.formType === "reservation") return config.reservationEndpoint;
    if (form.dataset.formType === "lead") return config.leadEndpoint;
    return config.contactEndpoint;
  }

  function fallbackWhatsApp(payload) {
    var lines = Object.keys(payload).map(function (key) {
      return key + ": " + payload[key];
    });
    return "https://wa.me/" + config.whatsappPhone + "?text=" + encodeURIComponent(lines.join("\n"));
  }

  document.querySelectorAll("[data-integrated-form]").forEach(function (form) {
    var status = form.querySelector("[data-form-status]");
    var button = form.querySelector("button[type='submit']");

    form.addEventListener("submit", function (event) {
      event.preventDefault();

      if (!form.reportValidity()) return;

      var payload = payloadFromForm(form);
      var endpoint = endpointFor(form);
      if (button) button.disabled = true;
      if (status) {
        status.classList.remove("is-success", "is-error");
        status.classList.add("is-loading");
        status.textContent = "Enviando solicitud...";
      }

      fetch(endpoint, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      })
        .then(function (response) {
          if (!response.ok) throw new Error("Endpoint unavailable");
          if (status) {
            status.classList.remove("is-loading", "is-error");
            status.classList.add("is-success");
            status.textContent = form.dataset.success || "Solicitud recibida. Te contactaremos pronto.";
          }
          form.reset();
        })
        .catch(function () {
          if (status) {
            status.classList.remove("is-loading", "is-success");
            status.classList.add("is-error");
            status.textContent = "No hemos podido completar el envío directo. Abrimos WhatsApp con los datos para no perder la solicitud.";
          }
          window.location.href = fallbackWhatsApp(payload);
        })
        .finally(function () {
          if (button) button.disabled = false;
        });
    });
  });

  var requestedDate = new URLSearchParams(window.location.search).get("fecha");
  if (requestedDate) {
    var dateField = document.querySelector("select[name='date']");
    if (dateField) dateField.value = requestedDate;
  }
})();

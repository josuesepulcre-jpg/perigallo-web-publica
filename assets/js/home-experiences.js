(function () {
  "use strict";

  var root = document.querySelector("[data-experience-carousel]");
  if (!root) return;

  var fallbackImage = "/assets/images/perigallo-hero-original-03.jpg";
  var activeIndex = 0;
  var experiences = [];
  var touchStartX = null;

  function escapeHtml(value) {
    return String(value || "").replace(/[&<>"]/g, function (character) {
      return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[character];
    });
  }

  function escapeAttribute(value) {
    return escapeHtml(value).replace(/'/g, "&#39;");
  }

  function eventDate(value) {
    if (!value) return null;
    var date = new Date(String(value).replace(" ", "T"));
    return Number.isNaN(date.getTime()) ? null : date;
  }

  function formatDate(value) {
    var date = eventDate(value);
    if (!date) return "Por anunciar";
    return new Intl.DateTimeFormat("es-ES", { day: "numeric", month: "long", year: "numeric" }).format(date);
  }

  function formatTime(value) {
    var date = eventDate(value);
    if (!date) return "Por confirmar";
    return new Intl.DateTimeFormat("es-ES", { hour: "2-digit", minute: "2-digit", hour12: false }).format(date) + " h";
  }

  function formatPrice(value) {
    if (!Number(value)) return "";
    return new Intl.NumberFormat("es-ES", { style: "currency", currency: "EUR" }).format(Number(value) / 100);
  }

  function publicEvents(events) {
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    return events.filter(function (event) {
      var date = eventDate(event.starts_at);
      return date && date >= today;
    }).sort(function (a, b) {
      return eventDate(a.starts_at) - eventDate(b.starts_at);
    });
  }

  function availability(event) {
    if (event.status === "sold_out") return "Completo";
    return "Plazas limitadas";
  }

  function eventLink(event) {
    return "/experiencias/" + encodeURIComponent(event.slug) + "/";
  }

  function eventDescription(event) {
    var copy = event.short_description || event.description || "Una edición Perigallo creada para una fecha, una mesa y una atmósfera irrepetibles.";
    return String(copy).replace(/\s+/g, " ").trim();
  }

  function imageUrl(event) {
    return event.card_image_url || event.image_url || fallbackImage;
  }

  function renderEmpty() {
    root.className = "experience-carousel experience-carousel-empty";
    root.removeAttribute("aria-busy");
    root.removeAttribute("tabindex");
    root.innerHTML = [
      '<div class="experience-carousel-visual" aria-hidden="true"></div>',
      '<div class="experience-carousel-content">',
      '<span class="experience-carousel-kicker">Experiencias Perigallo</span>',
      '<h2 class="experience-carousel-title" id="experiences-carousel-heading">Próxima experiencia<br><em>en camino</em></h2>',
      '<p class="experience-carousel-subtitle">Una nueva fecha está tomando forma.</p>',
      '<p class="experience-carousel-desc">Estamos preparando la siguiente experiencia Perigallo. Muy pronto anunciaremos una nueva fecha, su menú y todos los detalles para vivirla.</p>',
      '<div class="experience-carousel-footer"><a class="btn-primary" href="/experiencias/">Descubrir Perigallo</a></div>',
      '</div>'
    ].join("");
  }

  function renderError() {
    root.className = "experience-carousel experience-carousel-empty";
    root.removeAttribute("aria-busy");
    root.removeAttribute("tabindex");
    root.innerHTML = [
      '<div class="experience-carousel-visual" aria-hidden="true"></div>',
      '<div class="experience-carousel-content">',
      '<span class="experience-carousel-kicker">Experiencias Perigallo</span>',
      '<h2 class="experience-carousel-title" id="experiences-carousel-heading">Las experiencias<br><em>están por llegar</em></h2>',
      '<p class="experience-carousel-desc">No hemos podido consultar el calendario en este momento. Puedes descubrir todas las experiencias publicadas desde la agenda.</p>',
      '<div class="experience-carousel-footer"><a class="btn-primary" href="/experiencias/">Ver experiencias</a></div>',
      '</div>'
    ].join("");
  }

  function render() {
    var event = experiences[activeIndex];
    var price = formatPrice(event.price_from_cents);
    var image = imageUrl(event);
    var isSingle = experiences.length === 1;
    var meta = [
      ["Fecha", formatDate(event.starts_at)],
      ["Hora", event.ends_at ? formatTime(event.starts_at) + " - " + formatTime(event.ends_at) : formatTime(event.starts_at)],
      ["Lugar", event.location || "Perigallo"],
      ["Estado", availability(event)]
    ];

    root.className = "experience-carousel" + (isSingle ? " is-single" : "");
    root.removeAttribute("aria-busy");
    root.setAttribute("tabindex", "0");
    root.innerHTML = [
      '<div class="experience-carousel-track">',
      '<div class="experience-carousel-visual">',
      '<div class="experience-carousel-image" style="background-image:url(\'' + escapeAttribute(image) + '\')"></div>',
      '<span class="experience-carousel-badge">' + escapeHtml(availability(event)) + '</span>',
      '</div>',
      '<div class="experience-carousel-content">',
      '<span class="experience-carousel-kicker">Experiencias Perigallo · ' + String(activeIndex + 1).padStart(2, "0") + " / " + String(experiences.length).padStart(2, "0") + '</span>',
      '<h2 class="experience-carousel-title" id="experiences-carousel-heading">' + escapeHtml(event.title) + '</h2>',
      event.subtitle ? '<p class="experience-carousel-subtitle">' + escapeHtml(event.subtitle) + '</p>' : "",
      '<div class="experience-carousel-meta">',
      meta.map(function (item) { return '<div class="experience-carousel-meta-item"><span class="experience-carousel-meta-label">' + item[0] + '</span><span class="experience-carousel-meta-value">' + escapeHtml(item[1]) + '</span></div>'; }).join(""),
      '</div>',
      '<p class="experience-carousel-desc">' + escapeHtml(eventDescription(event)) + '</p>',
      '<div class="experience-carousel-footer">',
      price ? '<div class="experience-carousel-price">Desde ' + escapeHtml(price) + '<small>por persona</small></div>' : '<span class="experience-carousel-meta-value">Precio por anunciar</span>',
      '<div class="experience-carousel-actions"><a class="btn-ghost" href="/experiencias/">Ver todas</a><a class="btn-primary" href="' + eventLink(event) + '">Ver experiencia</a></div>',
      '</div>',
      '</div>',
      '</div>',
      '<div class="experience-carousel-navigation" aria-label="Navegación entre experiencias">',
      '<button class="experience-carousel-control" type="button" data-experience-previous aria-label="Experiencia anterior">&#8592;</button>',
      '<button class="experience-carousel-control" type="button" data-experience-next aria-label="Experiencia siguiente">&#8594;</button>',
      '<div class="experience-carousel-dots" aria-label="Seleccionar experiencia">',
      experiences.map(function (item, index) { return '<button class="experience-carousel-dot" type="button" data-experience-index="' + index + '" aria-label="Ver ' + escapeAttribute(item.title) + '" aria-current="' + String(index === activeIndex) + '"></button>'; }).join(""),
      '</div>',
      '</div>'
    ].join("");
  }

  function changeTo(nextIndex) {
    if (!experiences.length) return;
    var length = experiences.length;
    activeIndex = (nextIndex + length) % length;
    root.classList.add("is-changing");
    window.setTimeout(function () {
      render();
      window.requestAnimationFrame(function () { root.classList.remove("is-changing"); });
    }, 150);
  }

  root.addEventListener("click", function (event) {
    var previous = event.target.closest("[data-experience-previous]");
    var next = event.target.closest("[data-experience-next]");
    var dot = event.target.closest("[data-experience-index]");
    if (previous) changeTo(activeIndex - 1);
    if (next) changeTo(activeIndex + 1);
    if (dot) changeTo(Number(dot.dataset.experienceIndex));
  });

  root.addEventListener("keydown", function (event) {
    if (event.key === "ArrowLeft") { event.preventDefault(); changeTo(activeIndex - 1); }
    if (event.key === "ArrowRight") { event.preventDefault(); changeTo(activeIndex + 1); }
  });

  root.addEventListener("touchstart", function (event) {
    touchStartX = event.changedTouches[0].clientX;
  }, { passive: true });

  root.addEventListener("touchend", function (event) {
    if (touchStartX === null) return;
    var difference = event.changedTouches[0].clientX - touchStartX;
    touchStartX = null;
    if (Math.abs(difference) < 45) return;
    changeTo(difference < 0 ? activeIndex + 1 : activeIndex - 1);
  }, { passive: true });

  fetch("/api/events", { headers: { Accept: "application/json" } })
    .then(function (response) {
      if (!response.ok) throw new Error("No se pudo cargar el calendario.");
      return response.json();
    })
    .then(function (data) {
      experiences = publicEvents(Array.isArray(data.events) ? data.events : []);
      if (!experiences.length) {
        renderEmpty();
        return;
      }
      render();
    })
    .catch(renderError);
}());

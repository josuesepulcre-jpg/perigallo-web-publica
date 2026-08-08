(function () {
  "use strict";

  var root = document.querySelector("[data-experience-carousel]");
  if (!root) return;

  var fallbackImage = "/assets/images/perigalla-01/hero-desktop.webp";
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
    return new Intl.DateTimeFormat("es-ES", { day: "2-digit", month: "short", year: "numeric" })
      .format(date)
      .replace(".", "")
      .toUpperCase();
  }

  function formatTime(value) {
    var date = eventDate(value);
    if (!date) return "Por confirmar";
    return new Intl.DateTimeFormat("es-ES", { hour: "2-digit", minute: "2-digit", hour12: false }).format(date) + " H";
  }

  function formatPrice(value) {
    if (!Number(value)) return "";
    return new Intl.NumberFormat("es-ES", { maximumFractionDigits: 0 }).format(Number(value) / 100) + " €";
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
    return event.status === "sold_out" ? "Completo" : "Plazas limitadas";
  }

  function eventLink(event) {
    return "/experiencias/" + encodeURIComponent(event.slug) + "/";
  }

  function imageUrl(event) {
    return event.card_image_url || event.image_url || fallbackImage;
  }

  function isPerigalla01(event) {
    return /la\s+perigalla\s*0?1/i.test(String(event.title || ""));
  }

  function eventDescription(event) {
    if (isPerigalla01(event)) {
      return "Una boda ficticia convertida en experiencia gastronómica inmersiva. Una noche de blanco, cocina, relato y celebración.";
    }
    return String(event.short_description || event.description || "Una edición Perigallo creada para una fecha, una mesa y una atmósfera irrepetibles.")
      .replace(/\s+/g, " ")
      .trim();
  }

  function locationMarkup(event) {
    var place = String(event.location || "Perigallo");
    if (isPerigalla01(event) && !/alicante/i.test(place)) place += " · Alicante";
    return place;
  }

  function microDetail(event) {
    if (isPerigalla01(event)) return "Total White · +18";
    return event.dress_code ? String(event.dress_code).replace(/\s+/g, " ").trim() : "Edición limitada";
  }

  function priceMarkup(price) {
    return [
      '<div class="experience-carousel-price">',
      '<strong>' + escapeHtml(price) + '</strong>',
      '<small>Por persona</small>',
      '</div>'
    ].join("");
  }

  function renderEmpty() {
    root.className = "experience-carousel experience-carousel-empty";
    root.removeAttribute("aria-busy");
    root.removeAttribute("tabindex");
    root.innerHTML = [
      '<div class="experience-carousel-visual" aria-hidden="true"></div>',
      '<div class="experience-carousel-content">',
      '<span class="experience-carousel-kicker">Próxima edición</span>',
      '<h3 class="experience-carousel-title">La próxima experiencia<br><em>está en camino</em></h3>',
      '<p class="experience-carousel-subtitle">Una nueva fecha está tomando forma.</p>',
      '<p class="experience-carousel-desc">Estamos preparando la siguiente experiencia Perigallo. Muy pronto anunciaremos una nueva fecha y todo lo necesario para vivirla.</p>',
      '<div class="experience-carousel-footer"><div class="experience-carousel-actions"><a class="btn-primary" href="/experiencias/">Descubrir experiencias</a></div></div>',
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
      '<span class="experience-carousel-kicker">Agenda Perigallo</span>',
      '<h3 class="experience-carousel-title">Las experiencias<br><em>están por llegar</em></h3>',
      '<p class="experience-carousel-desc">No hemos podido consultar el calendario en este momento. Puedes descubrir todas las experiencias desde la agenda.</p>',
      '<div class="experience-carousel-footer"><div class="experience-carousel-actions"><a class="btn-primary" href="/experiencias/">Ver experiencias</a></div></div>',
      '</div>'
    ].join("");
  }

  function render() {
    var event = experiences[activeIndex];
    var image = imageUrl(event);
    var price = formatPrice(event.price_from_cents);
    var isSingle = experiences.length === 1;
    var time = event.ends_at ? formatTime(event.starts_at) + " — " + formatTime(event.ends_at) : formatTime(event.starts_at);

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
      '<span class="experience-carousel-kicker">Experiencia Perigallo · ' + String(activeIndex + 1).padStart(2, "0") + '</span>',
      '<h3 class="experience-carousel-title">' + escapeHtml(event.title) + '</h3>',
      event.subtitle ? '<p class="experience-carousel-subtitle">' + escapeHtml(event.subtitle) + '</p>' : "",
      '<div class="experience-carousel-meta">',
      '<div class="experience-carousel-meta-item"><span class="experience-carousel-meta-label">Fecha</span><span class="experience-carousel-meta-value">' + escapeHtml(formatDate(event.starts_at)) + '</span></div>',
      '<div class="experience-carousel-meta-item"><span class="experience-carousel-meta-label">Hora</span><span class="experience-carousel-meta-value">' + escapeHtml(time) + '</span></div>',
      '<div class="experience-carousel-meta-item"><span class="experience-carousel-meta-label">Lugar</span><span class="experience-carousel-meta-value">' + escapeHtml(locationMarkup(event)) + '</span></div>',
      '</div>',
      '<p class="experience-carousel-microdetail">' + escapeHtml(microDetail(event)) + '</p>',
      '<p class="experience-carousel-desc">' + escapeHtml(eventDescription(event)) + '</p>',
      '<div class="experience-carousel-footer">',
      price ? priceMarkup(price) : '<span class="experience-carousel-meta-value">Precio por anunciar</span>',
      '<div class="experience-carousel-actions"><a class="btn-primary" href="' + eventLink(event) + '">Descubrir la experiencia</a><a class="experience-carousel-all-link" href="/experiencias/">Ver todas las experiencias</a></div>',
      '</div>',
      '</div>',
      '</div>',
      '<div class="experience-carousel-navigation" aria-label="Navegación entre experiencias">',
      '<button class="experience-carousel-control" type="button" data-experience-previous aria-label="Experiencia anterior">&#8592;</button>',
      '<div class="experience-carousel-dots" aria-label="Seleccionar experiencia">',
      experiences.map(function (item, index) { return '<button class="experience-carousel-dot" type="button" data-experience-index="' + index + '" aria-label="Ver ' + escapeAttribute(item.title) + '" aria-current="' + String(index === activeIndex) + '"></button>'; }).join(""),
      '</div>',
      '<button class="experience-carousel-control" type="button" data-experience-next aria-label="Experiencia siguiente">&#8594;</button>',
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
    }, 220);
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
      if (!experiences.length) return renderEmpty();
      render();
    })
    .catch(renderError);
}());

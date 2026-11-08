(function () {
  "use strict";
  function el(tag, text, cls) {
    var n = document.createElement(tag);
    if (text) n.textContent = text;
    if (cls) n.className = cls;
    return n;
  }
  document
    .querySelectorAll("[data-archive-collection]")
    .forEach(async function (target) {
      async function load() {
        target.setAttribute("aria-busy", "true");
        try {
          var response = await fetch("/api/experience-archive", {
            headers: { Accept: "application/json" },
            signal: AbortSignal.timeout(15000),
          });
          if (!response.ok) throw new Error();
          var data = await response.json();
          if (!data.ok || !Array.isArray(data.experiences)) throw new Error();
          target.replaceChildren();
          data.experiences
            .filter(function (e) {
              return e.slug !== target.dataset.exclude;
            })
            .forEach(function (entry) {
              var d = entry.document,
                article = el("article", "", "archive-card"),
                link = el("a");
              link.href =
                "/experiencias/vividas/" + encodeURIComponent(entry.slug) + "/";
              var visual = el("div", "", "archive-card-visual"),
                image = el("img");
              image.src = d.cover_image;
              image.alt = d.cover_alt;
              image.loading = "lazy";
              image.decoding = "async";
              visual.append(
                image,
                el("span", d.edition || "Perigallo", "archive-card-edition"),
              );
              var copy = el("div", "", "archive-card-copy");
              var date = new Date(d.date + "T12:00:00");
              copy.append(
                el(
                  "p",
                  new Intl.DateTimeFormat("es-ES", {
                    dateStyle: "long",
                  }).format(date),
                  "eyebrow",
                ),
                el("h3", d.title),
                el("p", d.subtitle, "archive-card-subtitle"),
                el("p", d.description),
                el("span", "Revivir la experiencia ↗", "archive-text-link"),
              );
              link.append(visual, copy);
              article.append(link);
              target.append(article);
            });
          if (!target.children.length) {
            var message = el("div", "", "archive-empty");
            message.append(
              el(
                "p",
                target.dataset.exclude
                  ? "Cada edición abre una nueva forma de celebrar."
                  : "Estamos dando forma a nuestro archivo.",
              ),
              el(
                "a",
                "Conocer las próximas experiencias ↗",
                "archive-text-link",
              ),
            );
            message.querySelector("a").href = "/experiencias/#agenda";
            target.append(message);
          }
        } catch (_) {
          target.replaceChildren(el("p", "No hemos podido abrir el archivo."));
          var retry = el("button", "Volver a intentar", "archive-text-link");
          retry.type = "button";
          retry.addEventListener("click", load);
          target.append(retry);
        } finally {
          target.removeAttribute("aria-busy");
        }
      }
      await load();
    });
  var heroVideo = document.querySelector("[data-archive-hero-video]"),
    videoToggle = document.querySelector("[data-archive-video-toggle]");
  if (heroVideo && videoToggle) {
    var motion = matchMedia("(prefers-reduced-motion: reduce)"),
      wantsPlay = !motion.matches;
    function updateVideoButton() {
      videoToggle.textContent = heroVideo.paused
        ? "Reproducir vídeo de portada"
        : "Pausar vídeo de portada";
      videoToggle.setAttribute("aria-pressed", String(!heroVideo.paused));
    }
    heroVideo.addEventListener("play", updateVideoButton);
    heroVideo.addEventListener("pause", updateVideoButton);
    videoToggle.addEventListener("click", function () {
      wantsPlay = heroVideo.paused;
      if (wantsPlay) heroVideo.play().catch(updateVideoButton);
      else heroVideo.pause();
    });
    motion.addEventListener("change", function () {
      if (motion.matches) {
        wantsPlay = false;
        heroVideo.pause();
      }
    });
    if ("IntersectionObserver" in window) {
      new IntersectionObserver(
        function (entries) {
          entries.forEach(function (e) {
            if (e.isIntersecting && wantsPlay)
              heroVideo.play().catch(updateVideoButton);
            else heroVideo.pause();
          });
        },
        { threshold: 0.15 },
      ).observe(heroVideo);
    } else if (wantsPlay) heroVideo.play().catch(updateVideoButton);
  }
  var dialog = document.querySelector(".archive-lightbox"),
    buttons = Array.from(document.querySelectorAll("[data-archive-image]")),
    index = 0,
    origin;
  if (dialog) {
    function show(i) {
      index = (i + buttons.length) % buttons.length;
      var b = buttons[index],
        img = dialog.querySelector("[data-viewer-photo]");
      img.src = b.dataset.archiveImage;
      img.alt = b.querySelector("img").alt;
      dialog.querySelector("[data-viewer-caption]").textContent =
        b.dataset.caption;
      dialog.querySelector("[data-viewer-counter]").textContent =
        index + 1 + " / " + buttons.length;
    }
    buttons.forEach(function (b, i) {
      b.addEventListener("click", function () {
        origin = b;
        show(i);
        dialog.showModal();
        document.documentElement.classList.add("archive-viewer-open");
      });
    });
    dialog
      .querySelector("[data-viewer-close]")
      .addEventListener("click", function () {
        dialog.close();
      });
    dialog
      .querySelector("[data-viewer-prev]")
      .addEventListener("click", function () {
        show(index - 1);
      });
    dialog
      .querySelector("[data-viewer-next]")
      .addEventListener("click", function () {
        show(index + 1);
      });
    dialog.addEventListener("keydown", function (e) {
      if (e.key === "ArrowRight") {
        e.preventDefault();
        show(index + 1);
      }
      if (e.key === "ArrowLeft") {
        e.preventDefault();
        show(index - 1);
      }
    });
    dialog.addEventListener("click", function (e) {
      if (e.target === dialog) dialog.close();
    });
    dialog.addEventListener("close", function () {
      document.documentElement.classList.remove("archive-viewer-open");
      if (origin) origin.focus();
    });
    var touchX;
    dialog.addEventListener(
      "touchstart",
      function (e) {
        touchX = e.changedTouches[0].clientX;
      },
      { passive: true },
    );
    dialog.addEventListener(
      "touchend",
      function (e) {
        if (
          touchX !== undefined &&
          Math.abs(e.changedTouches[0].clientX - touchX) > 60
        )
          show(index + (e.changedTouches[0].clientX < touchX ? 1 : -1));
        touchX = undefined;
      },
      { passive: true },
    );
  }
  if (
    "IntersectionObserver" in window &&
    !matchMedia("(prefers-reduced-motion: reduce)").matches
  ) {
    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (e) {
          if (e.isIntersecting) {
            e.target.classList.add("is-revealed");
            observer.unobserve(e.target);
          }
        });
      },
      { threshold: 0.08 },
    );
    document.querySelectorAll(".archive-frame").forEach(function (n) {
      n.classList.add("archive-reveal");
      observer.observe(n);
    });
  }
})();

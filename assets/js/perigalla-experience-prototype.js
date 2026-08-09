(function () {
  "use strict";

  var savedScrollY = 0;
  var lastTrigger = null;

  function isLanding() {
    return document.body.classList.contains("perigalla-landing-page");
  }

  function setOverlayOpen(overlay, open, trigger) {
    if (!overlay) return;
    if (open) {
      savedScrollY = window.scrollY;
      lastTrigger = trigger || document.activeElement;
      overlay.hidden = false;
      overlay.setAttribute("aria-hidden", "false");
      document.body.classList.add("perigalla-overlay-open");
      var frameHost = overlay.querySelector("[data-story-frame]");
      if (frameHost && !frameHost.querySelector("iframe")) {
        var frame = document.createElement("iframe");
        frame.src = "https://perigallo.com/la-perigalla-01/#historia=cover&escena=0";
        frame.title = "La historia de La Perigalla 01";
        frame.allow = "autoplay; fullscreen";
        frame.setAttribute("allowfullscreen", "");
        frameHost.appendChild(frame);
      }
      var close = overlay.querySelector("[data-story-overlay-close]");
      if (close) close.focus();
      return;
    }
    overlay.hidden = true;
    overlay.setAttribute("aria-hidden", "true");
    var activeFrame = overlay.querySelector("iframe");
    if (activeFrame) activeFrame.remove();
    document.body.classList.remove("perigalla-overlay-open");
    window.scrollTo(0, savedScrollY);
    if (lastTrigger && lastTrigger.focus) lastTrigger.focus();
  }

  function showDish(trigger) {
    var data = window.Perigalla01Gastronomy || [];
    var dish = data[Number(trigger.dataset.gastronomyIndex)];
    var dialog = document.querySelector("[data-gastronomy-dialog]");
    if (!dish || !dialog) return;
    dialog.querySelector("[data-gastronomy-index-label]").textContent = "Pieza " + String(Number(trigger.dataset.gastronomyIndex) + 1).padStart(2, "0");
    dialog.querySelector("[data-gastronomy-title]").textContent = dish.scene;
    dialog.querySelector("[data-gastronomy-dish]").textContent = dish.dish;
    dialog.querySelector("[data-gastronomy-allergens]").textContent = "Alérgenos: " + dish.allergens.join(", ") + ".";
    var image = dialog.querySelector("[data-gastronomy-image]");
    image.src = dish.image;
    image.alt = dish.alt;
    dialog.hidden = false;
    dialog.setAttribute("aria-hidden", "false");
    document.body.classList.add("perigalla-dialog-open");
    dialog.querySelector("[data-gastronomy-close]").focus();
  }

  function closeDish(dialog) {
    if (!dialog) return;
    dialog.hidden = true;
    dialog.setAttribute("aria-hidden", "true");
    document.body.classList.remove("perigalla-dialog-open");
  }

  function closeMobileNavigation() {
    var toggle = document.querySelector(".event-nav-toggle");
    var menu = document.querySelector(".event-mobile-navigation");
    if (!toggle || !menu) return;
    menu.hidden = true;
    toggle.setAttribute("aria-expanded", "false");
  }

  document.addEventListener("click", function (event) {
    var toggle = event.target.closest(".event-nav-toggle");
    if (toggle && isLanding()) {
      var menu = document.querySelector(".event-mobile-navigation");
      var opening = menu.hidden;
      menu.hidden = !opening;
      toggle.setAttribute("aria-expanded", String(opening));
      return;
    }
    var openStory = event.target.closest("[data-story-overlay-open]");
    if (openStory) {
      setOverlayOpen(document.querySelector("[data-story-overlay]"), true, openStory);
      return;
    }
    if (event.target.closest("[data-story-overlay-close]")) {
      setOverlayOpen(document.querySelector("[data-story-overlay]"), false);
      return;
    }
    var dish = event.target.closest("[data-gastronomy-open]");
    if (dish) {
      showDish(dish);
      return;
    }
    if (event.target.closest("[data-gastronomy-close]")) closeDish(document.querySelector("[data-gastronomy-dialog]"));
    if (event.target.closest(".event-mobile-navigation a")) closeMobileNavigation();
  });

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Escape") return;
    var overlay = document.querySelector("[data-story-overlay]");
    if (overlay && !overlay.hidden) return setOverlayOpen(overlay, false);
    var dialog = document.querySelector("[data-gastronomy-dialog]");
    if (dialog && !dialog.hidden) closeDish(dialog);
    closeMobileNavigation();
  });
})();

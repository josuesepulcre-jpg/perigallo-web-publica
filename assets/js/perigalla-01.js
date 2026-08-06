(function () {
  "use strict";

  var scenes = [
    { image: "scene-01-desktop.jpg", kicker: "La llegada", title: "Una invitaci\u00f3n inesperada", copy: "Una noche de verano abre la puerta a la historia de Sof\u00eda y Carlos, donde cada detalle anuncia que algo especial est\u00e1 a punto de comenzar." },
    { image: "scene-03-desktop.jpg", kicker: "El primer acto", title: "La ceremonia", copy: "La finca se transforma en escenario y los invitados entran en un relato que se vive con todos los sentidos." },
    { image: "scene-05-desktop.jpg", kicker: "El recorrido", title: "La isla en la mesa", copy: "Las piezas gastron\u00f3micas avanzan como una historia: brasa, mar, fruta, pan y memoria mediterr\u00e1nea." },
    { image: "scene-07-desktop.jpg", kicker: "Un recuerdo", title: "Todo empieza con un mensaje", copy: "Un gesto peque\u00f1o conecta los dos viajes de Sof\u00eda y Carlos y cambia el ritmo de la noche." },
    { image: "scene-09-desktop.jpg", kicker: "La noche", title: "Bajo las estrellas", copy: "La m\u00fasica, la luz y el servicio acompa\u00f1an una celebraci\u00f3n que no responde a una f\u00f3rmula \u00fanica." },
    { image: "scene-11-desktop.jpg", kicker: "El sabor", title: "Mercat de la Vila", copy: "La cocina recoge referencias de la isla y las convierte en una secuencia de bocados para compartir." },
    { image: "scene-14-desktop.jpg", kicker: "La escena", title: "Paseo por la playa", copy: "El recorrido se abre a nuevas atm\u00f3sferas, al salitre y a una sobremesa que se alarga sin prisa." },
    { image: "scene-16-desktop.jpg", kicker: "La celebraci\u00f3n", title: "La noche contin\u00faa", copy: "Cuando cae la noche, la experiencia cambia de ritmo: sorpresas, m\u00fasica y baile bajo las luces de la finca." },
    { image: "scene-19-desktop.jpg", kicker: "El final", title: "El \u00faltimo brindis", copy: "Una \u00faltima mesa, una copa fr\u00eda y el recuerdo de una noche creada para no repetirse igual." },
    { image: "scene-21-desktop.jpg", kicker: "La Perigalla 01", title: "Una historia para celebrar", copy: "La experiencia termina, pero la historia se queda en quienes la han vivido juntos." }
  ];

  var dialog = document.querySelector(".story-dialog");
  var image = document.getElementById("story-image");
  var kicker = document.getElementById("story-kicker");
  var title = document.getElementById("dialog-title");
  var copy = document.getElementById("story-copy");
  var count = document.getElementById("story-count");
  var progress = document.getElementById("story-progress");
  var menu = document.querySelector("[data-menu]");
  var menuToggle = document.querySelector(".menu-toggle");
  var index = 0;
  var lastTrigger = null;
  var swipeStartX = null;

  function sceneUrl(scene) {
    return "/assets/images/perigalla-01/" + scene.image;
  }

  function preloadNextScene() {
    var next = scenes[(index + 1) % scenes.length];
    var preload = new Image();
    preload.src = sceneUrl(next);
  }

  function renderScene() {
    var scene = scenes[index];
    image.classList.add("is-loading");
    image.alt = scene.title + ", La Perigalla 01";
    image.onload = function () { image.classList.remove("is-loading"); };
    image.src = sceneUrl(scene);
    kicker.textContent = scene.kicker;
    title.textContent = scene.title;
    copy.textContent = scene.copy;
    count.textContent = String(index + 1).padStart(2, "0") + " / " + String(scenes.length).padStart(2, "0");
    progress.style.width = ((index + 1) / scenes.length * 100) + "%";
    preloadNextScene();
  }

  function closeMenu() {
    if (!menu || !menuToggle) return;
    menu.classList.remove("is-open");
    menuToggle.setAttribute("aria-expanded", "false");
    menuToggle.setAttribute("aria-label", "Abrir navegaci\u00f3n");
    document.body.classList.remove("menu-open");
  }

  function toggleMenu() {
    var isOpen = menu.classList.toggle("is-open");
    menuToggle.setAttribute("aria-expanded", String(isOpen));
    menuToggle.setAttribute("aria-label", isOpen ? "Cerrar navegaci\u00f3n" : "Abrir navegaci\u00f3n");
    document.body.classList.toggle("menu-open", isOpen);
  }

  function closeStory() {
    if (dialog.open) dialog.close();
  }

  function moveScene(direction) {
    index = (index + direction + scenes.length) % scenes.length;
    renderScene();
    var frame = dialog.querySelector(".story-frame");
    if (frame) frame.scrollTop = 0;
  }

  if (menuToggle && menu) {
    menuToggle.addEventListener("click", toggleMenu);
    menu.querySelectorAll("a").forEach(function (link) { link.addEventListener("click", closeMenu); });
  }

  document.querySelectorAll("[data-open-story]").forEach(function (button) {
    button.addEventListener("click", function () {
      lastTrigger = button;
      index = 0;
      renderScene();
      if (typeof dialog.showModal === "function") {
        dialog.showModal();
        document.body.classList.add("dialog-open");
        dialog.querySelector("[data-close-story]").focus();
      }
    });
  });

  document.querySelector("[data-close-story]").addEventListener("click", closeStory);
  document.querySelector("[data-story-prev]").addEventListener("click", function () { moveScene(-1); });
  document.querySelector("[data-story-next]").addEventListener("click", function () { moveScene(1); });

  dialog.addEventListener("close", function () {
    document.body.classList.remove("dialog-open");
    if (lastTrigger) lastTrigger.focus();
  });
  dialog.addEventListener("click", function (event) { if (event.target === dialog) closeStory(); });
  dialog.addEventListener("pointerdown", function (event) { swipeStartX = event.clientX; });
  dialog.addEventListener("pointerup", function (event) {
    if (swipeStartX === null) return;
    var delta = event.clientX - swipeStartX;
    swipeStartX = null;
    if (Math.abs(delta) < 48) return;
    moveScene(delta < 0 ? 1 : -1);
  });
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      closeMenu();
      if (dialog.open) closeStory();
    }
  });
}());

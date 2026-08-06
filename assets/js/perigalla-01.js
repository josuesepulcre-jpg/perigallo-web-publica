(function () {
  "use strict";

  var scenes = [
    { image: "scene-01-desktop.jpg", kicker: "La llegada", title: "Una invitacion inesperada", copy: "Una noche de verano abre la puerta a la historia de Sofia y Carlos, donde cada detalle anuncia que algo especial esta a punto de comenzar." },
    { image: "scene-03-desktop.jpg", kicker: "El primer acto", title: "La ceremonia", copy: "La finca se transforma en escenario y los invitados entran en un relato que se vive con todos los sentidos." },
    { image: "scene-05-desktop.jpg", kicker: "El recorrido", title: "La isla en la mesa", copy: "Las piezas gastronomicas avanzan como una historia: brasa, mar, fruta, pan y memoria mediterranea." },
    { image: "scene-07-desktop.jpg", kicker: "Un recuerdo", title: "Todo empieza con un mensaje", copy: "Un gesto pequeno conecta los dos viajes de Sofia y Carlos y cambia el ritmo de la noche." },
    { image: "scene-09-desktop.jpg", kicker: "La noche", title: "Bajo las estrellas", copy: "La musica, la luz y el servicio acompanan una celebracion que no responde a una formula unica." },
    { image: "scene-11-desktop.jpg", kicker: "El sabor", title: "Mercat de la Vila", copy: "La cocina recoge referencias de la isla y las convierte en una secuencia de bocados para compartir." },
    { image: "scene-14-desktop.jpg", kicker: "La escena", title: "Paseo por la playa", copy: "El recorrido se abre a nuevas atmosferas, al salitre y a una sobremesa que se alarga sin prisa." },
    { image: "scene-16-desktop.jpg", kicker: "La celebracion", title: "La noche continua", copy: "Cuando cae la noche, la experiencia cambia de ritmo: sorpresas, musica y baile bajo las luces de la finca." },
    { image: "scene-19-desktop.jpg", kicker: "El final", title: "El ultimo brindis", copy: "Una ultima mesa, una copa fria y el recuerdo de una noche creada para no repetirse igual." },
    { image: "scene-21-desktop.jpg", kicker: "La Perigalla 01", title: "Una historia para celebrar", copy: "La experiencia termina, pero la historia se queda en quienes la han vivido juntos." }
  ];

  var dialog = document.querySelector(".story-dialog");
  var image = document.getElementById("story-image");
  var kicker = document.getElementById("story-kicker");
  var title = document.getElementById("dialog-title");
  var copy = document.getElementById("story-copy");
  var count = document.getElementById("story-count");
  var progress = document.getElementById("story-progress");
  var index = 0;

  function renderScene() {
    var scene = scenes[index];
    image.src = "/assets/images/perigalla-01/" + scene.image;
    image.alt = scene.title + ", La Perigalla 01";
    kicker.textContent = scene.kicker;
    title.textContent = scene.title;
    copy.textContent = scene.copy;
    count.textContent = String(index + 1).padStart(2, "0") + " / " + String(scenes.length).padStart(2, "0");
    progress.style.width = ((index + 1) / scenes.length * 100) + "%";
  }

  document.querySelectorAll("[data-open-story]").forEach(function (button) {
    button.addEventListener("click", function () {
      index = 0;
      renderScene();
      if (typeof dialog.showModal === "function") dialog.showModal();
    });
  });
  document.querySelector("[data-close-story]").addEventListener("click", function () { dialog.close(); });
  document.querySelector("[data-story-prev]").addEventListener("click", function () { index = (index - 1 + scenes.length) % scenes.length; renderScene(); });
  document.querySelector("[data-story-next]").addEventListener("click", function () { index = (index + 1) % scenes.length; renderScene(); });
  dialog.addEventListener("click", function (event) { if (event.target === dialog) dialog.close(); });
  renderScene();
}());

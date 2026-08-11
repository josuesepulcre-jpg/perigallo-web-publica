/*
 * Safari on iPhone does not transfer the tap that opens the parent overlay to
 * media inside its iframe. Keep the player paused behind one explicit start
 * screen, then use that touch to start its narration and score together.
 */
(() => {
  const isIPhone = /iPhone|iPod/i.test(navigator.userAgent || "");
  const opensStory = new URLSearchParams(location.hash.replace(/^#/, "")).has("historia");
  if (!isIPhone || !opensStory) return;

  const startedKey = "perigalloIosStoryStarted";
  const originalPlay = HTMLMediaElement.prototype.play;
  const nativeSetTimeout = window.setTimeout;
  const isStoryMedia = (media) => media instanceof HTMLMediaElement && media.closest(".story-player");
  const isStarted = (media) => media.dataset[startedKey] === "true";

  HTMLMediaElement.prototype.play = function perigalloIPhoneStoryPlay(...args) {
    if (isStoryMedia(this) && !isStarted(this)) return Promise.resolve();
    return originalPlay.apply(this, args);
  };

  const showStartScreen = (player) => {
    if (player.querySelector(".ios-story-start")) return;
    let waitingForStart = true;
    window.setTimeout = function frozenIPhoneStoryTimers() {
      return 0;
    };

    const screen = document.createElement("section");
    screen.className = "ios-story-start";
    screen.setAttribute("role", "dialog");
    screen.setAttribute("aria-modal", "true");
    screen.setAttribute("aria-label", "Iniciar historia");
    screen.innerHTML = '<p>LA PERIGALLA 01 · HISTORIA AUDIOVISUAL</p><h1>La isla a la que<br><em>siempre se vuelve.</em></h1><span>La narración y la banda sonora comenzarán al tocar el botón.</span><button type="button">Empezar la historia <b aria-hidden="true">→</b></button>';

    const button = screen.querySelector("button");
    button.disabled = true;
    const waitForNarration = () => {
      if (!waitingForStart) return;
      const narration = player.querySelector("audio[data-story-scene]");
      if (!narration?.src) {
        nativeSetTimeout(waitForNarration, 50);
        return;
      }
      button.disabled = false;
    };
    waitForNarration();

    button.addEventListener("click", () => {
      waitingForStart = false;
      window.setTimeout = nativeSetTimeout;
      const media = Array.from(player.querySelectorAll("audio"));
      media.forEach((item) => { item.dataset[startedKey] = "true"; });
      screen.remove();
      media.forEach((item) => { originalPlay.call(item).catch(() => undefined); });
    }, { once: true });

    player.append(screen);
  };

  const observer = new MutationObserver(() => {
    const player = document.querySelector(".story-player");
    if (!player) return;
    showStartScreen(player);
    observer.disconnect();
  });

  observer.observe(document.documentElement, { childList: true, subtree: true });
})();

/*
 * Mobile narration guard.
 *
 * WebKit can reject play() while a new scene is still loading, even when the
 * visitor has already started the story.  Keep one pending request per scene,
 * wait for playable data, and make a rejected media session recoverable rather
 * than silently skipping the voice-over.
 */
(() => {
  const pendingKey = "__perigalloNarrationPending";
  const originalPlay = HTMLMediaElement.prototype.play;

  const isStoryNarration = (media) => (
    media instanceof HTMLAudioElement
    && media.dataset.storyScene
    && media.closest(".story-player")
  );

  const clearRecovery = (media) => {
    const player = media.closest(".story-player");
    if (!player) return;
    player.classList.remove("needs-audio-recovery", "is-audio-buffering");
    player.querySelector(".story-audio-recovery")?.setAttribute("hidden", "");
  };

  const showRecovery = (media) => {
    const player = media.closest(".story-player");
    if (!player) return;

    let button = player.querySelector(".story-audio-recovery");
    if (!button) {
      button = document.createElement("button");
      button.type = "button";
      button.className = "story-audio-recovery";
      button.textContent = "Reanudar narración";
      button.addEventListener("click", () => {
        button.disabled = true;
        originalPlay.call(media)
          .then(() => clearRecovery(media))
          .catch(() => { button.disabled = false; });
      });
      player.append(button);
    }

    player.classList.remove("is-audio-buffering");
    player.classList.add("needs-audio-recovery");
    button.removeAttribute("hidden");
  };

  const waitForPlayableNarration = (media, args) => {
    const sourceAtRequest = media.src || media.currentSrc;
    if (media[pendingKey]?.source === sourceAtRequest) return media[pendingKey].promise;
    const playWhenReady = () => originalPlay.apply(media, args)
      .then(() => {
        clearRecovery(media);
      })
      .catch((error) => {
        if (error?.name === "NotAllowedError") {
          showRecovery(media);
          return;
        }
        throw error;
      });

    const pending = new Promise((resolve, reject) => {
      let settled = false;
      let fallback;

      const finish = (callback) => {
        if (settled) return;
        settled = true;
        window.clearTimeout(fallback);
        media.removeEventListener("canplay", start);
        media.removeEventListener("error", fail);
        callback();
      };

      const start = () => finish(() => {
        if ((media.src || media.currentSrc) !== sourceAtRequest) {
          resolve();
          return;
        }
        playWhenReady().then(resolve, reject);
      });

      const fail = () => finish(() => reject(new Error("No se pudo cargar la narración.")));

      media.addEventListener("canplay", start, { once: true });
      media.addEventListener("error", fail, { once: true });
      fallback = window.setTimeout(start, 1400);
    });

    const tracked = pending.finally(() => {
      if (media[pendingKey]?.source === sourceAtRequest) media[pendingKey] = null;
    });
    media[pendingKey] = { source: sourceAtRequest, promise: tracked };
    return tracked;
  };

  HTMLMediaElement.prototype.play = function perigalloSafeNarrationPlay(...args) {
    if (!isStoryNarration(this)) return originalPlay.apply(this, args);

    const player = this.closest(".story-player");
    if (this.readyState >= HTMLMediaElement.HAVE_FUTURE_DATA) {
      return originalPlay.apply(this, args).catch((error) => {
        if (error?.name !== "NotAllowedError") throw error;
        showRecovery(this);
      });
    }

    player?.classList.add("is-audio-buffering");
    return waitForPlayableNarration(this, args);
  };

  document.addEventListener("playing", (event) => {
    if (isStoryNarration(event.target)) clearRecovery(event.target);
  }, true);
})();

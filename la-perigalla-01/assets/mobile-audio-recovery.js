/*
 * Mobile narration guard.
 *
 * iOS/WebKit can abort a new audio source during an automatic scene change.
 * The story bundle used to swallow that rejection and continue silently. This
 * guard keeps a pending playback per source, retries interrupted loads, and
 * only asks the visitor to resume if the browser explicitly requires a tap.
 */
(() => {
  const pendingKey = "__perigalloNarrationPending";
  const retryKey = "__perigalloNarrationRetry";
  const attemptsKey = "__perigalloNarrationAttempts";
  const endedKey = "__perigalloNarrationEndedSource";
  const originalPlay = HTMLMediaElement.prototype.play;
  const volumeDescriptor = Object.getOwnPropertyDescriptor(HTMLMediaElement.prototype, "volume");

  const isStoryNarration = (media) => (
    media instanceof HTMLAudioElement
    && media.dataset.storyScene
    && media.closest(".story-player")
  );

  const playerFor = (media) => media.closest(".story-player");
  const sourceFor = (media) => media.src || media.currentSrc;
  const shouldContinue = (media) => {
    const player = playerFor(media);
    return Boolean(player?.classList.contains("is-playing") && !player.classList.contains("is-intro"));
  };

  const clearRetry = (media) => {
    window.clearTimeout(media[retryKey]);
    media[retryKey] = null;
  };

  const clearRecovery = (media) => {
    const player = playerFor(media);
    if (!player) return;
    clearRetry(media);
    media[attemptsKey] = 0;
    player.classList.remove("needs-audio-recovery", "is-audio-buffering");
    player.querySelector(".story-audio-recovery")?.setAttribute("hidden", "");
  };

  const showRecovery = (media) => {
    const player = playerFor(media);
    if (!player) return;

    let button = player.querySelector(".story-audio-recovery");
    if (!button) {
      button = document.createElement("button");
      button.type = "button";
      button.className = "story-audio-recovery";
      button.textContent = "Reanudar narración";
      button.addEventListener("click", () => {
        button.disabled = true;
        media[attemptsKey] = 0;
        originalPlay.call(media)
          .then(() => clearRecovery(media))
          .catch(() => { button.disabled = false; });
      });
      player.append(button);
    }

    clearRetry(media);
    player.classList.remove("is-audio-buffering");
    player.classList.add("needs-audio-recovery");
    button.removeAttribute("hidden");
  };

  const scheduleRetry = (media, source, delay = 220) => {
    if (!shouldContinue(media) || sourceFor(media) !== source || !media.paused) return;

    const attempts = (media[attemptsKey] || 0) + 1;
    media[attemptsKey] = attempts;
    if (attempts > 7) {
      showRecovery(media);
      return;
    }

    clearRetry(media);
    media[retryKey] = window.setTimeout(() => {
      if (shouldContinue(media) && sourceFor(media) === source && media.paused) {
        waitForPlayableNarration(media, []);
      }
    }, delay);
  };

  const attemptPlayback = (media, args, source) => originalPlay.apply(media, args)
    .then(() => clearRecovery(media))
    .catch((error) => {
      if (error?.name === "NotAllowedError") {
        showRecovery(media);
        return;
      }
      scheduleRetry(media, source);
    });

  const waitForPlayableNarration = (media, args) => {
    const source = sourceFor(media);
    if (!source || !shouldContinue(media)) return Promise.resolve();
    if (media[pendingKey]?.source === source) return media[pendingKey].promise;

    const player = playerFor(media);
    const start = () => attemptPlayback(media, args, source);
    const pending = media.readyState >= HTMLMediaElement.HAVE_FUTURE_DATA
      ? start()
      : new Promise((resolve) => {
        let settled = false;
        let fallback;
        const finish = (callback) => {
          if (settled) return;
          settled = true;
          window.clearTimeout(fallback);
          media.removeEventListener("canplay", onCanPlay);
          media.removeEventListener("error", onError);
          callback();
        };
        const onCanPlay = () => finish(() => resolve(start()));
        const onError = () => finish(() => {
          scheduleRetry(media, source, 360);
          resolve();
        });

        player?.classList.add("is-audio-buffering");
        media.addEventListener("canplay", onCanPlay, { once: true });
        media.addEventListener("error", onError, { once: true });
        fallback = window.setTimeout(onCanPlay, 1400);
      });

    const tracked = Promise.resolve(pending).finally(() => {
      if (media[pendingKey]?.source === source) media[pendingKey] = null;
    });
    media[pendingKey] = { source, promise: tracked };
    return tracked;
  };

  HTMLMediaElement.prototype.play = function perigalloSafeNarrationPlay(...args) {
    if (!isStoryNarration(this)) return originalPlay.apply(this, args);
    return waitForPlayableNarration(this, args);
  };

  if (volumeDescriptor?.get && volumeDescriptor.set) {
    Object.defineProperty(HTMLMediaElement.prototype, "volume", {
      configurable: volumeDescriptor.configurable,
      enumerable: volumeDescriptor.enumerable,
      get: volumeDescriptor.get,
      set(value) {
        const safeValue = isStoryNarration(this)
          ? Math.min(1, Math.max(0, Number.isFinite(value) ? value : 0))
          : value;
        return volumeDescriptor.set.call(this, safeValue);
      },
    });
  }

  const watchNarration = (media) => {
    const source = sourceFor(media);
    if (!isStoryNarration(media) || !shouldContinue(media) || !media.paused || media[endedKey] === source) return;
    scheduleRetry(media, source, 80);
  };

  document.addEventListener("playing", (event) => {
    if (isStoryNarration(event.target)) {
      event.target[endedKey] = null;
      clearRecovery(event.target);
    }
  }, true);
  document.addEventListener("ended", (event) => {
    if (isStoryNarration(event.target)) event.target[endedKey] = sourceFor(event.target);
  }, true);
  document.addEventListener("pause", (event) => {
    if (isStoryNarration(event.target)) watchNarration(event.target);
  }, true);
  document.addEventListener("loadstart", (event) => {
    if (isStoryNarration(event.target)) {
      event.target[attemptsKey] = 0;
      event.target[endedKey] = null;
      watchNarration(event.target);
    }
  }, true);

  const observer = new MutationObserver((records) => {
    for (const record of records) {
      const target = record.target instanceof Element ? record.target : null;
      const player = target?.closest?.(".story-player") || target?.querySelector?.(".story-player");
      const narration = player?.querySelector("audio[data-story-scene]");
      if (narration) watchNarration(narration);
    }
  });
  observer.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ["class", "src", "data-story-scene"],
    childList: true,
    subtree: true,
  });
})();

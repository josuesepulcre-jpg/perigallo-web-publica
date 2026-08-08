(() => {
  const carousel = document.querySelector('[data-celebration-carousel]');
  if (!carousel) return;

  const images = [
    'assets/images/perigallo-wedding-table-at-dusk.png',
    'assets/images/finca-la-llaguna-principal.jpg',
    'assets/images/perigalla-01/hero-desktop.webp',
    'assets/images/perigallo-hero-original-03.jpg',
    'assets/images/gastronomy-carousel/vieiras-espuma.jpg',
    'assets/images/gastronomy-carousel/nigiri-atun.jpg'
  ];
  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const currentLabel = carousel.querySelector('[data-celebration-current]');
  const totalLabel = carousel.querySelector('[data-celebration-total]');
  const nextButton = carousel.querySelector('[data-celebration-next]');
  const previousButton = carousel.querySelector('[data-celebration-previous]');
  const firstLayer = carousel.querySelector('.celebrate-media');
  const secondLayer = document.createElement('div');
  let activeLayer = 0;
  let currentIndex = 0;
  let timer = null;
  let isVisible = false;
  let touchStartX = null;

  secondLayer.className = 'celebrate-media';
  secondLayer.setAttribute('aria-hidden', 'true');
  firstLayer.after(secondLayer);

  const layers = [firstLayer, secondLayer];
  if (totalLabel) totalLabel.textContent = String(images.length).padStart(2, '0');

  function normalizedIndex(index) {
    return (index + images.length) % images.length;
  }

  function updateStatus() {
    if (currentLabel) currentLabel.textContent = String(currentIndex + 1).padStart(2, '0');
  }

  function preload(index) {
    const image = new Image();
    image.src = images[normalizedIndex(index)];
  }

  function show(index) {
    const nextIndex = normalizedIndex(index);
    if (nextIndex === currentIndex) return;

    const incomingLayer = layers[1 - activeLayer];
    const outgoingLayer = layers[activeLayer];
    incomingLayer.style.backgroundImage = `url('${images[nextIndex]}')`;
    incomingLayer.classList.add('is-active');
    outgoingLayer.classList.remove('is-active');
    activeLayer = 1 - activeLayer;
    currentIndex = nextIndex;
    updateStatus();
    preload(currentIndex + 1);
    preload(currentIndex + 2);
  }

  function stop() {
    window.clearTimeout(timer);
    timer = null;
  }

  function schedule() {
    stop();
    if (prefersReducedMotion || document.hidden || !isVisible) return;
    timer = window.setTimeout(() => {
      show(currentIndex + 1);
      schedule();
    }, 7600);
  }

  function advance() {
    show(currentIndex + 1);
    schedule();
  }

  function previous() {
    show(currentIndex - 1);
    schedule();
  }

  nextButton?.addEventListener('click', advance);
  previousButton?.addEventListener('click', previous);
  carousel.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowRight') {
      event.preventDefault();
      advance();
    }
    if (event.key === 'ArrowLeft') {
      event.preventDefault();
      previous();
    }
  });
  carousel.addEventListener('touchstart', (event) => {
    touchStartX = event.changedTouches[0]?.screenX ?? null;
  }, { passive: true });
  carousel.addEventListener('touchend', (event) => {
    const touchEndX = event.changedTouches[0]?.screenX;
    if (touchStartX === null || touchEndX === undefined) return;
    const distance = touchEndX - touchStartX;
    touchStartX = null;
    if (Math.abs(distance) < 42) return;
    if (distance < 0) advance();
    else previous();
  }, { passive: true });

  new IntersectionObserver((entries) => {
    isVisible = entries.some((entry) => entry.isIntersecting);
    schedule();
  }, { threshold: 0.24 }).observe(carousel);

  document.addEventListener('visibilitychange', schedule);
  carousel.setAttribute('tabindex', '0');
  preload(1);
  preload(2);
})();

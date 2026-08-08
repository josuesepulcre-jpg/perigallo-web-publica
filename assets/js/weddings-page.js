(() => {
  const nav = document.querySelector('#weddingNav');
  if (nav) {
    const updateNav = () => nav.classList.toggle('is-scrolled', window.scrollY > 80);
    updateNav();
    window.addEventListener('scroll', updateNav, { passive: true });
  }

  const carousel = document.querySelector('.wedding-story-carousel');
  if (!carousel || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  if (window.__perigalloWeddingCarouselStarted) return;
  window.__perigalloWeddingCarouselStarted = true;

  const slides = [
    '/assets/images/gastronomy-carousel/foreground/fortune-cookie.webp',
    '/assets/images/gastronomy-carousel/foreground/brioche-braseado.webp',
    '/assets/images/gastronomy-carousel/foreground/helado-rosa.webp',
    '/assets/images/gastronomy-carousel/foreground/churro-copa.webp',
    '/assets/images/gastronomy-carousel/foreground/canape-negro.webp',
    '/assets/images/gastronomy-carousel/foreground/postre-rojo.webp',
    '/assets/images/gastronomy-carousel/foreground/hojaldre-caviar.webp',
    '/assets/images/gastronomy-carousel/foreground/nigiri-atun.webp',
    '/assets/images/gastronomy-carousel/foreground/vieiras-espuma.webp',
    '/assets/images/gastronomy-carousel/foreground/pollo-cine.webp',
    '/assets/images/gastronomy-carousel/foreground/cafe-crema.webp',
    '/assets/images/gastronomy-carousel/foreground/croqueta-rellena.webp'
  ];
  const slots = Array.from(carousel.querySelectorAll('.wedding-story-carousel__slide'));
  const current = carousel.querySelector('[data-wedding-carousel-current]');
  let activeSlot = 0;
  let activeIndex = 0;
  let timer = null;
  let paused = false;

  const preload = (index) => {
    const image = new Image();
    image.src = slides[index % slides.length];
  };

  const schedule = () => {
    window.clearTimeout(timer);
    if (paused || document.hidden) return;
    timer = window.setTimeout(() => setIndex(activeIndex + 1), 6200);
  };

  const setIndex = (index) => {
    const nextIndex = index % slides.length;
    const nextSlot = activeSlot === 0 ? 1 : 0;
    const source = slides[nextIndex];
    const preloadImage = new Image();
    let revealed = false;
    const reveal = () => {
      if (revealed) return;
      revealed = true;
      const image = slots[nextSlot];
      image.src = source;
      image.classList.add('is-active');
      slots[activeSlot].classList.remove('is-active');
      activeSlot = nextSlot;
      activeIndex = nextIndex;
      if (current) current.textContent = String(activeIndex + 1).padStart(2, '0');
      preload(activeIndex + 1);
      schedule();
    };
    preloadImage.onload = reveal;
    preloadImage.onerror = schedule;
    preloadImage.src = source;
    if (preloadImage.complete && preloadImage.naturalWidth) reveal();
  };

  const start = () => {
    paused = false;
    schedule();
  };
  const stop = () => {
    window.clearInterval(timer);
    window.clearTimeout(timer);
    timer = null;
    paused = true;
  };

  preload(2);
  start();
  carousel.addEventListener('mouseenter', stop);
  carousel.addEventListener('mouseleave', start);
  document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));
})();

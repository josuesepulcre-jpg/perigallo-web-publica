(() => {
  const nav = document.querySelector('#weddingNav');
  if (!nav) return;

  const updateNav = () => nav.classList.toggle('is-scrolled', window.scrollY > 80);
  updateNav();
  window.addEventListener('scroll', updateNav, { passive: true });
})();

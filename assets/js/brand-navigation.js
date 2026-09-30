(() => {
  const header = document.querySelector('[data-brand-header]');
  const button = header?.querySelector('.brand-menu');
  if (!header || !button) return;
  const close = () => { header.classList.remove('is-open'); button.setAttribute('aria-expanded', 'false'); };
  button.addEventListener('click', () => { const open = header.classList.toggle('is-open'); button.setAttribute('aria-expanded', String(open)); });
  header.querySelectorAll('a').forEach(link => link.addEventListener('click', close));
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && header.classList.contains('is-open')) { close(); button.focus(); } });
  document.addEventListener('click', event => { if (!header.contains(event.target)) close(); });
  header.addEventListener('focusout', event => { if (event.relatedTarget && !header.contains(event.relatedTarget)) close(); });
  window.matchMedia('(min-width: 1051px)').addEventListener('change', close);
})();

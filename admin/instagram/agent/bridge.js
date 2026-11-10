'use strict';
(() => {
  if (document.getElementById('perigallo-agent-link')) return;
  const navigation = document.querySelector('nav[aria-label="Secciones del estudio"]') || document.querySelector('nav');
  if (!navigation) return;
  const link = document.createElement('a');
  link.id = 'perigallo-agent-link';
  link.href = '/admin/instagram/agent/';
  link.textContent = 'Agente · Simulación ↗';
  link.setAttribute('aria-label', 'Abrir agente de Instagram en simulación');
  link.style.cssText = 'display:inline-flex;align-items:center;padding:12px 18px;border:1px solid currentColor;border-radius:999px;text-decoration:none;color:inherit;font:inherit;white-space:nowrap;';
  navigation.append(link);
})();

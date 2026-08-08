import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const page = readFileSync(resolve('celebraciones-familiares-alicante/index.html'), 'utf8');
const styles = readFileSync(resolve('assets/css/celebrations-page.css'), 'utf8');

for (const fragment of [
  'class="wedding-nav" id="weddingNav"',
  'href="/#descubre-perigallo">Descubre Perigallo</a>',
  'El día de la familia',
  'Una fecha importante',
  'Todo tiene que<br><em>sentirse cercano.</em>',
  'class="wedding-story-carousel"',
  'data-wedding-carousel-current',
  'href="/comuniones-alicante/"',
  'href="/bautizos-alicante/"',
  'href="/eventos-privados-alicante/"',
  'assets/css/weddings-page.css?v=20260808-weddings-v2',
  'assets/css/celebrations-page.css?v=20260808-celebrations-v1',
  'assets/js/weddings-page.js?v=20260808-weddings-v3'
]) {
  if (!page.includes(fragment)) throw new Error(`Falta en la página de celebraciones: ${fragment}`);
}

if (page.includes('pg-breadcrumb') || page.includes('data-public-nav') || page.includes('public-pages.css')) {
  throw new Error('La página de celebraciones conserva la subcabecera anterior.');
}

for (const fragment of [
  '.celebrations-page .wedding-hero',
  'finca-la-llaguna-principal.jpg',
  '.celebrations-page .wedding-story-carousel'
]) {
  if (!styles.includes(fragment)) throw new Error(`Falta en los estilos de celebraciones: ${fragment}`);
}

for (const asset of [
  'assets/images/perigallo-logo-original.png',
  'assets/images/finca-la-llaguna-principal.jpg',
  'assets/images/gastronomy-carousel/foreground/fortune-cookie.webp'
]) {
  if (!existsSync(resolve(asset))) throw new Error(`Falta el recurso de celebraciones: ${asset}`);
}

console.log('Celebrations page static checks passed.');

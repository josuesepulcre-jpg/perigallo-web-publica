import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const page = readFileSync(resolve('bodas-alicante/index.html'), 'utf8');
const styles = readFileSync(resolve('assets/css/weddings-page.css'), 'utf8');
const script = readFileSync(resolve('assets/js/weddings-page.js'), 'utf8');

for (const fragment of [
  'class="wedding-nav" id="weddingNav"',
  'href="/#descubre-perigallo">Descubre Perigallo</a>',
  'href="/#quienes-somos">Quiénes somos</a>',
  'href="/#contact">Contacto</a>',
  'Una boda que se recuerda',
  'Una celebración<br>con <em>vuestro relato.</em>',
  'Todo tiene que<br><em>sentirse conectado.</em>',
  'class="wedding-story-carousel"',
  'data-wedding-carousel-current',
  'gastronomy-carousel/foreground/fortune-cookie.webp',
  'Empecemos por<br><em>imaginarla juntos.</em>',
  'assets/css/weddings-page.css?v=20260808-weddings-v2',
  'assets/js/weddings-page.js?v=20260808-weddings-v3',
]) {
  if (!page.includes(fragment)) throw new Error(`Falta en la página de bodas: ${fragment}`);
}

for (const fragment of [
  '.wedding-nav{position:fixed;',
  '.wedding-hero{position:relative;',
  '.wedding-connection{position:relative;',
  '.wedding-story-carousel{position:relative;',
  '.wedding-layers__grid{display:grid;',
  '@media(max-width:900px)',
]) {
  if (!styles.includes(fragment)) throw new Error(`Falta en los estilos de bodas: ${fragment}`);
}

if (page.includes('pg-breadcrumb') || page.includes('data-public-nav')) {
  throw new Error('La página de bodas conserva la subcabecera anterior.');
}

if (!script.includes('window.addEventListener(\'scroll\'')) {
  throw new Error('La cabecera de bodas no conserva su comportamiento al hacer scroll.');
}

if (!script.includes('wedding-story-carousel') || !script.includes('gastronomy-carousel/foreground/fortune-cookie.webp')) {
  throw new Error('El carrusel gastronómico de bodas no utiliza las imágenes de la portada.');
}

for (const asset of [
  'assets/images/perigallo-logo-original.png',
  'assets/images/perigallo-wedding-table-at-dusk.png',
  'assets/images/gastronomy-carousel/foreground/fortune-cookie.webp',
  'assets/images/gastronomy-carousel/foreground/croqueta-rellena.webp',
]) {
  if (!existsSync(resolve(asset))) throw new Error(`Falta el recurso de bodas: ${asset}`);
}

console.log('Wedding page static checks passed.');

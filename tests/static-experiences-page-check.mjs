import { readFile } from 'node:fs/promises';

const html = await readFile(new URL('../experiencias/index.html', import.meta.url), 'utf8');
const css = await readFile(new URL('../assets/css/experiences-page.css', import.meta.url), 'utf8');

const expectations = [
  ['cabecera compartida', 'class="wedding-nav"'],
  ['sin cabecera anterior', !html.includes('class="pg-nav"')],
  ['agenda dinámica', 'data-events-list'],
  ['enlace a mis entradas', 'href="/mis-entradas/"'],
  ['carrusel editorial', 'class="wedding-story-carousel experiences-story__carousel"'],
  ['script de eventos', '/assets/js/ticketing.js'],
  ['estilos específicos', 'experiences-agenda']
];

for (const [label, value] of expectations) {
  if (!(typeof value === 'boolean' ? value : html.includes(value) || css.includes(value))) {
    throw new Error(`Falta ${label}.`);
  }
}

console.log('OK static experiences page');

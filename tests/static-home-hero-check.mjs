import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const home = readFileSync(resolve('index.html'), 'utf8');
const required = [
  '<h1 class="hero-title">Perigallo</h1>',
  'Gastronomía inmersiva',
  'Diseñamos cocina, servicio y puesta en escena para celebraciones irrepetibles.',
  'Quiero celebrar un evento',
  'Quiero vivir una experiencia Perigallo',
  'href="#about" class="scroll-indicator"',
  'radial-gradient(ellipse 62% 60% at 50% 43%',
  'background-blend-mode:color',
  'background-size:min(48vw,760px) auto',
  'radial-gradient(ellipse 31% 48% at 50% 51%',
  'assets/images/gastronomy-carousel/fortune-cookie.jpg',
  'assets/images/gastronomy-carousel/croqueta-rellena.jpg',
  'id="heroSlideCurrent"',
  'border-radius:999px',
  'background:#29474d',
  'favicon.svg?v=perigallo-monogram-20260808',
  '.hero-slide{position:absolute;inset:0;z-index:0;overflow:hidden;opacity:0;background:transparent;transition:opacity 1.55s cubic-bezier(.22,1,.36,1);will-change:opacity}',
  '.hero-slide.active{z-index:2;opacity:1}.hero-slide.is-leaving{z-index:1;opacity:0}',
  'var heroFadeDuration=1550;',
  'preloadSlide(1);',
  '<section class="manifest" id="about" aria-label="Quiénes somos">',
  'class="manifest-artwork" src="assets/images/about/quienes-somos-perigallo.png"',
  'width="1672" height="941"',
  '.manifest-artwork{display:block;width:100%;max-width:1672px;height:auto;margin:0 auto}',
];

for (const fragment of required) {
  if (!home.includes(fragment)) {
    throw new Error(`Falta el elemento del hero: ${fragment}`);
  }
}

if (home.includes('class="cursor"') || home.includes('class="cursor-ring"')) {
  throw new Error('El cursor decorativo no se ha eliminado del hero.');
}

const carouselSlides = (home.match(/class="hero-slide(?: active)?"/g) ?? []).length;
if (carouselSlides !== 12) {
  throw new Error(`El carrusel debe incluir las 12 fotografías gastronómicas; incluye ${carouselSlides}.`);
}

console.log('Home hero static checks passed.');

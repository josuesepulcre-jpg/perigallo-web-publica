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
  'assets/images/gastronomy-carousel/fortune-cookie.jpg',
  'assets/images/gastronomy-carousel/croqueta-rellena.jpg',
  'id="heroSlideCurrent"',
  'border-radius:999px',
  'background:#29474d',
  'favicon.svg?v=perigallo-monogram-20260808',
  '.hero-slide::before{display:block;background-image:linear-gradient',
  'background-blend-mode:color',
  'transparent 88%',
  'id="about-title" class="manifest-heading reveal">Quiénes somos</h2>',
  'Perigallo nace de la visión compartida de <em>David y Josué</em>.',
  'assets/images/about/josue-perigallo.jpg',
  'assets/images/about/david-perigallo.jpg',
  'Cocina con relato · Producción cuidada · Experiencias a medida',
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

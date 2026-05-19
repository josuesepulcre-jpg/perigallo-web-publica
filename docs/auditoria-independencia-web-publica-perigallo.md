# Auditoria de independencia de la web publica Perigallo

Fecha: 2026-05-19

## 1. Diagnostico general

La carpeta actual funciona como web publica estatica de Perigallo, pero todavia no esta preparada como proyecto independiente y desplegable sin riesgo. La home publica esta en `index.html`, el formulario inicial en `solicitud-evento/index.html`, la politica provisional en `politica-privacidad/index.html` y existen rutas auxiliares tipo `bodas/`, `celebraciones/`, `pop-up/`, `reservar/`, `contacto/` y `la-finca/`.

El principal problema de independencia no es funcional, sino de orden de proyecto: conviven archivos publicos reales con zips, backups, una carpeta de referencia, CSS/JS legado no enlazado por la home actual y documentacion interna. Si esta carpeta se sube tal cual a produccion, se publicarian materiales de trabajo que no deben formar parte del webroot publico.

La integracion de reservas esta correctamente planteada como pasarela externa a `https://reservas.perigallo.com/reservar?source=web`. No hay iframe activo hacia `reservas.perigallo.com` en el DOM actual, lo cual es coherente con el bloqueo CSP `frame-ancestors 'none'` del motor de reservas. Si se intenta forzar embed sin cambiar CSP en Reservas, la experiencia volvera a romperse.

La carpeta no es un repositorio Git y no debe inicializarse automaticamente sin decidir antes que archivos son codigo fuente, que archivos son historicos y que queda fuera de deploy.

## 2. Que pertenece a la web publica

| Ruta/archivo | Estado | Uso recomendado |
| --- | --- | --- |
| `index.html` | Publico principal | Home comercial de Perigallo. Debe permanecer como entrada principal. |
| `solicitud-evento/index.html` | Publico funcional | Formulario inicial de celebraciones. Debe mantenerse, revisando integracion comercial en fase posterior. |
| `politica-privacidad/index.html` | Publico legal provisional | Debe mantenerse solo como solucion temporal hasta validacion legal. |
| `assets/images/finca-la-llaguna-principal.jpg` | Publico | Imagen usada por home y metadatos OG/Twitter. Debe optimizarse antes de produccion. |
| `bodas/index.html` | Publico stub | Redireccion HTML a `/#celebrate`. Puede mantenerse temporalmente, pero no es una landing SEO real. |
| `celebraciones/index.html` | Publico stub | Redireccion HTML a `/#celebrate`. Conviene convertir en pagina real. |
| `pop-up/index.html` | Publico stub | Redireccion HTML a `/#popup`. Conviene convertir en pagina real. |
| `reservar/index.html` | Publico stub | Redireccion HTML a `/#fechas`. Conviene revisar porque la home usa tambien `#reservas`. |
| `contacto/index.html` | Publico stub | Redireccion HTML a `/#contact`. Puede mantenerse hasta crear pagina real. |
| `la-finca/index.html` | Publico stub | Redireccion HTML a `/#finca`. Canonical ya corregido a URL absoluta. |

## 3. Que NO deberia desplegarse

| Ruta/archivo | Motivo | Recomendacion |
| --- | --- | --- |
| `perigallo-web-redesign.zip` | ZIP de trabajo | Excluir del deploy o mover fuera del webroot. |
| `perigallo-web-perigallo-restaurado.zip` | ZIP de restauracion/trabajo | Excluir del deploy o mover fuera del webroot. |
| `_reference-web-periallo/` | Referencia historica, no web publica final | Excluir del deploy o mover a archivo interno. |
| `_reference-web-periallo/perigallo-web.zip` | ZIP dentro de carpeta de referencia | Excluir del deploy. |
| `index.original-perigallo-2026-05-06.html` | Backup HTML | Excluir del deploy. |
| `index.multi-page-before-restore-2026-05-07.html` | Backup HTML | Excluir del deploy. |
| `.claude/launch.json` | Configuracion local de herramienta | No debe subirse al webroot publico. Puede versionarse solo si se decide repo interno. |
| `docs/` | Documentacion interna | No deberia publicarse como parte del sitio web salvo decision explicita. |
| `assets/css/styles.css` | CSS heredado no enlazado por home actual | Revisar si se conserva como fuente futura o se excluye hasta consolidar estilos. |
| `assets/js/site.js` | JS heredado no cargado por home actual, contiene endpoints potenciales | No desplegar como funcional sin decidir integracion real. |

## 4. Riesgos de mezclar esta web con Reservas o Suite

- **Riesgo operativo:** la web publica capta leads y envia usuarios al motor oficial; Reservas y Suite gestionan disponibilidad, backoffice, CRM y operativa interna. Mezclarlas en el mismo proyecto puede romper responsabilidades y dificultar despliegues seguros.
- **Riesgo de datos:** cualquier endpoint, token, variable o flujo interno de Suite/Reservas no debe vivir en el webroot publico.
- **Riesgo de CSP:** `reservas.perigallo.com` bloquea embed mediante `frame-ancestors 'none'`. La web publica no debe depender de iframe hasta que Reservas permita explicitamente el dominio publico.
- **Riesgo de privacidad:** si se mezclan formularios publicos con flujos internos, la politica legal debe cubrir correctamente responsables, encargados, cookies, comunicaciones y almacenamiento.
- **Riesgo SEO:** stubs, backups y rutas internas publicadas pueden indexarse y diluir la posicion de la marca.
- **Riesgo de confianza:** enlaces o textos que mencionen Suite, backoffice o endpoints internos pueden hacer que el cliente final perciba una web no terminada.

## 5. Checklist previo a crear repo Git

- Confirmar que esta carpeta sera el proyecto independiente de `perigallo.es`.
- No ejecutar `git init` hasta limpiar o excluir zips, backups, referencias y configuraciones locales.
- Crear `.gitignore` antes del primer commit.
- Decidir si `docs/` se versiona dentro del repo o si se guarda en una carpeta interna no desplegable.
- Confirmar que no hay credenciales, `.env`, tokens, datos personales ni configuraciones de Suite/Reservas.
- Confirmar rutas publicas finales: home one-page temporal o paginas reales por servicio.
- Confirmar politica legal final o marcar `politica-privacidad/` como provisional/noindex hasta revision.
- Crear `README.md` con instrucciones de desarrollo, validacion y despliegue.
- Crear `robots.txt` y `sitemap.xml` cuando se decida la arquitectura indexable.
- Validar localmente rutas principales y enlaces externos antes del primer commit.
- Hacer el primer commit solo con archivos fuente/publicos y documentacion aprobada.

## 6. Propuesta de estructura limpia

Estructura recomendada para un proyecto estatico independiente:

```text
/
  index.html
  robots.txt
  sitemap.xml
  README.md
  .gitignore

  assets/
    images/
    css/
    js/
    icons/

  bodas/
    index.html
  celebraciones/
    index.html
  pop-up/
    index.html
  reservar/
    index.html
  contacto/
    index.html
  la-finca/
    index.html
  solicitud-evento/
    index.html
  politica-privacidad/
    index.html

  docs/
    auditoria-web-publica-perigallo-diseno-operatividad.md
    auditoria-independencia-web-publica-perigallo.md
    nota-exclusion-deploy-web-publica.md
```

Estructura recomendada fuera del webroot o excluida del deploy:

```text
_archive/
  perigallo-web-redesign.zip
  perigallo-web-perigallo-restaurado.zip
  _reference-web-periallo/
  index.original-perigallo-2026-05-06.html
  index.multi-page-before-restore-2026-05-07.html
```

No se han movido archivos en esta fase.

## 7. Propuesta de `.gitignore`

```gitignore
# Sistema
.DS_Store
Thumbs.db

# Entornos y secretos
.env
.env.*
!.env.example

# Dependencias/build si el proyecto evoluciona
node_modules/
dist/
build/
.cache/

# Backups y paquetes de trabajo
*.zip
*.tar
*.tar.gz
*.bak
*.backup
*.old
index.original-*.html
index.*restore*.html

# Referencias internas
_reference-web-periallo/
_archive/

# Configuracion local de herramientas
.claude/
```

Si se decide versionar `.claude/launch.json` para trabajo local, excluirlo del deploy aunque se mantenga en Git.

## 8. Propuesta de `README.md`

Contenido minimo recomendado:

````markdown
# Web publica Perigallo

Proyecto estatico independiente para la web publica/comercial de Perigallo.

## Ambito

- Este proyecto sirve `perigallo.es`.
- No contiene Perigallo Suite.
- No contiene el motor de reservas.
- Las reservas se derivan a `https://reservas.perigallo.com/reservar?source=web`.

## Desarrollo local

```bash
python3 -m http.server 8000
```

Abrir:

```text
http://127.0.0.1:8000/
```

## Validacion antes de deploy

- Revisar `/`, `/solicitud-evento/`, `/politica-privacidad/`, `/la-finca/`.
- Confirmar que no hay `href="#form"`.
- Confirmar que no hay iframes activos hacia `reservas.perigallo.com`.
- Confirmar enlaces externos: Reservas, Finca La Llaguna, Instagram.
- Confirmar canonical, Open Graph y Twitter Card.
- Confirmar que no se despliegan zips, backups ni carpetas de referencia.

## Deploy

No desplegar desde esta carpeta completa sin aplicar la lista de exclusion documentada.
````

## 9. Recomendacion sobre reservas: pasarela externa vs embed

Recomendacion inmediata: **mantener pasarela externa hacia Perigallo Reservas**.

Motivos:

- El motor oficial responde correctamente en `https://reservas.perigallo.com/reservar?source=web`.
- La web publica no tiene iframe activo hacia Reservas.
- El dominio de Reservas bloquea embedding mediante CSP, por lo que un iframe fallaria o quedaria en blanco.
- La pasarela evita formularios duplicados y mantiene las reservas dentro del sistema oficial.

Solo recomendaria intentar embed si se cumplen estas condiciones:

- Reservas permite explicitamente `https://perigallo.es` en `frame-ancestors`.
- Se valida que el motor embebido no rompe responsive ni accesibilidad.
- La experiencia embebida mantiene login, disponibilidad, confirmacion y backoffice sin duplicar datos.
- Existe fallback visible a "Abrir reservas" si el iframe falla.

## 10. SEO y estructura pendiente

Estado actual:

- La home tiene canonical absoluto, OG y Twitter Card basicos.
- `politica-privacidad/` tiene canonical absoluto y `noindex,follow`.
- Varias rutas stub usan canonical relativo a anchors: `/bodas/`, `/celebraciones/`, `/pop-up/`, `/reservar/`, `/contacto/`.
- No existe `robots.txt`.
- No existe `sitemap.xml`.
- No se detecta schema.org activo en la home.
- La imagen OG existe, pero conviene revisar dimensiones/peso y preparar version final.

Recomendacion:

- En Fase 2, crear `robots.txt` y `sitemap.xml` solo con rutas aprobadas.
- En Fase 3, convertir rutas comerciales principales en paginas indexables reales o aplicar redireccion HTTP desde servidor si se decide mantener one-page.
- En Fase 4, anadir schema.org (`Organization`, `LocalBusiness`, `Restaurant`/`FoodEstablishment` y `EventVenue` solo si los datos legales y de ubicacion estan confirmados).

## 11. Rendimiento y deuda tecnica

Hallazgos:

- `index.html` pesa aproximadamente 575 KB.
- `solicitud-evento/index.html` pesa aproximadamente 164 KB.
- Hay imagenes base64 embebidas en `index.html` y `solicitud-evento/index.html`.
- `assets/images/finca-la-llaguna-principal.jpg` pesa aproximadamente 489 KB.
- `assets/css/styles.css` y `assets/js/site.js` existen pero la home actual no los carga; parecen restos de una version multipagina anterior.
- `assets/js/site.js` contiene endpoints potenciales (`/api/reservas/pop-up`, `/api/solicitudes/celebraciones`, `/api/contactos`) que no deben considerarse integracion activa sin backend confirmado.

Propuesta sin aplicar todavia:

- Extraer logos/base64 a archivos reales en `assets/images/` o `assets/icons/`.
- Optimizar imagen de finca en WebP/AVIF con JPG fallback.
- Consolidar CSS/JS usado por la home en archivos versionados.
- Eliminar o archivar CSS/JS no usado solo despues de confirmar que no se necesita para rutas futuras.
- Retirar la referencia JavaScript muerta a `.booking-modal-frame` y al URL `embed=1` de Reservas, porque no hay iframe de reservas activo y puede confundir futuras revisiones.

## 12. Roadmap recomendado

### Fase 2: orden tecnico y deploy seguro

- Crear `.gitignore`.
- Crear `README.md`.
- Definir carpeta limpia de deploy.
- Excluir zips, backups, `_reference-web-periallo/`, `.claude/` y documentacion interna del webroot publico.
- Corregir canonicals relativos de rutas stub o decidir redirecciones HTTP reales.
- Crear `robots.txt` y `sitemap.xml` basicos.
- Eliminar referencias JS muertas a iframe de reservas, manteniendo pasarela externa.
- Revisar favicon y assets OG finales.

### Fase 3: arquitectura comercial y captacion

- Convertir `bodas/`, `celebraciones/`, `pop-up/`, `contacto/` y `la-finca/` en paginas reales o decidir que no se indexan.
- Crear contenido especifico para bodas, eventos privados, empresas, pop-ups y finca.
- Validar el flujo real de solicitudes: EmailJS, endpoint propio o CRM/backoffice.
- Revisar politica de privacidad y cookies con datos legales confirmados.
- Anadir WhatsApp/contacto si forma parte del flujo comercial aprobado.

### Fase 4: performance, SEO avanzado y QA

- Extraer CSS/JS inline a assets versionados.
- Optimizar imagenes y logos.
- Anadir schema.org con datos legales confirmados.
- Medir Lighthouse/Core Web Vitals.
- Validar accesibilidad: contraste, foco, labels, navegacion con teclado.
- Validar responsive en 390, 768, 1440 y pantallas grandes.
- Preparar checklist de release antes de subir a produccion.

## 13. Criterios de aceptacion antes de produccion

- No hay zips, backups, carpetas de referencia ni configuracion local dentro del deploy publico.
- No hay referencias visibles a `suite.perigallo.com`.
- Las referencias a `reservas.perigallo.com` son solo enlaces externos o pasarela oficial, no iframe activo.
- Todas las rutas publicas aprobadas devuelven 200 o redireccion HTTP correcta.
- No queda ningun `href="#form"` ni enlaces legales a `#`.
- La politica de privacidad esta validada o claramente marcada como provisional/noindex.
- Canonical, OG, Twitter Card, favicon, `robots.txt` y `sitemap.xml` estan alineados.
- No hay errores graves de consola en la home.
- No hay scroll horizontal en mobile.
- Los enlaces principales funcionan: Reservas, Finca La Llaguna, Instagram, email y telefono.
- El deploy se hace desde una carpeta limpia o pipeline que excluye archivos internos.
- Si se crea repo Git, el primer commit no incluye backups/zips/reference folders.

## 14. Validaciones ejecutadas

Comandos y comprobaciones realizadas:

```bash
find .. -name AGENTS.md -print
test -e .git && echo GIT_EXISTS || echo NO_GIT
test -f package.json && echo PACKAGE_EXISTS || echo NO_PACKAGE
test -f README.md && echo README_EXISTS || echo NO_README
test -f .gitignore && echo GITIGNORE_EXISTS || echo NO_GITIGNORE
test -f robots.txt && echo ROBOTS_EXISTS || echo NO_ROBOTS
test -f sitemap.xml && echo SITEMAP_EXISTS || echo NO_SITEMAP
find . -maxdepth 3 \( -name '*.zip' -o -iname '*backup*' -o -iname '*original*' -o -iname '*restore*' -o -name '_reference*' \) -print | sort
du -ah . | sort -hr | head -50
rg -n 'href="#form"|href="#"|<iframe[^>]+reservas\.perigallo\.com|suite\.perigallo\.com|Sábado, 17 de mayo' . --glob '!*.zip' --glob '!_reference-web-periallo/**'
/usr/bin/curl -L -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8000/
```

Resultados relevantes:

- `.git`: no existe.
- `package.json`: no existe.
- `README.md`: no existe.
- `.gitignore`: no existe.
- `robots.txt`: no existe.
- `sitemap.xml`: no existe.
- Servidor local disponible en puerto `8000`.
- Rutas locales principales validadas con 200: `/`, `/solicitud-evento/`, `/politica-privacidad/`, `/la-finca/`.
- Rutas auxiliares validadas con 200: `/pop-up/`, `/celebraciones/`, `/bodas/`, `/reservar/`, `/contacto/`.
- No se detecta `href="#form"` en archivos publicos activos.
- No se detecta iframe activo hacia `reservas.perigallo.com`.
- En navegador: canonical home, OG y Twitter Card correctos; consola sin errores; overflow horizontal `0`.
- En navegador: no hay referencias visibles a `suite.perigallo.com`; el naming visible usa `Finca La Llaguna`.
- Enlaces externos principales comprobados con 200: Reservas, Finca La Llaguna e Instagram.

## 15. Recomendacion inmediata

No crear repo Git todavia. Primero debe prepararse una carpeta limpia o una estrategia de exclusion de deploy. La accion inmediata recomendada es aprobar una Fase 2 tecnica de bajo riesgo para crear `.gitignore`, `README.md`, `robots.txt`, `sitemap.xml`, ordenar los archivos no desplegables y retirar deuda muerta de iframe/reservas sin tocar el diseno visual.

Hasta entonces, la web publica debe seguir separada conceptualmente de Perigallo Suite y de Perigallo Reservas. Reservas debe mantenerse como motor externo oficial mediante pasarela.

# Checklist pre-Git de la web publica Perigallo

Fecha: 2026-05-19

## 1. Estado actual del proyecto

La carpeta revisada es:

```text
/Users/josue/Documents/Codex/2026-05-06/files-mentioned-by-the-user-perigallo
```

Estado confirmado:

- No es un repositorio Git.
- No existe `package.json`.
- Existe `.gitignore`.
- Existe `README.md`.
- Existen documentos de auditoria y deploy en `docs/`.
- La web publica es estatica y se sirve correctamente con `python3 -m http.server`.
- No se ha inicializado Git, no se ha hecho commit y no se ha preparado ningun deploy.

La web publica debe mantenerse separada de:

- Perigallo Suite.
- `suite.perigallo.com`.
- El motor de Reservas.
- `reservas.perigallo.com`, salvo enlaces externos/pasarela oficial.
- Produccion/Plesk.

## 2. Archivos que entrarian en un primer commit

Lista propuesta para un primer commit limpio, si se aprueba inicializar Git en una fase posterior:

```text
.gitignore
README.md
index.html
solicitud-evento/index.html
politica-privacidad/index.html
la-finca/index.html
bodas/index.html
celebraciones/index.html
pop-up/index.html
reservar/index.html
contacto/index.html
assets/images/finca-la-llaguna-principal.jpg
assets/css/styles.css
assets/js/site.js
docs/auditoria-web-publica-perigallo-diseno-operatividad.md
docs/auditoria-independencia-web-publica-perigallo.md
docs/nota-exclusion-deploy-web-publica.md
docs/estructura-deploy-web-publica.md
docs/pre-git-checklist-web-publica.md
```

Notas:

- `assets/css/styles.css` y `assets/js/site.js` pueden entrar en Git como historico tecnico del proyecto, pero no deberian considerarse activos ni desplegables sin confirmar uso. La home actual no parece enlazarlos.
- `docs/` puede entrar en Git como documentacion interna del proyecto, pero no debe publicarse en el webroot.
- `politica-privacidad/index.html` puede entrar en Git como pagina provisional, pero debe revisarse legalmente antes de produccion.

## 3. Archivos excluidos por `.gitignore`

El `.gitignore` actual excluye:

- Archivos de sistema: `.DS_Store`, `Thumbs.db`.
- Logs y temporales: `*.log`, `*.tmp`, `*.temp`, swaps y backups de editor.
- Entornos y secretos: `.env`, `.env.*`, `*.local`.
- Posibles datos sensibles o dumps: `*.sql`, `*.sqlite`, `*.sqlite3`, `*.db`.
- Claves/certificados: `*.pem`, `*.key`, `*.crt`, `*.p12`, `id_rsa*`, `id_dsa*`.
- Dependencias y builds futuros: `node_modules/`, `dist/`, `build/`, `.cache/`, `.parcel-cache/`, `.vite/`, `coverage/`.
- Configuracion local de editores: `.vscode/`, `.idea/`, `.history/`.
- Paquetes y backups: `*.zip`, `*.tar`, `*.tar.gz`, `*.tgz`, `*.bak`, `*.backup`, `*.old`, `*.orig`.
- Backups HTML: `index.original-*.html`, `index.*restore*.html`, `*-before-restore-*.html`.
- Referencias internas: `_reference-web-periallo/`, `_archive/`.
- Configuracion local de herramienta: `.claude/`.
- Carpetas de salida de deploy local: `deploy/`, `public-build/`.

## 4. Archivos que NO deben entrar en Git

No se deben versionar:

```text
perigallo-web-redesign.zip
perigallo-web-perigallo-restaurado.zip
_reference-web-periallo/
_reference-web-periallo/index.html
_reference-web-periallo/perigallo-web.zip
index.original-perigallo-2026-05-06.html
index.multi-page-before-restore-2026-05-07.html
.claude/launch.json
```

Tampoco deben entrar en Git si aparecen en el futuro:

```text
.env
.env.*
*.sql
*.sqlite
*.sqlite3
*.db
*.pem
*.key
*.crt
*.p12
node_modules/
dist/
build/
deploy/
public-build/
```

## 5. Archivos dudosos que requieren decision humana

| Archivo/carpeta | Motivo | Decision recomendada |
| --- | --- | --- |
| `assets/css/styles.css` | CSS externo de una version anterior/multipagina; la home actual usa CSS inline. | Versionar por ahora, pero no desplegar como activo sin confirmar uso. |
| `assets/js/site.js` | JS externo con endpoints potenciales y no cargado por la home actual. | Versionar por ahora para no perder contexto, pero no tratarlo como integracion activa. |
| `docs/` | Documentacion interna util para el proyecto. | Versionar en Git, excluir del webroot. |
| `politica-privacidad/index.html` | Pagina legal provisional. | Versionar, revisar legalmente antes de produccion. |
| Rutas stub | `bodas/`, `celebraciones/`, `pop-up/`, `reservar/`, `contacto/`, `la-finca/`. | Versionar como estado actual, decidir si seran landings reales o redirecciones de servidor. |

## 6. Archivos que podrian formar parte de deploy

Deploy publico minimo actual:

```text
index.html
assets/images/finca-la-llaguna-principal.jpg
solicitud-evento/index.html
politica-privacidad/index.html
la-finca/index.html
bodas/index.html
celebraciones/index.html
pop-up/index.html
reservar/index.html
contacto/index.html
```

Pendientes antes de considerar deploy final:

- Confirmar si `assets/css/styles.css` y `assets/js/site.js` deben desplegarse o archivarse.
- Crear `robots.txt`.
- Crear `sitemap.xml`.
- Confirmar favicon final.
- Revisar politica de privacidad.
- Confirmar estrategia de rutas stub.

## 7. Archivos que nunca deberian desplegarse

No desplegar en webroot:

```text
.git/
.gitignore
README.md
docs/
.claude/
_reference-web-periallo/
perigallo-web-redesign.zip
perigallo-web-perigallo-restaurado.zip
_reference-web-periallo/perigallo-web.zip
index.original-perigallo-2026-05-06.html
index.multi-page-before-restore-2026-05-07.html
```

Tampoco desplegar si aparecen:

```text
.env
.env.*
*.log
*.tmp
*.sql
*.sqlite
*.sqlite3
*.db
*.pem
*.key
*.crt
*.p12
node_modules/
dist/
build/
deploy/
public-build/
```

## 8. Inconsistencias tecnicas documentadas

| Punto | Estado actual | Accion recomendada |
| --- | --- | --- |
| Rutas stub | `bodas/`, `celebraciones/`, `pop-up/`, `reservar/`, `contacto/` son paginas con `meta refresh`. | Mantener temporalmente o convertir en paginas reales/redirects HTTP. |
| Canonicals relativos | `bodas/`, `celebraciones/`, `pop-up/`, `reservar/`, `contacto/` usan canonicals tipo `/#...`. | Convertir a URLs absolutas o crear landings reales. |
| `la-finca/` | Stub con canonical absoluto `https://perigallo.es/#finca`. | Valorar landing real para SEO local. |
| `robots.txt` | No existe. | Crear antes de produccion. |
| `sitemap.xml` | No existe. | Crear antes de produccion cuando se aprueben rutas indexables. |
| Favicon final | No se ha confirmado. | Preparar asset final. |
| `reservar/` | Redirige a `/#fechas`; la home tambien usa `#reservas`. | Unificar criterio. |
| Reservas iframe | No hay iframe activo hacia Reservas, pero queda JS heredado relacionado con `.booking-modal-frame` y `embed=1`. | Limpiar en fase tecnica controlada; no hacerlo en esta fase. |
| Politica de privacidad | Existe pagina provisional con responsable parcial y `noindex,follow`. | Revisar legalmente antes de produccion. |

## 9. Referencias a Reservas y Suite

Estado actual:

- No hay iframe activo hacia `reservas.perigallo.com`.
- Los enlaces publicos a Reservas son enlaces externos a `https://reservas.perigallo.com/reservar?source=web`.
- No se detectan referencias funcionales a `suite.perigallo.com` en archivos publicos activos.
- Las menciones a Suite/Reservas dentro de `README.md` y `docs/` son documentales y sirven para separar responsabilidades.

Referencia heredada pendiente:

```text
index.html:981-990
```

Contiene codigo para una clase `.booking-modal-frame` y fallback `embed=1`, aunque actualmente no hay iframe real con esa clase. No se ha modificado por seguridad.

## 10. Resultado de busqueda de secretos

Patrones buscados:

```text
password
secret
token
api_key / api-key
private
.env
smtp
mysql
database
```

Resultado:

- No se han encontrado credenciales reales ni valores sensibles expuestos.
- Coincidencias detectadas son falsos positivos documentales (`.env`, `token`, `secretos`) o campos del formulario narrativo (`algo-secreto`, `q-secreto`, `secreto`).
- No se han encontrado archivos `.env`, dumps SQL, bases de datos locales, claves privadas ni certificados dentro del arbol revisado.

## 11. Decisiones pendientes antes de `git init`

- Confirmar que el repo se llamara, por ejemplo, `perigallo-web-publica`.
- Confirmar que `docs/` debe entrar en Git.
- Confirmar que `assets/css/styles.css` y `assets/js/site.js` se versionan como legado tecnico o se archivan antes del primer commit.
- Confirmar que zips, backups y `_reference-web-periallo/` se conservaran fuera de Git.
- Confirmar que `.claude/` queda fuera de Git.
- Confirmar que no se incluira ningun archivo de Suite ni Reservas.
- Revisar manualmente `git status` despues de ejecutar `git init` en una fase posterior.

## 12. Decisiones pendientes antes de produccion

- Crear carpeta limpia de deploy.
- Decidir si las rutas stub se mantienen o pasan a paginas reales.
- Crear `robots.txt`.
- Crear `sitemap.xml`.
- Preparar favicon final.
- Revisar politica de privacidad legalmente.
- Decidir si `assets/css/styles.css` y `assets/js/site.js` se despliegan, se consolidan o se eliminan del deploy.
- Limpiar JS heredado de iframe de Reservas si se aprueba.
- Validar responsive y consola en navegador antes de subir.
- No subir `README.md`, `.gitignore`, `docs/`, `.git/`, `.claude/`, zips, backups ni referencias.

## 13. Checklist manual antes del primer commit

Antes de ejecutar `git init`:

- [ ] Confirmar que se esta en la carpeta correcta.
- [ ] Confirmar que no existe `.git/`.
- [ ] Revisar `.gitignore`.
- [ ] Revisar este checklist.
- [ ] Confirmar lista de archivos del primer commit.
- [ ] Confirmar exclusiones de zips, backups, referencias y `.claude/`.
- [ ] Confirmar que no hay `.env` ni secretos.
- [ ] Confirmar que no se va a subir Plesk, Suite, Reservas ni produccion.

Despues de ejecutar `git init` en una fase futura:

- [ ] Ejecutar `git status`.
- [ ] Confirmar que no aparecen zips.
- [ ] Confirmar que no aparece `_reference-web-periallo/`.
- [ ] Confirmar que no aparece `.claude/`.
- [ ] Confirmar que no aparecen backups HTML.
- [ ] Confirmar que no aparecen secretos o entornos locales.
- [ ] Revisar manualmente el primer `git add`.

Comando previsto para una fase posterior, no ejecutado en esta fase:

```bash
git init
git add .gitignore README.md docs/ index.html solicitud-evento/ politica-privacidad/ la-finca/ bodas/ celebraciones/ pop-up/ reservar/ contacto/ assets/
git status
```

## 14. Validaciones ejecutadas en esta revision

Validaciones de solo lectura ejecutadas el 2026-05-19:

| Validacion | Resultado |
| --- | --- |
| Estado Git | La carpeta no es un repositorio Git. |
| Arbol de archivos | Revisado con `find`; existen zips, backups, `_reference-web-periallo/` y `.claude/`, todos clasificados como no versionables/no desplegables. |
| Candidatos al primer commit | Listados sin zips, backups, referencias ni `.claude/`. |
| Busqueda de secretos | Sin credenciales reales; solo falsos positivos documentales y campos narrativos del formulario. |
| Iframes activos hacia Reservas | No se detecta iframe activo hacia `reservas.perigallo.com`. |
| `href="#form"` | No se detecta en archivos publicos activos. |
| Referencias Suite/Reservas | No se detectan referencias funcionales peligrosas; Reservas queda como enlace externo/documentacion. |
| Rutas locales | Todas devuelven HTTP 200 con servidor temporal local. |

Rutas comprobadas localmente:

```text
/ -> 200
/solicitud-evento/ -> 200
/politica-privacidad/ -> 200
/la-finca/ -> 200
/bodas/ -> 200
/celebraciones/ -> 200
/pop-up/ -> 200
/reservar/ -> 200
/contacto/ -> 200
```

Nota tecnica: la comprobacion HTTP se hizo con `python3 -m http.server` en un puerto temporal y `/usr/bin/curl`; no se dejo ningun servidor persistente.

## 15. Confirmacion de alcance

En esta fase:

- No se ha inicializado Git.
- No se ha hecho commit.
- No se ha hecho push.
- No se ha tocado Plesk.
- No se ha tocado produccion.
- No se ha tocado Suite.
- No se ha tocado Reservas.
- No se han tocado bases de datos.
- No se han tocado variables de entorno.
- No se han borrado zips, backups ni carpetas de referencia.
- No se ha cambiado el diseno visual.
- No se ha cambiado copy comercial publico.

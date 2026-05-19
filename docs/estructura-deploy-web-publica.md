# Estructura de deploy de la web publica Perigallo

Fecha: 2026-05-19

## 1. Objetivo

Definir que debe subirse y que debe quedar fuera cuando la web publica de Perigallo se prepare como proyecto independiente. Este documento no ejecuta deploy, no inicializa Git y no mueve archivos.

## 2. Archivos y carpetas que si deberian subirse

Lista base para un deploy publico estatico, pendiente de aprobacion final:

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

Notas:

- `assets/css/styles.css` y `assets/js/site.js` no parecen estar enlazados por la home actual. No incluirlos como funcionales sin confirmar uso.
- `docs/` puede versionarse internamente, pero no deberia publicarse en el webroot salvo decision explicita.
- Cuando existan `robots.txt`, `sitemap.xml` y favicon final, deberian formar parte del deploy publico.

## 3. Archivos y carpetas que no deberian subirse

| Ruta | Motivo |
| --- | --- |
| `perigallo-web-redesign.zip` | ZIP de trabajo. |
| `perigallo-web-perigallo-restaurado.zip` | ZIP de restauracion/trabajo. |
| `_reference-web-periallo/` | Carpeta de referencia historica. |
| `_reference-web-periallo/perigallo-web.zip` | ZIP de referencia. |
| `index.original-perigallo-2026-05-06.html` | Backup HTML. |
| `index.multi-page-before-restore-2026-05-07.html` | Backup HTML. |
| `.claude/` | Configuracion local de herramienta. |
| `.git/` | Metadatos Git, si se crea en el futuro. |
| `.gitignore` | Puede versionarse, pero no es necesario publicarlo en webroot. |
| `README.md` | Puede versionarse, pero no es necesario publicarlo en webroot. |
| `docs/` | Documentacion interna; excluir del webroot salvo decision explicita. |
| `*.log`, `*.tmp`, `.DS_Store` | Archivos temporales/sistema. |

## 4. Propuesta de estructura limpia final

Estructura de proyecto versionable:

```text
perigallo-web-publica/
  .gitignore
  README.md
  index.html
  robots.txt
  sitemap.xml

  assets/
    images/
    icons/
    css/
    js/

  solicitud-evento/
    index.html
  politica-privacidad/
    index.html
  la-finca/
    index.html
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

  docs/
    auditoria-web-publica-perigallo-diseno-operatividad.md
    auditoria-independencia-web-publica-perigallo.md
    nota-exclusion-deploy-web-publica.md
    estructura-deploy-web-publica.md
```

Estructura limpia de deploy/webroot:

```text
webroot/
  index.html
  robots.txt
  sitemap.xml

  assets/
    images/
    icons/
    css/
    js/

  solicitud-evento/
  politica-privacidad/
  la-finca/
  bodas/
  celebraciones/
  pop-up/
  reservar/
  contacto/
```

Archivos historicos recomendados fuera del webroot:

```text
_archive/
  perigallo-web-redesign.zip
  perigallo-web-perigallo-restaurado.zip
  _reference-web-periallo/
  index.original-perigallo-2026-05-06.html
  index.multi-page-before-restore-2026-05-07.html
```

## 5. Riesgos actuales

- La carpeta contiene zips y backups que podrian quedar publicos si se sube entera.
- Hay rutas stub con `meta refresh`; sirven como fallback temporal, no como paginas comerciales SEO reales.
- Los canonicals de `bodas/`, `celebraciones/`, `pop-up/`, `reservar/` y `contacto/` son relativos a anchors. Recomendacion: convertir a URLs absolutas o crear paginas reales.
- `reservar/index.html` redirige a `/#fechas`, mientras la home usa tambien `#reservas`; conviene unificar criterio.
- Existe JS muerto relacionado con `.booking-modal-frame` en `index.html` aunque no hay iframe de reservas activo.
- Hay base64 embebido en HTML, lo que dificulta cache, mantenimiento y rendimiento.
- La politica de privacidad sigue siendo provisional.
- No existen todavia `robots.txt`, `sitemap.xml` ni schema.org final.

## 6. Referencias muertas a iframe de reservas

No hay iframe activo hacia `reservas.perigallo.com` en el HTML actual.

Referencias a revisar antes de una limpieza tecnica:

| Archivo | Linea aproximada | Referencia | Recomendacion |
| --- | --- | --- | --- |
| `index.html` | 981 | `bookingModalFrame = bookingModal ? bookingModal.querySelector(".booking-modal-frame") : null` | Parece codigo heredado. No encuentra elemento real si no existe `.booking-modal-frame`. |
| `index.html` | 987-990 | Asignacion de `src` a `https://reservas.perigallo.com/reservar?embed=1...` | No se ejecuta si no existe `.booking-modal-frame`, pero confunde auditorias. Recomendada eliminacion en fase tecnica controlada. |
| `index.html` | 1022 | Listener para `.booking-frame, .booking-modal-frame` | Parece heredado de la version con iframe. Mantener hasta decidir limpieza. |

No se ha eliminado este codigo en esta fase porque la instruccion es documentar y no hacer cambios funcionales.

## 7. Estado de rutas stub

| Ruta | Tipo actual | Canonical actual | Recomendacion |
| --- | --- | --- | --- |
| `/bodas/` | Stub con `meta refresh` a `/#celebrate` | `/#celebrate` | Convertir en landing real o canonical absoluto aprobado. |
| `/celebraciones/` | Stub con `meta refresh` a `/#celebrate` | `/#celebrate` | Convertir en landing real o canonical absoluto aprobado. |
| `/pop-up/` | Stub con `meta refresh` a `/#popup` | `/#popup` | Convertir en landing real de pop-ups o canonical absoluto aprobado. |
| `/reservar/` | Stub con `meta refresh` a `/#fechas` | `/#fechas` | Unificar con `#reservas` o dirigir directamente a pasarela externa segun estrategia. |
| `/contacto/` | Stub con `meta refresh` a `/#contact` | `/#contact` | Convertir en pagina real o canonical absoluto aprobado. |
| `/la-finca/` | Stub con `meta refresh` a `/#finca` | `https://perigallo.es/#finca` | Ya usa canonical absoluto; valorar landing real. |

## 8. Checklist antes de crear repositorio GitHub

- Confirmar nombre del repo, por ejemplo `perigallo-web-publica`.
- Confirmar que esta carpeta sera fuente de `perigallo.es`.
- Confirmar que no hay `.env`, secretos, credenciales ni datos personales.
- Confirmar que `.gitignore` esta revisado.
- Decidir si `docs/` entra en Git o se conserva fuera del repo.
- Decidir si `.claude/` queda siempre fuera.
- Decidir destino de zips/backups: mover a archivo interno o conservar fuera del repo.
- Revisar rutas stub y canonicals.
- Validar localmente rutas principales y consola.
- Inicializar Git solo despues de aprobar el alcance del primer commit.

## 9. Checklist antes de subir a Plesk

- Crear una carpeta limpia de deploy o lista exacta de archivos permitidos.
- No subir zips, backups, `_reference-web-periallo/`, `.claude/`, `.git/`, `README.md` ni `docs/`.
- Confirmar que la home carga assets correctos.
- Confirmar que Reservas abre como enlace externo.
- Confirmar que no hay iframe activo hacia `reservas.perigallo.com`.
- Confirmar que politica de privacidad esta aprobada o marcada como provisional/noindex.
- Crear `robots.txt` y `sitemap.xml` si la web va a indexarse.
- Revisar canonical, OG, Twitter Card y favicon final.
- Validar rutas con HTTP 200 o redireccion de servidor aprobada.
- Validar mobile sin scroll horizontal.
- Validar consola sin errores graves.

## 10. Recomendacion de dominio/subdominio

- Dominio publico recomendado: `https://perigallo.es/`.
- Motor de reservas: mantener externo en `https://reservas.perigallo.com/`.
- Suite interna: mantener fuera de este proyecto en `suite.perigallo.com` o dominio interno equivalente.
- Finca La Llaguna: mantener enlace externo actual `https://fincalallaguna.com/` hasta confirmar estrategia de marca/dominio.

No se recomienda alojar esta web publica dentro de `reservas.perigallo.com` ni de `suite.perigallo.com`, porque mezcla captacion publica con operativa interna.

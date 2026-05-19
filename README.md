# Web publica Perigallo

Proyecto estatico de la web publica/comercial de Perigallo.

Este proyecto corresponde solo a la web publica de marca y captacion. No es Perigallo Suite, no es `suite.perigallo.com` y no contiene el motor de reservas de `reservas.perigallo.com`.

## Ambito del proyecto

- Presentar Perigallo como marca gastronomica.
- Explicar pop-ups, celebraciones, bodas, eventos privados y Finca La Llaguna.
- Derivar reservas de pop-up al motor oficial externo.
- Captar solicitudes de celebraciones mediante el formulario publico.

## Relacion con Perigallo Reservas

Las reservas deben mantenerse como enlace externo hacia:

```text
https://reservas.perigallo.com/reservar?source=web
```

No se debe integrar por iframe mientras `reservas.perigallo.com` bloquee embedding mediante CSP. La web publica actua como pasarela comercial y el sistema de reservas conserva la gestion operativa.

## Relacion con Finca La Llaguna

La web publica menciona Finca La Llaguna como uno de los espacios donde Perigallo desarrolla experiencias. El enlace externo actual de la finca se mantiene como:

```text
https://fincalallaguna.com/
```

No modificar esta URL sin confirmar previamente la estrategia de dominio de la finca.

## Rutas publicas actuales

| Ruta | Tipo actual | Notas |
| --- | --- | --- |
| `/` | Home publica principal | Pagina comercial principal. |
| `/solicitud-evento/` | Formulario publico | Solicitud inicial para celebraciones/eventos. |
| `/politica-privacidad/` | Pagina legal provisional | Texto pendiente de validacion legal final. |
| `/la-finca/` | Stub con meta refresh | Redirige a `/#finca`. |
| `/bodas/` | Stub con meta refresh | Redirige a `/#celebrate`. |
| `/celebraciones/` | Stub con meta refresh | Redirige a `/#celebrate`. |
| `/pop-up/` | Stub con meta refresh | Redirige a `/#popup`. |
| `/reservar/` | Stub con meta refresh | Redirige a `/#fechas`; revisar porque la home usa tambien `#reservas`. |
| `/contacto/` | Stub con meta refresh | Redirige a `/#contact`. |

## Archivos que no deben desplegarse

No borrar sin aprobacion, pero no subir al webroot publico:

- `perigallo-web-redesign.zip`
- `perigallo-web-perigallo-restaurado.zip`
- `_reference-web-periallo/`
- `_reference-web-periallo/perigallo-web.zip`
- `index.original-perigallo-2026-05-06.html`
- `index.multi-page-before-restore-2026-05-07.html`
- `.claude/`
- Documentacion interna de `docs/`, salvo decision explicita.

## Desarrollo local

Desde la raiz del proyecto:

```bash
python3 -m http.server 8000
```

Abrir:

```text
http://127.0.0.1:8000/
```

Si el puerto `8000` esta ocupado, usar otro puerto:

```bash
python3 -m http.server 8743
```

## Validacion local minima

Comprobar que estas rutas devuelven `200`:

- `/`
- `/solicitud-evento/`
- `/politica-privacidad/`
- `/la-finca/`
- `/bodas/`
- `/celebraciones/`
- `/pop-up/`
- `/reservar/`
- `/contacto/`

Comprobar ademas:

- No hay `href="#form"` en archivos activos.
- No hay iframes activos hacia `reservas.perigallo.com`.
- Los enlaces a Reservas, Finca La Llaguna e Instagram abren correctamente.
- La consola del navegador no muestra errores graves.
- No hay scroll horizontal en mobile.
- Canonical, Open Graph y Twitter Card de la home apuntan a `https://perigallo.es/`.

## Flujo recomendado antes de inicializar Git

1. Revisar `docs/auditoria-independencia-web-publica-perigallo.md`.
2. Revisar `docs/nota-exclusion-deploy-web-publica.md`.
3. Confirmar que esta carpeta sera el proyecto independiente de `perigallo.es`.
4. Confirmar que zips, backups y `_reference-web-periallo/` quedan excluidos.
5. Confirmar si `docs/` se versiona en Git o se guarda fuera del deploy.
6. Confirmar si las rutas stub se mantienen temporalmente o se convierten en paginas reales.
7. Revisar que no hay credenciales, `.env`, datos personales ni configuracion de Suite/Reservas.
8. Solo entonces, inicializar Git y hacer el primer commit limpio.

## Checklist previo a deploy

- Deploy desde una carpeta limpia o lista cerrada de archivos aprobados.
- Excluir zips, backups, referencias y configuracion local.
- No incluir Perigallo Suite.
- No incluir codigo o configuracion del motor de Reservas.
- Mantener Reservas como enlace externo.
- Revisar politica de privacidad antes de produccion.
- Crear o validar `robots.txt` y `sitemap.xml`.
- Revisar metadatos SEO y Open Graph finales.
- Validar rutas, enlaces, consola y responsive.

## Estado Git

Esta carpeta esta inicializada como repositorio Git local independiente. No hay remoto configurado todavia.

Antes de conectar GitHub, revisar:

- `git status`
- que no aparecen zips, backups, `_reference-web-periallo/` ni `.claude/`
- que no aparecen `.env`, dumps, certificados ni claves
- que el remoto nuevo sera exclusivo para la web publica, no para Suite ni Reservas

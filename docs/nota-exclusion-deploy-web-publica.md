# Nota de exclusión para deploy de la web pública

Fecha: 2026-05-19

Esta nota documenta archivos y carpetas detectados dentro del repositorio/webroot que no deberían desplegarse públicamente sin revisión. No se ha eliminado nada en esta fase.

## Archivos detectados

| Ruta | Tipo | Recomendación |
| --- | --- | --- |
| `perigallo-web-redesign.zip` | ZIP de trabajo | Mover fuera del webroot o excluir del deploy. |
| `perigallo-web-perigallo-restaurado.zip` | ZIP de trabajo/restauración | Mover fuera del webroot o excluir del deploy. |
| `_reference-web-periallo/` | Carpeta de referencia | Mover fuera del webroot o excluir del deploy. |
| `_reference-web-periallo/perigallo-web.zip` | ZIP de referencia | Mover fuera del webroot o excluir del deploy. |
| `index.original-perigallo-2026-05-06.html` | Backup HTML | Mover fuera del webroot o excluir del deploy. |
| `index.multi-page-before-restore-2026-05-07.html` | Backup HTML | Mover fuera del webroot o excluir del deploy. |

## `.gitignore`

No se ha encontrado un `.gitignore` en la raíz del proyecto durante esta revisión. Antes de publicar o automatizar deploy conviene añadir reglas para ZIPs, backups HTML fechados y carpetas de referencia internas, sin borrar los archivos actuales hasta que el cliente confirme qué se conserva.

## Naming de la finca

Se han encontrado dos variantes: `Finca La Laguna` y `Finca La Llaguna`. El contexto interno del proyecto y el formulario de solicitud usan `Finca La Llaguna`, por lo que en esta fase se han normalizado textos visibles hacia `Finca La Llaguna`.

No se ha modificado la URL externa existente `https://fincalallaguna.com/`, porque podría ser la dirección pública real del sitio de la finca.

Estado tras la Fase 1:

| Variante | Ubicación |
| --- | --- |
| `Finca La Llaguna` | Textos visibles normalizados en `index.html`, `la-finca/index.html` y `solicitud-evento/index.html`. |
| `Finca La Laguna` | Permanece en archivos históricos/backups como `index.multi-page-before-restore-2026-05-07.html`, en la auditoría ya generada y en esta propia nota como referencia documental. |
| `fincalallaguna.com` | Permanece como enlace externo de la finca. |

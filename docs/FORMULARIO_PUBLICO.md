# Formulario público permanente

La ruta canónica del formulario de solicitudes es `/formulario/`. Es una ruta física
del repositorio y persiste aunque el formulario se pause desde administración.

## Auditoría histórica

El cuestionario original estaba en `solicitud-evento/index.html` desde el commit
`6bc93f094720cd60f1644cafb5b1a4edb8c2d89b`. El commit
`99eb5dec34876256f4e57178810e99589d2f3512` lo sustituyó por una pasarela a
Perigallo Suite; no se creó la compatibilidad de `/formulario/`, por lo que esa URL
acabó en el 404 del servidor.

El cuestionario recuperado conserva estructura, preguntas y diseño histórico. El
envío antiguo con EmailJS se ha reemplazado por persistencia propia en base de datos
y notificación de correo desde el servidor. Los borradores ya no se guardan en
`localStorage`, porque contienen datos personales.

## Funcionamiento

- `/solicitud-evento/` redirige con HTTP 301 a `/formulario/`.
- `POST /api/formulario` valida, aplica honeypot y límite de 4 solicitudes por IP
  hash en 15 minutos, guarda la solicitud y después intenta enviar el aviso.
- La tabla guarda el estado comercial y el estado del email (`pending`, `sent` o
  `failed`); un fallo de correo nunca elimina la solicitud.
- `/admin/formulario/` permite configurar el estado, textos y destinatario, además
  de consultar y clasificar solicitudes. Requiere sesión de administración.

## Migración y comprobación pública

```bash
php api/scripts/apply-migration.php database/migrations/019_public_lead_form.sql
node tests/smoke-public-form.mjs https://perigallo.com/formulario/
```

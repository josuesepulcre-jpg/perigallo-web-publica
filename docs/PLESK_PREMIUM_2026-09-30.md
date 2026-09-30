# Publicación de la revisión premium — 30 de septiembre de 2026

Esta entrega está preparada y probada localmente. No se ha desplegado. La rama en los tres repositorios es `codex/premium-web-audit`.

## Proyectos y alcance

| Dominio | Repositorio GitHub | Publicación |
| --- | --- | --- |
| perigallo.com | josuesepulcre-jpg/perigallo-web-publica | HTML, CSS, JS, imágenes y regla de redirección de `/formulario/` |
| suite.perigallo.com | josuesepulcre-jpg/perigallo-suite | Aplicación Node.js; formulario público, validación y dependencias |
| reservas.perigallo.com | josuesepulcre-jpg/reservas_perigallo | Aplicación Node.js; calendario público, textos, tipografía y dependencias |

El cambio no requiere SQL ni migraciones. Se mantienen las variables de entorno y los datos existentes.

## Pull y despliegue en Plesk

1. Hacer backup del sitio o aplicación antes de publicar. Guardarlo fuera del Document Root. Anotar el commit que está publicado para poder volver atrás.
2. En **Git del dominio correspondiente**, seleccionar `codex/premium-web-audit` y usar **Pull/Extraer actualizaciones**. Revisar los cambios antes de **Desplegar**. No usar el Git de Reservas para Suite.
3. Publicar primero Suite, después Reservas y finalmente la web pública: la web enlaza con ambos servicios.
4. En Suite y Reservas, después de copiar el código a la raíz de la aplicación, usar **Node.js → Ejecutar comandos npm**:

```text
ci
run build
```

5. Si la compilación termina correctamente, usar **Reiniciar aplicación**. Conservar el startup file `server.js` y la versión Node.js 24 de Plesk. No copiar la `.next` del Mac al servidor; compilar en Plesk.
6. La web pública no necesita compilación Node.js. Conservar su configuración PHP, `api/`, `.env` y los archivos de clientes/subidas. No publicar `docs/`, `tests/`, `reports/`, `preview-local/`, `tmp/`, `.git/` ni archivos de trabajo. El paquete `perigallo-web-premium.zip` contiene únicamente los archivos públicos de esta entrega y puede extraerse sobre el Document Root si no se usa el despliegue Git.

### Si se trabaja por SSH desde un checkout Git limpio

Ejecutar en el checkout del repositorio correcto, no dentro de otro dominio:

```bash
git status --short
git fetch origin
git switch codex/premium-web-audit
git pull --ff-only origin codex/premium-web-audit
```

Si `git status` muestra cambios locales del servidor, conservarlos y resolverlos antes de cambiar de rama. No usar `reset --hard` para hacer el pull. Si no existe un checkout `.git` en la carpeta, usar la pantalla Git de Plesk o el paquete de archivos; estos comandos no son para un repositorio bare de Plesk.

## Texto para entregar a quien gestione Plesk

> Publica la rama codex/premium-web-audit de cada repositorio en su dominio. Haz backup externo y anota el commit anterior. Suite y Reservas requieren npm ci, npm run build y reinicio de Node.js; no necesitan SQL, migraciones ni cambios de variables de entorno. Mantén Node.js 24 y server.js. Publica la web estática al final y conserva la API y las subidas existentes. No ejecutes sincronizaciones de datos ni envíes correos o WhatsApp de prueba. Comprueba las rutas indicadas abajo.

## Comprobaciones después de publicar

- Portada, Bodas, Celebraciones, Experiencias, Comuniones, Bautizos, Empresa, Finca, Nosotros y Contacto: menú visible en ordenador y funcional en móvil.
- Enlaces de privacidad, aviso legal, cookies y condiciones: sin 404.
- `/experiencias/`: el evento del 29 de agosto aparece como edición anterior y no como próxima venta.
- `/experiencias/la-perigalla-01-ibicenca/`: mensaje de experiencia celebrada, sin botón de compra activo.
- `/formulario/`: redirige al formulario oficial de Suite; los enlaces nuevos apuntan directamente a Suite.
- `/solicitud`: si falta un campo, indica qué completar. Con datos válidos permite avanzar. Revisar que no aparece el aviso de instalar la aplicación.
- `/reservar`: anterior y siguiente cambian un mes; febrero no se salta. Los días de relleno son consecutivos. Un mes sin fechas ofrece orientación y enlaces alternativos.
- Comprobar una solicitud de boda controlada hasta la confirmación y su recepción en Suite. Esta entrega no ha creado nuevas solicitudes en producción.
- Revisar el correo transaccional y, con un evento futuro habilitado, el recorrido de reserva y pago. No se han realizado cargos ni reservas nuevas durante esta revisión.

## Validación local y límites

- Pruebas de agenda y navegación: 5; pruebas de formulario: 8; pruebas de calendario: 4. Todas pasan.
- Comprobaciones estáticas de portada, Bodas, Celebraciones, Experiencias y ticketing: pasan.
- Builds de producción de Suite y Reservas: pasan.
- `npm audit`: sin vulnerabilidades detectadas en las dependencias instaladas de Suite y Reservas en esta revisión. No equivale a una auditoría integral de seguridad.
- Generación y lectura local de correo MIME y transformación de imagen: correctas, sin envíos externos.
- Revisión visual en Chrome a 1280 px y 390 px. No hay desbordamiento horizontal en los recorridos comprobados.
- Permanece un aviso de Next sobre el trazado de archivos del endpoint interno de conversión de audio de WhatsApp en Reservas; no impide compilar. Conviene optimizar ese módulo interno en una revisión separada.
- No se ha medido Core Web Vitals real ni comprobado un cobro nuevo. La aceptación final se confirma después del despliegue; no se atribuye una puntuación 100/100 sin esa comprobación.

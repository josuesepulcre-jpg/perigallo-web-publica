# Agente de Instagram de Perigallo — simulación

## Estado y alcance

El módulo añade `/admin/instagram/agent/` al estudio privado existente. La puerta `admin/instagram/index.php` inserta únicamente un enlace al agente: no sustituye el estudio mantenido fuera del repositorio ni modifica su almacén de borradores.

Esta versión usa IA para preparar textos, carruseles, guiones y respuestas ficticias, y conserva borradores, revisiones, aprobaciones, consultas, tareas e informes. Las imágenes se adjuntan como recursos privados por el usuario. Una propuesta visual o un guion no equivale a una fotografía o un vídeo ya producido.

**No contiene transporte de publicación, mensajería ni moderación de Instagram.** El modo está fijado a `SIMULATION` tanto en el controlador como en el servicio y el almacén. La lectura de Instagram que ya existe en el estudio permanece independiente.

## Componentes

- `admin/instagram/agent/index.php`: entrada autenticada del panel.
- `view.php`, `app.js`, `app.css`: interfaz de revisión.
- `api.php`: API privada con sesión administrativa y CSRF en cambios.
- `bridge.js`: enlace desde la navegación del estudio anterior.
- `InstagramAgentRuntime`: configuración privada, recursos y capacidades.
- `InstagramAgentStore`: JSON fuera del directorio público, bloqueo de archivo y escritura atómica.
- `InstagramAgentService`: revisiones, aprobaciones, idempotencia, límites, pausa y ciclo programado.
- `InstagramAgentGenerator`: llamada acotada a OpenAI Responses, salida estructurada y validación de dominio.
- `api/src/prompts/instagram-agent.txt`: instrucciones de simulación, protegidas por la regla existente de `api/src`.
- `api/cron/instagram-agent.php`: trabajador de línea de comandos; su instalación y activación son separadas del despliegue de código.

No añade dependencias de Node, Python, base de datos ni una pila nueva de autenticación. Reutiliza PHP y `AdminAuth`. Necesita PHP 8.1 o posterior con cURL, JSON, mbstring y fileinfo, además de permisos de escritura en la carpeta privada. Los datos son compartidos por los administradores de Perigallo, como el estudio existente; no es un producto multicliente.

## Datos y configuración

Variables documentadas en `.env.example`:

| Variable | Función |
| --- | --- |
| `OPENAI_API_KEY` | Clave ya presente en el entorno; tiene prioridad y nunca se devuelve al navegador. |
| `INSTAGRAM_AGENT_MODEL` | Modelo de generación; valor inicial `gpt-5.4-mini`, configurable según acceso del proyecto. |
| `INSTAGRAM_AGENT_STORAGE_DIR` | Carpeta privada de estado y recursos. |
| `INSTAGRAM_AGENT_OPENAI_KEY_FILE` | Archivo privado alternativo con la clave. |

Ubicación inicial: `/var/www/vhosts/perigallo.com/private/instagram-agent`.

El estado y los recursos se guardan fuera del directorio público con permisos restringidos. No se permiten enlaces simbólicos en la ruta de datos. Si el entorno ya contiene una clave, la interfaz indica que se reutiliza; no la sobrescribe. En ausencia de esa clave, solo la cuenta propietaria puede guardarla mediante el formulario privado, con CSRF, sin lectura posterior ni almacenamiento en el navegador.

La configuración de claves debe completarse con el flujo seguro de OpenAI. No se deben copiar claves en commits, comentarios de PR, conversaciones, registros ni capturas. Guardar una clave indica presencia, no que se hayan verificado crédito o acceso al modelo: eso se comprueba en la primera generación real de contenido de simulación.

## Revisión editorial

Una pieza tiene dos estados separados: revisión y ejecución. Las aprobaciones incluyen cuenta, modo, revisión y huella del contenido. Editar texto, imágenes, derechos, fechas o campos editoriales invalida la aprobación. Los cambios de versión enviados desde otra sesión se rechazan.

Una publicación simulada requiere aprobación de su versión. Su ejecución solo añade un registro y no abre ningún canal con Meta. Repetir la petición no duplica el registro.

El agente informa de datos comerciales pendientes. No debe inventar disponibilidad, precios, reservas o garantías alimentarias. El modelo no recibe credenciales ni herramientas que puedan publicar.

## Conversaciones y seguimiento

Las entradas del simulador son ficticias y quedan etiquetadas. El servicio conserva la clasificación, respuesta propuesta, decisión y siguiente acción.

Las preferencias de no contactar y la toma de control humana cancelan los seguimientos comerciales asociados. Los casos de alergias, quejas y datos desconocidos requieren el tratamiento indicado en el prompt. No hay envío real de respuestas.

El panel mantiene tareas internas con responsable, estado, fecha y vinculación opcional a una conversación. Una tarea resuelta o cancelada no constituye una comunicación enviada.

## Ejecución y límites

Cada petición de generación utiliza `request_id`. Se reserva la ejecución y el límite diario antes de llamar al proveedor; la llamada ocurre fuera del bloqueo de archivo. Un error no se sustituye por contenido prefabricado ni se reintenta a ciegas.

La pausa bloquea generación y salidas simuladas, pero permite revisar y corregir borradores. Si se pausa durante una generación, la salida no se convierte en un nuevo borrador activo.

El límite inicial es diez intentos de generación diarios. Los intentos fallidos también cuentan. Este límite de volumen no es un presupuesto monetario ni un límite de facturación del proveedor. La programación comienza desactivada.

El trabajador registra un latido, comprueba la hora de Europe/Madrid y reserva como máximo un ciclo por día. Cada ciclo prepara una propuesta y actualiza el informe de actividad. No contacta a clientes ni publica contenido.

## Informes

Las cifras se calculan desde el estado persistido: propuestas, revisiones, aprobaciones, conversaciones ficticias, escalados, tareas y ejecuciones. Las métricas de alcance, ventas, reservas y seguidores permanecen como N/D dentro del agente.

La sincronización real que el estudio anterior ya muestra no se presenta como una integración de métricas del nuevo módulo. Los informes identifican su periodo y diferencian actividad del panel de resultados del negocio.

## Encaje con el estudio existente

El código privado del estudio y su contrato de borradores no están incluidos en este repositorio. Por ello, esta versión conserva ambos espacios:

1. El estudio existente mantiene su perfil, campañas y borradores.
2. El agente administra sus propuestas y revisiones dentro del mismo acceso privado.
3. Cada propuesta del agente se puede exportar como paquete JSON o copiar al estudio.
4. Una sincronización automática con el almacén anterior requiere revisar su implementación privada antes de escribir en ella.

No se presupone una API de importación ni se envían escrituras a `drafts.php` a partir de un esquema inventado.

## Despliegue y activación

Seguir `ops/OVH-DEPLOY.md`. El repositorio tiene despliegue automático de main; la rama de trabajo y la PR no equivalen a una instalación en producción.

Antes de activar:
- Revisar los cambios del módulo y las pruebas.
- Confirmar la carpeta privada y su propietario de sistema.
- Comprobar el acceso autenticado a `/admin/instagram/agent/`.
- Verificar la presencia de la clave, el modelo y una generación acotada.
- Verificar persistencia, revisión, edición y simulación en el servidor.
- Decidir si se instala el trabajador con el procedimiento de cron del operador.

El comando del trabajador es:

```sh
php /ruta-del-checkout/api/cron/instagram-agent.php
```

El operador debe sustituir la ruta por la ruta real de la publicación y usar el mismo entorno y usuario de PHP que sirve la aplicación. No se instala ni modifica cron desde una petición web. El panel solo muestra el programador como detectado cuando recibe un latido reciente. Si no está instalado, el botón de ciclo manual sigue siendo una ejecución manual.

Cambios de cron o configuración pueden requerir revisión del operador según la política del controlador de OVH. No se modifica esa política para desplegar este módulo.

## Pruebas

```sh
php tests/instagram-agent-service-test.php
php tests/instagram-agent-generator-test.php
php tests/instagram-agent-runtime-test.php
php tests/instagram-agent-http-test.php
node --check admin/instagram/agent/app.js
```

Las pruebas de generación utilizan un transporte inyectado, sin llamadas de pago. Las pruebas HTTP crean sesiones y datos efímeros únicamente en un servidor local de prueba. No usan clientes, borradores ni credenciales reales.

Verificar adicionalmente el panel en escritorio y móvil, el rechazo de operaciones de producción, la protección CSRF, la edición posterior a aprobación y la conservación de borradores del estudio anterior.

## Referencias de la integración de IA

Se utiliza Responses API como función acotada porque Perigallo ya gestiona estado, revisiones y ejecución en PHP. Las sesiones de Agents API son una opción futura si se amplía a herramientas y trabajo prolongado; no se añade un servicio de ejecución ajeno al despliegue existente.

- https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=responses
- https://developers.openai.com/api/docs/guides/migrate-to-responses?api-mode=responses
- https://developers.openai.com/api/docs/models/gpt-5.4-mini

Contratos consultados el 10 de noviembre de 2026. Modelo y acceso deben verificarse con la clave del proyecto.

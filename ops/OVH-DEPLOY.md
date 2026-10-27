# Despliegue de main a OVH

OVH comprueba main cada tres minutos tras terminar la ejecución anterior. No necesita que el Mac esté encendido. Cada repositorio tiene una clave de despliegue distinta, solo de lectura, custodiada en OVH. No hay acceso entrante nuevo ni tokens personales en el servidor.

El controlador y su política efectiva son archivos de root, fuera del directorio público. Un cambio en este documento no modifica esa política. El código canónico del controlador se versiona en `perigallo-suite/ops/ovh-autodeploy/`.

Antes de publicar se comprueba que no haya cambios manuales en OVH y que main avance desde la versión desplegada. Se prueban las aplicaciones en contenedores sin secretos ni datos reales; los tests no tienen acceso a Internet. Se compila con la misma versión mayor de Node que usa Passenger. Suite también prueba integridad y WhatsApp con MariaDB desechable sin puertos publicados ni red externa.

Se guarda una copia privada de código, configuración y datos compartidos, además de una copia SQL transaccional cuando corresponde. Se verifica la compresión y se registran hashes. La activación intercambia el directorio o el enlace de la aplicación de forma atómica y comprueba la respuesta por HTTPS. Un fallo revierte el código anterior; nunca restaura automáticamente una base de datos. Se conserva el almacenamiento compartido y los recursos estáticos anteriores.

Cambios en migraciones, esquemas, autenticación, pagos, correo, cron, configuración de arranque y protecciones de WhatsApp requieren revisión del operador. No se ejecutan migraciones, tareas programadas, colas, envíos ni compras como comprobación. Staff sigue retirado y Personal se publica dentro de Lab.

Operación en OVH (root):

```sh
systemctl status ovh-main-deploy.timer
journalctl -u ovh-main-deploy.service -n 50
python3 /usr/local/lib/ovh-main-deploy/controller.py --self-test
python3 /usr/local/lib/ovh-main-deploy/controller.py --app APP --force --validate
systemctl start ovh-main-deploy.service
```

APP: lab, reservas, consejo, suite, web, finca o josue. Estado y copias en `/var/lib/ovh-main-deploy/`, accesible solo por root. No copiar archivos de ese directorio al repositorio. El despliegue se detiene por falta de espacio y conserva las copias; su retirada se realiza con revisión, nunca borrando el almacenamiento compartido inicial.

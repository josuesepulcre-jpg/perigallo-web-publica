# Presupuesto monetario compartido

Las cuatro aplicaciones reservan **antes** de llamar a OpenAI contra un único
libro SQLite privado: **100 000 microeuros = 0,10 EUR por fecha de Madrid**.
`BEGIN IMMEDIATE` serializa reservas concurrentes; ni reintentos, reinicios,
timeouts ni respuestas ambiguas devuelven crédito. No se guardan claves, prompts
ni documentos. Si el servicio, la base o la tarifa no pueden verificarse se
bloquea la IA y se conserva el trabajo manual.

Se reserva el coste superior, no el gasto comunicado a posteriori: bytes UTF-8
(incluido esquema) + margen de framing para texto; techo de parches para imágenes;
contexto completo para ficheros/PDF. Esto puede bloquear PDFs y audios aunque
hubieran acabado costando menos. El flujo de audio actual no ofrece un límite
fiable de duración/tokens, por lo que se rechaza antes del envío y se puede
escribir el texto. Ninguna respuesta incompleta se aplica como resultado válido.

Solo se admiten los modelos mini revisados. La cota usada es USD 1 por millón de
tokens de entrada y USD 5 de salida, sin descuentos de caché; conversión prudente
de EUR 2/USD que incluye margen para IVA y cambio. Los importes son enteros, no
floats. Se fuerza `service_tier=default`; no se permiten herramientas facturables,
streaming, contexto anterior invisible ni ejecuciones en segundo plano.

Tarifas revisadas el 15-11-2026. **Caducan el 01-12-2026:** sin revisión explícita
se bloquean las nuevas llamadas, nunca se asume que siguen siendo válidas.
Fuentes: https://developers.openai.com/api/docs/models/gpt-4.1-mini,
https://developers.openai.com/api/docs/models/gpt-5-mini,
https://developers.openai.com/api/docs/models/gpt-5.4-mini,
https://developers.openai.com/api/docs/guides/images-vision.

El libro limita las llamadas que pasan por estas aplicaciones. No cambia el
precio de OpenAI ni cubre el uso de las claves desde programas externos o cargos
anteriores. No sustituye un límite del proveedor. El día de activación se reserva
todo el presupuesto restante como precaución por el consumo previo no conocido;
el cupo normal empieza a medianoche de Madrid.

Pruebas: `python3 ops/ai-spend-guard/test_guard.py` y las pruebas de cliente Node/PHP.
Servicio: instalar desde el commit revisado, código root-owned en
`/usr/local/lib/perigallo-ai-spend`, systemd `perigallo-ai-spend.service` con
`DynamicUser`, acceso solo AF_UNIX y sin red. Respaldar SQLite antes de actualizar;
**no borrar ni reinicializar su contenido al desplegar**.

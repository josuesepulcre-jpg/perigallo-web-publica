# Auditoría web pública Perigallo

Fecha de auditoría: 19 de mayo de 2026  
Alcance: web pública estática en este repo, home, rutas públicas, motor de reservas embebido, formulario de solicitud de celebración y artefactos desplegables.

## 1. Resumen ejecutivo

La web tiene una dirección visual con intención editorial y una identidad diferencial, pero todavía no funciona con la claridad comercial, SEO y operativa que necesita una web de captación premium. El mayor problema es que la primera pantalla puede quedarse sin propuesta visible por animaciones, las fechas de pop-up están vencidas o incoherentes, las páginas SEO son redirecciones vacías y el formulario de celebración es demasiado largo para un lead inicial. También hay deuda técnica: imágenes base64 embebidas, CSS/JS duplicado o no usado, backups públicos, falta de robots/sitemap/schema/Open Graph y enlace de privacidad vacío. El motor de reservas está integrado con `reservas.perigallo.com`, pero la experiencia sigue dependiendo de iframe externo y debe pulirse desde conversión, fallback y confianza. La marca no parece genérica, pero aún necesita más prueba real, mejor arquitectura por servicios y menos fricción para convertir visitas en reservas o solicitudes.

## 2. Skills y agentes utilizados

| Recurso | Encontrado | Usado | Cómo influyó en la auditoría |
| --- | --- | --- | --- |
| `AGENTS.md` | Sí, en `../files-mentioned-by-the-user-perigallo-2/AGENTS.md`. No existe en este repo público. | Sí, como regla de criterio. | Marcó el estándar premium Perigallo: editorial, gastronómico, mediterráneo, elegante, sin romper lógica ni producción. |
| `premium-ui-art-director` | Sí, en `../files-mentioned-by-the-user-perigallo-2/.codex/skills/premium-ui-art-director/SKILL.md`. | Sí, lectura aplicada manualmente. | Elevó el análisis de composición, jerarquía, ritmo visual, primera impresión, uso de imagen y sensación premium. |
| `landing-page-luxury-brand` | Sí, en `../files-mentioned-by-the-user-perigallo-2/.codex/skills/landing-page-luxury-brand/SKILL.md`. | Sí, lectura aplicada manualmente. | Se usó para valorar relato, deseo, promesa, estructura comercial, confianza y CTAs de una landing luxury. |
| `frontend-design-system` | Sí, en `../files-mentioned-by-the-user-perigallo-2/.codex/skills/frontend-design-system/SKILL.md`. | Sí, lectura aplicada manualmente. | Guió la revisión de tokens, CSS, duplicidades, estados, formularios, botones, contraste y coherencia visual. |
| `visual-qa-playwright` | Sí, en `../files-mentioned-by-the-user-perigallo-2/.codex/skills/visual-qa-playwright/SKILL.md`. | Sí, aplicado con Browser. | Se revisaron escritorio, tablet y móvil con navegador local, capturas visuales, anchura, alturas, iframes y consola. |
| `app-dashboard-ux` | Sí, en `../files-mentioned-by-the-user-perigallo-2/.codex/skills/app-dashboard-ux/SKILL.md`. | Parcialmente. | No es el skill principal para web pública, pero ayudó a evaluar operativa de reserva, formulario, estados y claridad funcional. |
| Browser / in-app browser | Disponible como skill/plugin. | Sí. | Se abrió `http://127.0.0.1:8000/`, se revisaron capturas desktop/mobile, DOM, iframes, enlaces, headings y errores de consola. |
| Agente Pauli | Disponible como subagente. | Sí. | Auditó estructura técnica, rutas, SEO, accesibilidad, assets y riesgos de deploy. |
| Agente Huygens | Disponible como subagente. | Sí. | Auditó estética, copy, conversión, dirección de arte y fricción comercial. |
| Lint/build/test | No hay `package.json` ni pipeline detectado. | No aplicable. | Se documenta como limitación. Se hicieron validaciones HTTP, DOM y browser en local. |
| Lighthouse/axe crawler | No hay herramienta específica instalada en este repo. | No. | Limitación: faltan métricas reales de Lighthouse, accesibilidad automatizada y Core Web Vitals. Recomendable añadirlo en una fase posterior. |

Limitaciones:
- Las skills específicas están en un repo hermano, no instaladas localmente dentro de este repo público. Se han usado como criterios explícitos de auditoría, no como comandos automáticos.
- No se ha tocado Plesk, producción, base de datos, variables de entorno ni lógica.
- No se puede confirmar desde este repo si EmailJS o el motor de reservas registran correctamente en el backoffice interno; solo se audita lo que el código público evidencia.

## 3. Diagnóstico global

| Área | Puntuación | Lectura |
| --- | ---: | --- |
| Diseño visual | 7/10 | Hay una estética reconocible y premium, pero el hero falla comercialmente y faltan imágenes reales/semánticas. |
| Claridad comercial | 5/10 | Se entienden varias líneas, pero bodas, empresas, pop-ups y finca no tienen rutas ni mensajes suficientemente independientes. |
| UX | 5/10 | Buen ambiente, pero navegación móvil incompleta, CTAs con fricción y formulario de lead demasiado largo. |
| Mobile | 4/10 | No hay menú de servicios visible en móvil; el hero puede aparecer sin texto ni CTA; la página llega a más de 14.000 px de scroll. |
| Copywriting | 6/10 | Hay frases fuertes, pero conviven tono abstracto, palabras genéricas como “catering” y falta de concreción comercial. |
| SEO | 3/10 | Faltan landings indexables, canonical real, sitemap, robots, schema, Open Graph y contenido local por servicio. |
| Conversión | 4/10 | Hay botones y contacto, pero fechas vencidas, CTAs rotos a `#form`, privacidad vacía y lead form largo reducen conversión. |
| Performance percibida | 5/10 | Sin errores de consola, pero hay base64 embebidos, iframes externos y alto peso/scroll. |
| Coherencia de marca | 6/10 | La estética tiene personalidad, pero hay naming inconsistente `La Laguna`/`La Llaguna` y sistemas visuales mezclados. |

## 4. Mapa de páginas y secciones

| Página/ruta | Función actual | Problema detectado | Oportunidad de mejora |
| --- | --- | --- | --- |
| `/` | Home principal con hero, manifiesto, servicios, pop-up, historia gastronómica, reservas, celebraciones, catering, finca y contacto. | Demasiadas funciones en una sola página; el hero puede no mostrar texto/CTA; fechas vencidas; varias intenciones compiten. | Mantenerla como portada premium y distribuidor claro hacia pop-up, bodas/eventos, empresas, finca y contacto. |
| `/#popup` | Presenta el pop-up y el próximo evento. | Muestra “Sábado, 17 de mayo”; a fecha de auditoría, 19 de mayo de 2026, está vencido y además el 17 de mayo de 2026 fue domingo. | Convertir en bloque dinámico: próxima fecha real, lista de experiencias o estado “próximamente”. |
| `/#reservas` y `/#fechas` | Bloque de reserva con iframe oficial de `reservas.perigallo.com`. | Visualmente más integrado que versiones previas, pero el motor sigue dependiendo de iframe y ocupa mucho scroll; fallback existe. | Hacer una experiencia modal/fullscreen más directa, con copy de confianza y CTA fijo en móvil. |
| `/#celebrate` | Bloque de bodas/celebraciones privadas. | Las llamadas `Solicitar propuesta` apuntan a `#form`, pero no existe `id="form"`. | Enlazar todas las solicitudes al modal/formulario real o a una landing de solicitud. |
| `/#catering` | Explica producción/catering a medida. | “Catering” puede diluir la diferencia de Perigallo frente a proveedores convencionales. | Reencuadrar como “Producción gastronómica a medida” o “Experiencias fuera de la finca”. |
| `/#finca` | Presenta Finca La Laguna y enlaza a `fincalallaguna.com`. | Naming inconsistente frente a `Finca La Llaguna` en formulario y contexto de marca. | Verificar spelling oficial y convertir la finca en un activo narrativo, no solo un bloque informativo. |
| `/#contact` | Contacto y apertura del formulario inicial. | Hay email y teléfono, pero el flujo principal abre un briefing largo. | Añadir lead breve de 2 minutos y dejar el briefing completo como segundo paso. |
| `/bodas/` | Stub HTML que redirige por meta refresh a `/#celebrate`. | No es una landing SEO ni comercial real. | Crear página específica de bodas con propuesta, proceso, imágenes, confianza y formulario. |
| `/celebraciones/` | Stub que redirige a `/#celebrate`. | No posiciona ni resuelve intención de usuario. | Crear página para celebraciones familiares y privadas. |
| `/pop-up/` | Stub que redirige a `/#popup`. | No hay landing indexable de pop-ups. | Crear página con calendario, menú, FAQ, condiciones y reserva. |
| `/reservar/` | Stub que redirige a `/#fechas`. | Correcto como atajo, flojo como experiencia directa. | Convertirlo en página dedicada al motor de reservas o redirigir 301 al dominio oficial. |
| `/la-finca/` | Stub que redirige a `/#finca`. | Pierde oportunidad local/SEO. | Página de finca: espacio, usos, galería, ubicación, enlace oficial y relación con Perigallo. |
| `/contacto/` | Stub que redirige a `/#contact`. | No hay página de contacto indexable. | Página sencilla con contacto, WhatsApp, formulario breve y selección de motivo. |
| `/solicitud-evento/` | Formulario largo de briefing de boda/evento. | 25 minutos, 46 preguntas, 19 bloques; usa EmailJS y localStorage; no evidencia CRM/backoffice. | Separar lead inicial corto de briefing completo; añadir privacidad, consentimiento y backoffice. |
| `assets/css/styles.css` | CSS externo del diseño anterior/multipágina. | No parece enlazado por la home actual. | Consolidar sistema visual: tokens, botones, forms, cards y layouts. |
| `assets/js/site.js` | JS con endpoints `/api/reservas/pop-up`, `/api/solicitudes/celebraciones`, `/api/contactos`. | No parece cargado por `index.html`; integración potencial queda muerta. | Decidir si se recupera como integración real o se elimina para evitar deuda. |

Páginas clave que faltan para una web comercial:
- `/bodas/` real.
- `/eventos-privados/` o `/celebraciones/` real.
- `/empresas/` real.
- `/experiencias-pop-up/` real.
- `/finca-la-llaguna/` real.
- `/galeria/` o `/experiencias/`.
- `/sobre-perigallo/`.
- `/contacto/` real.
- `/politica-privacidad/` y aviso legal.

## 5. Hallazgos priorizados

### P0

| Ubicación | Problema | Impacto | Recomendación | Archivos probablemente implicados | Riesgo de implementación |
| --- | --- | --- | --- | --- | --- |
| Hero de `/`, CSS líneas 51-61 y captura desktop/mobile | El primer pantallazo puede aparecer solo con imagen y navegación; textos y CTAs dependen de animaciones con `opacity: 0`. | Rompe comprensión y conversión en la primera impresión. Si la animación tarda/falla, el usuario no sabe qué ofrece Perigallo ni qué hacer. | Hacer visible por defecto el H1/subcopy/CTA y animar solo transform/opacidad desde estado accesible o con clase `is-ready`. | `index.html` | Bajo-medio: ajuste CSS, pero hay que validar estética. |
| `#popup`, línea 539 | Evento vencido o incoherente: “Sábado, 17 de mayo”; hoy es 19 de mayo de 2026 y el 17 de mayo de 2026 fue domingo. | Destruye confianza comercial y hace que la reserva parezca abandonada. | No mostrar una fecha fija si no hay fecha futura confirmada. Usar estado dinámico: próximas fechas, lista desde reservas o “próximamente”. | `index.html`, posible backoffice/reservas | Medio: depende de fuente real de fechas. |
| CTAs `Solicitar propuesta` / `Solicitar celebración`, líneas 741, 770, 784 | Apuntan a `#form`, pero no existe ningún elemento con `id="form"`. | Botones críticos de conversión pueden no llevar al formulario real. | Cambiar esos enlaces a `/solicitud-evento/`, al modal existente `data-event-form-open`, o a una landing de solicitud. | `index.html` | Bajo. |
| Footer/legal y formulario | La política de privacidad apunta a `#` y el formulario captura datos personales usando localStorage y EmailJS. | Riesgo de confianza, cumplimiento y pérdida de leads; un usuario premium puede abandonar si no ve garantías. | Crear política real, consentimiento explícito y trazabilidad de tratamiento. Conectar lead a backoffice/CRM en lugar de depender solo de EmailJS. | `index.html`, `solicitud-evento/index.html`, backend/CRM si existe | Medio-alto si se integra backoffice; bajo para enlace legal. |
| Raíz del webroot | Hay zips, backups y referencias antiguas en la raíz: `perigallo-web-redesign.zip`, `perigallo-web-perigallo-restaurado.zip`, `index.original...`, `_reference-web-periallo/`. | Si se despliega la raíz completa, se publican versiones antiguas, assets y posibles datos internos. | Excluir del deploy o mover fuera del webroot. Mantener solo artefactos públicos finales. | raíz del repo, configuración de despliegue | Bajo si solo se limpia deploy; no borrar sin backup. |

### P1

| Ubicación | Problema | Impacto | Recomendación | Archivos probablemente implicados | Riesgo de implementación |
| --- | --- | --- | --- | --- | --- |
| `/bodas/`, `/celebraciones/`, `/pop-up/`, `/reservar/`, `/la-finca/`, `/contacto/` | Son meta-refresh a anchors y usan canonical con fragmento. | No sirven como landings SEO/comerciales ni para campañas. | Crear páginas indexables reales o aplicar 301 fuera del HTML si se decide no tener páginas. | carpetas de rutas | Medio. |
| Head de `index.html` | Faltan canonical absoluto, Open Graph, Twitter cards, schema, favicon/manifest, robots y sitemap. | Baja calidad al compartir, peor indexación y nula estructura local. | Añadir metadatos completos, `Organization`, `LocalBusiness`/`Restaurant`/`EventVenue`, sitemap y robots. | `index.html`, `robots.txt`, `sitemap.xml` | Bajo-medio. |
| Mobile, CSS líneas 337-340 | En móvil se ocultan todos los enlaces de navegación y solo queda logo + Instagram. No hay menú ni CTA reservar/contactar. | Fricción alta; el usuario móvil no tiene caminos claros. | Añadir navegación móvil real: menú, CTA reservar y CTA solicitar propuesta. | `index.html` | Medio por QA responsive. |
| `/solicitud-evento/`, líneas 327-334 | El formulario se presenta como `~25 minutos`, `46 preguntas`, `19 bloques`. | Demasiada fricción para un lead inicial; puede filtrar negativamente solicitudes buenas. | Crear flujo en dos capas: lead inicial corto y briefing completo posterior. | `solicitud-evento/index.html`, `index.html` | Medio. |
| Integración comercial | No hay evidencia de que las solicitudes de celebración entren al backoffice; el formulario envía por EmailJS. | Riesgo de leads fuera del CRM, seguimiento manual y pérdida de trazabilidad. | Reutilizar endpoint/backoffice si existe. Si no, crear endpoint de leads y dejar EmailJS solo como confirmación. | `solicitud-evento/index.html`, `assets/js/site.js`, backend | Alto si toca backend. |
| Marca finca | Se usa `Finca La Laguna` en home/footer y `Finca La Llaguna` en formulario. | Inconsistencia de marca/local SEO y posible confusión. | Confirmar nombre oficial y normalizar todo: copy, URL, schema, alt, headings. | `index.html`, `solicitud-evento/index.html`, rutas SEO | Bajo tras confirmar naming. |
| Visual/imagen | El hero usa imagen artística fuerte, pero no muestra claramente finca, mesa, cocina, servicio o celebración. | Genera atmósfera, pero no prueba la experiencia. | Combinar hero editorial con imágenes reales de mesas/eventos/gastronomía; usar `<picture>` semántico. | `index.html`, assets | Medio por selección de fotografía. |
| Copy de posicionamiento | Se menciona “catering” varias veces. | Puede acercar la marca a proveedor genérico cuando el objetivo es experiencia gastronómica premium. | Sustituir por “producción gastronómica a medida” o “experiencias gastronómicas privadas” cuando no sea SEO táctico. | `index.html`, futuras landings | Bajo. |
| Prueba social | No hay testimonios, clientes, prensa, parejas, eventos reales, galería o cifras. | Falta confianza para bodas/eventos de alto valor. | Añadir prueba real curada: galería, 3 testimonios, logos si procede, casos o “experiencias anteriores”. | home y nuevas landings | Medio por contenido. |

### P2

| Ubicación | Problema | Impacto | Recomendación | Archivos probablemente implicados | Riesgo de implementación |
| --- | --- | --- | --- | --- | --- |
| H1/H2 con `<br>` | El `textContent` queda concatenado: “Reserva tumesa”, “Cuatro formasde vivir Perigallo”. | Lectura accesible y SEO menos limpios. | Añadir espacios semánticos, `aria-label` o estructura sin concatenación. | `index.html` | Bajo. |
| `index.html` completo | CSS y JS inline enormes; `assets/css/styles.css` y `assets/js/site.js` existen pero no parecen usados. | Deuda técnica, inconsistencias, difícil mantener sistema visual. | Consolidar CSS/JS en archivos versionados o componentes, manteniendo tokens. | `index.html`, `assets/css/styles.css`, `assets/js/site.js` | Medio. |
| Performance/assets | `index.html` pesa aprox. 560 KB y contiene múltiples `data:image`; hay imágenes embebidas en CSS. | Peor caché, peor carga, sin `srcset`, sin alt semántico. | Extraer imágenes a assets, crear WebP/AVIF, lazy loading y preload selectivo del hero. | `index.html`, `assets/images/` | Medio. |
| Accesibilidad formulario | Muchas cards son `div onclick`, labels sin `for`, controles no nativos. | Navegación por teclado y lectores de pantalla deficientes. | Usar botones, radios/checkboxes reales, `fieldset`, `legend`, `aria-pressed` y focus states. | `solicitud-evento/index.html` | Medio-alto por formulario largo. |
| Modales | Hay `role="dialog"` y `aria-modal`, pero no focus trap/inert del fondo. | Usuarios de teclado pueden perderse; riesgo accesible. | Añadir focus trap, restaurar foco, `aria-labelledby` y bloqueo de tab fuera del modal. | `index.html` | Medio. |
| Booking | El iframe de reservas mide 660 px en mobile y la sección de reservas alcanza aprox. 2.502 px. | Mucho scroll antes de completar una tarea de alta intención. | En móvil, priorizar CTA sticky/fullscreen y reducir narrativa repetida alrededor del iframe. | `index.html` | Medio. |
| Enlazado interno | Nav usa anchors y rutas stub; no hay estructura de enlaces por intención. | SEO y orientación comercial débiles. | Enlazar a páginas reales por servicio cuando existan. | `index.html`, rutas | Medio. |
| WhatsApp | Hay teléfono pero no CTA visible de WhatsApp. | Se pierde un canal comercial habitual para eventos. | Añadir WhatsApp como CTA secundario en contacto y mobile, respetando privacidad/consentimiento. | `index.html` | Bajo. |
| Cursor custom | `body{cursor:none}` y cursor JS. | Puede reducir usabilidad y accesibilidad; si JS falla, la experiencia se degrada. | Usar cursor custom solo en desktop con fallback y no ocultar cursor en elementos operativos. | `index.html` | Bajo. |

### P3

| Ubicación | Problema | Impacto | Recomendación | Archivos probablemente implicados | Riesgo de implementación |
| --- | --- | --- | --- | --- | --- |
| Copy de hero | “Gastronomía, pop-ups y celebraciones diseñadas con intención” es correcto pero poco concreto. | Menos claridad en 5 segundos. | Incluir lugar, servicios y beneficio emocional/operativo en el primer bloque. | `index.html` | Bajo. |
| Botones | Algunos CTAs tienen pesos similares para intenciones distintas. | Jerarquía de acción mejorable. | Diferenciar reserva inmediata vs propuesta a medida con peso visual y texto. | `index.html` | Bajo. |
| Formulario | Tono muy cálido, pero algunos emojis/cards lo acercan a cuestionario informal. | Puede restar percepción premium. | Sustituir por iconografía lineal o texto más sobrio. | `solicitud-evento/index.html` | Bajo-medio. |
| Sharing social | Sin OG image ni título específico para compartir. | Al compartir se verá genérico o pobre. | Añadir imagen social editorial de Perigallo y copies por página. | `index.html`, futuras rutas | Bajo. |

## 6. Auditoría visual

Primera impresión:
- La atmósfera es elegante y reconocible: verde petróleo, marfil, dorado suave, tipografía serif editorial y ritmo lento.
- La captura inicial en desktop mostró una imagen potente pero sin texto ni CTA visible en el primer pantallazo por el uso de animaciones. Esto convierte una decisión estética en un riesgo comercial.
- En móvil ocurre algo similar: el usuario ve logo, Instagram e imagen, pero no propuesta ni acción clara.

Hero:
- Visualmente tiene personalidad, pero el recurso de flor/objeto gastronómico es más simbólico que comercial.
- Para una web de captación, debería aparecer antes una promesa concreta: qué hace Perigallo, dónde, para quién y qué puede pedir el usuario.
- Recomendación: mantener la sensibilidad editorial, pero combinarla con una foto real o semirreal de mesa, finca, emplatado o celebración.

Tipografía:
- La combinación Montserrat + Cormorant Garamond aporta tono editorial.
- Hay buena intención en titulares grandes y aire visual.
- Problema: el uso de `<br>` genera textos concatenados para lectura semántica. También hay textos muy pequeños con letter-spacing alto que pueden costar en móvil.

Paleta:
- La paleta encaja con Perigallo: verde petróleo, dorado suave, marfil.
- No se percibe genérica SaaS.
- Riesgo: tanto bloque oscuro seguido puede hacer que la web sea densa. Conviene alternar con marfil/off-white en secciones de confianza, galería o proceso.

Composición y ritmo:
- La home tiene secciones interesantes, pero el relato no está completamente ordenado por intención de usuario.
- La sección de reservas tiene una composición más ambiciosa, pero en desktop aparece muy grande y en móvil alarga demasiado la tarea.
- El bloque de finca es correcto como atmósfera, pero necesita más relato y prueba visual real.

Imágenes:
- Muchas imágenes están embebidas como base64 en estilos y no como `<img>`/`picture`. Esto impide alt real, caché independiente y carga responsive.
- La imagen de finca sí usa `assets/images/finca-la-llaguna-principal.jpg` como background con `role="img"` y `aria-label`, pero sigue sin ser imagen semántica para SEO.

Sensación premium:
- La web tiene base premium, pero se debilita por fechas vencidas, rutas vacías, legal incompleto, navegación móvil insuficiente y falta de pruebas reales.
- Una marca luxury puede ser minimalista, pero no puede parecer incompleta ni operativamente frágil.

## 7. Auditoría UX/comercial

Qué entiende un cliente al entrar:
- Entiende que Perigallo tiene relación con gastronomía, pop-ups y celebraciones.
- No entiende de forma inmediata si es principalmente restaurante pop-up, bodas, eventos privados, empresa o catering gastronómico.
- No ve suficiente prueba de que Perigallo haga bodas o eventos de empresa con solvencia.
- La ubicación aparece, pero debería estar antes y con mayor claridad: Crevillent, Alicante, Finca La Llaguna/La Laguna, radio de trabajo.

CTAs:
- `Ver pop-ups` y `Celebrar con Perigallo` son correctos como dos caminos.
- `Reservar` está en desktop, pero no en navegación móvil.
- Varios CTAs de celebración apuntan a `#form`, ancla inexistente.
- `Abrir formulario` sí existe en contacto y abre `/solicitud-evento/`.

Recorrido de cliente:
- Para pop-up: el usuario puede llegar al bloque de reservas y el iframe oficial carga desde `reservas.perigallo.com`.
- Para bodas/eventos: el usuario cae en un briefing largo, no en un lead inicial amable.
- Para empresa/corporativo: aparece una tarjeta, pero no hay página ni flujo propio.
- Para finca: hay enlace externo operativo a `https://fincalallaguna.com/`, pero no hay una página local Perigallo sobre el espacio.

Navegación:
- Desktop: suficiente, aunque faltan “Bodas” y “Empresas” si se quieren captar esas intenciones.
- Mobile: insuficiente. La navegación de servicios se oculta y no hay hamburguesa ni CTA visible.

Footer:
- Tiene servicios, empresa y contacto.
- Problema serio: `Política de privacidad` apunta a `#`.
- “Catering a medida” aparece como servicio, lo que puede ser útil para SEO pero debe tratarse con tono premium.

Operatividad comercial:
- Reservas pop-up: el enlace directo y el iframe responden con HTTP 200 en la auditoría.
- Finca: el enlace externo responde con HTTP 200.
- Instagram: el enlace responde con HTTP 200.
- Solicitudes de celebración: el código evidencia EmailJS y localStorage, pero no entrada en backoffice/CRM.

## 8. Auditoría de copywriting

| Texto actual | Problema | Texto recomendado | Motivo |
| --- | --- | --- | --- |
| “Gastronomía, pop-ups y celebraciones diseñadas con intención” | Bonito, pero poco concreto para primera visita. | “Pop-ups de autor, bodas y eventos privados en torno a una gastronomía diseñada para celebrarse.” | Explica servicios y posicionamiento sin perder tono. |
| H1 “Perigallo” | Marca visible, pero sola no explica propuesta. | Mantener “Perigallo” y añadir subheadline fuerte: “Gastronomía para celebrar lo irrepetible.” | La marca manda, pero la promesa debe aparecer en el primer golpe. |
| “Perigallo no es un catering. Tampoco un restaurante al uso...” | Buen punto de vista, pero podría ser más directo. | “No hacemos catering convencional. Diseñamos encuentros gastronómicos: cocina, relato, servicio y puesta en escena para pop-ups, bodas y celebraciones privadas.” | Diferencia sin quedarse solo en negación. |
| “Cuatro formas de vivir Perigallo” | Correcto, pero puede sonar interno. | “Elige cómo vivir Perigallo” | Más orientado al usuario. |
| “Catering a medida” | Puede bajar percepción premium. | “Producción gastronómica a medida” | Mantiene servicio sin parecer proveedor genérico. |
| “Restaurante pop-up · Próximo evento” | Con fecha vencida genera desconfianza. | Si no hay fecha: “Próximas fechas por anunciar.” | Evita prometer una reserva caducada. |
| “Sábado, 17 de mayo” | Vencido/incoherente. | Fecha real futura desde el motor o “Nueva fecha próximamente”. | Debe estar sincronizado con operación real. |
| “Reserva tu mesa” | Bien. | “Elige tu noche. Asegura tu mesa.” | Más experiencial sin perder claridad. |
| “Elige fecha. Elige momento. Nosotros nos encargamos del resto.” | Buen tono. | Mantener, añadiendo “con disponibilidad real de Perigallo Reservas”. | Refuerza confianza operativa. |
| “Celebraciones diseñadas por Perigallo” | Correcto pero amplio. | “Bodas y celebraciones privadas diseñadas desde vuestra historia.” | Conecta con intención principal y emoción. |
| “Solicitar propuesta” | Correcto, pero debe funcionar. | “Contar mi celebración” o “Solicitar propuesta privada”. | Más humano y específico. |
| “Cuéntanos vuestra historia” | Muy cálido, pero abre un formulario largo. | “Primero, lo esencial.” | Reduce presión inicial. |
| “~25 minutos · 46 preguntas · 19 bloques” | Frena al lead. | “2 minutos para orientar la propuesta” en el primer formulario; briefing completo después. | Reduce fricción y mantiene profundidad cuando ya hay interés. |
| “Perigallo desarrolla experiencias gastronómicas, pop-ups, catering...” | “Catering” vuelve a diluir. | “Perigallo diseña experiencias gastronómicas, pop-ups, bodas y celebraciones privadas.” | Coherencia de marca. |
| “Finca La Laguna” / “Finca La Llaguna” | Inconsistencia. | Usar el nombre oficial confirmado en toda la web. | Confianza, SEO local y marca. |

Propuesta de hero recomendado:

```text
Eyebrow:
Finca La Llaguna · Crevillent, Alicante

H1:
Perigallo

Subheadline:
Gastronomía para celebrar lo irrepetible

Subcopy:
Pop-ups de autor, bodas y eventos privados donde el menú, la puesta en escena y el ritmo del día se diseñan como una misma experiencia.

CTA principal:
Reservar pop-up

CTA secundario:
Diseñar una celebración
```

## 9. Auditoría responsive

Validaciones realizadas:
- Desktop: 1440 x 1000.
- Tablet: 768 x 1024.
- Mobile: 390 x 844.
- Consola: sin errores ni warnings capturados durante la revisión.
- No se detectó scroll horizontal global: `scrollWidth` coincidió con `clientWidth` en los tres tamaños.
- Capturas revisadas en Browser: desktop hero, desktop reservas, mobile hero, mobile contacto/anchor.

Desktop:
- El hero puede aparecer sin texto/CTA visible en la captura inicial.
- La sección de reservas ocupa aprox. 1.609 px de alto; es visualmente ambiciosa pero puede sentirse pesada.
- Las rutas por anchor funcionan, pero la navegación no sustituye landings reales.

Tablet:
- La página crece hasta aprox. 13.335 px de alto.
- La reserva pasa a un bloque muy largo; conviene compactar narrativa y priorizar acción.
- La jerarquía mantiene estética, pero algunos textos pequeños con tracking alto pierden legibilidad.

Mobile:
- Problema principal: no hay navegación de servicios ni CTA reservar/contactar visible; solo logo e Instagram.
- El hero puede aparecer sin texto en primer pantallazo.
- La página llega a aprox. 14.708 px de alto.
- El iframe de reservas mide aprox. 311 x 660 px y la sección de reservas alcanza aprox. 2.502 px; es demasiado para una acción directa.
- El formulario de solicitud ocupa pantalla completa con navegación inferior; puede ser elegante, pero su longitud declarada genera fricción.

Recomendaciones mobile:
- Añadir menú móvil con “Pop-ups”, “Bodas”, “Eventos”, “Finca”, “Contacto”.
- Añadir CTA sticky o visible: “Reservar” y “Solicitar propuesta”.
- Mostrar texto y CTA del hero sin depender de animación.
- Abrir reserva en modal/fullscreen mobile de forma prioritaria.
- Cambiar lead de celebración por formulario corto antes del briefing largo.

## 10. Auditoría SEO

Estado actual:
- Home con title y meta description básicos.
- Rutas de servicio como meta-refresh, no landings indexables.
- No se detectan `robots.txt` ni `sitemap.xml`.
- No se detectan schema.org, Open Graph ni Twitter cards.
- No hay canonical absoluto en home.
- Las rutas stub usan canonical con fragmentos (`/#celebrate`, `/#popup`, etc.).
- Muchas imágenes no son semánticas, sino backgrounds/base64.

Oportunidades por búsqueda:
- `catering en Alicante` y `catering en Elche`: trabajar con cuidado, sin convertir la marca en catering genérico.
- `catering para bodas Alicante` / `Elche`: página de bodas con gastronomía, finca, proceso y solicitud.
- `bodas en finca Alicante`: landing específica con Finca La Llaguna si es oficial.
- `eventos gastronómicos Alicante`: página de experiencias privadas y empresas.
- `experiencias gastronómicas privadas`: página editorial con ejemplos.
- `pop-up gastronómico`: página pop-up con calendario y FAQ.
- `finca para bodas en Elche` / `Crevillent`: página local con ubicación y enlace a finca.
- `Perigallo`: reforzar marca, Organization schema, perfiles sociales y favicon.

Problemas técnicos SEO:
- Anchors no sustituyen URLs indexables.
- Falta contenido específico por intención.
- Falta enlazado interno entre servicios.
- Falta schema para negocio local, organización, eventos y formularios/contacto.
- Falta imagen social.
- Falta sitemap y robots.
- Falta página legal.

Recomendaciones SEO:
- Crear landings reales: `/bodas/`, `/eventos-privados/`, `/empresas/`, `/pop-up/`, `/finca-la-llaguna/`, `/contacto/`.
- Añadir `title` y `description` únicos por ruta.
- Añadir `Organization` y `LocalBusiness`/`Restaurant`/`FoodEstablishment` según encaje legal real.
- Añadir `Event` schema solo para pop-ups con fecha real futura.
- Usar imágenes reales con alt descriptivo.
- Crear `sitemap.xml` y `robots.txt`.
- Añadir canonical absoluto.

Calidad frontend relacionada:
- No hay pipeline de build/lint/test detectado.
- `index.html` es monolítico y grande.
- `assets/js/site.js` contiene endpoints de integración, pero no está cargado por la home actual.
- `assets/css/styles.css` parece legado/no central.
- Recomendable una refactorización ligera a sistema de diseño estático antes de crecer páginas.

## 11. Auditoría de conversión

Qué ayuda:
- La marca tiene personalidad visual.
- El motor de reservas oficial está integrado y los enlaces externos responden.
- Hay email y teléfono visibles.
- Hay dos caminos conceptuales: pop-up y celebraciones.
- El relato gastronómico tiene momentos memorables, especialmente “No servimos platos. Contamos una escena.”

Qué frena:
- Primer pantallazo sin texto/CTA visible por animación.
- Fecha del pop-up vencida o incoherente.
- Botones de celebración a `#form` sin destino real.
- Formulario inicial demasiado largo para captación.
- Falta prueba social real.
- Falta galería de eventos/mesas/personas.
- Falta página específica para bodas, empresas y eventos privados.
- Falta WhatsApp como canal rápido.
- Falta privacidad real.
- Falta explicar proceso comercial: qué ocurre después de solicitar propuesta.

Objeciones no resueltas:
- ¿Hacéis bodas completas o solo gastronomía?
- ¿Trabajáis fuera de la finca?
- ¿Cuál es el rango de invitados?
- ¿Qué incluye la propuesta?
- ¿Cómo es el proceso comercial?
- ¿Hay menús, degustaciones o referencias?
- ¿Qué disponibilidad hay realmente?
- ¿Qué pasa si el pop-up está completo?
- ¿Dónde está exactamente la finca?

Mejoras de conversión recomendadas:
- Hero con promesa + 2 CTAs claros.
- Pop-up con fecha real o estado “próximamente”.
- Lead corto de celebraciones con 6-8 campos.
- WhatsApp y llamada visible en contacto.
- Galería/portfolio breve.
- Testimonios reales.
- Página “Bodas” con proceso y confianza.
- Microcopy de post-envío: “Te respondemos en menos de 48 horas”.

## 12. Propuesta de arquitectura ideal de la web

| Ruta | Objetivo | Contenido recomendado | CTA principal |
| --- | --- | --- | --- |
| `/` | Portada de marca y distribuidor. | Hero claro, dos caminos principales, manifiesto, servicios, prueba visual, pop-up destacado, celebraciones, finca, confianza, contacto. | `Reservar pop-up` y `Diseñar una celebración`. |
| `/bodas/` | Captar parejas. | Hero emocional, propuesta diferencial, finca, gastronomía, proceso, galería, testimonios, FAQ, lead corto. | `Solicitar propuesta de boda`. |
| `/eventos-privados/` | Captar celebraciones familiares y privadas. | Cumpleaños, aniversarios, comuniones, bautizos, cenas privadas, gastronomía a medida, espacios. | `Contar mi evento`. |
| `/empresas/` | Captar eventos corporativos. | Cenas, presentaciones, experiencias para equipos, marca, logística, producción gastronómica. | `Solicitar propuesta para empresa`. |
| `/experiencias-pop-up/` o `/pop-up/` | Reserva directa de experiencias abiertas. | Calendario real, menús, horarios, plazas, condiciones, FAQ, ubicación. | `Reservar mesa`. |
| `/finca-la-llaguna/` | Posicionar el espacio y derivar a finca oficial. | Relato del lugar, fotografías, usos Perigallo, ubicación, enlace a web oficial. | `Ver la finca` / `Celebrar aquí`. |
| `/sobre-perigallo/` | Construir confianza y marca. | Equipo, filosofía, cocina, forma de trabajar, valores. | `Hablar con Perigallo`. |
| `/galeria/` o `/experiencias/` | Prueba visual y deseo. | Eventos reales, mesas, pop-ups, cocina, detalles, finca. | `Diseñar una experiencia`. |
| `/contacto/` | Convertir solicitudes. | Selección de motivo, lead corto, WhatsApp, email, teléfono, ubicación, privacidad. | `Enviar solicitud`. |
| `/politica-privacidad/` | Cumplimiento y confianza. | Tratamiento de datos, responsable, finalidad, derechos, terceros. | No aplica. |

Arquitectura de navegación recomendada:
- Pop-ups
- Bodas
- Eventos
- Empresas
- La Finca
- Contacto
- CTA destacado: Reservar

En móvil:
- CTA fijo o visible: `Reservar`.
- Menú con servicios.
- Acceso a WhatsApp/contacto.

## 13. Roadmap de implementación

Fase 1: quick wins sin riesgo
- Corregir fecha de pop-up o mostrar “próximas fechas por anunciar”.
- Arreglar CTAs `#form`.
- Añadir política de privacidad real o retirar enlace hasta tenerla.
- Añadir copy de fallback si no hay fechas.
- Añadir CTA móvil básico a reservar/contactar.
- Normalizar naming `Finca La Laguna` vs `Finca La Llaguna` tras confirmación.
- Añadir metadatos básicos: canonical, OG title/description/image, favicon.
- Excluir zips/backups del deploy.

Fase 2: mejora visual premium
- Revisar hero para que texto y CTA estén visibles desde el primer render.
- Sustituir/combinar imagen abstracta con imagen real de experiencia.
- Crear sistema visual compartido: tokens, botones, cards, formularios, nav, footer.
- Extraer imágenes base64 a assets optimizados.
- Añadir galería o bloque de prueba visual real.
- Ajustar ritmo con secciones marfil/off-white para aire y contraste.

Fase 3: conversión y formularios
- Crear lead inicial corto para bodas/eventos.
- Mantener briefing largo como segundo paso.
- Conectar solicitudes a backoffice/CRM si existe.
- Añadir confirmación clara y seguimiento comercial.
- Añadir WhatsApp con mensaje prellenado.
- Revisar consentimientos y privacidad.
- Pulir modal de reserva y fallback.

Fase 4: SEO y contenido
- Crear landings reales para bodas, eventos privados, empresas, pop-up, finca y contacto.
- Añadir titles/descriptions únicos.
- Añadir schema.org.
- Crear sitemap y robots.
- Añadir contenido local natural: Alicante, Elche, Crevillent, Finca La Llaguna.
- Añadir enlazado interno y breadcrumbs si procede.

Fase 5: optimización final
- QA responsive en 1440, 1024, 768, 430, 390, 360.
- Lighthouse/Core Web Vitals.
- Axe/accessibility.
- Test de teclado en modales y formulario.
- Validar reservas reales en entorno seguro.
- Validar creación de lead en backoffice.
- Revisar performance de imágenes y fuentes.

## 14. Checklist de validación antes de tocar código

- Confirmar nombre oficial: `Finca La Laguna` o `Finca La Llaguna`.
- Confirmar si `Perigallo` quiere posicionar “catering” o evitarlo salvo SEO táctico.
- Confirmar fecha real de próximos pop-ups.
- Confirmar si existe endpoint/backoffice para leads de celebraciones.
- Confirmar si EmailJS debe mantenerse o sustituirse.
- Confirmar texto legal y responsable de tratamiento de datos.
- Revisar que no se publiquen zips/backups en Plesk.
- Hacer copia antes de modificar `index.html` por su tamaño y base64.
- Definir jerarquía de CTAs: reserva inmediata vs solicitud a medida.
- Definir landings prioritarias: `bodas` y `pop-up` primero.
- Definir fotografía principal real para hero y galería.
- Validar mobile nav antes de publicar.
- Probar enlaces externos: reservas, finca, Instagram.
- Probar modal de reserva y formulario en desktop/móvil.
- Probar teclado/focus en modales.
- Validar sitemap/robots/schema con herramientas externas.

## 15. Plan de implementación recomendado

Primero haría una fase de estabilización comercial sin rediseñar toda la web:
1. Corregir fecha vencida, CTAs rotos, privacidad y navegación móvil.
2. Asegurar que el hero siempre muestra propuesta y CTA.
3. Confirmar naming oficial de la finca.
4. Revisar que el formulario de celebración no sea la única puerta de entrada.

Después abordaría la mejora premium:
1. Hero con fotografía real y copy más claro.
2. Dos caminos muy marcados: `Reservar pop-up` y `Solicitar celebración`.
3. Sistema visual unificado y extracción de imágenes base64.
4. Galería/testimonios/proceso para confianza.

Después crearía landings:
1. `/bodas/` y `/pop-up/` como prioridad.
2. `/eventos-privados/`, `/empresas/`, `/finca-la-llaguna/` y `/contacto/`.
3. SEO técnico y schema.

Qué evitaría tocar al principio:
- No cambiaría el motor oficial de reservas sin validar flujo real con backoffice.
- No eliminaría el formulario largo; lo convertiría en segundo paso.
- No haría un rediseño total antes de resolver los P0.
- No borraría backups sin confirmar estrategia de archivo; sí los excluiría del webroot/deploy.
- No cambiaría “catering” de todo el sitio sin decidir antes si se quiere captar esa keyword.

Validaciones realizadas en esta auditoría:
- Servidor local abierto en `http://127.0.0.1:8000/`.
- HTTP 200 en `/`, `/pop-up/`, `/bodas/` y `/solicitud-evento/`.
- HTTP 200 externo en `https://reservas.perigallo.com/reservar?source=web`.
- HTTP 200 externo en `https://fincalallaguna.com/`.
- HTTP 200 externo en `https://www.instagram.com/perigallo/`.
- Revisión browser desktop/tablet/mobile.
- Revisión de consola sin errores/warnings.
- Revisión de DOM: headings, iframes, links, CTAs y anchors.
- No se ejecutó build/lint/test porque no existe pipeline detectado.

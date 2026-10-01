# Estándar de mensajería Maya (v1)

Norma única para todo lo que pasa por RabbitMQ en el ecosistema Maya: apps actuales y futuras,
servicios de plataforma (n8n, Odoo…) y los tres entornos (desarrollo local con `maya_infra`, K3s de
desarrollo y producción). Si algo no está aquí, no existe en el broker.

| Pieza | Dónde | Papel |
|---|---|---|
| **Catálogo** | [`topology.yaml`](topology.yaml) | Fuente de verdad: exchanges, colas, consumidores y servicios |
| Generador | [`tools/gen_definitions.py`](tools/gen_definitions.py) | Topología de RabbitMQ para cualquier entorno, desde el catálogo |
| Comprobación de apps | [`tools/check_app.py`](tools/check_app.py) | Paso obligatorio del CI de cada app (`build-app.yml`) |
| Cliente Laravel | `packages/php/shared-messaging-laravel` | Única forma de publicar y consumir desde una app Laravel |
| Contrato de mensajes | `DOCUMENTATION/…/servicios/rabbitmq-contracts.md` | Payloads y claves campo a campo |

El catálogo y el paquete se vigilan entre sí: si una cola o un exchange está en uno y no en el otro,
falla el test `MessagingStandardTest` del paquete. El catálogo se valida en `messaging.yml`.

## 1. Principios

1. **Un solo cliente.** Las apps Laravel publican y consumen solo con `shared-messaging-laravel`
   (`LogPublisher`, `NotificationPublisher`, `AuditPublisher`, canal de log `rabbit`, comandos
   `ConsumeQueueCommand`). Nada de `php-amqplib` directo. Otros lenguajes siguen el contrato.
2. **Las apps no declaran nada.** Exchanges, colas, bindings y políticas los crea la
   infraestructura desde el catálogo (`configure=^$` para todos los servicios). Si falta la
   topología, el error salta en el despliegue, no en producción a media noche.
3. **Una cola, un consumidor.** Cada cola tiene un único servicio que la lee. Para que otro
   servicio reciba los mismos mensajes se crea **otra cola** con su binding, no se comparte.
4. **Todo lo compartido, compartido de verdad.** Todas las apps escriben en los mismos exchanges
   con las mismas claves: `maya.logs` lo lee maya_logs, `maya.notifications` maya_dashboard (y n8n el
   canal email), `maya.audit` maya_audit. Una app nueva no crea su propio «log» ni su propia
   «auditoría».
5. **Ningún mensaje se pierde en silencio.** Cada cola tiene su DLQ y su política DLX con
   `dead-letter-routing-key`.
6. **RabbitMQ no es la cola de trabajos de Laravel.** Los jobs internos de cada app van a Redis
   (`QUEUE_CONNECTION=redis`); RabbitMQ es solo para mensajes entre servicios.

## 2. Nombres

| Elemento | Regla | Ejemplos |
|---|---|---|
| Vhost de las apps | `/maya` en los clústeres; en `maya_infra`, uno por slot (`/<slot>`) con la misma topología | `/maya` |
| Exchange | `maya.<dominio>`, minúsculas | `maya.logs`, `maya.notifications`, `maya.audit` |
| Cola | `<dominio>.<propósito>`, minúsculas | `logs.ingest`, `notifications.email` |
| DLQ | `dlq.<cola>` (la genera el generador) | `dlq.logs.ingest` |
| DLX | `maya.dlx`, único, `direct`, clave = nombre de la cola | — |
| Política | `maya-dlx-<cola>`, prioridad 20 | `maya-dlx-audit.ingest` |
| Identificador de app | `maya-<slug>` = `MAYA_MESSAGING_APP` = `applications.slug` = nombre del chart; slug `[a-z][a-z0-9]*` (sin `-` ni `_`: también da nombre a la BD y al namespace) | `maya-dms` |
| Servicio de plataforma | minúsculas, dígitos y guiones | `n8n`, `odoo` |
| Usuario RabbitMQ | identificador con `-` → `_` | `maya_dms`, `n8n` |
| Contraseña (variable) | `RABBITMQ_PASSWORD_<SUFIJO>`; sufijo = id sin `maya-`, en mayúsculas | `RABBITMQ_PASSWORD_DMS` |

## 3. Claves de enrutado

El primer segmento identifica siempre al emisor. Los segmentos no llevan espacios ni mayúsculas.

| Exchange | Clave | Ejemplo |
|---|---|---|
| `maya.logs` | `<app>.<severity>` (`critical, high, medium, low, other`) | `maya-dms.high` |
| `maya.notifications` | `<app>.<type>.<channel>` y `.critical` al final si la severidad es `critical` o `high` | `maya-dms.document.approved.email.critical` |
| `maya.audit` | `<app>.<entity_type>.<action>` | `maya-dms.document.updated` |

`<type>` puede llevar puntos (`document.approved`). Por eso los bindings usan `#` y `#.email`
(+ `#.email.critical`), nunca `*.*.*`.

## 4. Conexión de una app

| Variable | Clúster (chart) | Desarrollo local (`.env.example`) |
|---|---|---|
| `MAYA_MESSAGING_APP` | `maya-<nombre>` | `maya-<nombre>` |
| `RABBITMQ_HOST` / `RABBITMQ_PORT` | `rabbitmq-maya-app.maya-sync.svc.cluster.local` / `5672` | `maya_rabbitmq` / `5672` |
| `RABBITMQ_USER` | `maya_<nombre>` | `maya_<nombre>` |
| `RABBITMQ_PASSWORD` | Vault (`vault.keys`) | `.env` del slot |
| `RABBITMQ_VHOST` | `/maya` | `/${MAYA_SLOT_DB}` |
| `LOG_STACK` | `stderr,rabbit` (`stderr` si consume `logs.ingest`) | `daily,rabbit` (`daily` si consume `logs.ingest`) |
| `QUEUE_CONNECTION` | `redis` | `redis` (ver § 8) |

Permisos que recibe cada servicio (los deriva el generador):
- `configure=^$`;
- `write` = los exchanges compartidos (apps) o su lista `publishes` (servicios);
- `read` = solo las colas que consume.

## 5. Mensajes

- Propiedades AMQP: `content_type=application/json`, `delivery_mode=2`, `message_id` (UUID),
  `timestamp` (segundos), `app_id`, cabecera `x-maya-schema-version: 1`. Las pone el paquete.
- Consumidor idempotente por `message_id` (Redis, 24 h). Una notificación multicanal comparte
  `message_id` en todas sus copias.
- Reintentos: `nack(requeue)` hasta 3 veces; después, DLQ.
- Payloads: contrato en `rabbitmq-contracts.md`. Un cambio incompatible sube
  `x-maya-schema-version` y convive con la versión anterior mientras haya consumidores antiguos.

## 6. Cómo crecer

**App nueva** (desde `maya_template`):
1. Entrada en `topology.yaml` → `services: maya-<nombre>: {kind: app}`.
2. `.env.example` y chart con las variables del § 4 (la plantilla ya las trae).
3. Generar y aplicar la topología en cada entorno (§ 7). Con eso ya publica logs, notificaciones y
   auditoría; `check_app.py` lo comprueba en su CI.

**Una app empieza a consumir algo:**
1. Nueva cola en `topology.yaml` (`<dominio>.<propósito>`, sus bindings y `consumer`).
2. La misma clave en `packages/php/shared-messaging-laravel/config/messaging.php` → `queues`.
3. Comando que extiende `ConsumeQueueCommand` y lee la cola de `config('messaging.queues.<clave>')`.

**Nuevo dominio (exchange):**
1. `maya.<dominio>` en `topology.yaml` (con `apps_publish` si lo usan todas las apps).
2. Su publisher en el paquete y su clave en `config/messaging.php` → `exchanges`.
3. Su contrato en `rabbitmq-contracts.md`.

`maya.alerts` (`AlertPublisher`) existe en el paquete pero no en el catálogo: entra el día que una
app publique alertas.

**Servicio de plataforma** (n8n, Odoo…): entrada `kind: service` con su lista `publishes` y las
colas que consume.

## 7. Aplicar la topología

| Entorno | Cómo |
|---|---|
| Desarrollo local (`maya_infra`) | `python3 scripts/sync-messaging-standard.py` regenera `docker/rabbitmq/{definitions.json,topology.vhost.json,permissions.tsv}`; `ensure-slot-resources.sh` los aplica a cada slot (idempotente) |
| K3s de desarrollo (`IaC/dev`) | `bootstrap.sh` (usa `gen_definitions.py --passwords env --admin`) |
| Producción (`IaC/prod`, F2) | `gen_definitions.py --passwords vault` → bloque del vhost `/maya` en `values/rabbitmq/definitions.json` (plantilla de Vault con `RABBITMQ_PASSWORD_<SUFIJO>` en `secret/data/rabbitmq`) |

Las definiciones se importan al arrancar RabbitMQ (`load_definitions`) o por la API. La
importación solo añade: no borra ni recrea colas existentes, así que aplicarla es seguro en un
broker en marcha. Retirar algo del catálogo exige borrarlo a mano en el broker.

## 8. Excepciones conocidas (con fecha de salida)

| Excepción | Por qué | Hasta |
|---|---|---|
| `.env.example` de dashboard, dms, audit, logs y plantilla con `QUEUE_CONNECTION` distinto de `redis`; workers locales sobre la cola `database` | En 0.20, `RetryAmqpPublishJob` encola en `database` y en local un worker la drena | Publicar `shared-messaging-laravel` 0.22 (reintentos en la cola por defecto) y pasar los `.env.example` a `redis` |
| `maya_infra` sin usuario `n8n` | n8n local usa su credencial actual | Alta del usuario `n8n` en `maya_infra` |
| Vhost `/` de producción con `maya.alerts`, `maya.keycloak`, `notifications.internal.email` | Plataforma previa al estándar (Keycloak → user-worker, n8n) | F2: apps en `/maya`; `notifications.internal.email` (sin binding ni consumidor) se retira |
| Versiones distintas de RabbitMQ (local 4.2.5, clústeres 3.13.7) | `maya_infra` se actualizó por su cuenta | Decidir versión común (ver informe de alineación) |

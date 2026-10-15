# maya-common — library chart de las apps Maya

Despliega en k3s la topología estándar de una app Laravel + React de Maya a
partir de sus **4 imágenes** (`<app>-api`, `<app>-worker`, `<app>-reverb`,
`<app>-frontend`, ver `maya_platform/docker/README.md`):

| Recurso | Imagen / args | Notas |
|---|---|---|
| Deployment `api` | `*-api` `["api"]` | nginx + php-fpm en 8080; probes `/healthz` (nginx) y `/api/v1/health/ready` |
| Deployment `worker` | `*-worker` (args opcionales) | sin args ejecuta `MAYA_WORKER_CMD` de la imagen; liveness `pgrep -f "php artisan"` |
| Deployment `scheduler` | `*-worker` `["scheduler"]` | 1 réplica; `scheduler.enabled` |
| Deployment `reverb` | `*-reverb` `["reverb"]` | WebSocket en 8080; `REVERB_HOST` de los backends = Service interno |
| Deployment `frontend` | `*-frontend` | nginx uid 101; ConfigMap propio con `MAYA_PUBLIC_*` (→ `/config.js`) y `MAYA_CSP` |
| Job `views` | `*-worker` `["artisan", "maya:views"]` | `views.enabled`. Vistas de lectura de la app en `maya_apps` de `maya_erp` (D17), como el rol `maya_apps_views`; hook post-install/pre-upgrade antes de `migrate` (peso -10), con su NetworkPolicy (hook) hacia el PostgreSQL de Odoo. Único pod con `views.passwordKey` |
| Job `migrate` | `*-worker` `["migrate"]` | hook post-install/pre-upgrade (la SA y el PVC existen ya en el primer install); configuración inline. Sin `helm install --wait`: esperar con `kubectl rollout status` |
| ConfigMap, Services, Ingress ×2, PVC, PDB ×2, NetworkPolicy, ServiceAccount | | |

Es un chart de tipo `library`: no se instala solo. Cada app lo declara como
dependencia y renderiza todo con una línea:

```yaml
# deploy/helm/Chart.yaml
apiVersion: v2
name: maya-dms
type: application
version: 0.0.0-dev
dependencies:
  - name: maya-common
    version: 0.3.0
    repository: oci://ghcr.io/maya-aqss/charts
```

```yaml
# deploy/helm/templates/all.yaml
{{ include "maya-common.all" . }}
```

`maya-common.all` fusiona los defaults de este chart (`values.yaml`) con los de la
app, así el `values.yaml` de cada app solo contiene lo que cambia (imagen,
`config`, hosts, claves de Vault, política de red...).

## Contrato de values (resumen)

| Clave | Qué es |
|---|---|
| `image.registry`, `image.repository`, `image.tag` | `<registry>/<repository>-<componente>:<tag>`. `tag` es obligatorio (misma versión que el chart). `<componente>.imageTag` lo cambia para un componente (frontend de desarrollo). |
| `config` | Variables no sensibles → ConfigMap (envFrom de todos los backends). `APP_ENV`, `APP_DEBUG` y `SESSION_SECURE_COOKIE` se fuerzan. |
| `vault.keys` | Claves que el Vault Agent exporta en `/vault/secrets/config` desde `secret/data/<app>`; rol Vault = nombre de la release, atado a la ServiceAccount del chart. |
| `secret.externalName` | Solo con `vault.enabled=false`: Secret k8s creado fuera del chart. |
| `frontend.env` | `MAYA_PUBLIC_*` (configuración en ejecución de la SPA) y `MAYA_CSP`. |
| `ingress.host`, `ingress.apiHost` | `<app>.ceedcv.es` (SPA) y `api.<app>.ceedcv.es` (API; `/app` → Reverb). |
| `worker.args`, `scheduler.enabled`, `reverb.enabled`, `migrate.args` | Procesos. |
| `views.enabled`, `views.passwordKey`, `views.networkPolicy.cidrs` | Job `views` (D17). Con Vault, `passwordKey` (`MAYA_APPS_VIEWS_PASSWORD`) tiene que estar en `vault.keys` (si no, el chart no se renderiza) y solo la recibe ese Job. `cidrs` vacío = los de `networkPolicy.egress.cidrs`. |
| `storage.*` | PVC RWX (maya-nfs) montado en `storage/app/media` con `subPath`. |
| `networkPolicy.egress.{cidrs,namespaces,internet}` | Deny-all por defecto; se abre solo lo listado (PostgreSQL, Redis, RabbitMQ, Keycloak, Vault, DNS). |

Todo lo demás (réplicas, recursos, probes, anti-afinidad, PDB, securityContext)
tiene defaults razonables en `values.yaml`.

## Prerrequisitos en el clúster

1. Namespace de la app y pull secret: `kubectl -n <ns> create secret docker-registry regcred …`.
2. Vault: `vault kv put secret/<app> APP_KEY=… DB_PASSWORD=… …` (las claves de `vault.keys`) y un rol
   `<app>` en `auth/kubernetes` atado a la SA `<app>` del namespace `<ns>` con una política
   de solo lectura sobre `secret/data/<app>`.
3. PostgreSQL: base de datos, rol y extensiones de la app; rol lector `maya_fdw_reader` para los FDW.
   Con `views.enabled`: esquema `maya_apps` en `maya_erp` y el rol `maya_apps_views` que crea vistas
   en él (playbook `05.5`, etiqueta `maya_apps`; `dev/sql` en desarrollo), y su contraseña en
   `secret/<app>` como `MAYA_APPS_VIEWS_PASSWORD`.
4. RabbitMQ: vhost `/maya`, usuario de la app y topología `maya.*`.
5. DNS `<app>` y `api.<app>` → VIP de Traefik; `ClusterIssuer maya-internal-ca`.

## Publicar

Lo publica `build-base-images.yml` en `oci://ghcr.io/maya-aqss/charts` (paquete público:
el chart no contiene nada privado). Las apps lo resuelven al empaquetar su chart en el CI, que
lo incluye dentro del `.tgz`: el clúster nunca descarga `maya-common` por separado.

```bash
helm package charts/maya-common -d /tmp/chart
helm push /tmp/chart/maya-common-0.3.0.tgz oci://ghcr.io/maya-aqss/charts
```

## Cambios

- **0.3.0** (contrato de values nuevo, `views.*`): Job `views` (D17) con `php artisan maya:views` antes de
  `migrate` y su NetworkPolicy, ambos hooks `post-install,pre-upgrade` (no `pre-install`: la
  ServiceAccount del Vault Agent no existe todavía en el primer install, como `migrate` en 0.2.1).
  `views.passwordKey` sale de las anotaciones de Vault de los demás pods. Necesita
  shared-platform-laravel con `maya:views` (maya_platform ≥ 0.23).
- **0.2.2**: con `storage.enabled`, `storage/app` es un emptyDir propio y el PVC se monta dentro
  (`storage/app/media`). Antes, el runtime creaba `storage/app` como root para el punto de montaje y el
  entrypoint (uid 82) no podía crear `storage/app/public`: dms en CrashLoop (K3s de desarrollo, 14-10).
- **0.2.1**: Job `migrate` como hook `post-install,pre-upgrade` (antes `pre-install`: en la
  primera instalación no existían su ServiceAccount ni el PVC de dms y el Job no arrancaba);
  `<componente>.imageTag`; `config.TRUSTED_PROXIES` por defecto (pod CIDR de K3s, `10.42.0.0/16`).
- **0.2.0**: las 4 imágenes por app, Vault con rol por app, Ingress `<app>` / `api.<app>`.

## Probar en local

```bash
# copia el chart como dependencia local y renderiza
mkdir -p deploy/helm/charts && cp -r ../maya_platform/charts/maya-common deploy/helm/charts/
sed -i 's|repository: oci://.*|repository: file://./charts/maya-common|' deploy/helm/Chart.yaml   # solo en local
helm dependency build deploy/helm
helm template maya-dms deploy/helm -n maya-dms --set image.tag=1.0.0 | kubeconform -strict
```

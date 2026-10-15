#!/usr/bin/env bash
# Prueba del Job `views` (D17) con helm template: un chart consumidor mínimo, como el de una app.
# Uso: charts/maya-common/tests/views.sh   (necesita helm y python3 con PyYAML; kubeconform si está)
set -euo pipefail
COMMON="$(cd "$(dirname "$0")/.." && pwd)"
W="$(mktemp -d)"
trap 'rm -rf "$W"' EXIT
fallos=0
check() { if eval "$2"; then printf '  ok    %s\n' "$1"; else printf '  FALLO %s\n' "$1"; fallos=$((fallos + 1)); fi; }

mkdir -p "$W/app/templates"
cat > "$W/app/Chart.yaml" <<EOF
apiVersion: v2
name: maya-prueba
type: application
version: 1.0.0
dependencies:
  - name: maya-common
    version: ">=0.3.0"
    repository: file://$COMMON
EOF
echo '{{ include "maya-common.all" . }}' > "$W/app/templates/all.yaml"
cat > "$W/values.yaml" <<'EOF'
image: { tag: 1.0.0 }
vault:
  keys: [APP_KEY, DB_PASSWORD, FDW_ODOO_PASSWORD, MAYA_APPS_VIEWS_PASSWORD]
config:
  DB_HOST: 10.224.237.252
  FDW_ODOO_HOST: 10.224.237.252
  FDW_ODOO_DATABASE: maya_erp
networkPolicy:
  egress:
    cidrs: [{ cidr: 10.224.237.252/32, ports: [5432] }]
storage: { enabled: true }
views: { enabled: true }
ingress: { host: prueba.ceedcv, apiHost: api.prueba.ceedcv }
EOF
helm dependency build "$W/app" >/dev/null
render() { helm template maya-prueba "$W/app" -n maya-prueba -f "$W/values.yaml" "$@"; }

# Resumen de lo renderizado: tipo, nombre, hook, peso y, por pod, claves de Vault y volúmenes
resumen() { python3 -c '
import sys, yaml
for d in yaml.safe_load_all(sys.stdin):
    if not d:
        continue
    a = d["metadata"].get("annotations") or {}
    line = [d["kind"], d["metadata"]["name"], a.get("helm.sh/hook", "-"), a.get("helm.sh/hook-weight", "-")]
    if d["kind"] in ("Deployment", "Job"):
        pod = d["spec"]["template"]
        tpl = (pod["metadata"].get("annotations") or {}).get("vault.hashicorp.com/agent-inject-template-config", "")
        line += ["views-key" if "MAYA_APPS_VIEWS_PASSWORD" in tpl else "-",
                 "pvc" if any("persistentVolumeClaim" in v for v in pod["spec"].get("volumes", [])) else "-",
                 " ".join(pod["spec"]["containers"][0].get("args") or [])]
    if d["kind"] == "NetworkPolicy":
        line += [d["spec"]["podSelector"].get("matchLabels", {}).get("app.kubernetes.io/component", "*"),
                 ",".join(t["to"][0]["ipBlock"]["cidr"] for t in d["spec"]["egress"] if "ipBlock" in t["to"][0])]
    print(" ".join(line))'; }

render | tee "$W/out.yaml" | resumen > "$W/r.txt"
check "Job views: worker, artisan maya:views, hook antes de migrate" \
  "grep -q '^Job maya-prueba-views post-install,pre-upgrade -10 views-key - artisan maya:views$' $W/r.txt && grep -q '^Job maya-prueba-migrate post-install,pre-upgrade -5 - pvc migrate$' $W/r.txt"
check "NetworkPolicy del Job (hook, antes que él) hacia PostgreSQL" \
  "grep -q '^NetworkPolicy maya-prueba-views post-install,pre-upgrade -11 views 10.224.237.252/32$' $W/r.txt"
check "solo el Job views recibe la contraseña de maya_apps_views" \
  "[ \"\$(grep -c views-key $W/r.txt)\" = 1 ]"
check "el Job views no monta el PVC de ficheros" "grep -q '^Job maya-prueba-views .* views-key - ' $W/r.txt"

render --set views.enabled=false | resumen > "$W/off.txt"
check "views.enabled=false: ni Job ni NetworkPolicy views" "! grep -q -- '-views ' $W/off.txt"

check "views.enabled sin la clave en vault.keys: no se renderiza" \
  "! render --set 'vault.keys={APP_KEY}' > /dev/null 2> $W/err.txt && grep -q 'views.enabled necesita MAYA_APPS_VIEWS_PASSWORD' $W/err.txt"

render --set vault.enabled=false --set secret.externalName=s --set 'views.networkPolicy.cidrs[0].cidr=10.0.0.1/32' > "$W/dev.yaml"
check "sin Vault: Secret externo; views.networkPolicy.cidrs manda" \
  "python3 -c '
import sys, yaml
docs = {(d[\"kind\"], d[\"metadata\"][\"name\"]): d for d in yaml.safe_load_all(open(sys.argv[1])) if d}
job = docs[(\"Job\", \"maya-prueba-views\")][\"spec\"][\"template\"][\"spec\"][\"containers\"][0]
assert job[\"envFrom\"] == [{\"secretRef\": {\"name\": \"s\"}}]
np = docs[(\"NetworkPolicy\", \"maya-prueba-views\")]
assert [t[\"to\"][0][\"ipBlock\"][\"cidr\"] for t in np[\"spec\"][\"egress\"] if \"ipBlock\" in t[\"to\"][0]] == [\"10.0.0.1/32\"]
' $W/dev.yaml"

if command -v kubeconform >/dev/null 2>&1; then
  check "kubeconform" "kubeconform -strict -summary $W/out.yaml $W/dev.yaml > $W/kc.txt 2>&1"
fi

[ "$fallos" = 0 ] && echo "views.sh: todo bien" || { echo "views.sh: $fallos fallos"; exit 1; }

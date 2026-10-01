{{/*
  maya-common — nombres, etiquetas y piezas compartidas.
  Todos los helpers reciben el contexto del chart consumidor (.Chart, .Release, .Values).
*/}}

{{- define "maya-common.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "maya-common.fullname" -}}
{{- if .Values.fullnameOverride -}}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- $name := default .Chart.Name .Values.nameOverride -}}
{{- if contains $name .Release.Name -}}
{{- .Release.Name | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" -}}
{{- end -}}
{{- end -}}
{{- end -}}

{{- define "maya-common.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "maya-common.labels" -}}
helm.sh/chart: {{ include "maya-common.chart" . }}
{{ include "maya-common.selectorLabels" . }}
app.kubernetes.io/version: {{ .Values.image.tag | default .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- with .Values.commonLabels }}
{{ toYaml . }}
{{- end }}
{{- end -}}

{{- define "maya-common.selectorLabels" -}}
app.kubernetes.io/name: {{ include "maya-common.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end -}}

{{/* Variantes por componente. Uso: (dict "root" . "component" "api") */}}
{{- define "maya-common.componentLabels" -}}
{{ include "maya-common.labels" .root }}
app.kubernetes.io/component: {{ .component }}
{{- end -}}

{{- define "maya-common.componentSelectorLabels" -}}
{{ include "maya-common.selectorLabels" .root }}
app.kubernetes.io/component: {{ .component }}
{{- end -}}

{{/* <nombre completo>-<componente>: nombres estables para Deployments y Services. */}}
{{- define "maya-common.componentName" -}}
{{- printf "%s-%s" (include "maya-common.fullname" .root) .component | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{/*
  Referencia de imagen: <registry>/<repository>-<componente>:<tag>.
  Uso: (dict "root" . "component" "worker"). `image.tag` es obligatorio.
*/}}
{{- define "maya-common.image" -}}
{{- $v := .root.Values -}}
{{- /* <componente>.imageTag permite un tag propio (p. ej. el frontend de desarrollo, con los VITE_* de ese entorno). */ -}}
{{- $tag := (index $v .component | default dict).imageTag | default $v.image.tag -}}
{{- if not $tag -}}
{{- fail "image.tag es obligatorio (misma versión semver que el chart)" -}}
{{- end -}}
{{- printf "%s/%s-%s:%s" $v.image.registry $v.image.repository .component $tag -}}
{{- end -}}

{{- define "maya-common.serviceAccountName" -}}
{{- if .Values.serviceAccount.create -}}
{{- default (include "maya-common.fullname" .) .Values.serviceAccount.name -}}
{{- else -}}
{{- default "default" .Values.serviceAccount.name -}}
{{- end -}}
{{- end -}}

{{- define "maya-common.vaultRole" -}}
{{- default (include "maya-common.fullname" .) .Values.vault.role -}}
{{- end -}}

{{- define "maya-common.vaultSecretPath" -}}
{{- default (printf "secret/data/%s" (include "maya-common.fullname" .)) .Values.vault.secretPath -}}
{{- end -}}

{{/*
  Anotaciones del Vault Agent Injector. Renderiza /vault/secrets/config con
  `export CLAVE="valor"` por cada clave; el entrypoint de la imagen lo carga.
  pre-populate-only: solo init container (sin sidecar), así los Jobs terminan.
*/}}
{{- define "maya-common.vaultAnnotations" -}}
{{- if .Values.vault.enabled }}
vault.hashicorp.com/agent-inject: "true"
vault.hashicorp.com/agent-pre-populate-only: "true"
vault.hashicorp.com/agent-init-first: "true"
vault.hashicorp.com/role: {{ include "maya-common.vaultRole" . | quote }}
vault.hashicorp.com/agent-inject-secret-config: {{ include "maya-common.vaultSecretPath" . | quote }}
vault.hashicorp.com/agent-run-as-user: "82"
vault.hashicorp.com/agent-run-as-group: "82"
{{- with .Values.vault.agentResources }}
vault.hashicorp.com/agent-requests-cpu: {{ .requests.cpu | quote }}
vault.hashicorp.com/agent-requests-mem: {{ .requests.memory | quote }}
vault.hashicorp.com/agent-limits-cpu: {{ .limits.cpu | quote }}
vault.hashicorp.com/agent-limits-mem: {{ .limits.memory | quote }}
{{- end }}
vault.hashicorp.com/agent-inject-template-config: |
{{ printf "  {{- with secret %s -}}" (include "maya-common.vaultSecretPath" . | quote) }}
{{- range .Values.vault.keys }}
{{ printf "  export %s=\"{{ .Data.data.%s }}\"" . . }}
{{- end }}
{{ printf "  {{- end -}}" }}
{{- end }}
{{- end -}}

{{/* Anotaciones comunes de los pods backend: checksum de config + Vault. */}}
{{- define "maya-common.backendPodAnnotations" -}}
checksum/config: {{ include "maya-common.configmap" . | sha256sum }}
{{- include "maya-common.vaultAnnotations" . }}
{{- end -}}

{{/* envFrom de los backends: ConfigMap siempre; Secret solo sin Vault. */}}
{{- define "maya-common.envFrom" -}}
- configMapRef:
    name: {{ include "maya-common.fullname" . }}-config
{{- if not .Values.vault.enabled }}
- secretRef:
    name: {{ required "secret.externalName es obligatorio con vault.enabled=false" .Values.secret.externalName }}
{{- end }}
{{- end -}}

{{/* Misma configuración que el ConfigMap, como lista `env` (para el Job de migración). */}}
{{- define "maya-common.envInline" -}}
{{- range $k, $v := .Values.config }}
- name: {{ $k }}
  value: {{ $v | toString | quote }}
{{- end }}
- name: APP_ENV
  value: "production"
- name: APP_DEBUG
  value: "false"
- name: SESSION_SECURE_COOKIE
  value: "true"
{{- end -}}

{{- define "maya-common.preStop" -}}
preStop:
  exec:
    command: ["/bin/sh", "-c", "sleep {{ .Values.preStopSleepSeconds | default 5 }}"]
{{- end -}}

{{/*
  Rutas escribibles con readOnlyRootFilesystem (contrato de maya/php-base):
  storage/, bootstrap/cache y /tmp como emptyDir; el entrypoint recrea la
  estructura de storage/ al arrancar. El PVC de ficheros (storage.*) se monta
  dentro de storage/app.
*/}}
{{- define "maya-common.runtimeVolumes" -}}
- name: storage
  emptyDir: {}
- name: bootstrap-cache
  emptyDir: {}
- name: tmp
  emptyDir: {}
{{- if .Values.storage.enabled }}
- name: media
  persistentVolumeClaim:
    claimName: {{ .Values.storage.existingClaim | default (printf "%s-media" (include "maya-common.fullname" .)) }}
{{- end }}
{{- end -}}

{{- define "maya-common.runtimeVolumeMounts" -}}
- name: storage
  mountPath: /var/www/html/storage
- name: bootstrap-cache
  mountPath: /var/www/html/bootstrap/cache
- name: tmp
  mountPath: /tmp
{{- if .Values.storage.enabled }}
- name: media
  mountPath: {{ .Values.storage.mountPath }}
  {{- with .Values.storage.subPath }}
  subPath: {{ . }}
  {{- end }}
{{- end }}
{{- end -}}

{{/* Bloque común del spec de pod de los backends. */}}
{{- define "maya-common.backendPodSpecCommon" -}}
{{- with .Values.image.pullSecrets }}
imagePullSecrets:
  {{- toYaml . | nindent 2 }}
{{- end }}
serviceAccountName: {{ include "maya-common.serviceAccountName" . }}
automountServiceAccountToken: {{ .Values.serviceAccount.automountServiceAccountToken }}
securityContext:
  {{- toYaml .Values.podSecurityContext | nindent 2 }}
{{- end -}}

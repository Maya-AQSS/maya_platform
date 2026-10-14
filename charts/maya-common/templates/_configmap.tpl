{{/*
  ConfigMap de los backends (envFrom). Claves tal cual (.env). Los valores de
  producción críticos se fuerzan al final y no se pueden sobreescribir.
*/}}
{{- define "maya-common.configmap" -}}
apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ include "maya-common.fullname" . }}-config
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.labels" . | nindent 4 }}
data:
  {{- range $k, $v := .Values.config }}
  {{ $k }}: {{ $v | toString | quote }}
  {{- end }}
  APP_ENV: "production"
  APP_DEBUG: "false"
  SESSION_SECURE_COOKIE: "true"
{{- end -}}

{{/* ConfigMap del frontend: MAYA_PUBLIC_* (→ /config.js) y MAYA_CSP. */}}
{{- define "maya-common.frontendConfigmap" -}}
{{- if .Values.frontend.enabled -}}
apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ include "maya-common.fullname" . }}-frontend-config
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" "frontend") | nindent 4 }}
data:
  {{- range $k, $v := .Values.frontend.env }}
  {{ $k }}: {{ $v | toString | quote }}
  {{- end }}
{{- end -}}
{{- end -}}

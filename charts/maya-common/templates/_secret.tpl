{{/*
  Secret gestionado por el chart. Solo se renderiza con vault.enabled=false y
  sin secret.externalName: pruebas locales, nunca producción.
*/}}
{{- define "maya-common.secret" -}}
{{- if and (not .Values.vault.enabled) (not .Values.secret.externalName) .Values.secret.data -}}
apiVersion: v1
kind: Secret
metadata:
  name: {{ include "maya-common.fullname" . }}-secret
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.labels" . | nindent 4 }}
type: Opaque
stringData:
  {{- range $k, $v := .Values.secret.data }}
  {{ $k }}: {{ $v | toString | quote }}
  {{- end }}
{{- end -}}
{{- end -}}

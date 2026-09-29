{{/*
  ServiceAccount dedicada por app. Es la identidad con la que el Vault Agent
  se autentica (rol `vault.role`, atado a esta SA y a este namespace).
*/}}
{{- define "maya-common.serviceaccount" -}}
{{- if .Values.serviceAccount.create -}}
apiVersion: v1
kind: ServiceAccount
metadata:
  name: {{ include "maya-common.serviceAccountName" . }}
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.labels" . | nindent 4 }}
  {{- with .Values.serviceAccount.annotations }}
  annotations:
    {{- toYaml . | nindent 4 }}
  {{- end }}
automountServiceAccountToken: {{ .Values.serviceAccount.automountServiceAccountToken }}
{{- end -}}
{{- end -}}

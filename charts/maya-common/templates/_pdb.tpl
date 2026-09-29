{{/*
  PodDisruptionBudget para api y frontend cuando hay más de una réplica:
  un drenaje de nodo nunca deja la app sin pods.
*/}}
{{- define "maya-common.pdb" -}}
{{- if and .Values.api.enabled .Values.api.pdb.enabled (gt (int .Values.api.replicas) 1) }}
apiVersion: policy/v1
kind: PodDisruptionBudget
metadata:
  name: {{ include "maya-common.componentName" (dict "root" . "component" "api") }}
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" "api") | nindent 4 }}
spec:
  minAvailable: {{ .Values.api.pdb.minAvailable }}
  selector:
    matchLabels:
      {{- include "maya-common.componentSelectorLabels" (dict "root" . "component" "api") | nindent 6 }}
{{- end }}
{{- if and .Values.frontend.enabled .Values.frontend.pdb.enabled (gt (int .Values.frontend.replicas) 1) }}
---
apiVersion: policy/v1
kind: PodDisruptionBudget
metadata:
  name: {{ include "maya-common.componentName" (dict "root" . "component" "frontend") }}
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" "frontend") | nindent 4 }}
spec:
  minAvailable: {{ .Values.frontend.pdb.minAvailable }}
  selector:
    matchLabels:
      {{- include "maya-common.componentSelectorLabels" (dict "root" . "component" "frontend") | nindent 6 }}
{{- end }}
{{- end -}}

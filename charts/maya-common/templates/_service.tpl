{{/*
  Services ClusterIP con nombres estables para el este-oeste:
    <nombre completo>-api.<ns>.svc.cluster.local:8080
    <nombre completo>-reverb.<ns>.svc.cluster.local:8080   (REVERB_HOST de los backends)
*/}}
{{- define "maya-common.service" -}}
{{- if .Values.api.enabled }}
apiVersion: v1
kind: Service
metadata:
  name: {{ include "maya-common.componentName" (dict "root" . "component" "api") }}
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" "api") | nindent 4 }}
spec:
  type: ClusterIP
  selector:
    {{- include "maya-common.componentSelectorLabels" (dict "root" . "component" "api") | nindent 4 }}
  ports:
    - name: http
      port: {{ .Values.api.port }}
      targetPort: http
      protocol: TCP
{{- end }}
{{- if .Values.frontend.enabled }}
---
apiVersion: v1
kind: Service
metadata:
  name: {{ include "maya-common.componentName" (dict "root" . "component" "frontend") }}
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" "frontend") | nindent 4 }}
spec:
  type: ClusterIP
  selector:
    {{- include "maya-common.componentSelectorLabels" (dict "root" . "component" "frontend") | nindent 4 }}
  ports:
    - name: http
      port: {{ .Values.frontend.port }}
      targetPort: http
      protocol: TCP
{{- end }}
{{- if .Values.reverb.enabled }}
---
apiVersion: v1
kind: Service
metadata:
  name: {{ include "maya-common.componentName" (dict "root" . "component" "reverb") }}
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" "reverb") | nindent 4 }}
spec:
  type: ClusterIP
  selector:
    {{- include "maya-common.componentSelectorLabels" (dict "root" . "component" "reverb") | nindent 4 }}
  ports:
    - name: ws
      port: {{ .Values.reverb.port }}
      targetPort: ws
      protocol: TCP
{{- end }}
{{- end -}}

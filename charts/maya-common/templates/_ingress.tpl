{{/*
  Ingress (Traefik). Dos hosts por app:
    ingress.host     → frontend
    ingress.apiHost  → api, y apiHost + reverbPath (/app) → reverb (WebSocket)
  Traefik prioriza la ruta más larga, así que /app gana a / en el mismo host.
  TLS: cert-manager emite un certificado por Ingress (anotación cluster-issuer).
*/}}
{{- define "maya-common.ingress" -}}
{{- if .Values.ingress.enabled -}}
{{- $fullname := include "maya-common.fullname" . -}}
{{- if .Values.frontend.enabled }}
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $fullname }}-frontend
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" "frontend") | nindent 4 }}
  {{- with .Values.ingress.annotations }}
  annotations:
    {{- toYaml . | nindent 4 }}
  {{- end }}
spec:
  ingressClassName: {{ .Values.ingress.className }}
  {{- if .Values.ingress.tls.enabled }}
  tls:
    - hosts:
        - {{ required "ingress.host es obligatorio" .Values.ingress.host | quote }}
      secretName: {{ .Values.ingress.tls.frontendSecretName | default (printf "%s-frontend-tls" $fullname) }}
  {{- end }}
  rules:
    - host: {{ .Values.ingress.host | quote }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ include "maya-common.componentName" (dict "root" . "component" "frontend") }}
                port:
                  name: http
{{- end }}
{{- if .Values.api.enabled }}
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $fullname }}-api
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" "api") | nindent 4 }}
  {{- with .Values.ingress.annotations }}
  annotations:
    {{- toYaml . | nindent 4 }}
  {{- end }}
spec:
  ingressClassName: {{ .Values.ingress.className }}
  {{- if .Values.ingress.tls.enabled }}
  tls:
    - hosts:
        - {{ required "ingress.apiHost es obligatorio" .Values.ingress.apiHost | quote }}
      secretName: {{ .Values.ingress.tls.apiSecretName | default (printf "%s-api-tls" $fullname) }}
  {{- end }}
  rules:
    - host: {{ .Values.ingress.apiHost | quote }}
      http:
        paths:
          {{- if .Values.reverb.enabled }}
          - path: {{ .Values.ingress.reverbPath }}
            pathType: Prefix
            backend:
              service:
                name: {{ include "maya-common.componentName" (dict "root" . "component" "reverb") }}
                port:
                  name: ws
          {{- end }}
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ include "maya-common.componentName" (dict "root" . "component" "api") }}
                port:
                  name: http
{{- end }}
{{- end -}}
{{- end -}}

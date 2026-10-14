{{/*
  Deployment api: imagen *-api (nginx + php-fpm en un contenedor, puerto 8080).
*/}}
{{- define "maya-common.api" -}}
{{- if .Values.api.enabled -}}
{{- $c := "api" -}}
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ include "maya-common.componentName" (dict "root" . "component" $c) }}
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" $c) | nindent 4 }}
spec:
  replicas: {{ .Values.api.replicas }}
  revisionHistoryLimit: 3
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxSurge: 1
      maxUnavailable: 0
  selector:
    matchLabels:
      {{- include "maya-common.componentSelectorLabels" (dict "root" . "component" $c) | nindent 6 }}
  template:
    metadata:
      labels:
        {{- include "maya-common.componentSelectorLabels" (dict "root" . "component" $c) | nindent 8 }}
      annotations:
        {{- include "maya-common.backendPodAnnotations" . | nindent 8 }}
        {{- with .Values.api.podAnnotations }}
        {{- toYaml . | nindent 8 }}
        {{- end }}
    spec:
      {{- include "maya-common.backendPodSpecCommon" . | nindent 6 }}
      terminationGracePeriodSeconds: {{ .Values.terminationGracePeriodSeconds }}
      containers:
        - name: api
          image: {{ include "maya-common.image" (dict "root" . "component" "api") }}
          imagePullPolicy: {{ .Values.image.pullPolicy }}
          args: ["api"]
          envFrom:
            {{- include "maya-common.envFrom" . | nindent 12 }}
          {{- with .Values.api.extraEnv }}
          env:
            {{- toYaml . | nindent 12 }}
          {{- end }}
          ports:
            - name: http
              containerPort: {{ .Values.api.port }}
              protocol: TCP
          {{- with .Values.api.startupProbe }}
          startupProbe:
            {{- toYaml . | nindent 12 }}
          {{- end }}
          livenessProbe:
            {{- toYaml .Values.api.livenessProbe | nindent 12 }}
          readinessProbe:
            {{- toYaml .Values.api.readinessProbe | nindent 12 }}
          resources:
            {{- toYaml .Values.api.resources | nindent 12 }}
          securityContext:
            {{- toYaml .Values.containerSecurityContext | nindent 12 }}
          lifecycle:
            {{- include "maya-common.preStop" . | nindent 12 }}
          volumeMounts:
            {{- include "maya-common.runtimeVolumeMounts" . | nindent 12 }}
      volumes:
        {{- include "maya-common.runtimeVolumes" . | nindent 8 }}
      {{- with .Values.api.nodeSelector }}
      nodeSelector:
        {{- toYaml . | nindent 8 }}
      {{- end }}
      {{- with .Values.api.tolerations }}
      tolerations:
        {{- toYaml . | nindent 8 }}
      {{- end }}
      {{- with .Values.api.affinity }}
      affinity:
        {{- toYaml . | nindent 8 }}
      {{- end }}
{{- end -}}
{{- end -}}

{{/*
  Deployment scheduler: imagen *-worker con args ["scheduler"] (schedule:work).
  Siempre 1 réplica: dos schedulers duplicarían las tareas.
*/}}
{{- define "maya-common.scheduler" -}}
{{- if .Values.scheduler.enabled -}}
{{- $c := "scheduler" -}}
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ include "maya-common.componentName" (dict "root" . "component" $c) }}
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" $c) | nindent 4 }}
spec:
  replicas: 1
  revisionHistoryLimit: 3
  strategy:
    type: Recreate
  selector:
    matchLabels:
      {{- include "maya-common.componentSelectorLabels" (dict "root" . "component" $c) | nindent 6 }}
  template:
    metadata:
      labels:
        {{- include "maya-common.componentSelectorLabels" (dict "root" . "component" $c) | nindent 8 }}
      annotations:
        {{- include "maya-common.backendPodAnnotations" . | nindent 8 }}
    spec:
      {{- include "maya-common.backendPodSpecCommon" . | nindent 6 }}
      terminationGracePeriodSeconds: {{ .Values.terminationGracePeriodSeconds }}
      containers:
        - name: scheduler
          image: {{ include "maya-common.image" (dict "root" . "component" "worker") }}
          imagePullPolicy: {{ .Values.image.pullPolicy }}
          args: ["scheduler"]
          envFrom:
            {{- include "maya-common.envFrom" . | nindent 12 }}
          {{- with .Values.scheduler.extraEnv }}
          env:
            {{- toYaml . | nindent 12 }}
          {{- end }}
          {{- with .Values.scheduler.livenessProbe }}
          livenessProbe:
            {{- toYaml . | nindent 12 }}
          {{- end }}
          resources:
            {{- toYaml .Values.scheduler.resources | nindent 12 }}
          securityContext:
            {{- toYaml .Values.containerSecurityContext | nindent 12 }}
          volumeMounts:
            {{- include "maya-common.runtimeVolumeMounts" . | nindent 12 }}
      volumes:
        {{- include "maya-common.runtimeVolumes" . | nindent 8 }}
{{- end -}}
{{- end -}}

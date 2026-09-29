{{/*
  Deployment worker: imagen *-worker. Sin args ejecuta el comando grabado en la
  imagen (MAYA_WORKER_CMD); con args, `php artisan <args>`.
*/}}
{{- define "maya-common.worker" -}}
{{- if .Values.worker.enabled -}}
{{- $c := "worker" -}}
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ include "maya-common.componentName" (dict "root" . "component" $c) }}
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" $c) | nindent 4 }}
spec:
  replicas: {{ .Values.worker.replicas }}
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
      terminationGracePeriodSeconds: {{ .Values.worker.terminationGracePeriodSeconds }}
      containers:
        - name: worker
          image: {{ include "maya-common.image" (dict "root" . "component" "worker") }}
          imagePullPolicy: {{ .Values.image.pullPolicy }}
          {{- with .Values.worker.args }}
          args:
            {{- toYaml . | nindent 12 }}
          {{- end }}
          envFrom:
            {{- include "maya-common.envFrom" . | nindent 12 }}
          {{- with .Values.worker.extraEnv }}
          env:
            {{- toYaml . | nindent 12 }}
          {{- end }}
          {{- with .Values.worker.livenessProbe }}
          livenessProbe:
            {{- toYaml . | nindent 12 }}
          {{- end }}
          resources:
            {{- toYaml .Values.worker.resources | nindent 12 }}
          securityContext:
            {{- toYaml .Values.containerSecurityContext | nindent 12 }}
          volumeMounts:
            {{- include "maya-common.runtimeVolumeMounts" . | nindent 12 }}
      volumes:
        {{- include "maya-common.runtimeVolumes" . | nindent 8 }}
      {{- with .Values.worker.nodeSelector }}
      nodeSelector:
        {{- toYaml . | nindent 8 }}
      {{- end }}
      {{- with .Values.worker.tolerations }}
      tolerations:
        {{- toYaml . | nindent 8 }}
      {{- end }}
{{- end -}}
{{- end -}}

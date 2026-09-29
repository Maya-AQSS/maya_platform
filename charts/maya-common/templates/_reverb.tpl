{{/*
  Deployment reverb: imagen *-reverb (WebSocket en 8080). Los backends publican
  en el Service interno (REVERB_HOST=<nombre completo>-reverb); el navegador
  entra por el Ingress de la api en ingress.reverbPath.
*/}}
{{- define "maya-common.reverb" -}}
{{- if .Values.reverb.enabled -}}
{{- $c := "reverb" -}}
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ include "maya-common.componentName" (dict "root" . "component" $c) }}
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" $c) | nindent 4 }}
spec:
  replicas: {{ .Values.reverb.replicas }}
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
      terminationGracePeriodSeconds: {{ .Values.reverb.terminationGracePeriodSeconds }}
      containers:
        - name: reverb
          image: {{ include "maya-common.image" (dict "root" . "component" "reverb") }}
          imagePullPolicy: {{ .Values.image.pullPolicy }}
          args: ["reverb"]
          envFrom:
            {{- include "maya-common.envFrom" . | nindent 12 }}
          env:
            - name: MAYA_REVERB_PORT
              value: {{ .Values.reverb.port | quote }}
            {{- with .Values.reverb.extraEnv }}
            {{- toYaml . | nindent 12 }}
            {{- end }}
          ports:
            - name: ws
              containerPort: {{ .Values.reverb.port }}
              protocol: TCP
          livenessProbe:
            {{- toYaml .Values.reverb.livenessProbe | nindent 12 }}
          readinessProbe:
            {{- toYaml .Values.reverb.readinessProbe | nindent 12 }}
          resources:
            {{- toYaml .Values.reverb.resources | nindent 12 }}
          securityContext:
            {{- toYaml .Values.containerSecurityContext | nindent 12 }}
          lifecycle:
            {{- include "maya-common.preStop" . | nindent 12 }}
          volumeMounts:
            {{- include "maya-common.runtimeVolumeMounts" . | nindent 12 }}
      volumes:
        {{- include "maya-common.runtimeVolumes" . | nindent 8 }}
{{- end -}}
{{- end -}}

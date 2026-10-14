{{/*
  Deployment frontend: imagen *-frontend (maya/web-base, nginx uid 101, puerto 8080).
  Solo escribe en /tmp. La configuración en ejecución llega por el ConfigMap
  -frontend-config (MAYA_PUBLIC_* → /config.js, MAYA_CSP).
*/}}
{{- define "maya-common.frontend" -}}
{{- if .Values.frontend.enabled -}}
{{- $c := "frontend" -}}
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ include "maya-common.componentName" (dict "root" . "component" $c) }}
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" $c) | nindent 4 }}
spec:
  replicas: {{ .Values.frontend.replicas }}
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
        checksum/config: {{ include "maya-common.frontendConfigmap" . | sha256sum }}
        {{- with .Values.frontend.podAnnotations }}
        {{- toYaml . | nindent 8 }}
        {{- end }}
    spec:
      {{- with .Values.image.pullSecrets }}
      imagePullSecrets:
        {{- toYaml . | nindent 8 }}
      {{- end }}
      automountServiceAccountToken: false
      securityContext:
        {{- toYaml .Values.frontend.securityContext | nindent 8 }}
      terminationGracePeriodSeconds: {{ .Values.terminationGracePeriodSeconds }}
      containers:
        - name: web
          image: {{ include "maya-common.image" (dict "root" . "component" "frontend") }}
          imagePullPolicy: {{ .Values.image.pullPolicy }}
          envFrom:
            - configMapRef:
                name: {{ include "maya-common.fullname" . }}-frontend-config
          ports:
            - name: http
              containerPort: {{ .Values.frontend.port }}
              protocol: TCP
          livenessProbe:
            {{- toYaml .Values.frontend.livenessProbe | nindent 12 }}
          readinessProbe:
            {{- toYaml .Values.frontend.readinessProbe | nindent 12 }}
          resources:
            {{- toYaml .Values.frontend.resources | nindent 12 }}
          securityContext:
            {{- toYaml .Values.containerSecurityContext | nindent 12 }}
          lifecycle:
            {{- include "maya-common.preStop" . | nindent 12 }}
          volumeMounts:
            - name: tmp
              mountPath: /tmp
      volumes:
        - name: tmp
          emptyDir: {}
      {{- with .Values.frontend.nodeSelector }}
      nodeSelector:
        {{- toYaml . | nindent 8 }}
      {{- end }}
      {{- with .Values.frontend.tolerations }}
      tolerations:
        {{- toYaml . | nindent 8 }}
      {{- end }}
      {{- with .Values.frontend.affinity }}
      affinity:
        {{- toYaml . | nindent 8 }}
      {{- end }}
{{- end -}}
{{- end -}}

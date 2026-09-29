{{/*
  Job de migración: hook pre-install / pre-upgrade con la imagen *-worker y
  args ["migrate"] (php artisan migrate --force).

  No usa el ConfigMap: en el primer `helm install` los hooks corren antes de que
  exista, así que recibe la misma configuración inline (maya-common.envInline).
  Los secretos llegan por Vault Agent (init container) o, sin Vault, por el
  Secret externo, que debe existir antes de instalar.

  before-hook-creation borra el Job anterior antes de crear el nuevo; los
  fallidos se conservan para el postmortem. helm rollback NO revierte el
  esquema: hacer copia de seguridad antes de desplegar.
*/}}
{{- define "maya-common.jobMigrate" -}}
{{- if .Values.migrate.enabled -}}
apiVersion: batch/v1
kind: Job
metadata:
  name: {{ include "maya-common.fullname" . }}-migrate
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" "migrate") | nindent 4 }}
  annotations:
    "helm.sh/hook": pre-install,pre-upgrade
    "helm.sh/hook-weight": {{ .Values.migrate.hookWeight | quote }}
    "helm.sh/hook-delete-policy": before-hook-creation,hook-succeeded
spec:
  backoffLimit: {{ .Values.migrate.backoffLimit }}
  activeDeadlineSeconds: {{ .Values.migrate.activeDeadlineSeconds }}
  ttlSecondsAfterFinished: 86400
  template:
    metadata:
      labels:
        {{- include "maya-common.componentSelectorLabels" (dict "root" . "component" "migrate") | nindent 8 }}
      annotations:
        {{- include "maya-common.vaultAnnotations" . | nindent 8 }}
    spec:
      restartPolicy: Never
      {{- include "maya-common.backendPodSpecCommon" . | nindent 6 }}
      containers:
        - name: migrate
          image: {{ include "maya-common.image" (dict "root" . "component" "worker") }}
          imagePullPolicy: {{ .Values.image.pullPolicy }}
          args:
            {{- toYaml .Values.migrate.args | nindent 12 }}
          env:
            - name: MAYA_WAIT_FOR_DB
              value: "1"
            {{- include "maya-common.envInline" . | nindent 12 }}
          {{- if not .Values.vault.enabled }}
          envFrom:
            - secretRef:
                name: {{ required "secret.externalName es obligatorio con vault.enabled=false" .Values.secret.externalName }}
          {{- end }}
          resources:
            {{- toYaml .Values.migrate.resources | nindent 12 }}
          securityContext:
            {{- toYaml .Values.containerSecurityContext | nindent 12 }}
          volumeMounts:
            {{- include "maya-common.runtimeVolumeMounts" . | nindent 12 }}
      volumes:
        {{- include "maya-common.runtimeVolumes" . | nindent 8 }}
{{- end -}}
{{- end -}}

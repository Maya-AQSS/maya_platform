{{/*
  PVC de ficheros de la app (RWX en maya-nfs). Se conserva al desinstalar
  (resource-policy: keep) para no perder los ficheros subidos.
*/}}
{{- define "maya-common.pvc" -}}
{{- if and .Values.storage.enabled (not .Values.storage.existingClaim) -}}
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ include "maya-common.fullname" . }}-media
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" "media") | nindent 4 }}
  annotations:
    "helm.sh/resource-policy": keep
spec:
  accessModes:
    - {{ .Values.storage.accessMode }}
  storageClassName: {{ .Values.storage.storageClassName }}
  resources:
    requests:
      storage: {{ .Values.storage.size }}
{{- end -}}
{{- end -}}

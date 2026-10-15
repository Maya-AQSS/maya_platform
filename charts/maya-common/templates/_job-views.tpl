{{/*
  Job `views` (D17): crea o actualiza las vistas de lectura de la app en el esquema maya_apps de
  maya_erp con `php artisan maya:views` (las comunes de maya_platform y las de database/views),
  conectando como maya_apps_views. Imagen *-worker, args ["artisan", "maya:views"].

  Hook post-install / pre-upgrade con un peso menor que `migrate`: en cada fase corre antes que él.
  No pre-install, por lo mismo que `migrate` (0.2.1): en la primera instalación la ServiceAccount
  con la que se autentica el Vault Agent todavía no existe.

  Es el único pod que recibe views.passwordKey (MAYA_APPS_VIEWS_PASSWORD): el resto de pods se
  renderizan sin esa clave (maya-common.vaultAnnotations). Una transacción: si falla, no cambia
  ninguna vista y el despliegue se para antes de migrar.

  NetworkPolicy propia, también hook y con peso menor que el Job: en un pre-upgrade todavía manda la
  NetworkPolicy de la versión anterior, así que la salida hacia el PostgreSQL de Odoo no puede
  depender de ella. Se borra al terminar los hooks (hook-succeeded).
*/}}
{{- define "maya-common.jobViews" -}}
{{- if .Values.views.enabled -}}
{{- if and .Values.vault.enabled (not (has .Values.views.passwordKey .Values.vault.keys)) -}}
{{- fail (printf "views.enabled necesita %s en vault.keys (contraseña del rol maya_apps_views)" .Values.views.passwordKey) -}}
{{- end -}}
{{- if .Values.networkPolicy.enabled }}
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: {{ include "maya-common.fullname" . }}-views
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" "views") | nindent 4 }}
  annotations:
    "helm.sh/hook": post-install,pre-upgrade
    "helm.sh/hook-weight": {{ sub (int .Values.views.hookWeight) 1 | quote }}
    "helm.sh/hook-delete-policy": before-hook-creation,hook-succeeded
spec:
  podSelector:
    matchLabels:
      {{- include "maya-common.componentSelectorLabels" (dict "root" . "component" "views") | nindent 6 }}
  policyTypes:
    - Egress
  egress:
    {{- if .Values.networkPolicy.egress.dns }}
    - to:
        - namespaceSelector:
            matchLabels:
              kubernetes.io/metadata.name: kube-system
          podSelector:
            matchLabels:
              k8s-app: kube-dns
      ports:
        - port: 53
          protocol: UDP
        - port: 53
          protocol: TCP
    {{- end }}
    {{- if .Values.vault.enabled }}
    - to:
        - namespaceSelector:
            matchLabels:
              kubernetes.io/metadata.name: {{ .Values.vault.namespace }}
      ports:
        - port: 8200
    {{- end }}
    {{- range (.Values.views.networkPolicy.cidrs | default .Values.networkPolicy.egress.cidrs) }}
    - to:
        - ipBlock:
            cidr: {{ .cidr }}
      {{- with .ports }}
      ports:
        {{- range . }}
        - port: {{ . }}
        {{- end }}
      {{- end }}
    {{- end }}
---
{{- end }}
apiVersion: batch/v1
kind: Job
metadata:
  name: {{ include "maya-common.fullname" . }}-views
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.componentLabels" (dict "root" . "component" "views") | nindent 4 }}
  annotations:
    "helm.sh/hook": post-install,pre-upgrade
    "helm.sh/hook-weight": {{ .Values.views.hookWeight | quote }}
    "helm.sh/hook-delete-policy": before-hook-creation,hook-succeeded
spec:
  backoffLimit: {{ .Values.views.backoffLimit }}
  activeDeadlineSeconds: {{ .Values.views.activeDeadlineSeconds }}
  ttlSecondsAfterFinished: 86400
  template:
    metadata:
      labels:
        {{- include "maya-common.componentSelectorLabels" (dict "root" . "component" "views") | nindent 8 }}
      annotations:
        {{- include "maya-common.vaultAnnotationsWithKeys" (dict "root" . "keys" .Values.vault.keys) | nindent 8 }}
    spec:
      restartPolicy: Never
      {{- include "maya-common.backendPodSpecCommon" . | nindent 6 }}
      containers:
        - name: views
          image: {{ include "maya-common.image" (dict "root" . "component" "worker") }}
          imagePullPolicy: {{ .Values.image.pullPolicy }}
          args:
            {{- toYaml .Values.views.args | nindent 12 }}
          env:
            {{- include "maya-common.envInline" . | nindent 12 }}
          {{- if not .Values.vault.enabled }}
          envFrom:
            - secretRef:
                name: {{ required "secret.externalName es obligatorio con vault.enabled=false" .Values.secret.externalName }}
          {{- end }}
          resources:
            {{- toYaml .Values.views.resources | nindent 12 }}
          securityContext:
            {{- toYaml .Values.containerSecurityContext | nindent 12 }}
          # Las rutas escribibles de maya/php-base; sin el PVC de ficheros (storage.*), que no usa
          volumeMounts:
            - name: storage
              mountPath: /var/www/html/storage
            - name: bootstrap-cache
              mountPath: /var/www/html/bootstrap/cache
            - name: tmp
              mountPath: /tmp
      volumes:
        - name: storage
          emptyDir: {}
        - name: bootstrap-cache
          emptyDir: {}
        - name: tmp
          emptyDir: {}
{{- end -}}
{{- end -}}

{{/*
  NetworkPolicy: deny-all en el namespace y solo lo necesario.
    Ingress: mismo namespace; Traefik → frontend, api y reverb; namespaces
             autorizados (otras apps Maya) → api.
    Egress:  DNS; mismo namespace (api → reverb); Vault Agent → Vault;
             CIDRs externos (PostgreSQL); namespaces del clúster (Redis,
             RabbitMQ, Keycloak, otras apps); Internet opcional (80/443).
*/}}
{{- define "maya-common.networkpolicy" -}}
{{- if .Values.networkPolicy.enabled -}}
{{- $np := .Values.networkPolicy -}}
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: {{ include "maya-common.fullname" . }}
  namespace: {{ .Release.Namespace }}
  labels:
    {{- include "maya-common.labels" . | nindent 4 }}
spec:
  podSelector: {}
  policyTypes:
    - Ingress
    - Egress
  ingress:
    - from:
        - podSelector: {}
    - from:
        - namespaceSelector:
            matchLabels:
              kubernetes.io/metadata.name: {{ $np.traefik.namespace }}
          podSelector:
            matchLabels:
              {{- toYaml $np.traefik.podLabels | nindent 14 }}
      ports:
        - port: {{ .Values.frontend.port }}
        - port: {{ .Values.api.port }}
        - port: {{ .Values.reverb.port }}
    {{- with $np.allowedNamespaces }}
    - from:
        {{- range . }}
        - namespaceSelector:
            matchLabels:
              kubernetes.io/metadata.name: {{ . }}
        {{- end }}
      ports:
        - port: {{ $.Values.api.port }}
    {{- end }}
  egress:
    - to:
        - podSelector: {}
    {{- if $np.egress.dns }}
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
    {{- range $np.egress.cidrs }}
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
    {{- range $np.egress.namespaces }}
    - to:
        - namespaceSelector:
            matchLabels:
              kubernetes.io/metadata.name: {{ .name }}
      {{- with .ports }}
      ports:
        {{- range . }}
        - port: {{ . }}
        {{- end }}
      {{- end }}
    {{- end }}
    {{- if $np.egress.internet }}
    - to:
        - ipBlock:
            cidr: 0.0.0.0/0
            except:
              - 10.0.0.0/8
              - 172.16.0.0/12
              - 192.168.0.0/16
      ports:
        - port: 80
        - port: 443
    {{- end }}
{{- end -}}
{{- end -}}

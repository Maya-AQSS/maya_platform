{{/*
  Todos los recursos de una app Maya. El chart de cada app solo necesita:
    # deploy/helm/templates/all.yaml
    {{ include "maya-common.all" . }}

  Helm no fusiona los values de un library chart con los del consumidor: los
  deja bajo .Values["maya-common"]. Aquí se fusionan (los del consumidor mandan)
  y se pasa a cada template un contexto con los valores completos, así el
  values.yaml de la app contiene solo lo que cambia respecto a los defaults.
*/}}
{{- define "maya-common.all" -}}
{{- $defaults := index .Values "maya-common" | default (dict) -}}
{{- $overrides := omit .Values "maya-common" -}}
{{- $values := mergeOverwrite (deepCopy $defaults) $overrides -}}
{{- $root := dict "Values" $values "Chart" .Chart "Release" .Release "Capabilities" .Capabilities "Template" .Template -}}
{{ include "maya-common.serviceaccount" $root }}
---
{{ include "maya-common.configmap" $root }}
---
{{ include "maya-common.frontendConfigmap" $root }}
---
{{ include "maya-common.secret" $root }}
---
{{ include "maya-common.pvc" $root }}
---
{{ include "maya-common.jobMigrate" $root }}
---
{{ include "maya-common.api" $root }}
---
{{ include "maya-common.worker" $root }}
---
{{ include "maya-common.scheduler" $root }}
---
{{ include "maya-common.reverb" $root }}
---
{{ include "maya-common.frontend" $root }}
---
{{ include "maya-common.service" $root }}
---
{{ include "maya-common.ingress" $root }}
---
{{ include "maya-common.pdb" $root }}
---
{{ include "maya-common.networkpolicy" $root }}
{{- end -}}

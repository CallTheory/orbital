{{/*
Helper templates shared across every Orbital chart resource.

Naming convention: every Pod / Service / ConfigMap / etc. is prefixed
with the release name so multiple Orbital installs in the same cluster
don't collide ("orbital-prod-laravel" vs "orbital-staging-laravel").
*/}}

{{/*
The full release-prefixed name. Truncated to 63 chars (K8s DNS limit).
*/}}
{{- define "orbital.fullname" -}}
{{- if .Values.fullnameOverride -}}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- printf "%s-%s" .Release.Name .Chart.Name | trunc 63 | trimSuffix "-" -}}
{{- end -}}
{{- end -}}

{{/*
Per-component name. Pass the component name as the second arg.
  {{ include "orbital.componentName" (list . "laravel") }}
*/}}
{{- define "orbital.componentName" -}}
{{- $ctx := index . 0 -}}
{{- $component := index . 1 -}}
{{- printf "%s-%s" (include "orbital.fullname" $ctx) $component | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{/*
Standard label set every resource carries. Drives kubectl filtering
and Helm's release tracking.
*/}}
{{- define "orbital.labels" -}}
app.kubernetes.io/name: {{ .Chart.Name }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
helm.sh/chart: {{ printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end -}}

{{/*
Component-scoped selector labels. Used in matchLabels (must be a
stable subset that never changes mid-release).
  {{ include "orbital.selectorLabels" (list . "laravel") }}
*/}}
{{- define "orbital.selectorLabels" -}}
{{- $ctx := index . 0 -}}
{{- $component := index . 1 -}}
app.kubernetes.io/name: {{ $ctx.Chart.Name }}
app.kubernetes.io/instance: {{ $ctx.Release.Name }}
app.kubernetes.io/component: {{ $component }}
{{- end -}}

{{/*
Component label set = standard labels + the component identifier.
Use this in `metadata.labels`; selectors use `selectorLabels` (the
narrower, stable subset).
  {{ include "orbital.componentLabels" (list . "laravel") }}
*/}}
{{- define "orbital.componentLabels" -}}
{{- $ctx := index . 0 -}}
{{- $component := index . 1 -}}
{{ include "orbital.labels" $ctx }}
app.kubernetes.io/component: {{ $component }}
{{- end -}}

{{/*
Release tag for first-party Orbital images: global.image.tag if set,
otherwise the chart's appVersion. CI packages each release with
appVersion = the release version (X.Y.Z, no "v"), which is also the
image tag it publishes, so a published chart deploys its own images
without any --set.
*/}}
{{- define "orbital.imageTag" -}}
{{- .Values.global.image.tag | default .Chart.AppVersion -}}
{{- end -}}

{{/*
Build a fully-qualified image reference for a first-party Orbital
image (Laravel, Asterisk, agent-worker). Honors per-component tag
override; falls back to orbital.imageTag.
  {{ include "orbital.image" (dict "ctx" . "name" "laravel" "tag" .Values.laravel.image.tag) }}
*/}}
{{- define "orbital.image" -}}
{{- $registry := .ctx.Values.global.image.registry -}}
{{- $repository := .ctx.Values.global.image.repository -}}
{{- $tag := .tag | default (include "orbital.imageTag" .ctx) -}}
{{- printf "%s/%s/%s:%s" $registry $repository .name $tag -}}
{{- end -}}

{{/*
Build an image reference for a third-party component (LiveKit,
LiveKit-SIP, postgres image, valkey image, seaweedfs image).
Source registry/repo/name come from the component's own values.
  {{ include "orbital.thirdPartyImage" .Values.livekit.image }}
*/}}
{{- define "orbital.thirdPartyImage" -}}
{{- printf "%s/%s/%s:%s" .registry .repository .name .tag -}}
{{- end -}}

{{/*
Image-pull-secrets block. Every Pod template includes this so K8s
attaches the customer-created docker-registry secret on pull.
*/}}
{{- define "orbital.imagePullSecrets" -}}
{{- with .Values.global.imagePullSecrets -}}
imagePullSecrets:
{{- toYaml . | nindent 0 }}
{{- end -}}
{{- end -}}

{{/*
Resolve a tier's failover mode from values.
- If the explicit `failoverTiers.<tier>.mode` is set, use it.
- Otherwise derive from `<tier>.external.enabled` (true → "managed", false → "cluster").
  {{ include "orbital.tierMode" (dict "ctx" . "tier" "postgres") }}
*/}}
{{- define "orbital.tierMode" -}}
{{- $ctx := .ctx -}}
{{- $tier := .tier -}}
{{- $explicit := index $ctx.Values.failoverTiers $tier "mode" -}}
{{- if $explicit -}}
{{- $explicit -}}
{{- else if (index $ctx.Values $tier "external" "enabled") -}}
managed
{{- else -}}
cluster
{{- end -}}
{{- end -}}

{{/*
Storage class to use for a PVC. Empty values.global.storageClass means
"use cluster default" — emit nothing so the K8s default applies.
*/}}
{{- define "orbital.storageClass" -}}
{{- with .Values.global.storageClass -}}
storageClassName: {{ . | quote }}
{{- end -}}
{{- end -}}

{{/*
Spread a component's pods across nodes.

The gap this closes: "2 replicas" is not redundancy if Kubernetes puts
both on the same node, which it will happily do — the scheduler
optimises for fit, not for surviving a node loss. On a 3-node VKE
cluster that is not a hypothetical.

topologySpreadConstraints rather than podAntiAffinity because it
expresses the actual requirement ("keep these balanced across hosts")
instead of approximating it with "never co-locate", and it degrades
sensibly when there are fewer nodes than replicas.

whenUnsatisfiable defaults to ScheduleAnyway so a single-node dev or
k3s cluster still schedules everything; set `spreadPods.required=true`
on a real multi-node cluster to make it a hard constraint and have
Kubernetes refuse to co-locate rather than quietly doing it.

  {{ include "orbital.topologySpread" (list . "laravel") }}
*/}}
{{- define "orbital.topologySpread" -}}
{{- $ctx := index . 0 -}}
{{- $component := index . 1 -}}
{{- if $ctx.Values.spreadPods.enabled }}
topologySpreadConstraints:
  - maxSkew: 1
    topologyKey: kubernetes.io/hostname
    whenUnsatisfiable: {{ if $ctx.Values.spreadPods.required }}DoNotSchedule{{ else }}ScheduleAnyway{{ end }}
    labelSelector:
      matchLabels:
        {{- include "orbital.selectorLabels" (list $ctx $component) | nindent 8 }}
{{- end }}
{{- end -}}

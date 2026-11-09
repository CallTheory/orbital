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
Update strategy for Deployments that roll (see global.rollingUpdate).
  {{- include "orbital.rollingUpdate" . | nindent 2 }}
*/}}
{{- define "orbital.rollingUpdate" -}}
strategy:
  type: RollingUpdate
  rollingUpdate:
    maxSurge: {{ .Values.global.rollingUpdate.maxSurge }}
    maxUnavailable: {{ .Values.global.rollingUpdate.maxUnavailable }}
{{- end -}}

{{/*
Public hostname for LiveKit signaling when livekit.public is on.
*/}}
{{- define "orbital.livekitPublicHost" -}}
{{- .Values.livekit.public.host | default (printf "livekit.%s" .Values.global.domain) -}}
{{- end -}}

{{/*
Public hostname for LiveKit's TURN/TLS when livekit.public.turn is on.
*/}}
{{- define "orbital.livekitTurnHost" -}}
{{- .Values.livekit.public.turn.host | default (printf "turn.%s" .Values.global.domain) -}}
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
A PVC size, raised to global.minVolumeGi when it's smaller. Block
storage often has a floor (Vultr: 10 GB NVMe, 40 GB HDD) and rejects
anything under it, so a 5Gi request just stays Pending. Sizes not in
whole Gi pass through unchanged.
  {{ include "orbital.volumeSize" (list . .Values.asterisk.persistence.size) }}
*/}}
{{- define "orbital.volumeSize" -}}
{{- $ctx := index . 0 -}}
{{- $size := index . 1 | toString -}}
{{- $min := $ctx.Values.global.minVolumeGi | default 0 | int -}}
{{- if and (gt $min 0) (regexMatch "^[0-9]+Gi$" $size) (lt (regexFind "^[0-9]+" $size | int) $min) -}}
{{- printf "%dGi" $min -}}
{{- else -}}
{{- $size -}}
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
    # Count only nodes the pod could run on. Otherwise a cordoned node
    # (e.g. the one-node built-in pool left by a VKE resize) is a domain
    # holding 0 pods, and with DoNotSchedule no other node may take a
    # second replica: 4 agent workers on 3 schedulable nodes never fit.
    nodeTaintsPolicy: Honor
    labelSelector:
      matchLabels:
        {{- include "orbital.selectorLabels" (list $ctx $component) | nindent 8 }}
{{- end }}
{{- end -}}

{{/*
Domain of Orbital's own inbound addresses ({account}@<domain>):
inboundMail.domain, else inbound.<global.domain>.
*/}}
{{- define "orbital.inboundMailDomain" -}}
{{- .Values.inboundMail.domain | default (printf "inbound.%s" .Values.global.domain) -}}
{{- end -}}

{{/*
True ("true") when Haraka runs in this release.
*/}}
{{- define "orbital.harakaEnabled" -}}
{{- if and .Values.inboundMail.enabled (eq .Values.inboundMail.receiver "haraka") -}}true{{- end -}}
{{- end -}}

{{/*
Status-page cards to gray out ("not part of this deployment"),
comma-separated, for HEALTH_DISABLED_COMPONENTS.

health.disabledComponents, when set, replaces the derivation. Otherwise:
- the docker-compose stack's own HAProxy and dev panels: always;
- observability backends and hold-music streaming, which this chart
  doesn't deploy: unless health.probes says where one is
  (tempo also counts as present when tracing is on);
- Haraka, Ollama and local transcription: unless their feature is on
  here (or probed);
- plus anything in health.extraDisabled.
*/}}
{{- define "orbital.healthDisabled" -}}
{{- if .Values.health.disabledComponents -}}
{{- .Values.health.disabledComponents -}}
{{- else -}}
{{- $probes := .Values.health.probes | default dict -}}
{{- $off := list "haproxy" "pgadmin" "redis_commander" -}}
{{- range $key := list "prometheus" "loki" "grafana" "promtail" "alertmanager" "icecast" -}}
{{- if not (hasKey $probes $key) -}}{{- $off = append $off $key -}}{{- end -}}
{{- end -}}
{{- if not (or .Values.observability.tracing.enabled (hasKey $probes "tempo")) -}}{{- $off = append $off "tempo" -}}{{- end -}}
{{- if not (or (include "orbital.harakaEnabled" .) (hasKey $probes "haraka")) -}}{{- $off = append $off "haraka" -}}{{- end -}}
{{- if not (or .Values.ollama.enabled (hasKey $probes "ollama")) -}}{{- $off = append $off "ollama" -}}{{- end -}}
{{- if not (or .Values.localTranscription.enabled (hasKey $probes "whisper_local")) -}}{{- $off = append $off "whisper_local" -}}{{- end -}}
{{- concat $off (.Values.health.extraDisabled | default list) | uniq | join "," -}}
{{- end -}}
{{- end -}}

{{/*
Probe addresses for status-page cards whose component isn't at its
docker-compose hostname, as key=host:port pairs for HEALTH_PROBES:
health.probes, plus Haraka's Service when it runs here.
*/}}
{{- define "orbital.healthProbes" -}}
{{- $probes := dict -}}
{{- if include "orbital.harakaEnabled" . -}}
{{- $_ := set $probes "haraka" (printf "%s:%v" (include "orbital.componentName" (list . "haraka")) .Values.inboundMail.haraka.port) -}}
{{- end -}}
{{- $probes = merge (deepCopy (.Values.health.probes | default dict)) $probes -}}
{{- $pairs := list -}}
{{- range $key := keys $probes | sortAlpha -}}
{{- $pairs = append $pairs (printf "%s=%s" $key (get $probes $key)) -}}
{{- end -}}
{{- join "," $pairs -}}
{{- end -}}

{{/*
Platform Settings keys this release sets from its own values, for
ORBITAL_MANAGED_SETTINGS (read-only in the UI): the inbound mail domain
always, and the webhook token while Haraka reads it from app-secrets.
*/}}
{{- define "orbital.managedSettings" -}}
{{- $keys := list "services.inbound_mail.domain" -}}
{{- if include "orbital.harakaEnabled" . -}}{{- $keys = append $keys "services.inbound_mail.token" -}}{{- end -}}
{{- join "," $keys -}}
{{- end -}}

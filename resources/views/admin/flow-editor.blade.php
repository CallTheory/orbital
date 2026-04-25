<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Flow Editor — {{ $graph->name }} · {{ $client->name }}</title>
    @vite(['resources/js/flow-editor/main.ts'])
</head>
<body>
    <div
        id="flow-editor-root"
        data-graph-id="{{ $graph->id }}"
        data-graph-name="{{ $graph->name }}"
        data-client-id="{{ $client->id }}"
        data-client-name="{{ $client->name }}"
        @if ($focusFlowId) data-focus-flow-id="{{ $focusFlowId }}" @endif
    ></div>
</body>
</html>

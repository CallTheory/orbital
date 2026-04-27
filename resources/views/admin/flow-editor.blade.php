<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Flow Editor — {{ $orchestration->name }}{{ $client ? ' · ' . $client->name : ' · Platform' }}</title>
    @vite(['resources/js/flow-editor/main.ts'])
</head>
<body>
    <div
        id="flow-editor-root"
        data-orchestration-id="{{ $orchestration->id }}"
        data-orchestration-name="{{ $orchestration->name }}"
        data-is-shared="{{ $client ? '0' : '1' }}"
        data-close-url="{{ url('/admin/orchestrations') }}"
        @if ($client)
            data-client-id="{{ $client->id }}"
            data-client-name="{{ $client->name }}"
        @endif
        @if ($focusFlowId) data-focus-flow-id="{{ $focusFlowId }}" @endif
    ></div>
</body>
</html>

<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $persona->name }} — {{ $team->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full text-white">
    <div class="flex h-full min-h-screen flex-col">
        <header class="border-b border-white/10 px-6 py-4">
            <div class="mx-auto flex max-w-3xl items-center gap-3">
                <div class="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-600 text-sm font-bold">
                    {{ mb_substr($persona->name, 0, 1) }}
                </div>
                <div>
                    <h1 class="text-sm font-semibold">{{ $persona->name }}</h1>
                    <p class="text-xs text-gray-400">{{ $team->name }} — {{ $persona->role }}</p>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto px-6 py-4">
            <div class="mx-auto max-w-3xl">
                @livewire('chat-console', ['sessionId' => $chatSession->id])
            </div>
        </main>
    </div>

    @livewireScripts
</body>
</html>

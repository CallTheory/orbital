# CLAUDE.md

This file provides guidance to Claude Code when working with code in this repository.

## Project Overview

**Orbital** is a multi-tenant answering-service / virtual-receptionist platform. It's installable software: a call-center company (the "platform operator") runs Orbital to take calls on behalf of their customers ("tenants"). It combines Asterisk (SIP telephony), LiveKit (AI voice agents + WebRTC), and Laravel (management UI) into a unified platform where both AI agents and platform-staff operators handle calls. Tenants are customers — they get a portal to view messages, call history, and recordings taken on their behalf; they never configure infrastructure or take calls themselves.

## Commands

### Development
- **Start full dev environment**: `composer dev` — Runs server, queue, log viewer, and Vite concurrently
- **Start Docker containers**: `./vendor/bin/sail up -d`
- **Build frontend assets**: `pnpm run build`
- **Run tests**: `php artisan test`
- **Fix code style**: `vendor/bin/pint`
- **Generate telephony configs**: `php artisan orbital:generate-config`
- **Check system status**: `php artisan orbital:status`

### Initial Setup
1. `cp .env.example .env`
2. `composer install`
3. `php artisan key:generate`
4. `./vendor/bin/sail up -d`
5. `pnpm install`
6. `php artisan migrate --seed`
7. `pnpm run dev`

## Architecture Overview

### Core Technologies
- **Backend**: Laravel 12.x, PHP 8.4+, Filament 5, Livewire 4
- **Frontend**: Tailwind CSS 4, Alpine.js, SipJS (WebRTC softphone)
- **Database**: PostgreSQL 17, Valkey (Redis-compatible)
- **Telephony**: Asterisk 22 LTS (SIP), LiveKit (AI agents + media)
- **AI**: Python LiveKit agents worker (Anthropic, OpenAI, ElevenLabs)
- **Development**: Laravel Sail (Docker), Horizon (queues)

### Key Architectural Patterns

1. **Multi-tenancy**: Jetstream team-based. All domain models use `BelongsToTeam` trait for automatic scoping.
2. **Config Generation**: Asterisk/LiveKit configs are generated from database via Blade templates, not hand-edited.
3. **Agent Worker**: Python process connects to LiveKit, fetches persona config from Laravel API (Sanctum auth).
4. **Separation of Concerns**: Asterisk handles SIP routing, LiveKit handles media/AI — cleanly separated for failover.

### Directory Structure
- `app/Models/` — Eloquent models (all tenant-scoped via BelongsToTeam trait)
- `app/Filament/` — Admin panel resources, pages, widgets
- `app/Services/Telephony/` — Asterisk config generation, AMI/ARI clients
- `app/Livewire/` — Livewire components (softphone, etc.)
- `agent-worker/` — Python LiveKit agent worker
- `docker/` — Dockerfiles and service configs
- `docker/asterisk/` — Asterisk 22 Dockerfile and generated configs
- `docker/livekit/` — LiveKit server and SIP bridge configs
- `resources/views/asterisk/` — Blade templates for Asterisk config generation

### Key Models
- `SipTrunk` — External SIP provider connections
- `Extension` — Phone extensions (hardware SIP, WebRTC, AI agent, virtual)
- `AgentPersona` — AI agent configuration (prompts, voice, LLM provider)
- `Script` — Unified call scripts for AI and human agents
- `CallQueue` — Call routing queues with overflow to AI
- `RoutingRule` — Inbound call routing (DID patterns, time conditions)
- `CallLog` — Call history and recordings

## Development Guidelines

1. Follow Laravel conventions for file structure and naming
2. Use `BelongsToTeam` trait on all tenant-scoped models
3. Use Filament for admin CRUD interfaces
4. Queue long-running tasks using Jobs (Horizon)
5. Use form requests for validation
6. Keep controllers thin, move logic to services
7. Use `declare(strict_types=1);` in new PHP files
8. Use pnpm (not npm) for JS dependencies

# Installation

## Prerequisites

- Docker and Docker Compose
- PHP 8.4+
- Composer
- Node.js 20+ with pnpm

## Quick Start

```bash
cp .env.example .env
composer install
php artisan key:generate
./vendor/bin/sail up -d
pnpm install
php artisan migrate --seed
pnpm run dev
```

## Default Credentials

After seeding, the following accounts are available:

| Account | Email | Password | Panel |
|---------|-------|----------|-------|
| Super Admin | *(set during setup)* | *(set during setup)* | Admin |
| Demo Operator | demo-operator@orbital.test | password | Operator |
| Demo Supervisor | demo-supervisor@orbital.test | password | Operator |
| Demo Customer Contact | contact@democustomer.test | password | Portal |
| Acme Corp Contact | contact@acmecorp.test | password | Portal |

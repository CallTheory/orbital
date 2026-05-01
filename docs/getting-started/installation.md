# Installation

Two paths depending on what you're doing:

- **Local development** — clone the repo, `./vendor/bin/sail up -d`, edit code, see changes. Everything runs in docker-compose. **Section below.**
- **Production deployment** — point `tofu apply` at Vultr / AWS / your own VMs, then `helm install` the K8s chart. See the [orbital-setup repo](https://git.calltheory.com/calltheory/orbital-setup) for the full operator guide; the rest of this page covers local dev only.

## Local development quick start

### Prerequisites

- Docker and Docker Compose
- PHP 8.4+
- Composer
- Node.js 20+ with pnpm

### Quick Start

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

# Arcana Tarot

MVP de consultas de Tarot por chat em tempo real, com catálogo de cartas, candidaturas de profissionais, créditos pré-pagos e comissões por consulta.

> Nome provisório. Verifique disponibilidade de domínio, marca e redes sociais antes de publicar.

## Stack

Laravel 13 · PHP 8.4 · Docker Compose · Nginx · MySQL 8.4 · Redis · Laravel Reverb · Blade · Bootstrap 5.3 · JavaScript · Laravel Echo · Vite · PHPUnit.

## Documentação

- [Plano do projeto](docs/PROJECT_PLAN.md)
- [Registro de decisões](docs/DECISIONS.md)

## Requisitos

- PHP 8.4+ e Composer
- Node.js e npm
- Docker e Docker Compose

## Instalação

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
npm run build
```

## Executar com Docker (recomendado)

```bash
cp .env.example .env
php artisan key:generate
docker compose up -d --build
docker compose exec app php artisan migrate
```

Acesse a aplicação em http://localhost:8000.

### Comandos úteis

```bash
# Subir / parar / status / logs
docker compose up -d --build
docker compose down
docker compose ps
docker compose logs -f app nginx mysql redis queue

# Migrations e seeders
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:fresh --seed

# Rebuild após alterar o Dockerfile
docker compose up -d --build

# Serviços opcionais (perfis)
docker compose --profile mailpit up -d mailpit   # UI em http://localhost:8025
docker compose --profile reverb up -d reverb     # ativado no Prompt 12
```

> MySQL e Redis não são expostos fora da rede Docker por padrão.

## Executar sem Docker

```bash
php artisan serve
npm run dev
```

## Testes

```bash
php artisan test
```

## Qualidade de código

```bash
composer lint    # Pint --test (verificação de estilo)
composer format  # Pint (formatação)
```

## Status

Em desenvolvimento. O roteiro de construção incremental (prompts 00–20) está em `arcana-tarot-prompts/`.

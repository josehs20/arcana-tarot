# Arcana Tarot

MVP de consultas de Tarot por chat em tempo real, com catálogo de cartas, candidaturas de profissionais, créditos pré-pagos e comissões por consulta.

> Nome provisório. Verifique disponibilidade de domínio, marca e redes sociais antes de publicar.

## Stack

Laravel 13 · PHP 8.4 · Docker Compose · Nginx · MySQL 8.4 · Redis · Laravel Reverb · Blade · Bootstrap 5.3 · JavaScript · Laravel Echo · Vite · PHPUnit.

## Documentação

- [Plano do projeto](docs/PROJECT_PLAN.md)
- [Registro de decisões](docs/DECISIONS.md)
- [Tempo real com Reverb/Echo](docs/REALTIME.md)
- [Carteira e livro-razão](docs/WALLET_LEDGER.md)
- [Cobrança por tempo](docs/TIME_BILLING.md)
- [Política de comissões](docs/COMMISSION_POLICY.md)
- [Relatórios administrativos](docs/ADMIN_REPORTING.md)
- [Trabalhe conosco e ativação de atendente](docs/ATTENDANT_ONBOARDING.md)
- [Segurança, privacidade e retenção](docs/SECURITY_PRIVACY.md)
- [Implantação e operação](docs/DEPLOYMENT.md)

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

Antes de subir a stack, crie o `.env` e preencha `REVERB_APP_SECRET` com um
segredo local (o exemplo deixa o campo vazio de propósito):

```bash
cp .env.example .env
php artisan key:generate
openssl rand -hex 32
# edite .env e cole o valor em REVERB_APP_SECRET
docker compose up -d --build
docker compose exec app php artisan migrate
```

Acesse a aplicação em http://localhost:8000. O chat em tempo real usa o Reverb
em `ws://localhost:8080`; o worker `queue` processa os eventos de broadcast.

### Comandos úteis

```bash
# Subir / parar / status / logs
docker compose up -d --build
docker compose down
docker compose ps
docker compose logs -f app nginx mysql redis queue reverb

# Migrations e seeders
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:fresh --seed

# Rebuild após alterar o Dockerfile
docker compose up -d --build

# Reiniciar processos após deploy
docker compose exec app php artisan queue:restart
docker compose exec app php artisan reverb:restart

# Serviço opcional
docker compose --profile mailpit up -d mailpit   # UI em http://localhost:8025
```

> MySQL, Redis e Reverb não são expostos fora da rede Docker, exceto a porta
> WebSocket `8080`, necessária para o navegador.

## Criar o primeiro administrador

O cadastro público cria apenas contas de **cliente**. Para criar o primeiro administrador **sem senha fixa no repositório**, use:

```bash
docker compose exec app php artisan arcana:create-admin
```

O comando pergunta nome, e-mail e senha (ou gera uma senha aleatória). Também aceita opções:

```bash
docker compose exec app php artisan arcana:create-admin --name="Admin" --email="admin@arcana.local"
```

> **Dados de demonstração**: `php artisan db:seed` cria contas, catálogo, consultas e finanças fictícias apenas em ambiente local/homologação. Consulte o manual [docs/SEEDERS_E_DADOS_DE_TESTE.md](docs/SEEDERS_E_DADOS_DE_TESTE.md) para credenciais, cenários e cuidados.

## Papéis

- `admin` — gestão administrativa (`/admin`)
- `attendant` — área do atendente (`/attendant`)
- `client` — área do cliente (`/client`)

Após o login, o usuário é redirecionado para o painel do seu papel. O acesso é validado no servidor pelo middleware `role`.

Os três painéis usam a mesma casca (`<x-layouts.panel>`): navbar e sidebar
compartilhadas, cartões de KPI (`<x-ui.stat-card>`) e gráficos SVG sem
JavaScript (`<x-ui.chart>`). A estrutura, as classes e as props estão em
[docs/DESIGN_SYSTEM.md](docs/DESIGN_SYSTEM.md#63-painel--x-layoutspanel).

O redesign visual, os painéis, os gráficos e o CMS estão documentados em
[docs/REDESIGN_VISUAL_E_CMS.md](docs/REDESIGN_VISUAL_E_CMS.md). Conteúdo
público (identidade, seções da home, navegação, FAQ e imagens) é editável em
**Conteúdo do site** (`/admin/conteudo`); detalhes operacionais e de
persistência em [docs/SITE_CONTENT.md](docs/SITE_CONTENT.md).

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

MVP dos Prompts 01–20 implementado e testado, incluindo o redesign visual dos
painéis (casca compartilhada, KPIs e gráficos SVG). A avaliação de prontidão, as
pendências de produção e o runbook estão em [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).
O status é **pronto com ressalvas para homologação; não pronto para produção**
enquanto PIX real, revisão jurídica, MFA, backups testados e política de retenção
não forem concluídos.
# arcana-tarot

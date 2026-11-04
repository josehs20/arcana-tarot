# Implantação e operação — Arcana Tarot MVP

> Runbook operacional dos Prompts 01–20. Não execute deploy em produção sem
> autorização explícita. Nenhuma senha, chave ou domínio real está incluído.

## 1. Requisitos e versões

| Componente | Versão usada no projeto |
|---|---|
| PHP | **8.4 obrigatório** (mínimo 8.4.1) — container `php:8.4-fpm-alpine`; no Hostoo, escolhido no painel |
| Laravel | 13.x |
| MySQL | 8.4 |
| Redis | 7 Alpine |
| Laravel Reverb | 1.x |
| Node.js | 22.x |
| npm | 9+ |
| Docker / Compose | Docker 29+, Compose v2+ |
| PHPUnit | 12.x |
| Navegador | Versão atual com WebSocket e JavaScript habilitados |

### 1.1 PHP 8.4 é requisito, não preferência

O `composer.json` declara `"php": "^8.4"` e os componentes do Symfony usados
pelo Laravel 13 exigem `>= 8.4.1`. Com isso o `vendor/composer/platform_check.php`
— gerado pelo Composer e carregado no *bootstrap* de toda requisição — aborta com
`Composer detected issues in your platform: ... require a PHP version ">= 8.4.1"`
em qualquer PHP anterior. O modo de falha é fechado: erro 500 imediato, nunca um
ambiente meio funcional.

| Série | Suporte (php.net, consultado em 04/11/2026) |
|---|---|
| 8.5 | correções de bugs e segurança até 31/12/2027; segurança até 31/12/2029 |
| **8.4 (alvo do projeto)** | correções de bugs e segurança até 31/12/2026; segurança até 31/12/2028 |
| 8.3 | apenas segurança, até 31/12/2027 |
| 8.2 | apenas segurança, até 31/12/2026 |
| 8.1 e anteriores | **fim de vida** — sem correções de segurança |

Séries em fim de vida (`5.6`, `7.x`, `8.0`, `8.1`) ainda aparecem como padrão em
painéis de hospedagem compartilhada e **não recebem patch de segurança**. Fixar
8.4 é o que mantém o servidor dentro da janela de suporte.

Dependências entram na mesma conta: os advisories do Symfony publicados em
28/10 e 04/11/2026 só foram zerados depois de subir `symfony/*` para
`8.1.10`/`8.1.11` (`composer audit` → `No security vulnerability advisories found`).

Verificação:

```bash
docker compose exec app php -v                      # PHP 8.4.x
docker compose exec app composer check-platform-reqs
```

O host de desenvolvimento pode não ter `pdo_sqlite`, `pdo_mysql` ou `redis`.
Nesse caso, execute testes, migrations e comandos dentro do container.

## 2. Configuração do `.env`

```bash
cp .env.example .env
php artisan key:generate
openssl rand -hex 32   # REVERB_APP_SECRET
openssl rand -hex 32   # PAYMENT_SANDBOX_WEBHOOK_SECRET (somente sandbox)
```

Preencha no `.env`:

- `APP_NAME`, `APP_URL` e `APP_ENV`;
- `APP_KEY` (nunca reutilize entre ambientes);
- `DB_*`;
- `REDIS_*`;
- `REVERB_*` e `VITE_REVERB_*`;
- `PAYMENT_SANDBOX_WEBHOOK_SECRET` se for testar PIX sandbox;
- mail, se aplicável.

Nunca versione `.env`, `.env.production`, dumps SQL, chaves ou credenciais.
`.env`, `*.sql`, `*.sql.gz` e variantes de produção já estão no `.gitignore`.

## 3. Subir e parar o Docker Compose

```bash
# build + subir em background
docker compose up -d --build

# status e logs
docker compose ps
docker compose logs -f app nginx mysql redis queue reverb

# parar sem apagar volumes
docker compose down

# parar e apagar volumes (DESTRUTIVO; somente ambiente descartável)
docker compose down -v
```

Serviços:

| Serviço | Função | Exposição |
|---|---|---|
| `app` | PHP-FPM e comandos Artisan | interna |
| `nginx` | HTTP em `${APP_PORT:-8000}` | pública no host de desenvolvimento |
| `mysql` | Banco | somente rede interna |
| `redis` | cache, filas e sessões | somente rede interna |
| `queue` | worker de filas | interna |
| `reverb` | WebSocket | porta `${REVERB_PORT:-8080}` |
| `mailpit` | e-mail local opcional | perfil `mailpit` |

MySQL e Redis não são publicados no host. Em produção, mantenha essa regra e
restrinja a porta do Reverb/HTTP por firewall ou proxy.

## 4. Dependências, migrations e seeders

Instalação limpa:

```bash
composer install
npm install
npm run build
docker compose exec app php artisan migrate --force
```

Se o `vendor` não existir no host, ele pode ser instalado no container (Composer
está na imagem). Node/npm **não** estão na imagem `app`; rode assets no host ou
em um estágio de CI que tenha Node 22:

```bash
docker compose exec app composer install
npm install
npm run build
```

Em CI, publique `public/build` junto do código ou adicione um estágio Node ao
pipeline. Não é necessário instalar Node dentro do container PHP.

Regras:

- `php artisan migrate --force` é o comando de produção; migrations devem ser
  revisadas e testadas antes.
- `php artisan db:seed` cria dados de demonstração com senha definida por
  `ARCANA_DEMO_PASSWORD`. O seeder é bloqueado com `APP_ENV=production`; nunca
  use essas credenciais em produção. Ver `docs/SEEDERS_E_DADOS_DE_TESTE.md`.
- `php artisan migrate:fresh` apaga todas as tabelas. Use apenas em ambiente
  descartável.
- Faça backup do banco e dos arquivos privados antes de qualquer migration em
  produção (seção 9).

### 4.1 Catálogo de baralhos em produção

`db:seed` está bloqueado em produção, então a vitrine não pode ser preenchida por
seeder. O catálogo real é instalado pelo comando operacional:

```bash
docker compose exec app php artisan arcana:import-tarot-catalog
```

- lê o acervo versionado em `database/seeders/assets/tarot` (114 JPEGs em domínio
  público) e grava as frentes em `storage/app/private/tarot/cards/`;
- cria/atualiza baralhos e cartas pelo `slug`, sem duplicar em reexecuções;
- nunca sobrescreve imagem enviada pela administração;
- aceita `--source=/caminho/do/acervo` quando as imagens forem enviadas fora do
  projeto.

Sem esse passo a loja sobe com os seletores de carta vazios, e não há como
enviar cartas no chat.

## 5. Criar o primeiro administrador

O cadastro público cria somente clientes. Crie o primeiro admin no servidor:

```bash
docker compose exec app php artisan arcana:create-admin
```

Ou com opções:

```bash
docker compose exec app php artisan arcana:create-admin \
  --name="Admin" \
  --email="admin@example.com"
```

Se a senha não for informada, o comando pergunta ou gera uma senha aleatória.
Guarde-a em cofre de senhas e remova do histórico do shell quando aplicável.

## 6. Criar atendente após aprovação

A aprovação de candidatura não cria acesso. Use o comando operacional:

```bash
docker compose exec app php artisan arcana:create-attendant \
  --name="Nome Completo" \
  --email="atendente@example.com" \
  --display-name="Nome público" \
  --commission-rate="25" \
  --price-text="100" \
  --publish
```

Sem `--publish`, o perfil fica não publicado e offline. Sem `--price-text`
(e demais `--price-audio`/`--price-video`, em centavos por minuto), o atendente
não recebe solicitações até que a administração defina os preços em
**Admin → Modalidades**. O comando registra auditoria e não altera a
candidatura. Detalhes em `docs/ATTENDANT_ONBOARDING.md`.

## 7. Worker, Reverb, scheduler e assets

No Compose, `queue` e `reverb` já sobem como serviços. Verifique:

```bash
docker compose ps queue reverb
docker compose logs -f queue reverb
```

Produção deve gerenciar esses processos com supervisor/systemd/orquestrador de
containers, com reinício automático, limite de memória e logs persistentes. Não
execute `queue:work` ou `reverb:start` em um terminal esquecido.

Scheduler (uma entrada de cron no host/container de operação):

```cron
* * * * * cd /caminho/do/projeto && php artisan schedule:run >> /dev/null 2>&1
```

Em **hospedagem compartilhada** não há worker permanente: além do
`schedule:run`, é obrigatória uma segunda entrada que drena a fila e encerra
sozinha. Sem ela, os jobs enfileirados nunca executam.

```cron
* * * * * cd /caminho/do/projeto && php artisan queue:work --stop-when-empty --max-time=50 --tries=3 >> storage/logs/queue.log 2>&1
```

Nesse cenário o transporte em tempo real também muda para polling. Ver
[ARQUITETURA_PRODUCAO_HOSTOO.md](ARQUITETURA_PRODUCAO_HOSTOO.md) e o modelo
[`.env.hostoo.example`](../.env.hostoo.example).

Assets:

```bash
npm run build
```

Em desenvolvimento, `npm run dev` ou `composer run dev`. Se aparecer “Unable to
locate file in Vite manifest”, gere o build novamente.

## 8. Testes, PIX sandbox e produção

### 8.1 Testes

```bash
docker compose exec app php artisan test --compact
docker compose exec app vendor/bin/pint --test
npm run build
```

O host sem `pdo_*` deve usar o container. A suíte cobre autenticação,
autorização, isolamento, catálogo, chat, Reverb, carteira, PIX, cobrança,
comissão, históricos, auditoria e fluxo integrado. O build de frontend precisa
passar antes do deploy.

### 8.2 PIX em sandbox

No `.env` de desenvolvimento/homologação:

```dotenv
PAYMENT_PROVIDER=pix_sandbox
PAYMENT_SANDBOX_ENABLED=true
PAYMENT_SANDBOX_WEBHOOK_SECRET=<segredo-aleatorio>
PAYMENT_SANDBOX_WEBHOOK_TOLERANCE_SECONDS=300
PAYMENT_SANDBOX_CHARGE_TTL_MINUTES=30
PAYMENT_MIN_AMOUNT_CENTS=100
PAYMENT_MAX_AMOUNT_CENTS=1000000
```

Simule um pagamento pendente:

```bash
docker compose exec app php artisan pix:sandbox-simulate <payment_id> approved
docker compose exec app php artisan pix:sandbox-simulate <payment_id> declined
docker compose exec app php artisan pix:sandbox-simulate <payment_id> expired
```

O comando assina o payload e chama o webhook real da aplicação; não altera o
pagamento diretamente. O sandbox não gera PIX válido, não movimenta dinheiro e
deve permanecer desabilitado em produção.

Para produção, é obrigatório escolher um provedor, implementar
`App\Support\Payments\Contracts\PixProvider`, configurar credenciais fora do
repositório, validar mTLS/assinatura conforme o provedor, testar conciliação e
manter `PAYMENT_SANDBOX_ENABLED=false`. Ver `docs/PIX_SANDBOX.md`.

### 8.3 Variáveis de produção

Checklist mínimo de `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://seu-dominio.example
APP_KEY=<chave-gerada>
LOG_LEVEL=warning
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
SESSION_SAME_SITE=lax
QUEUE_CONNECTION=redis
CACHE_STORE=redis
DB_HOST=mysql
REDIS_HOST=redis
PAYMENT_SANDBOX_ENABLED=false
```

- Sirva a aplicação por HTTPS e redirecione HTTP para HTTPS no proxy de borda.
- Configure `VITE_REVERB_HOST`, `VITE_REVERB_PORT` e `VITE_REVERB_SCHEME` com o
  hostname/porta públicos do WebSocket e **rebuild** dos assets. `REVERB_HOST` é
  o endereço interno usado pelo servidor.
- Configure `TrustProxies` no Laravel se houver proxy/load balancer terminando
  TLS; valide o esquema e o IP do cliente antes de confiar em headers.
- Restrinja `/admin` por rede/VPN se possível.
- Rotacione `APP_KEY` apenas com plano de migração de dados criptografados.
- Limite acesso ao MySQL, Redis, storage e `.env` ao menor conjunto de pessoas.
- Configure HSTS no proxy de borda somente depois de validar HTTPS em todos os
  subdomínios.
- CSP estrita deve ser testada em homologação antes de ser habilitada.
- Em hospedagem compartilhada (Hostoo), troque `SESSION_DRIVER`, `CACHE_STORE` e
  `QUEUE_CONNECTION` para `database` e use `BROADCAST_CONNECTION=log` com
  `CONSULTATION_WEBSOCKET_ENABLED=false`. O `TRUSTED_PROXIES` **não** vale no
  `.env`: injete com `SetEnv TRUSTED_PROXIES "*"` no servidor. Checklist completo
  em [ARQUITETURA_PRODUCAO_HOSTOO.md](ARQUITETURA_PRODUCAO_HOSTOO.md) e teste
  local em [TESTE_LOCAL_MODO_HOSTOO.md](TESTE_LOCAL_MODO_HOSTOO.md).

## 9. Migrations com backup e rollback

Procedimento recomendado:

1. avise a janela de manutenção e ative `php artisan down` (se aplicável);
2. gere backup do banco e dos arquivos privados (seção 10);
3. registre a versão atual do código (`git rev-parse HEAD`) e `migrate:status`;
4. revise a migration e teste em cópia do banco;
5. execute `php artisan migrate --force`;
6. rode smoke tests (`/up`, login, painel, consulta, imagem, webhook sandbox);
7. desative manutenção e monitore logs/filas.

Rollback:

```bash
php artisan migrate:status
php artisan migrate:rollback --step=1 --force
```

`rollback` só funciona se a migration tiver `down()` seguro. Em produção, muitas
vezes é mais seguro corrigir para frente com nova migration. Nunca use
`migrate:fresh` em produção. Se houver corrupção, restaure o backup em um banco
limpo, valide e só então troque a aplicação.


## 10. Backups e teste de restauração

### 10.1 O que salvar

- banco MySQL (fonte de verdade transacional);
- `storage/app/private/tarot` (imagens de catálogo e snapshots de chat);
- `storage/app/public`, se houver fotos públicas;
- `.env`/segredos em cofre separado, nunca junto do backup de dados;
- versão do código (`git rev-parse HEAD`) e versão das migrations.

Redis não é fonte de verdade persistente do domínio; não substitui backup do
MySQL nem dos arquivos.

### 10.2 Backup do banco

```bash
mkdir -p /var/backups/arcana
STAMP=$(date -u +%Y%m%dT%H%M%SZ)

docker compose exec -T mysql sh -c \
  'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE"' \
  | gzip > "/var/backups/arcana/db-${STAMP}.sql.gz"

tar -czf "/var/backups/arcana/storage-${STAMP}.tar.gz" storage/app/private storage/app/public
sha256sum /var/backups/arcana/*-${STAMP}.* > "/var/backups/arcana/checksums-${STAMP}.txt"
```

Restrinja permissões do diretório (`chmod 700`), criptografe em repouso e copie
para local/off-site com retenção definida. Teste a cópia e monitore falhas do job.

### 10.3 Teste de restauração (ambiente isolado)

Nunca restaure por cima da produção para “testar”. Use banco/container
descartável:

```bash
# cria banco de teste
docker compose exec -T mysql sh -c \
  'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS arcana_restore_test; CREATE DATABASE arcana_restore_test;"'

# restaura o dump no banco de teste
gunzip -c /var/backups/arcana/db-<STAMP>.sql.gz \
  | docker compose exec -T mysql sh -c \
    'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" arcana_restore_test'

# confere contagens e ledger
docker compose exec -T mysql sh -c \
  'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" arcana_restore_test -e "SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM consultations; SELECT COALESCE(SUM(CASE WHEN type=\'reversal\' THEN -commission_amount_cents ELSE commission_amount_cents END),0) FROM commission_ledger_entries;"'
```

Depois:

1. restaure os arquivos em um diretório temporário e compare checksums;
2. suba uma instância de homologação apontando para o banco restaurado;
3. valide login, consulta, imagem, histórico e webhook sandbox;
4. registre data, responsável, duração e resultado do teste;
5. descarte o ambiente de teste.

## 11. Monitoramento e operação

- Healthcheck HTTP: `GET /up`.
- Containers: `docker compose ps`, `docker compose logs -f app nginx queue reverb`.
- Logs da aplicação: `storage/logs/laravel.log` (ou canal configurado).
- Filas: monitore `failed_jobs`, tamanho da fila Redis e reinicie worker após
  deploy.
- Reverb: monitore porta, conexões e logs; o fallback HTTP mantém o chat
  funcional se o WebSocket cair.
- MySQL/Redis: espaço em disco, conexões, latência e backups.
- Alertas mínimos: erro 5xx, falha de webhook, fila parada, disco > 80%, backup
  atrasado, expiração de certificado.
- Não registre conteúdo de conversa, senha, token ou payload PIX.

Reinício após deploy:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan optimize
php artisan queue:restart
php artisan reverb:restart
docker compose restart queue reverb
```

`queue:restart` sinaliza o worker para encerrar após o job atual; o supervisor
deve reiniciá-lo. `reverb:restart` faz o mesmo para o servidor WebSocket.


## 12. Checklist de lançamento

- [ ] `APP_ENV=production` e `APP_DEBUG=false`.
- [ ] `APP_KEY` gerada e guardada em cofre.
- [ ] `.env`, chaves e dumps fora do Git; permissões restritas.
- [ ] HTTPS válido, redirect HTTP→HTTPS e cookies `Secure`/`HttpOnly`.
- [ ] MySQL e Redis não expostos publicamente.
- [ ] Worker de fila e Reverb gerenciados por serviço/supervisor.
- [ ] Scheduler `schedule:run` ativo e monitorado.
- [ ] Webhook acessível por HTTPS e validado com assinatura do provedor.
- [ ] `PAYMENT_SANDBOX_ENABLED=false` em produção; provedor real testado em
      sandbox oficial.
- [ ] Migrations aplicadas com backup e rollback documentado.
- [ ] Backup do banco + arquivos executado e **restauração testada**.
- [ ] Permissões de admin revisadas, contas antigas desativadas, MFA definido.
- [ ] Regras de cobrança e comissão revisadas em `docs/TIME_BILLING.md` e
      `docs/COMMISSION_POLICY.md`.
- [ ] Fluxo Trabalhe conosco, estados, privacidade e ativação manual revisados em
      `docs/ATTENDANT_ONBOARDING.md`.
- [ ] Termos e privacidade aprovados por jurídico; remover aviso de provisório.
- [ ] Política de retenção aprovada (`docs/SECURITY_PRIVACY.md`).
- [ ] Testes automatizados e build aprovados.
- [ ] Smoke test manual: cadastro, recarga sandbox, consulta, chat, carta,
      encerramento, histórico, comissão e auditoria.
- [ ] Monitoramento e alertas configurados.
- [ ] Plano de resposta a incidentes e contatos definidos.

## 13. Limitações conhecidas e fora do MVP

- **PIX real não implementado**; apenas sandbox. Não há conciliação bancária,
  estorno automático de recarga ou chargeback.
- **Comissão não é paga** ao atendente; é lançamento contábil interno.
- Não há saque, carteira de comissão, antecipação ou transferência.
- Não há cálculo de impostos, custos, lucro líquido ou fechamento contábil.
- Termos de uso e política de privacidade são modelos provisórios.
- Não há expurgo automático de dados; retenção depende de decisão.
- Não há MFA, verificação de e-mail obrigatória, portal do candidato ou edição de
  perfil pelo atendente.
- Não há acesso administrativo a mensagens; qualquer exceção exige processo
  próprio e auditoria.
- Não há teste de carga, pentest, DAST, WAF dedicado ou SIEM.
- WebSocket foi validado por testes de canal/evento e fallback HTTP; falta teste
  E2E em navegador real com reconexão.
- O host de desenvolvimento pode não ter drivers PHP; use Docker.
- O domínio, marca, textos legais e valores comerciais ainda precisam de decisão
  do responsável.
- `credit_unit_price_cents`, `attendant_modality_prices.price_per_minute_cents`
  e taxas de comissão são configurações administrativas; os valores comerciais
  não são fixados no código.

## 14. Avaliação objetiva de prontidão

**Status: pronto com ressalvas para homologação/demonstração controlada; não
pronto para produção.**

Justificativa:

1. O fluxo principal e os controles críticos estão implementados e cobertos por
   testes automatizados, com ledger imutável, snapshot de comissão, autorização
   por participante e isolamento de dados.
2. O build, a suíte e as auditorias de dependências passam no ambiente Docker.
3. Produção continua bloqueada por decisões e implementações externas:
   provedor PIX real e conciliação, documentos legais definitivos, MFA,
   política de retenção aprovada, backup/restauração testada, TLS/proxy,
   monitoramento e processo de suporte.
4. Nenhuma afirmação de conformidade jurídica é feita. A avaliação deve ser
   revisada após cada item pendente ser concluído e testado em ambiente de
   homologação.


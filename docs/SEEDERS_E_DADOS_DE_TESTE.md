# Seeders e dados de teste

Manual de preparação do ambiente local e de uso dos dados fictícios do Arcana Tarot.

> **Aviso:** todas as contas, senhas, pagamentos, consultas e imagens descritos aqui são de demonstração. Eles não representam clientes, atendentes, transações ou cartas reais. Os seeders são bloqueados quando `APP_ENV=production`.

## A. Pré-requisitos

- Docker Engine com Docker Compose v2.
- Porta `8000` livre para o Nginx e `8080` para o Reverb; `8025` apenas se o perfil Mailpit for usado.
- Node.js 20+ e npm no host para compilar os assets com Vite.
- O container `app` já contém PHP 8.4, Composer, extensões MySQL/Redis/GD e o código montado em `/var/www/html`.
- Serviços definidos em `compose.yaml`: `app`, `nginx`, `mysql` (MySQL 8.4), `redis` (Redis 7), `queue` e `reverb`.
- Arquivo `.env` baseado em `.env.example`, com banco MySQL, Redis e `APP_KEY` configurados.
- `ARCANA_DEMO_PASSWORD` define a senha inicial das contas fictícias. O valor padrão de `.env.example` é apenas local.
- As imagens das cartas são gravadas no disco privado `tarot_private` (`storage/app/private/tarot`). Elas não dependem de `storage:link` nem de URLs externas.
- O seeder copia para o disco privado as frentes de carta versionadas em `database/seeders/assets/tarot`, com imagens JPEG já normalizadas em 320 × 586. O Tarot de Marseille (Jean Dodal, Lyon, 1701-1715), o Petit Lenormand da BnF e o Rider-Waite-Smith de 1909 usam digitalizações em domínio público; os baralhos de 52 cartas e espanhol usam frentes originais desenhadas para o projeto. Nenhum download acontece em tempo de execução.

## B. Sequência de comandos

Executar os comandos de host no diretório raiz do projeto:

```bash
cd /caminho/para/arcana-tarot
```

1. **Preparar as variáveis de ambiente**

```bash
cp .env.example .env
```

Se o `.env` já existir, não o sobrescreva sem conferir as credenciais locais.

2. **Subir os serviços**

```bash
docker compose up -d --build
```

3. **Instalar dependências PHP, se necessário**

```bash
docker compose exec app composer install
```

4. **Gerar a chave da aplicação, se estiver vazia**

```bash
docker compose exec app php artisan key:generate
```

5. **Aplicar as migrations em um banco local vazio**

```bash
docker compose exec app php artisan migrate
```

6. **Popular os dados de demonstração**

```bash
docker compose exec app php artisan db:seed
```

7. **Criar o link público de storage (opcional)**

```bash
docker compose exec app php artisan storage:link
```

As imagens das cartas ficam no disco privado e são servidas por rota autorizada. O link público só é necessário para recursos que usam o disco `public`, como eventuais fotos de perfil.

8. **Compilar os assets no host**

```bash
npm ci
npm run build
```

Use `npm run dev` durante o desenvolvimento. Se aparecer “Unable to locate file in Vite manifest”, recompile os assets.

9. **Verificar a aplicação**

```bash
docker compose ps
curl -I http://localhost:8000/up
```

A aplicação fica em `http://localhost:8000`; o WebSocket, em `ws://localhost:8080`.

10. **Executar a suíte automatizada**

```bash
docker compose exec app php artisan test --compact
```

O `phpunit.xml` usa SQLite em memória, então os testes não tocam no banco MySQL de desenvolvimento.

> Comandos de host: `cp`, `npm`, `docker compose`. Comandos de aplicação: tudo após `docker compose exec app`.

## C. Como popular o banco

O comando principal é:

```bash
docker compose exec app php artisan db:seed
```

Ele executa, nesta ordem:

1. `SettingsSeeder`
2. `AdminSeeder`
3. `AttendantSeeder`
4. `ClientSeeder`
5. `AttendantApplicationSeeder`
6. `TarotDeckSeeder`
7. `PaymentSeeder`
8. `WalletSeeder`
9. `ConsultationSeeder`
10. `CommissionSeeder`

Para executar uma seeder específica:

```bash
docker compose exec app php artisan db:seed --class=TarotDeckSeeder
```

Ao executar seeders isoladas, respeite as dependências. Por exemplo, `WalletSeeder` exige que `PaymentSeeder` já tenha criado os pagamentos confirmados, e `ConsultationSeeder` exige perfis, cartas e saldo.

Não é necessário passar `--force` em desenvolvimento. O `DatabaseSeeder` e cada seeder individual recusam execução com `APP_ENV=production`.

## D. Como reiniciar os dados de demonstração

### Executar novamente as seeders

É seguro e recomendado para manutenção:

```bash
docker compose exec app php artisan db:seed
```

Contas, perfis, candidaturas, baralhos, cartas, pagamentos, créditos, consultas, mensagens e comissões usam chaves estáveis. Uma segunda execução não duplica registros nem altera senhas existentes.

A única atualização automática em registros já existentes é a disponibilidade publicada dos perfis de atendente (`is_published`, `availability`, `last_seen_at`), para restaurar o cenário online/offline. Nome, bio, especialidades, som de chamada, taxa de comissão, senha e papel não são reaplicados.

Se um cenário já foi consumido (por exemplo, a consulta concluída foi encerrada antes do previsto), a seeder não cria uma segunda consulta para o mesmo par cliente/atendente. Isso evita duplicação, mas também significa que o cenário só volta ao estado inicial em um banco novo.

### Aplicar apenas migrations pendentes

```bash
docker compose exec app php artisan migrate
```

Preserva todos os dados existentes.

### Recriar o banco local

Somente em um banco descartável e com autorização explícita:

```bash
docker compose exec app php artisan migrate:fresh --seed
```

> **Atenção:** `migrate:fresh` apaga todas as tabelas e todos os dados do banco configurado. Não execute em produção, homologação compartilhada ou qualquer banco com informações importantes. Faça backup antes.

### Testes automatizados

```bash
docker compose exec app php artisan test --compact
```

Os testes usam SQLite `:memory:` e `RefreshDatabase`. Eles não reaproveitam nem apagam o banco MySQL local.

## E. Contas de demonstração

Senha inicial de todas as contas: `ArcanaDemo!2026` ou o valor de `ARCANA_DEMO_PASSWORD` no `.env`.

Essas credenciais são exclusivamente locais, fictícias e não devem ser reutilizadas em produção. O seeder nunca redefine a senha de uma conta que já exista.

### Administração

| Papel | Nome | E-mail | Cenário |
|---|---|---|---|
| `admin` | Administração Arcana | `admin@arcana.local` | Acesso completo ao painel administrativo, candidaturas, catálogo, financeiro, comissões e auditoria |

### Atendentes

| Chave | Nome | E-mail | Comissão | Som de chamada | Estado |
|---|---|---|---|---|---|
| `marina` | Marina Luz | `marina.luz@arcana.local` | 30% | Sirene (padrão) | Online, publicada e disponível; possui histórico concluído de 13 minutos |
| `rafael` | Rafael Monteiro | `rafael.monteiro@arcana.local` | 25% | Sirene (padrão) | Offline e publicado |
| `iris` | Íris Campos | `iris.campos@arcana.local` | 20% | Sirene (padrão) | Online, publicada e disponível; sem atendimento ativo |

A comissão é congelada no aceite da consulta. Alterar a taxa atual de um atendente não altera comissões históricas.

Os seeders usam a sirene e não enviam MP3 personalizado: uploads de som são
arquivos enviados pela administração, não dados de demonstração. O arquivo fica
no disco `public` e exige `php artisan storage:link` no ambiente.

### Clientes

| Chave | Nome | E-mail | Cenário com os settings padrão |
|---|---|---|---|
| `free` | Clara Freitas | `cliente.livre@arcana.local` | 50 créditos (R$ 50,00; 5000 centavos), sem consulta aberta; pode solicitar atendimento |
| `no_balance` | Caio Nogueira | `cliente.sem-saldo@arcana.local` | Saldo 0 após consulta encerrada por insuficiência de créditos |
| `history` | Helena Prado | `cliente.historico@arcana.local` | Histórico concluído de 13 minutos, com comissão de R$ 3,90; saldo 27 créditos |
| `pending` | Paulo Mendes | `cliente.pendente@arcana.local` | Saldo 20 créditos e nenhum atendimento aberto; pode iniciar uma consulta pelo site |
| `active` | Alice Ribeiro | `cliente.ativo@arcana.local` | Saldo 20 créditos e nenhum atendimento aberto; pode iniciar uma consulta pelo site |

As chaves `pending` e `active` são nomes herdados dos antigos cenários de solicitação pendente e consulta ativa. Essas contas hoje não têm solicitação nem consulta aberta: o seeder nunca abre atendimento em nome de uma conta que não está logada, e o primeiro atendimento é sempre iniciado pelo cliente autenticado no site.

Valores em centavos: 1 crédito = R$ 1,00 e saldo mínimo de 1 minuto, conforme `SettingsSeeder`. Os preços de demonstração ficam em `attendant_modality_prices` (R$ 1,00/min no texto, R$ 2,00/min no áudio e R$ 3,00/min no vídeo para cada atendente demo), criados por `AttendantSeeder` via `DemoData::modalityPricePerMinuteCents()`. O saldo mínimo em centavos é o preço do atendente multiplicado pelos minutos mínimos. Se o banco já possuir configurações diferentes, os pagamentos e saldos são calculados com os valores vigentes, sem sobrescrever as configurações.

### Candidaturas

As candidaturas abaixo não criam contas de usuário:

- `candidatura.pendente@arcana.local` — pendente;
- `candidatura.em-analise@arcana.local` — em análise;
- `candidatura.aprovada@arcana.local` — aprovada, com revisão do admin;
- `candidatura.rejeitada@arcana.local` — rejeitada, com revisão do admin.

A aprovação é apenas uma decisão de triagem. A criação do acesso de atendente continua manual, pelo comando `arcana:create-attendant`.

### Pagamentos e catálogo

- Sete pagamentos fictícios do provedor `pix_sandbox`: cinco confirmados, um pendente e um expirado.
- Os confirmados são fixtures locais usados apenas como origem rastreável dos créditos. Nenhum webhook é disparado e nenhuma cobrança real é efetuada.
- Não há estorno de pagamento ou de comissão semeado: essas operações exigem ação administrativa explícita, motivo e auditoria.
- Cinco baralhos ativos, com imagens JPEG no disco privado:
  - **Tarot de Marseille** (`tarot-de-marseille`): 78 cartas — 22 arcanos maiores e 56 menores (14 por naipe: Paus, Copas, Espadas e Ouros), do Louco ao 14 de Ouros.
  - **Lenormand** (`lenormand`): 36 cartas do Petit Lenormand, do Cavaleiro à Cruz.
  - **Rider-Waite-Smith** (`rider-waite-smith`): 78 cartas — 22 arcanos maiores próprios desta tradição (Mago, Sacerdotisa, Hierofante, Força) e os mesmos 56 menores do Marseille.
  - **Baralho de 52 Cartas** (`baralho-de-52-cartas`): 52 cartas — do Ás ao Rei em Copas, Ouros, Espadas e Paus, com leitura composta por posição e naipe.
  - **Baralho Espanhol** (`baralho-espanhol`): 40 cartas — do Ás ao Sete e as figuras Sota, Cavalo e Rei em Ouros, Copas, Espadas e Paus.
- Cada carta tem significado normal e invertido preenchidos, e o seeder é idempotente: uma imagem substituída pela administração não é sobrescrita.
- Seis mensagens de chat: cinco no histórico concluído e uma na consulta encerrada por saldo. Uma mensagem é carta do catálogo com snapshot privado.

## F. Cenários de teste manual

Todos os cenários abaixo usam funcionalidades já implementadas no MVP. Não há criação de módulos simulados. Nenhum atendimento em aberto é semeado: a sirene, o modal de aceite e a chamada no painel só aparecem para atendimentos iniciados por clientes autenticados.

1. **Login administrativo**
   - Acesse `http://localhost:8000/login`.
   - Entre com `admin@arcana.local` e a senha de demonstração.
   - Confirme o redirecionamento para `/admin` e os indicadores do dashboard.

2. **Visualização de clientes e atendentes**
   - Em `/admin/usuarios`, confira as 9 contas e os papéis.
   - Em `/admin/consultas`, confira as duas consultas concluídas (histórico de 13 minutos e encerrada por saldo).
   - Em `/atendentes`, confirme Marina online, Rafael offline e Íris online, sem atendimento ativo.

3. **Análise de candidaturas**
   - Abra `/admin/candidaturas`.
   - Teste os filtros e abra as quatro candidaturas.
   - Inicie análise de uma candidatura pendente, aprove ou rejeite.
   - Confirme que nenhuma conta de atendente é criada automaticamente.

4. **Cadastro e visualização de baralhos**
   - Abra `/admin/baralhos`.
   - Confira os cinco baralhos, descrições e status ativo.
   - Edite um baralho e altere o status; o catálogo e a disponibilidade das cartas devem refletir a regra.

5. **Visualização das cartas e imagens**
   - Abra as cartas de um baralho em `/admin/baralhos/{id}/cartas`.
   - Confirme que cada carta tem número/ordem, descrição e imagem.
   - A imagem é servida pela rota autorizada `catalogo/cartas/{card}/imagem`, no disco privado.

6. **Login de cliente**
   - Saia do admin e entre como `cliente.livre@arcana.local`.
   - Confirme o redirecionamento para `/client`.

7. **Consulta de saldo e movimentações**
   - Em `/client`, confira o saldo.
   - Em `/client/historico`, confira créditos, débitos, pagamentos e consultas.
   - O saldo deve coincidir com a soma do livro-razão.

8. **Seleção de atendente disponível**
   - Em `/atendentes`, escolha Marina Luz.
   - Rafael deve aparecer offline; Íris deve aparecer disponível, pois nenhum atendimento em aberto é semeado.

9. **Solicitação e aceitação de consulta**
   - Não existe solicitação semeada: o chamado nasce quando um cliente autenticado pede o atendimento.
   - Como cliente livre, solicite uma consulta para Marina pelo site.
   - Saia e entre como `marina.luz@arcana.local`.
   - Em `/attendant`, aceite a solicitação.
   - O primeiro minuto é cobrado no aceite. O cliente precisa ter saldo mínimo.

10. **Envio e recebimento de mensagens**
    - Abra a consulta em `/consultas/{id}`.
    - Envie texto como cliente e responda como Marina.
    - Confirme persistência, ordem cronológica e autorização apenas dos participantes.

11. **Envio de cartas de Tarot pelo chat**
    - Como atendente, envie uma carta ativa do catálogo.
    - Confirme o snapshot no histórico, mesmo que a carta seja editada depois.
    - O cliente não pode enviar carta.

12. **Encerramento de consulta**
    - Cliente ou atendente participante pode encerrar a consulta ativa.
    - Confirme o motivo, o horário final, a cobrança do último minuto e a comissão gerada.

13. **Verificação do consumo de créditos**
    - Em `/admin/financeiro/carteiras/{user}`, confira os lançamentos de débito ligados à consulta.
    - No histórico do cliente, confira o total consumido.

14. **Verificação das comissões**
    - Em `/admin/comissoes`, confira as comissões de Marina no histórico e na consulta encerrada por saldo.
    - Confirme que a taxa histórica continua 30%, mesmo após alterar a taxa atual no painel.

15. **Consulta ao histórico financeiro**
    - Como admin, use `/admin/financeiro` e `/admin/pagamentos`.
    - Confira os pagamentos `pix_sandbox`, incluindo pendente e expirado.
    - Confirme que somente os confirmados possuem crédito correspondente.

16. **Permissões e isolamento entre usuários**
    - Cliente A não deve acessar a consulta do cliente B.
    - Admin não vê o conteúdo das mensagens, apenas metadados operacionais.
    - Atendente não deve aceitar consulta de outro atendente.
    - O painel de candidaturas e o financeiro exigem papel `admin`.

### Dependências externas ainda não testáveis

- Confirmação de pagamento por um provedor PIX real e conciliação financeira de produção.
- WebSocket em navegador real com reconexão e múltiplas abas; os testes automatizados usam o barramento nulo.
- MFA, backup testado, TLS/proxy e monitoramento de produção, conforme ressalvas de `docs/SECURITY_PRIVACY.md` e `docs/DEPLOYMENT.md`.

O fluxo de recarga sandbox existente pode ser exercitado manualmente depois de criar uma recarga pela interface, com `php artisan pix:sandbox-simulate {payment} approved`. O seeder não executa esse comando e não cria eventos de webhook.

## G. Problemas comuns

### O banco não está disponível

Verifique os containers e a saúde do MySQL:

```bash
docker compose ps
docker compose logs --tail=100 mysql
```

O serviço `app` depende do MySQL e do Redis saudáveis. Se o container `app` estiver parado, `docker compose up -d` deve ser executado novamente.

### Migrations pendentes ou tabelas ausentes

```bash
docker compose exec app php artisan migrate:status
docker compose exec app php artisan migrate
```

Não use `migrate:fresh` para corrigir uma migration pendente em banco com dados.

### Erro de conexão com MySQL ou Redis

Confirme `DB_*`, `REDIS_*` e `CACHE_STORE` no `.env`. Dentro do Docker, os hosts são `mysql` e `redis`, não `127.0.0.1`. Depois de alterar variáveis, limpe configurações em cache:

```bash
docker compose exec app php artisan config:clear
```

### Permissão de escrita no storage

O seeder grava imagens em `storage/app/private/tarot`. Se houver erro de escrita:

```bash
docker compose exec app ls -ld storage storage/app storage/app/private storage/app/private/tarot
docker compose logs --tail=100 app nginx
```

O usuário do PHP-FPM precisa de escrita nesses diretórios. Neste projeto, execute os comandos de diagnóstico dentro do container; o host pode não ter permissão de leitura nos arquivos criados pelo container, o que não impede a aplicação de servi-las.

### Imagens das cartas não aparecem

- Confirme que a carta tem `image_path` iniciado por `cards/`.
- Confirme que o arquivo existe no disco `tarot_private`.
- Como admin, a imagem é acessível pela rota `catalogo/cartas/{card}/imagem`.
- Cliente/atendente só acessam cartas ativas de baralhos ativos enquanto participam de consulta ativa.
- As imagens de chat usam cópias em `consultation-cards/{consultation_id}/`.
- Não é necessário `storage:link` para essas imagens privadas.
- Se o arquivo de uma carta de demonstração tiver sido removido, execute `db:seed` novamente. A seeder recria a imagem local quando o registro existe sem arquivo no disco.

### Serviços Docker não iniciados

```bash
docker compose up -d
docker compose ps
docker compose logs --tail=100 app nginx mysql redis queue reverb
```

O Reverb é necessário para o chat em tempo real no navegador; o chat persistente e os testes automatizados funcionam sem WebSocket.

### Variáveis de ambiente ausentes

Copie `.env.example` para `.env` e gere `APP_KEY`. `ARCANA_DEMO_PASSWORD` é opcional; sem ela, o valor padrão local é usado. Nunca aponte o `.env` local para um banco de produção.

### Erro de autenticação nas contas de demonstração

O seeder não altera a senha de uma conta já existente. Se o e-mail de demonstração já existia com outra senha, a senha documentada não será aplicada. Em um banco descartável, recrie o ambiente; em qualquer outro banco, redefina a senha pelo fluxo apropriado e nunca com `migrate:fresh` sem autorização.

### Dados duplicados ou inconsistentes

- Execute `db:seed` quantas vezes precisar; as chaves estáveis impedem duplicação.
- Não edite saldos diretamente: use os lançamentos e os serviços de carteira.
- Se um cenário já foi consumido, ele não é recriado. Para voltar ao estado inicial, use um banco local descartável e siga a seção D.
- Consultas concluídas de demonstração não são recriadas: se uma delas for apagada manualmente, use um banco local descartável e siga a seção D para voltar ao estado inicial.

### Seeder bloqueado

Se aparecer a mensagem de bloqueio de produção, verifique:

```bash
docker compose exec app php artisan about | grep -i environment
```

O `APP_ENV` deve ser `local` ou `testing`. Produção deve provisionar contas reais com `arcana:create-admin` e `arcana:create-attendant`.

## H. Resultado da execução

### Seeders criadas/atualizadas

- `DatabaseSeeder.php`
- `DemoSeeder.php` (bloqueio de produção)
- `DemoData.php` (identificadores e pricing)
- `TarotCatalog.php` (catálogo de baralhos, cartas e significados — hoje em
  `app/Support/Tarot/TarotCatalog.php`, pois também é usado pelo comando de produção
  `arcana:import-tarot-catalog`)
- `database/seeders/assets/tarot/` (marseille com 78 JPEGs e lenormand com 36 JPEGs, em domínio público)
- `SettingsSeeder.php`
- `AdminSeeder.php`
- `AttendantSeeder.php`
- `ClientSeeder.php`
- `AttendantApplicationSeeder.php`
- `TarotDeckSeeder.php`
- `PaymentSeeder.php`
- `WalletSeeder.php`
- `ConsultationSeeder.php`
- `CommissionSeeder.php`

As factories existentes foram preservadas. Não foi necessário criar novas entidades, tabelas ou migrations.

### Volume criado com os settings padrão

| Entidade | Total |
|---|---|
| Usuários | 9 (1 admin, 3 atendentes, 5 clientes) |
| Perfis de atendente | 3 |
| Candidaturas | 4 |
| Baralhos | 5 |
| Cartas | 284 (78 do Tarot de Marseille + 36 do Lenormand + 78 do Rider-Waite-Smith + 52 do baralho comum + 40 do baralho espanhol) |
| Carteiras | 5 |
| Pagamentos | 7 (5 confirmados, 1 pendente, 1 expirado) |
| Lançamentos de crédito | 19 (5 créditos + 14 débitos) |
| Consultas | 2 (ambas concluídas) |
| Mensagens | 6 (1 com carta) |
| Comissões | 2 |

Saldo final padrão: livre R$ 50,00; sem saldo R$ 0,00; histórico R$ 27,00; pendente R$ 20,00; ativo R$ 20,00. Todos os saldos coincidem com a soma do livro-razão.

### Testes executados

- `php artisan test --compact tests/Feature/DemoSeedersTest.php`: **14 testes, 2808 assertions, todos aprovados**.
- `php artisan test --compact tests/Feature/TarotCatalogImportCommandTest.php`: **5 testes, 322 assertions, todos aprovados**.
- `php artisan test --compact` (suíte completa): **518 testes, 6836 assertions, todos aprovados**.
- `migrate:fresh --seed` em SQLite temporário (`/tmp/arcana-demo-seed.sqlite`) dentro do container: aprovado.
- `db:seed` repetido no mesmo SQLite temporário: aprovado, sem duplicação (5 baralhos e 284 cartas, com 78 no Marseille e 78 no Rider-Waite-Smith).
- Validação das imagens com GD: JPEG decodificado com sucesso — 320 px de largura nos baralhos normalizados (Marseille, Rider-Waite-Smith, baralho de 52 cartas e espanhol) e 400 px no Lenormand.
- Pint nos arquivos alterados: aprovado.

### Comandos validados

```bash
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan db:seed
docker compose exec app php artisan test --compact
```

O `migrate:fresh` acima foi executado somente no arquivo SQLite temporário, não no MySQL de desenvolvimento.

### Limitações conhecidas

- A confirmação de pagamento real não é semeada. Os pagamentos confirmados são fixtures locais `pix_sandbox`, sem webhook e sem movimentação financeira externa.
- O teste WebSocket com navegador real continua manual.
- O seeder não cria atendimento em aberto: nenhuma conta fictícia é chamada no login, sem sirene, modal de aceite ou pop-up de consulta. O primeiro atendimento do demo é iniciado pelo cliente autenticado.
- Por isso, não há solicitação sujeita ao TTL (`CONSULTATION_REQUEST_TTL_MINUTES`): o chamado só existe a partir da ação do cliente.
- Não existem MFA, conciliação PIX de produção, backup testado ou monitoramento neste escopo.
- Novas alterações na estrutura de seeders devem atualizar este documento e os testes `DemoSeedersTest`.

### Confirmação financeira

Nenhuma operação financeira real foi executada. Não houve chamada a instituição financeira, provedor PIX, gateway ou serviço externo. Os pagamentos são registros fictícios do provedor `pix_sandbox`, identificados por `raw_status` iniciado em `demo_` e chave de idempotência `demo:pix:*`.





# Importação de dados do banco legado

> Implementado no Prompt de migração. O comando `arcana:import-legacy-users`
> traz usuários, senhas compatíveis e saldos do antigo banco (dump
> `astros21.sql`) para o Arcana. A execução padrão é **simulação**: nada é
> gravado sem `--execute`.

## 1. Escopo

| Dado | Importado | Observação |
|---|---|---|
| Contas de usuário | Sim | Criadas como `client`, ativas, com e-mail normalizado |
| Senhas | Condicional | Só bcrypt/argon2 verificáveis pelo Laravel |
| Saldos em créditos | Sim | Lançamento de abertura no livro-razão |
| Consultas, mensagens, artigos | Não | Fora do escopo do MVP |
| Perfis de atendente | Não | Devem ser criados por `arcana:create-attendant` |

## 2. Pré-requisito: o dump precisa estar íntegro

O comando **interpreta** o arquivo SQL, nunca o executa. Ele precisa que o dump
contenha o `CREATE TABLE` das tabelas de interesse no mesmo arquivo (é o padrão
do `mysqldump` e do phpMyAdmin).

Antes de qualquer importação real, valide o arquivo:

```bash
docker compose exec -T app php artisan arcana:import-legacy-users
```

A simulação mostra:

- caminho e tamanho do arquivo;
- **tabelas encontradas** com as primeiras colunas de cada uma;
- origem escolhida para credenciais e para saldos;
- tabelas descartadas na detecção e o motivo;
- avisos de exportação interrompida.

> **Situação conhecida do `astros21.sql` recebido:** o arquivo tem 464 MB (443
> MiB) e termina com uma página de erro do phpMyAdmin
> (`#2006 - MySQL server has gone away`). Ele contém apenas `batata`,
> `eam_artigos`, `eam_atendentes` e `eam_atendimentos` — nenhuma tabela com
> e-mail e senha e nenhuma coluna de saldo. O comando detecta isso e **bloqueia**
> a importação com a mensagem `Nenhuma tabela com e-mail e senha foi encontrada
> no dump`.
>
> Para prosseguir é necessário exportar novamente o banco completo (estrutura +
> dados, UTF-8) enquanto o servidor MySQL ainda responde, por exemplo com
> `mysqldump --single-transaction --default-character-set=utf8mb4 --no-tablespaces`
> ou, no phpMyAdmin, com "Exportar → Personalizado → Estrutura e dados →
> Inserções completas". Grave o arquivo em `storage/app/imports/`.

## 3. Leitura do dump

`App\Support\Legacy\LegacySqlDumpReader`:

- lê em blocos de 1 MB, sem carregar o arquivo inteiro na memória;
- reconhece `CREATE TABLE` (com ou sem crases, com chaves e índices) e
  `INSERT`/`REPLACE` com lista de colunas ou posicional;
- trata `NULL`, strings com escape (`\'`, `\\`), `;` e `,` dentro de valores;
- converte valores `latin1`/`utf8mb3` para UTF-8 (fallback Windows-1252);
- **não executa SQL** e não aceita nomes de tabela/coluna interpolados.

Sinais de integridade (`truncationMarker()` e `endsWithoutTerminator()`):
mensagens `MySQL server has gone away`, `mysqli_sql_exception`, `Fatal error</b>`
ou `alert alert-danger` no fim do arquivo, e ausência do `;` final.

## 4. Detecção de tabela e coluna

`App\Support\Legacy\LegacySchemaInspector` usa heurística conservadora:

- tabela de credenciais: precisa de coluna de **senha** e de **e-mail**
  (`email`, `mail`, `correio`, ...; `senha`, `password`, `pwd`, ...). Entre
  candidatas, pontua por nome (`usuario`, `cliente`, `user`, ...) e por ter
  nome/telefone/identificador;
- coluna de nome: `nome`, `name`, `apelido`, ...; de telefone: `telefone`,
  `celular`, `whatsapp`, ...;
- identificador: `id`, `codigo`, `usuario_id`, ...;
- saldos: coluna numérica `saldo*`, `creditos`, `balance`, ... na própria tabela
  de usuários ou em tabela separada com vínculo `usuario_id`/`cliente_id`.

Quando há ambiguidade nada é escolhido e o comando falha pedindo a opção
explícita. Nunca há "chute" silencioso.

## 5. Mapa de campos

| Legado | Arcana | Regra |
|---|---|---|
| e-mail | `users.email` | minúsculas, `FILTER_VALIDATE_EMAIL`, máx. 255 |
| nome | `users.name` | vazio → derivado da parte local do e-mail |
| telefone | `users.phone` | normalizado; inválido vira `NULL` |
| senha | `users.password` | ver seção 6 |
| saldo | `wallets.balance_cents` | reais → centavos, sem float |
| id/código | chave de idempotência | `legacy-import:{tabela}:{chave}` |

Papel `client` e `is_active = true` são fixos: o legado não fornece papéis
confiáveis. Atendentes e administradores são criados/ajustados manualmente.

Registros recusados (contam como *ignorado*, sem interromper o lote): sem
e-mail, e-mail inválido, saldo não numérico quando a origem é a tabela do
usuário.

## 6. Política de senhas

| Formato no legado | Ação | Consequência |
|---|---|---|
| bcrypt/argon2 com configuração compatível | hash copiado | login funciona com a senha antiga |
| bcrypt/argon2 com custo/parâmetros incompatíveis | senha aleatória de 64 caracteres | titular precisa redefinir |
| md5/sha1/sha256/sha512 | senha aleatória de 64 caracteres | titular precisa redefinir |
| texto puro | senha aleatória de 64 caracteres | titular precisa redefinir |
| vazio/NULL | senha aleatória de 64 caracteres | titular precisa redefinir |

A compatibilidade é confirmada com `Hash::info()` + `Hash::verifyConfiguration()`
antes de preservar o valor; o hash legado nunca é regravado às cegas. Senhas em
texto puro e digestos **nunca** são gravados em `users.password`, em log ou no
relatório — apenas o formato é registrado na auditoria.

Contas com senha inutilizável dependem do fluxo de recuperação de senha
(`/forgot-password`). Não existe e-mail de boas-vindas automático nesta etapa.

## 7. Política de créditos

- o saldo do legado é um **estado**, não um histórico: apenas o valor final é
  importado, como lançamento de abertura (`credit`);
- saldo na própria tabela de usuários: usa a coluna detectada na linha;
- saldo em tabela separada: a **última** linha de cada usuário prevalece;
- saldo `0` (ou vazio): carteira criada vazia, sem lançamento;
- saldo negativo: usuário importado, **nenhum** crédito lançado e motivo no
  relatório (a carteira não aceita saldo negativo);
- todo lançamento usa `idempotency_key = legacy-import:{tabela}:{chave}` e
  referencia o registro de auditoria da importação — reexecutar não duplica
  créditos;
- carteira e lançamento são criados na **mesma transação** do usuário.

## 8. Execução

```bash
# Simulação (padrão) — não grava nada
docker compose exec -T app php artisan arcana:import-legacy-users \
  storage/app/imports/astros21.sql

# Importação real
docker compose exec -T app php artisan arcana:import-legacy-users \
  storage/app/imports/astros21.sql --execute

# Lote piloto de 50 registros
docker compose exec -T app php artisan arcana:import-legacy-users \
  storage/app/imports/astros21.sql --execute --limit=50
```

Opções:

| Opção | Efeito |
|---|---|
| `file` (argumento) | caminho do dump; padrão `storage/app/imports/astros21.sql` |
| `--execute` | grava os dados; sem ela, apenas simula |
| `--force` | permite executar com avisos de dump truncado |
| `--limit=N` | processa no máximo N registros |
| `--users-table=` | força a tabela de credenciais |
| `--email-column=`, `--password-column=` | forçam as colunas obrigatórias |
| `--name-column=`, `--phone-column=` | forçam nome/telefone |
| `--user-key-column=` | força o identificador usado na idempotência |
| `--credits-table=`, `--credits-user-column=`, `--credits-balance-column=` | forçam a origem dos saldos (`--credits-table=none` desliga) |

Códigos de saída: `0` sucesso (mesmo com conflitos/ignorados); `1` quando a
importação é bloqueada, o dump é inválido/ilegível, há linha `falha` ou existem
avisos de truncamento sem `--force`.

A simulação lista uma linha por registro:

```
  [importado       ] maria@example.com   bcrypt (compatível) | saldo R$ 150,75
  [ignorado        ] registro sem e-mail
  [conflito        ] existente@example.com   e-mail já cadastrado no Arcana
```

## 9. Auditoria

| Ação | Momento | Metadados |
|---|---|---|
| `user.imported_from_legacy` | por usuário importado | `source_table`, `legacy_key`, `password_format`, `password_reused`, `credits_cents` |
| `user.import_batch_from_legacy` | fim de cada execução `--execute` | `dump_path`, `source_table`, `legacy_key_column`, `records`, `totals`, `credits_cents`, `forced` |

Nenhum hash, senha ou credencial é registrado.

## 10. Conflitos e reexecução

| Situação | Resultado |
|---|---|
| e-mail já cadastrado no Arcana | `conflito`; conta existente **não** é alterada |
| e-mail repetido no dump | `conflito` no segundo registro em diante |
| linha inválida | `ignorado`/`falha`; as demais seguem |
| comando reexecutado | usuários existentes viram `conflito`; nada é duplicado |

Cada usuário é gravado em transação própria: uma falha isolada não desfaz o
lote, mas também não deixa registro parcial (usuário, carteira, lançamento e
auditoria entram juntos).

## 11. Verificação após importar

```bash
docker compose exec -T app php artisan tinker --execute \
  'App\Models\User::where("role", "client")->count();'
docker compose exec -T app php artisan tinker --execute \
  'App\Models\CreditLedgerEntry::where("idempotency_key", "like", "legacy-import:%")->sum("amount_cents");'
docker compose exec -T app php artisan tinker --execute \
  'App\Models\AuditLog::where("action", "user.import_batch_from_legacy")->latest("id")->first()->metadata;'
```

Conferências recomendadas:

1. total de `client` igual ao total de `importado` no relatório;
2. soma dos créditos igual ao exibido no resumo;
3. login manual com uma conta bcrypt preservada;
4. recuperação de senha com uma conta de digesto/texto puro;
5. saldo da carteira igual ao `saldo` do registro legado.

## 12. Testes automatizados

```bash
docker compose exec -T app php artisan test --compact \
  tests/Feature/LegacyUserImportTest.php tests/Unit/LegacyUserRecordMapperTest.php
```

Cobrem: simulação sem escrita, importação com senha bcrypt e créditos, senha
incompatível → redefinição, saldos em tabela separada (último vence), conflito
de e-mail (interno e com conta existente), reexecução sem duplicar, dump sem
credenciais, dump truncado com/sem `--force`, arquivo ausente, SQL malformado,
saldo negativo e sobreposições de tabela/coluna com `--limit`.

## 13. Limitações conhecidas

- o dump precisa conter o `CREATE TABLE` das tabelas usadas;
- só o saldo final é migrado — o histórico de movimentos não é reconstruído;
- senhas legadas em digesto/texto puro exigem redefinição pelo titular;
- comissões, atendimentos e mensagens antigas não são migrados;
- o arquivo `astros21.sql` atual está incompleto (ver seção 2).




# Importação de dados do banco legado

> Implementado no Prompt de migração. O comando `arcana:import-legacy-users`
> traz usuários, senhas compatíveis, dados cadastrais e saldos do antigo banco
> (dump SQL `astros21.sql`, exportação CSV com todos os blocos ou **um arquivo
> por tabela**, como `eam_usuarios.csv` + `eam_clientes.csv`) para o Arcana. A
> execução padrão é **simulação**: nada é gravado sem `--execute`.

## 1. Escopo

| Dado | Importado | Observação |
|---|---|---|
| Contas de usuário | Sim | Criadas como `client`, ativas, com e-mail normalizado |
| Senhas | Condicional | Só bcrypt/argon2 verificáveis pelo Laravel |
| Saldos em créditos | Sim | Lançamento de abertura no livro-razão |
| Consultas, mensagens, artigos | Não | Fora do escopo do MVP |
| Perfis de atendente | Não | Devem ser criados por `arcana:create-attendant` |

## 2. Pré-requisito: a exportação precisa estar íntegra

O comando **interpreta** o arquivo, nunca o executa. O formato é escolhido pela
extensão (`LegacyDumpFactory`):

| Arquivo | Leitor | Como as tabelas são identificadas |
|---|---|---|
| `.sql` (mysqldump/phpMyAdmin) | `LegacySqlDumpReader` | pelo `CREATE TABLE` de cada tabela |
| `.csv` (exportação de registros) | `LegacyCsvDumpReader` | pelo cabeçalho de cada bloco; pelo nome do arquivo quando ele se chama como a tabela (`eam_usuarios.csv`) |

Nos dois formatos precisa existir a tabela de credenciais (`eam_usuarios`).

A exportação pode vir em **vários arquivos** (um por tabela): o comando aceita
uma lista de arquivos ou um diretório e os lê como uma origem só, agregados por
`LegacyDumpBundle`. Assim `eam_usuarios.csv` + `eam_clientes.csv` entram na mesma
execução, com telefone, nascimento e sexo aplicados. Um diretório é expandido
para os `.sql`/`.csv` que ele contém, em ordem alfabética; os demais arquivos são
ignorados. Quando dois arquivos trazem a mesma tabela, vale o primeiro.

Antes de qualquer importação real, valide o arquivo:

```bash
docker compose exec -T app php artisan arcana:import-legacy-users
```

A simulação mostra:

- caminho e tamanho do arquivo — com vários arquivos, a lista com o tamanho de
  cada um;
- **tabelas encontradas** com as primeiras colunas de cada uma;
- origem escolhida para credenciais e para saldos;
- tabelas descartadas na detecção e o motivo;
- avisos de exportação interrompida.

> **Situação das exportações recebidas:** `astros21.sql` (464 MB) e
> `astros21 (1).csv` (20,5 MB) foram cortados pelo mesmo erro do phpMyAdmin
> (`#2006 - MySQL server has gone away`) e contêm apenas `batata`,
> `eam_artigos`, `eam_atendentes` e `eam_atendimentos` — nenhuma tabela com
> e-mail e senha e nenhuma coluna de saldo. Com eles o comando detecta o
> problema e **bloqueia** a importação com a mensagem `A tabela de usuários do
> legado (eam_usuarios) não existe no arquivo CSV`.
>
> A exportação por tabela resolveu: `storage/app/imports/eam_usuarios.csv`
> (1.519 contas, `id`/`usuario`/`senha`/`email`/`nome`/`status`...) e
> `storage/app/imports/eam_clientes.csv` (1.501 registros com
> `usuario_id`/`sexo`/`data_nascimento`/`telefone`) são lidos juntos:
>
> ```bash
> docker compose exec -T app php artisan arcana:import-legacy-users
> ```
>
> Resultado da simulação: 2 arquivos, 1.519 registros, todos “seria importado”,
> nenhum saldo (o legado não tem coluna de saldo) e aviso de que
> `eam_atendentes` não foi exportada — sem bloquear. Como as senhas legadas são
> digesto MD5, cada conta entra com senha inutilizável e o titular precisa
> redefini-la.
>
> Se precisar reexportar, o caminho mais rápido é uma tabela por vez, começando
> por `eam_usuarios` (pequena, é a que contém e-mail e senha) e `eam_clientes`:
>
> - phpMyAdmin: selecionar a tabela → "Exportar → CSV" (ou "SQL → Estrutura e
>   dados"). O arquivo pode ser salvo como `eam_usuarios.csv`; o nome do arquivo
>   já identifica a tabela.
> - linha de comando: `mysqldump --single-transaction --quick
>   --default-character-set=utf8mb4 --no-tablespaces astros21 eam_usuarios
>   eam_clientes > storage/app/imports/legado-eam.sql`.
>
> Com as credenciais presentes, os outros blocos (artigos, atendimentos) são
> ignorados automaticamente pela importação.


## 3. Leitura do arquivo

Os dois leitores implementam `App\Support\Legacy\LegacyDumpSource`, então o
restante da importação (mapeamento, senha, créditos, auditoria) é idêntico nos
dois formatos. `LegacyDumpBundle` implementa a mesma interface agregando vários
arquivos: `files()` devolve cada arquivo com seu tamanho, `size()` soma os
tamanhos, `tables()` junta as tabelas (a primeira ocorrência vence) e `rows()`
repassa a leitura para o arquivo que contém a tabela.

`LegacySqlDumpReader`:

- lê em blocos de 1 MB, sem carregar o arquivo inteiro na memória;
- reconhece `CREATE TABLE` (com ou sem crases, com chaves e índices) e
  `INSERT`/`REPLACE` com lista de colunas ou posicional;
- trata `NULL`, strings com escape (`\'`, `\\`), `;` e `,` dentro de valores;
- converte valores `latin1`/`utf8mb3` para UTF-8 (fallback Windows-1252);
- **não executa SQL** e não aceita nomes de tabela/coluna interpolados.

`LegacyCsvDumpReader`:

- lê o arquivo em fluxo com `fgetcsv` (RFC 4180), tolerando quebras de linha
  dentro das aspas — os textos antigos têm várias;
- considera **cabeçalho** a linha em que todos os campos são nomes de coluna e o
  primeiro é `id`; cada cabeçalho abre um bloco de registros;
- dá nome a cada bloco pelo nome do arquivo (arquivos de uma tabela só), pela
  assinatura de colunas de `LegacySchemaPreset::SIGNATURES` (`eam_usuarios`,
  `eam_clientes`, `eam_atendentes`) ou por um nome sintético (`astros21#2`), que
  apenas aparece no relatório;
- trata `NULL`/vazio do CSV como `null`, remove BOM e converte `latin1` para
  UTF-8 como o leitor de SQL;
- infere o tipo das colunas pelos primeiros 100 registros (`decimal` quando todos
  os valores são numéricos, senão `varchar`) — é o que permite reconhecer a
  coluna de saldo, já que o CSV não traz o `CREATE TABLE`.

Sinais de integridade (`integrityIssues()`, com os marcadores de
`LegacyDumpTail`): mensagens `MySQL server has gone away`,
`mysqli_sql_exception`, `Fatal error</b>` ou `alert alert-danger` no fim do
arquivo. No dump SQL a ausência do `;` final também é apontada. Com vários
arquivos o aviso nomeia o arquivo cortado (`ATENÇÃO: o arquivo eam_clientes.csv
termina com uma mensagem de erro...`) e, sem `--force`, a gravação é bloqueada.

## 4. Mapeamento das tabelas

`App\Support\Legacy\LegacySchemaPreset` fixa as tabelas do legado — a importação
é de um banco único, então o comando não tem opções de tabela nem de coluna:

| Papel | Tabela |
|---|---|
| credenciais e identificação | `eam_usuarios` |
| dados complementares (telefone, nascimento e sexo) | `eam_clientes` |
| contas que não devem ser importadas (atendentes) | `eam_atendentes` |

O `LegacySchemaInspector` valida esses nomes contra o arquivo e, **dentro** de
cada tabela, identifica as colunas por heurística conservadora:

- credenciais: `email`/`mail`/`correio`... e `senha`/`password`/`pwd`...
  (obrigatórias);
- nome: `nome`, `name`, `apelido`, ...; telefone: `telefone`, `celular`, `fone`,
  `whatsapp`, ...;
- identificador: `id`, `codigo`, `usuario_id`, ...;
- dados complementares: vínculo `usuario_id`, `cliente_id`, ... (ou `email`,
  quando a tabela não tem o id do usuário) e colunas de telefone, nascimento
  (`data_nascimento`, `nascimento`, ...) e sexo (`sexo`, `genero`, ...);
- saldos: coluna numérica `saldo*`, `creditos`, `balance`, ... na própria tabela
  de usuários ou em tabela separada com vínculo `usuario_id`/`cliente_id`.

Ausências não impedem a importação de quem pode entrar: `eam_clientes` ou
`eam_atendentes` fora do arquivo, colunas faltando e saldo ambíguo viram **aviso**
no relatório e os campos correspondentes ficam vazios. O bloqueio fica para o que
impede a conta de existir: `eam_usuarios` ausente ou sem coluna de e-mail/senha.
Nunca há "chute" silencioso — se o legado usar outro nome de tabela, ajuste as
constantes de `LegacySchemaPreset`.

No CSV os blocos não têm nome, então o `LegacyCsvDumpReader` os identifica por
`LegacySchemaPreset::SIGNATURES` (grupos de colunas que só existem naquela
tabela — `email` + `senha` para usuários, `usuario_id` + `comissao` para
atendentes, vínculo + telefone/nascimento/sexo para dados complementares) e,
quando o arquivo tem uma tabela só, pelo próprio nome do arquivo. Bloco não
reconhecido aparece no relatório como `astros21#1` e é ignorado. Se o legado
mudar, ajuste as assinaturas no mesmo `LegacySchemaPreset`.

## 5. Mapa de campos

| Legado | Arcana | Regra |
|---|---|---|
| e-mail | `users.email` | minúsculas, `FILTER_VALIDATE_EMAIL`, máx. 255 |
| nome | `users.name` | vazio → derivado da parte local do e-mail |
| telefone | `users.phone` | normalizado; inválido vira `NULL`; se a tabela de credenciais não tem a coluna, usa a tabela complementar |
| telefone (tabela complementar) | `users.phone` | `eam_clientes.telefone`; usado só quando a tabela principal não tem o dado |
| data de nascimento | `users.birth_date` | `eam_clientes.data_nascimento`; aceita `Y-m-d`, `d/m/Y` e `d-m-Y`; `0000-00-00`, data futura ou implausível vira `NULL` |
| sexo | `users.gender` | enum `Gender` (`female`, `male`, `other`); valores desconhecidos ou vazios viram `NULL` |
| senha | `users.password` | ver seção 6 |
| saldo | `wallets.balance_cents` | reais → centavos, sem float |
| id/código | chave de idempotência | `legacy-import:{tabela}:{chave}` |

O papel é sempre `client` e `is_active = true` é fixo: atendentes não entram
por aqui. Quem aparece em `eam_atendentes` é marcado como *ignorado* com o
motivo, para ser tratado pelo fluxo próprio de atendentes
(`arcana:create-attendant`).

Registros recusados (contam como *ignorado*, sem interromper o lote): sem
e-mail, e-mail inválido, saldo não numérico quando a origem é a tabela do
usuário. Dados complementares ausentes ou inválidos **não** recusam o registro:
o campo correspondente fica vazio e o relatório aponta a falta.

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
# Simulação (padrão) — não grava nada; lê todos os .sql/.csv de storage/app/imports
docker compose exec -T app php artisan arcana:import-legacy-users

# Uma exportação por tabela: o diretório inteiro é lido de uma vez
docker compose exec -T app php artisan arcana:import-legacy-users \
  storage/app/imports

# Arquivos informados um a um (um dump SQL também serve)
docker compose exec -T app php artisan arcana:import-legacy-users \
  storage/app/imports/eam_usuarios.csv \
  storage/app/imports/eam_clientes.csv

# Importação real
docker compose exec -T app php artisan arcana:import-legacy-users --execute

# Lote piloto de 50 registros
docker compose exec -T app php artisan arcana:import-legacy-users --execute --limit=50
```

Opções:

| Opção | Efeito |
|---|---|
| `file` (argumento) | arquivo(s) `.sql`/`.csv` ou diretório da exportação (pode repetir); padrão `storage/app/imports` |
| `--execute` | grava os dados; sem ela, apenas simula |
| `--limit=N` | processa no máximo N registros |
| `--force` | permite executar com avisos de exportação truncada |

Não existem opções de tabela ou coluna: o mapeamento vem de
`LegacySchemaPreset`, o formato do arquivo é decidido pela extensão e vários
arquivos formam uma origem só. Arquivo, diretório ou lista, o relatório sempre
começa pelos arquivos lidos (`Arquivo: ...` ou `Arquivos (N, tamanho):`).

Códigos de saída: `0` sucesso (mesmo com conflitos, ignorados ou avisos em
simulação); `1` quando a importação é bloqueada, o dump é inválido/ilegível, há
linha `falha` ou existem avisos de truncamento sem `--force`.

A simulação lista uma linha por registro:

```
  [importado       ] maria@example.com   bcrypt (compatível) | saldo R$ 150,75 | nasc. 31/01/1985 | sexo Feminino
  [ignorado        ] registro sem e-mail
  [conflito        ] existente@example.com   e-mail já cadastrado no Arcana
  [ignorado        ] atendente@example.com   usuário presente em eam_atendentes: importar pelo fluxo próprio dessa origem
```

## 9. Auditoria

| Ação | Momento | Metadados |
|---|---|---|
| `user.imported_from_legacy` | por usuário importado | `source_table`, `legacy_key`, `password_format`, `password_reused`, `credits_cents`, `birth_date`, `gender`, `profile_matched` |
| `user.import_batch_from_legacy` | fim de cada execução `--execute` | `dump_path`, `source_table`, `legacy_key_column`, `profile_source`, `excluded_source`, `records`, `totals`, `credits_cents`, `forced` |

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
  tests/Feature/LegacyUserImportTest.php \
  tests/Feature/LegacyUserImportCsvTest.php \
  tests/Unit/LegacyUserRecordMapperTest.php
```

Cobrem: simulação sem escrita, importação com senha bcrypt e créditos, senha
incompatível → redefinição, saldos em tabela separada (último vence), conflito
de e-mail (interno e com conta existente), reexecução sem duplicar, dump sem
`eam_usuarios`, dump truncado com/sem `--force`, arquivo ausente, SQL malformado,
saldo negativo, `--limit`, dados complementares (telefone, nascimento e sexo) por
vínculo `usuario_id` e por e-mail, valores ausentes/inválidos que ficam vazios e
exclusão de atendentes.

Na exportação CSV: blocos concatenados com credenciais, saldo, dados
complementares e atendentes (incluindo o aviso de arquivo truncado), arquivo sem
o bloco de usuários bloqueado com a lista de blocos encontrados, e arquivo de uma
tabela só identificado pelo nome do arquivo.

Com vários arquivos: diretório com um arquivo por tabela importado em uma
execução só (vínculo `usuario_id` aplicando telefone, nascimento e sexo),
arquivos informados um a um, diretório sem exportação rejeitado com mensagem
própria e aviso de truncamento nomeando o arquivo cortado.

## 13. Limitações conhecidas

- no dump SQL o arquivo precisa conter o `CREATE TABLE` das tabelas usadas;
- no CSV não existe `CREATE TABLE`: o tipo das colunas é inferido pela amostra
  dos primeiros 100 registros, e um bloco só é reconhecido pela assinatura de
  colunas ou pelo nome do arquivo;
- os dois formatos precisam incluir a tabela de credenciais: `eam_usuarios`
  **não** está em `astros21.sql` nem em `astros21 (1).csv`, então esses dois
  arquivos não importam clientes (ver seção 2);
- os dados complementares (`eam_clientes`) são opcionais: sem eles o cliente é
  importado com e-mail e senha, mas sem telefone, nascimento e sexo;
- a origem pode ter um ou vários arquivos (ou um diretório), mas todos são lidos
  numa única execução: se `eam_clientes` estiver num arquivo que não foi
  informado, os dados complementares não entram;
- em diretórios, a seleção é apenas pela extensão (`.sql`/`.csv`) em ordem
  alfabética: um dump antigo esquecido ali entra junto e, se estiver truncado,
  bloqueia `--execute` até usar `--force`;
- quando dois arquivos trazem a mesma tabela, só a primeira ocorrência é usada e
  o relatório não avisa sobre a duplicata;
- só o saldo final é migrado — o histórico de movimentos não é reconstruído;
- senhas legadas em digesto/texto puro exigem redefinição pelo titular;
- comissões, atendimentos e mensagens antigas não são migrados;
- `astros21.sql` e `astros21 (1).csv` continuam incompletos (ver seção 2); a
  importação válida hoje vem de `storage/app/imports/eam_usuarios.csv` +
  `eam_clientes.csv`.




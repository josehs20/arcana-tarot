# Arcana Tarot — Catálogo de baralhos e cartas

> Documento produzido na etapa **Prompt 09 — Cadastro de baralhos e cartas**.
> Complementa `docs/DATA_MODEL.md` e `docs/DECISIONS.md` (D-021) com a política de
> armazenamento, autorização e preservação do histórico.

---

## 1. Escopo entregue

- CRUD administrativo de **baralhos** (`tarot_decks`): nome, slug único, descrição opcional e estado ativo/inativo.
- CRUD administrativo de **cartas** (`tarot_cards`) dentro de um baralho: nome, slug interno gerado pelo servidor, ordem, significados normal/invertido opcionais, imagem frontal obrigatória e estado.
- Reordenação por botões acessíveis (subir/descer), com normalização da ordem.
- Pré-visualização de imagens por rota autorizada.
- Auditoria de criação, edição, ativação/inativação, reordenação e substituição de imagem.
- **Não** inclui o seletor dentro do chat (Prompt de chat persistente) nem exclusão física de baralhos/cartas.

---

### 1.1 Catálogo inicial instalado

A seeder `TarotDeckSeeder` (demonstração) e o comando
`arcana:import-tarot-catalog` (qualquer ambiente, inclusive produção) montam o
mesmo catálogo de partida a partir de `App\Support\Tarot\TarotCatalog` e copiam
para o disco privado as frentes versionadas em `database/seeders/assets/tarot`:

| Baralho | Slug | Cartas | Origem das imagens |
|---|---|---|---|
| Tarot de Marseille | `tarot-de-marseille` | 78 (22 arcanos maiores + 56 menores) | Jean Dodal (Lyon, 1701-1715), digitalizações em domínio público |
| Lenormand | `lenormand` | 36 | Petit Lenormand da BnF/Gallica (`btv1b10529584r`), em domínio público |
| Rider-Waite-Smith | `rider-waite-smith` | 78 (22 arcanos maiores + 56 menores) | Pamela Colman Smith para a Rider & Son (Londres, 1909), digitalizações em domínio público |
| Baralho de 52 Cartas | `baralho-de-52-cartas` | 52 (13 posições em 4 naipes) | Frentes originais do Arcana, desenhadas para o projeto |
| Baralho Espanhol | `baralho-espanhol` | 40 (10 posições em 4 naipes) | Frentes originais do Arcana, desenhadas para o projeto |

- Os arquivos são JPEG normalizados em 320 × 586 e ficam em
  `cards/{marseille|lenormand|rider-waite|baralho-52|baralho-espanhol}/{slug da carta}.jpg`
  no disco privado, servidos apenas pela rota autorizada.
- Cada carta recebe significado normal e invertido a partir do catálogo.
- A execução é idempotente: baralho e carta são localizados pelo `slug`, e uma imagem
  cujo caminho tenha sido substituído pela administração não é sobrescrita.
- Baralhos fictícios de versões anteriores (`arcana-essencial`, `oraculo-da-lua`) e seus
  PNGs de demonstração são removidos na execução, sem afetar os snapshots do histórico.
- Em produção `db:seed` é bloqueado; o catálogo é instalado por
  `php artisan arcana:import-tarot-catalog` (opção `--source=` para um acervo fora do
  projeto). O comando usa a mesma rotina, então também é idempotente e não sobrescreve
  imagem enviada pela administração.

#### Como cada baralho é lido

- **Tarot de Marseille** e **Rider-Waite-Smith** têm 22 arcanos maiores próprios de cada
  tradição (o Marseille usa Bateleur/Papisa/Papa/Justiça; o Rider-Waite-Smith usa
  Mago/Sacerdotisa/Hierofante/Força) e compartilham os 56 arcanos menores, com nomes
  Valete, Cavaleiro, Rainha e Rei por naipe.
- **Baralho de 52 Cartas** e **Baralho Espanhol** não têm leitura tradicional fechada
  como o tarot: cada carta combina a leitura da posição (Ás a Rei, e no baralho espanhol
  também Sota e Cavalo) com o campo de vida do naipe (Copas afetivo, Ouros material,
  Espadas mental/conflitos, Paus ação/trabalho). O texto gravado no significado já traz
  essa composição.

#### Licenciamento das frentes

- Marseille, Lenormand e Rider-Waite-Smith são digitalizações de obras em domínio
  público (a primeira edição do Rider-Waite-Smith é de 1909).
- As frentes do baralho de 52 cartas e do baralho espanhol são desenhos originais
  produzidos para o Arcana, sem material de terceiros, e podem ser redistribuídas com o
  projeto.

---

## 2. Rotas e autorização

Todas as rotas abaixo exigem sessão autenticada, `role:admin` e autorização explícita
via `TarotCatalogPolicy` em cada ação do controller. Ocultar links não é suficiente.

| Método | Rota | Ação |
|---|---|---|
| GET | `/admin/baralhos` | listar/filtrar baralhos |
| GET/POST | `/admin/baralhos/novo`, `/admin/baralhos` | formulário e criação |
| GET/PATCH | `/admin/baralhos/{deck}/editar`, `/admin/baralhos/{deck}` | edição |
| PATCH | `/admin/baralhos/{deck}/status` | ativar/inativar baralho |
| GET | `/admin/baralhos/{deck}/cartas` | listar cartas do baralho |
| GET/POST | `/admin/baralhos/{deck}/cartas/nova`, `/admin/baralhos/{deck}/cartas` | formulário e criação de carta |
| GET/PATCH | `/admin/baralhos/{deck}/cartas/{card}/editar`, `/admin/baralhos/{deck}/cartas/{card}` | edição |
| PATCH | `/admin/baralhos/{deck}/cartas/{card}/status` | ativar/inativar carta |
| PATCH | `/admin/baralhos/{deck}/cartas/{card}/mover` | subir/descer na ordem |
| GET | `/catalogo/cartas/{card}/imagem` | pré-visualização autorizada |

A rota de imagem é a única acessível fora do papel de administrador:

- **Admin ativo**: pode visualizar qualquer carta, inclusive inativa ou de baralho inativo.
- **Cliente/atendente ativo**: somente cartas **ativas de baralhos ativos** e somente se
  participar (`client_id` ou `attendant_id`) de uma **consulta ativa**.
- Usuário inativo, visitante, participante de consulta apenas `requested` ou qualquer
  usuário sem vínculo recebem `403`; carta/arquivo inexistente recebe `404`.

A resposta usa `Cache-Control: private, max-age=300`, `X-Content-Type-Options: nosniff`
e `Content-Disposition` com nome lógico `carta-{id}.{ext}`. O caminho físico do storage
não aparece no HTML, nos headers nem no corpo da resposta.

---

## 3. Upload e validação

Regras aplicadas no servidor (Form Requests):

- Formatos aceitos: **JPEG, PNG e WebP**.
- SVG, GIF, formatos executáveis, arquivos de texto e conteúdo cujo MIME real não seja
  um dos três permitidos são rejeitados pelas regras `image`, `mimes` e `mimetypes`.
- Teto único de recebimento em `config/images.php` (`IMAGE_UPLOAD_MAX_KB`, padrão
  **25600 KB / 25 MB**), compartilhado pelas cartas, fotos de atendentes, logo,
  favicon e mídia das seções. O `php.ini` do container permite 32 MB por arquivo e
  40 MB por requisição; o Nginx aceita 40 MB (`client_max_body_size`).
- Depois da validação, a imagem é **normalizada no servidor** por
  `App\Support\Images\ImageNormalizer` antes de ser gravada:
  - a orientação do EXIF é aplicada (fotos de celular não ficam deitadas);
  - `scaleDown()` reduz até o perfil `tarot_card` (1200×1800), preservando a
    proporção e sem ampliar imagens menores;
  - o arquivo é recodificado em **WebP qualidade 85**, o que também descarta
    EXIF/GPS do original;
  - um teto de `IMAGE_MAX_PIXELS` (40 MP) barra *decompression bombs* antes da
    decodificação, e o orçamento de memória recusa imagens que não caberiam no
    processo.
- Em navegadores modernos, `resources/js/image-upload.js` reduz e recomprime no
  cliente arquivos acima de 4 MB antes do envio. É só uma otimização: a decisão
  final e a gravação continuam no servidor.
- A imagem é obrigatória na criação. Na edição, é opcional quando já existe imagem;
  cartas antigas sem imagem passam a exigi-la na próxima edição.
- O nome enviado pelo navegador **nunca** é usado como destino. O servidor gera
  `cards/{40 caracteres aleatórios}.{extensão do formato final}`.
- O binário não é gravado no MySQL. Persistimos apenas caminho relativo, MIME, tamanho
  em bytes e checksum SHA-256 **do arquivo normalizado**.
- Arquivos gravados antes desta mudança continuam sendo servidos como estão; a
  allowlist de entrega já aceita JPEG, PNG e WebP.

Configuração:

```dotenv
IMAGE_UPLOAD_MAX_KB=25600
IMAGE_MAX_PIXELS=40000000
IMAGE_DRIVER=gd
IMAGE_MEMORY_LIMIT=512M
```

Disco dedicado em `config/filesystems.php`:

```php
'tarot_private' => [
    'driver' => 'local',
    'root' => storage_path('app/private/tarot'),
    'serve' => false,
    'throw' => true,
],
```

As imagens ficam em `storage/app/private/tarot/cards/`, fora de `public/`. O disco
`public` e o link `storage:link` não são usados pelo catálogo.

---

## 4. Substituição de imagem e histórico

- Cada upload cria um **novo caminho imutável**. O caminho anterior nunca é sobrescrito.
- Ao substituir a imagem, o arquivo antigo **não é apagado**, pois pode estar
  referenciado por `consultation_messages.image_path` (snapshot) ou por registros
  históricos de auditoria.
- A troca gera `tarot_card.image_replaced` com `from_path`, `to_path`, `mime` e `size`.
  Nenhum conteúdo binário é registrado.
- Mensagens antigas preservam `deck_name`, `card_name` e `image_path`; desativar carta
  ou baralho não altera esses snapshots.
- Se a transação de banco falhar após a gravação, o novo arquivo é descartado. Arquivos
  antigos jamais entram nessa limpeza.

### Política de limpeza

Não existe exclusão de cartas/baralhos pela interface. Uma rotina futura de manutenção
só poderá remover arquivos quando **todas** as condições forem verdadeiras:

1. o arquivo não é o `image_path` atual de nenhuma carta;
2. o caminho não aparece em nenhum `consultation_messages.image_path`;
3. o caminho não aparece em metadados de auditoria relevantes;
4. o arquivo está fora da janela de retenção definida pela política de backup/LGPD.

A limpeza deve ser idempotente, registrar o que removeu e nunca tocar em arquivos
referenciados. Enquanto essa rotina não existir, o custo de armazenamento é aceito em
favor da integridade do histórico.

---

## 5. Seleção no chat

O catálogo oferece o scope `TarotCard::availableForSelection()`, que retorna apenas
cartas ativas cujo baralho também está ativo. `ConsultationController::availableCardDecks()`
usa esse critério no chat e ainda:

- limita a lista à consulta ativa em que o usuário é o atendente (`Gate::sendCard`);
- descarta cartas sem arquivo de imagem no disco (`hasStoredImage()`), então a lista
  nunca oferece uma opção que falharia no envio;
- carrega as cartas por baralho (`is_active`, `sort_order`, `name`) para evitar N+1.

O seletor é um `<fieldset class="chat-card-picker">` renderizado em
`consultations/partials/chat.blade.php`, dentro do bloco recolhível “Enviar uma carta”:

- um grupo por baralho com nome, contagem de cartas e uma grade de opções;
- cada opção é um `<input type="radio" name="tarot_card_id">` + `<label>` com a
  imagem (`route('tarot.cards.image', $card)`) e o nome da carta, usando `loading="lazy"`
  para não baixar dezenas de imagens de uma vez;
- o envio continua sendo `POST /consultas/{id}/cartas` (`consultations.cards.store`),
  que grava os snapshots (`deck_name`, `card_name`, `image_path`) na mensagem e
  recusa no servidor cartas inativas, baralhos inativos ou imagem ausente — mesmo que o
  navegador envie o ID manualmente.

Sem a lista acima o bloco mostra “Nenhuma carta ativa com imagem está disponível para
envio neste momento”; clientes e administradores nunca recebem o seletor.

---

## 6. Auditoria

| Ação | Quando |
|---|---|
| `tarot_deck.created` | criação do baralho |
| `tarot_deck.updated` | edição (campos alterados e transição de estado, se houver) |
| `tarot_deck.status_changed` | ativação/inativação do baralho |
| `tarot_card.created` | criação da carta |
| `tarot_card.updated` | edição de dados/ordem/slug |
| `tarot_card.status_changed` | ativação/inativação da carta |
| `tarot_card.reordered` | reordenação (posição de origem/destino) |
| `tarot_card.image_replaced` | troca de imagem (caminhos, MIME e tamanho) |

O ator é sempre o administrador autenticado. Metadados não incluem binários,
credenciais ou o conteúdo integral de descrições/significados.

---

## 7. Operação e testes

Após atualizar o código:

```sh
docker compose build app          # aplica uploads.ini, a extensão exif e o GD do Dockerfile
docker compose up -d
docker compose exec app php artisan migrate
npm run build
docker compose exec app php artisan test
```

Testes principais em `tests/Feature/AdminTarotCatalogTest.php`: CRUD, autorização,
inativação, vínculo carta/baralho, upload válido, MIME/tamanho inválido, storage fora
de `public/`, substituição sem apagar arquivo histórico, preview por participante de
consulta ativa e bloqueio de usuários não administradores.

---

## 8. Limitações conhecidas do MVP

- Baralho não possui imagem de capa; a imagem obrigatória é da carta.
- Não há editor de imagem, recorte ou redimensionamento; o arquivo validado é preservado
  como enviado (com nome e metadados controlados pelo servidor).
- A reordenação usa botões subir/descer, sem drag-and-drop.
- Não há exclusão física de baralhos/cartas pela interface; a estratégia é inativar.
- O seletor visual do chat é exclusivo do atendente da consulta ativa e não tem busca
  ou filtro: com muitos baralhos ativos a grade cresce e o atendente rola a lista.


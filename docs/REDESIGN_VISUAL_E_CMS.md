# Redesign visual, painéis e CMS

Documento da etapa 22: identidade visual, página pública, painéis com sidebar,
dashboards, gráficos SVG e gerenciamento de conteúdo do site. As regras de
negócio (consultas, créditos, cobrança, comissões e permissões) não foram
alteradas.

## 1. Identidade visual

A paleta e as medidas ficam em `resources/css/_tokens.scss`; os componentes
consomem variáveis CSS `--arcana-*`, sem cores soltas nos templates.

| Token | Valor | Uso |
|---|---|---|
| `--arcana-bg` | `#0a0918` | fundo azul-noite |
| `--arcana-bg-soft` | `#12102a` | seções alternadas |
| `--arcana-surface` | `#171433` | cards e sidebar |
| `--arcana-surface-2` | `#1e1a42` | hover e destaque |
| `--arcana-purple` | `#2f1b57` | roxo de apoio |
| `--arcana-gold` | `#c9a84c` | destaque e foco |
| `--arcana-gold-light` | `#e2c874` | links e ícones em hover |
| `--arcana-ivory` | `#f4f1e8` | texto principal |
| `--arcana-ivory-muted` | `#b9b3ce` | texto secundário |
| `--arcana-ivory-dim` | `#8a84a3` | metadados |

Estados usam `success #4caf7d`, `warning #e0a83c`, `danger #e0645f` e
`info #5b9bd5`, sempre com variantes escuras para superfícies. O foco visível
é dourado (`0 0 0 3px rgba(201, 168, 76, .35)`).

Tipografia: títulos em **Cormorant Garamond** (fallback Cinzel/serifada) e
interface em **Inter** (fallback Manrope/sistema). As fontes são empacotadas
localmente com `@fontsource` em `resources/css/app.scss`, sem depender de
Google Fonts em tempo de execução. Tamanhos usam `clamp()` para leitura em
telas pequenas.

Elementos simbólicos são SVG inline e CSS (estrelas, constelações, molduras e
gradientes). Não há emojis usados como ícones de interface. Botões, cards,
badges, alertas e tabelas têm transições curtas; `@media
(prefers-reduced-motion: reduce)` em `_utilities.scss` zera animações e
transições.

## 2. Página pública

- Layout compartilhado: `resources/views/components/layouts/public.blade.php`.
- Navbar/footer: `layouts/partials/navbar-public.blade.php` e
  `footer-public.blade.php`; links vêm do CMS e usam rotas nomeadas ou âncoras
  resolvidas no servidor.
- Home: hero, como funciona, atendentes em destaque, trabalhe conosco, FAQ e
  chamada final. O conteúdo e a **ordem das seções** vêm de
  `App\Support\SiteContent`; seções não publicadas não são renderizadas.
- Atendentes: diretório e perfil usam `components/public/attendant-card`,
  badges de presença e dados reais de especialidade/disponibilidade.
- Trabalhe conosco, contato e páginas legais usam a mesma casca pública.
- Login, cadastro e recuperação de senha usam `components/layouts/guest`.

A home aceita âncoras estáveis (`#como-funciona`, `#atendentes`,
`#trabalhe-conosco`, `#faq`) para a navbar e para o botão **Ver no site** do
CMS. A ordem das seções pode mudar sem quebrar esses destinos.

## 3. Painéis e sidebars

Os três painéis usam `<x-layouts.panel variant="panel|admin">`. A variante
`admin` monta `navbar-admin` + `sidebar-admin`; a variante `panel` atende
cliente e atendente com `navbar-auth` + `sidebar-client`, escolhendo os links
pelo papel autenticado.

- Sidebar fixa em desktop e offcanvas do Bootstrap em telas menores; o botão
  de fechar só aparece no mobile.
- Grupos do admin: Visão geral, Gestão, Financeiro e Sistema, incluindo
  **Conteúdo do site** e **Auditoria**.
- Grupos do cliente: **Meu espaço** e **Consultas** (carteira e atendentes).
- Atendente: **Meu espaço** com painel e histórico; os atalhos de comissões
  ficam no conteúdo do dashboard.
- Cabeçalhos usam `<x-layouts.page-header>`; mensagens de sessão usam
  `layouts/partials/flash-messages`.
- Conteúdo principal tem `id="conteudo-principal"`, link **Pular para o
  conteúdo**, `aria-current` nos links ativos e `noindex` nos painéis.

## 4. Dashboards e gráficos

Os gráficos são **SVG inline sem JavaScript**, gerados por
`<x-ui.chart>`. Há linha e barra, eixo com valores formatados (contagem ou
moeda via `App\Support\Money`), `<title>` por ponto/barra, tabela oculta para
leitores de tela e estado vazio **“Sem dados no período”**.

| Painel | Indicadores | Gráficos | Fonte |
|---|---|---|---|
| Cliente | saldo, consultas, atendentes disponíveis, mensagens novas | gastos por dia (barra, 14 dias) | `CreditLedgerEntry` de débitos ligados a consultas da carteira |
| Atendente | presença, consulta ativa, solicitações, concluídas, comissão | consultas recebidas e comissões por dia (linha/barra) | `Consultation` do atendente e `CommissionLedgerEntry` (estornos subtraídos) |
| Admin | clientes, atendentes, consultas ativas, candidaturas, recargas, pagamentos, comissões, receita | consultas por dia e recargas confirmadas (linha/barra) | `AdminDashboardMetrics`, `Payment.paid_at` e tabelas oficiais |

`AdminDashboardMetrics` calcula receita bruta, comissões líquidas e receita
operacional a partir do ledger; `DashboardTrend` agrupa os últimos 14 dias em
PHP para funcionar igual em SQLite e MySQL. Nenhum valor é fixo ou simulado.

## 5. CMS de conteúdo

Área **Conteúdo do site** em `/admin/conteudo` (`admin.site.*`), exclusiva de
administradores. Permite editar:

- identidade: nome, descrição, logo e favicon (`settings`);
- seções da home: olho, título, subtítulo, texto, imagem/alt, CTAs,
  publicação e ordem (`site_sections`);
- navbar e rodapé: grupo, rótulo, destino, nova aba, ativo e ordem
  (`site_navigation_links`);
- FAQ: pergunta, resposta, ativo e ordem (`faq_items`).

Sem registro no banco, os padrões de `config/site.php` são exibidos. Ao salvar,
o registro passa a ser a fonte pública. **Salvar publica imediatamente**; não
há rascunho separado. Destinos aceitam somente rotas permitidas, âncoras
conhecidas ou URLs `https`; valores inválidos são rejeitados e não renderizados.
A página inicial respeita `sort_order` e ignora seções despublicadas.

### Imagens

Upload no disco `public`, diretório `storage/app/public/site` (configurável),
com `php artisan storage:link` em produção. Formatos JPEG/PNG/WebP, teto único
de 25 MB (`IMAGE_UPLOAD_MAX_KB`), MIME real validado e nome aleatório de 40
caracteres com extensão derivada do formato processado. A imagem é reduzida
para o perfil da tela (logo 1200×1200, favicon 512×512 em PNG, seções
2400×1600) e recomprimida em WebP, o que também remove EXIF/GPS. O arquivo novo
é gravado antes da transação e removido se o banco falhar; a imagem antiga só é
apagada após o commit. A URL só é gerada se o arquivo existir. Detalhes em
[SITE_CONTENT.md](SITE_CONTENT.md).

### Permissões e auditoria

Todas as rotas do CMS exigem `access-admin-panel`; cliente e atendente recebem
403/redirecionamento conforme o middleware existente. Alterações relevantes
gravam `audit_logs` (`site.identity_updated`, `site.section_updated`,
`site.navigation_*`, `site.faq_*`) com ator e metadados sem segredos.

## 6. Persistência adicionada

Migrations criam `site_sections`, `site_navigation_links` e `faq_items`, com
chave única para seção, grupos/ordenação de links e índice por item ativo. As
configurações de identidade reutilizam a tabela `settings`. Nenhuma migration
altera tabelas financeiras ou de consultas.


## 7. Validação executada

```bash
docker compose exec app php artisan test --compact   # 242 testes, 2.031 asserções
docker compose exec app php artisan view:cache        # templates compilados
npm run build                                         # assets Vite
```

Pint foi aplicado aos arquivos PHP alterados com
`vendor/bin/pint --format agent` (o container não tem Git para `--dirty`).

## 8. Limitações conhecidas

- Não há pré-visualização/rascunho: salvar já publica.
- O CMS edita campos estruturados; não aceita HTML arbitrário por segurança.
- As chaves das seções são fixas; criar seções totalmente novas exige código.
- A inspeção visual em navegador não foi automatizada neste ambiente; a
  validação foi feita por testes de feature, compilação Blade e build Vite.
- O chat existente foi preservado; esta etapa não adiciona novas regras de
  negócio nem altera o cálculo financeiro.

## 9. Como editar a home

1. Entre como administrador e abra **Conteúdo do site**.
2. Edite **Identidade** para nome, descrição e imagens.
3. Abra uma seção, altere os textos/CTAs, publique e ajuste a ordem.
4. Use **Ver no site** para abrir a âncora correspondente.
5. Gerencie links da navbar/rodapé e o FAQ nos respectivos menus.
6. Faça upload de imagens em JPEG, PNG ou WebP (até 25 MB; o servidor reduz e converte para WebP automaticamente).


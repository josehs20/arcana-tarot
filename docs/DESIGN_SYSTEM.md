# Arcana Tarot — Design System

> Documento produzido na etapa **Prompt 05 — Design system e layouts Blade**.
> É a fonte de verdade para cores, tipografia, componentes e layouts enquanto as
> telas de negócio (consultas, chat, carteira, admin) forem sendo construídas.

---

## 1. Princípios

- **Premium e místico sem excesso**: superfícies escuras, dourado discreto e
  ornamentos leves (textura de estrelas gerada em CSS, sem imagens externas).
- **Conteúdo primeiro**: hierarquia clara, contraste adequado e nenhum efeito que
  atrapalhe a leitura.
- **Acessível por padrão**: foco visível, rótulos explícitos, estados que nunca
  dependem só da cor e animações que respeitam `prefers-reduced-motion`.
- **Sem dependências visuais externas obrigatórias**: ícones são SVG inline e as
  fontes são servidas localmente via `@fontsource`.
- **Bootstrap 5.3 + Sass + Vite**: nada de React, Vue ou Tailwind.

---

## 2. Stack front-end

| Item | Escolha | Arquivo |
|---|---|---|
| Framework CSS | Bootstrap 5.3 (SCSS) | `resources/css/app.scss` |
| Pré-processador | Sass | `resources/css/*.scss` |
| Build | Vite + `laravel-vite-plugin` | `vite.config.js` |
| JavaScript | Bootstrap bundle (navbar, dropdown, offcanvas, collapse, alert) | `resources/js/app.js` |
| Tipografia | Inter (texto) + Cormorant Garamond (títulos) | `@fontsource/*` |
| Ícones | SVG inline, traço 1.5–2 | componentes e partials |

O CSS é dividido em camadas importadas nesta ordem:

```scss
@use 'bootstrap-variables' as *;  // overrides dos !default do Bootstrap
@import 'bootstrap/scss/bootstrap';
@import 'base';                   // reset, tipografia, superfícies, utilitários de cor
@import 'components';             // botões, cards, badges, alertas, campos, accordion, vazio
@import 'layouts';                // navbar, sidebar, painel, auth, hero, seções, erro
@import 'utilities';              // status, animações, prefers-reduced-motion
```

---

## 3. Paleta

Azul-noite como base, roxo profundo como apoio, dourado discreto para destaque e
marfim para texto. Todos os valores existem como token Sass (`_tokens.scss`) e
como custom property (`:root` em `_base.scss`).

### 3.1 Superfícies e marca

| Token Sass | CSS var | Hex | Uso |
|---|---|---|---|
| `$arcana-night` | `--arcana-bg` | `#0a0918` | fundo do app e das páginas de erro |
| `$arcana-night-soft` | `--arcana-bg-soft` | `#12102a` | seções alternadas e rodapé |
| `$arcana-surface` | `--arcana-surface` | `#171433` | cards, sidebar, offcanvas, modal |
| `$arcana-surface-2` | `--arcana-surface-2` | `#1e1a42` | hover e superfícies elevadas |
| `$arcana-purple` | `--arcana-purple` | `#2f1b57` | apoio, item ativo da sidebar |
| `$arcana-purple-light` | `--arcana-purple-light` | `#4a2d85` | hover de botões secundários |
| `$arcana-purple-soft` | `--arcana-purple-soft` | `#6b4bb0` | gradientes e bordas |

### 3.2 Texto e destaque

| Token Sass | CSS var | Hex | Uso |
|---|---|---|---|
| `$arcana-gold` | `--arcana-gold` | `#c9a84c` | destaque principal, bordas, ícones |
| `$arcana-gold-light` | `--arcana-gold-light` | `#e2c874` | links, hover, títulos de destaque |
| `$arcana-gold-dark` | `--arcana-gold-dark` | `#8f7530` | contornos e gradiente |
| `$arcana-ivory` | `--arcana-ivory` | `#f4f1e8` | texto principal |
| `$arcana-ivory-muted` | `--arcana-ivory-muted` | `#b9b3ce` | texto secundário |
| `$arcana-ivory-dim` | `--arcana-ivory-dim` | `#8a84a3` | metadados, placeholders, hints |

### 3.3 Estados

| Token Sass | CSS var | Hex | Uso |
|---|---|---|---|
| `$arcana-success` | `--arcana-success` | `#4caf7d` | online, sucesso |
| `$arcana-warning` | `--arcana-warning` | `#e0a83c` | ocupado, atenção |
| `$arcana-danger` | `--arcana-danger` | `#e0645f` | erro, ação destrutiva |
| `$arcana-info` | `--arcana-info` | `#5b9bd5` | informação neutra |

> **Regra de acessibilidade:** status (`online`, `offline`, `ocupado`, `pendente`
> etc.) **sempre** exibem texto. A cor do ponto é apenas um reforço visual.

### 3.4 Gradientes

| Token | Valor | Uso |
|---|---|---|
| `$arcana-gradient-gold` | `linear-gradient(135deg, #e2c874, #c9a84c 55%, #8f7530)` | botão primário e filete de card |
| `$arcana-gradient-purple` | `linear-gradient(135deg, #4a2d85, #2f1b57)` | superfícies de destaque |
| `$arcana-gradient-hero` | `linear-gradient(160deg, #12102a, #0a0918 60%, #1a0f33)` | hero da landing page |

---

## 4. Tipografia

| Papel | Família | Token |
|---|---|---|
| Títulos e display | Cormorant Garamond, Cinzel, Times New Roman, serif | `$arcana-font-display` |
| Corpo, UI e formulários | Inter, Manrope, system-ui, sans-serif | `$arcana-font-body` |

- Corpo: `1rem` / linha `1.6`.
- `h1`: `clamp(2rem, 4.5vw, 3rem)`; `h2`: `clamp(1.6rem, 3.2vw, 2.25rem)`;
  `h3`: `clamp(1.3rem, 2.4vw, 1.65rem)`.
- `.arcana-eyebrow`: rótulo em caixa alta, `0.75rem`, `letter-spacing: .18em`,
  dourado — usado acima dos títulos de seção.
- `.arcana-lead`: parágrafo de introdução, `1.05rem`, marfim suave, largura máxima
  de `60ch`.
- O hero usa `clamp(2.4rem, 6vw, 4rem)` com peso 700.

---

## 5. Espaçamento, raios e sombras

| Token | Valor | Uso |
|---|---|---|
| `$arcana-radius-sm` | `0.375rem` | botões, inputs |
| `$arcana-radius` | `0.625rem` | cards, alertas, stat cards |
| `$arcana-radius-lg` | `1rem` | auth card, estado vazio |
| `$arcana-shadow-sm` | `0 1px 2px rgba(0,0,0,.35)` | cards em repouso |
| `$arcana-shadow` | `0 8px 24px rgba(0,0,0,.45)` | hover e dropdowns |
| `$arcana-shadow-lg` | `0 18px 48px rgba(0,0,0,.55)` | auth card e modais |
| `$arcana-ring-gold` | `0 0 0 3px rgba(201,168,76,.35)` | foco de botões e inputs |

Escala de espaçamento: a do Bootstrap (`0`, `1`–`5`, `gap-*`, `p-*`, `m-*`),
usada diretamente nas views. Seções públicas usam `padding-block: 4.5rem`
(`.arcana-section`); o hero usa `6rem`/`5rem`.

Layout:

- `.container-wide` — largura máxima `1320px`, padding lateral `1.25rem`, usada
  nas páginas públicas.
- `.container` / `.container-fluid` do Bootstrap — usadas em auth e painel.
- `--arcana-navbar-height: 3.9rem` — altura de referência para a sidebar fixa.

---

## 6. Layouts

Os layouts são **componentes Blade anônimos** em `resources/views/components/layouts/`,
usados com a sintaxe de slot. Todas as views novas devem usá-los (não há mais
`@extends`).

### 6.1 Público — `<x-layouts.public>`

Navbar pública fixa, conteúdo e rodapé. Inclui link “Pular para o conteúdo”.

```blade
<x-layouts.public
    title="Arcana Tarot — Consultas de Tarô Online"
    description="Consultas de tarô online com atendentes especializados."
>
    ...seções...
</x-layouts.public>
```

Props: `title`, `description`. Arquivos: `components/layouts/public.blade.php`,
`layouts/partials/navbar-public.blade.php`, `layouts/partials/footer-public.blade.php`.

### 6.2 Autenticação — `<x-layouts.guest>`

Fundo noturno com estrelas e cartão centralizado (`.auth-card`).

```blade
<x-layouts.guest title="Entrar | Arcana Tarot">
    <h1 class="h3 mb-1">Entrar</h1>
    <form method="POST" action="{{ route('login') }}">...</form>
</x-layouts.guest>
```

Props: `title`.

### 6.3 Painel — `<x-layouts.panel>`

Navbar + sidebar (offcanvas no mobile, coluna fixa a partir de `lg`) + flash
messages + conteúdo.

```blade
<x-layouts.panel variant="panel" title="Painel do Cliente | Arcana Tarot">
    <x-layouts.page-header title="Painel do Cliente" subtitle="...">
        <x-slot:actions>
            <x-ui.status-badge status="online" />
        </x-slot:actions>
    </x-layouts.page-header>
    ...
</x-layouts.panel>
```

- `variant="panel"` — clientes e atendentes (`navbar-auth` + `sidebar-client`);
  o destino do link “Painel” é resolvido pelo papel do usuário.
- `variant="admin"` — administração (`navbar-admin` + `sidebar-admin`).

No mobile, os botões `data-bs-toggle="offcanvas"` abrem a navegação lateral.
A sidebar fica abaixo da navbar fixa (`top: var(--arcana-navbar-height)`).

### 6.4 Erro — `<x-layouts.error>`

Página centralizada com código grande, título, texto e ações.

```blade
<x-layouts.error code="404" title="Página não encontrada" text="...">
    <a class="btn btn-ghost" href="{{ route('home') }}">Ir para o início</a>
</x-layouts.error>
```

Views em `resources/views/errors/`: `403`, `404`, `419`, `429`, `500`, `503`.

### 6.5 Cabeçalho de página — `<x-layouts.page-header>`

Props `title`, `subtitle`; slot `actions`. Classes: `.page-header`,
`.page-header__title`, `.page-header__subtitle`, `.page-header__actions`.

---

## 7. Componentes Blade

Todos ficam em `resources/views/components/ui/`. Aceitam `class` extra, que é
mesclado às classes do componente.

### 7.1 Botão — `<x-ui.button>`

| Prop | Valores | Padrão |
|---|---|---|
| `variant` | `primary`, `secondary`, `ghost`, `danger` | `primary` |
| `size` | `sm`, `md`, `lg` | `md` |
| `block` | booleano | `false` |
| `loading` | booleano (spinner + disabled) | `false` |
| `disabled` | booleano | `false` |
| `type` | `button`, `submit`, `reset` | `button` |

```blade
<x-ui.button type="submit" variant="primary" size="lg" block>Entrar</x-ui.button>
<x-ui.button variant="ghost" :loading="true">Carregando…</x-ui.button>
<a class="btn btn-primary" href="...">Link com aparência de botão</a>
```

- `.btn-primary` usa gradiente dourado e texto `#1a1408` (contraste alto).
- `.btn-ghost` tem contorno dourado sutil e fundo transparente.
- Para links, use as classes `.btn .btn-*` diretamente.

### 7.2 Card — `<x-ui.card>`, `<x-ui.card-header>`, `<x-ui.card-body>`, `<x-ui.card-footer>`

```blade
<x-ui.card class="card-gilded">
    <x-ui.card-header><h2 class="h6 mb-0">Minhas consultas</h2></x-ui.card-header>
    <x-ui.card-body>...</x-ui.card-body>
    <x-ui.card-footer>...</x-ui.card-footer>
</x-ui.card>
```

- `.card-gilded` adiciona um filete dourado no topo.
- Header usa Cormorant Garamond; body tem `1.25rem` de padding.
- Cards usam `.card` do Bootstrap com as variáveis sobrescritas em
  `_bootstrap-variables.scss`.

### 7.3 Alerta — `<x-ui.alert>`

| Prop | Valores | Padrão |
|---|---|---|
| `type` | `success`, `info`, `warning`, `danger` | `info` |
| `dismissible` | booleano | `true` |
| `icon` | booleano | `true` |

```blade
<x-ui.alert type="success">Consulta confirmada.</x-ui.alert>
<x-ui.alert type="danger" :dismissible="false">...</x-ui.alert>
```

Ícones são SVG inline por tipo; o botão de fechar usa `data-bs-dismiss="alert"`
(requer o bundle do Bootstrap, já importado). Alertas têm `role="alert"`.

### 7.4 Badge — `<x-ui.badge>`

| Prop | Valores | Padrão |
|---|---|---|
| `variant` | `gold`, `success`, `warning`, `danger`, `info`, `secondary` | `secondary` |

```blade
<x-ui.badge variant="gold">Destaque</x-ui.badge>
<x-ui.badge variant="success">Publicado</x-ui.badge>
```

Classes geradas: `.badge` + `.badge-{variant}` (definidas em `_components.scss`).

### 7.5 Status — `<x-ui.status-badge>`

| Prop | Valores | Padrão |
|---|---|---|
| `status` | `online`, `offline`, `busy`, `pending`, `under_review`, `approved`, `rejected`, `requested`, `active`, `ended`, `cancelled`, `expired` | `offline` |
| `label` | texto livre (sobrescreve o rótulo padrão) | `null` |
| `dot` | booleano | `true` |

```blade
<x-ui.status-badge status="online" />
<x-ui.status-badge status="busy" label="Em consulta" />
<x-ui.status-badge status="under_review" />
```

Sempre renderiza **texto visível** (`Online`, `Offline`, `Ocupado`, `Em análise`…)
além do ponto colorido. O ponto é `aria-hidden="true"`; quem usa leitor de tela lê
o texto. As cores vêm de `.status-dot--online/busy/offline` e
`.status-text--online/busy/offline` (`_utilities.scss`).

### 7.6 Campo — `<x-ui.field>`

| Prop | Descrição |
|---|---|
| `name` | obrigatório; usado em `name`, `id`, `old()` e mensagens |
| `label` | rótulo; padrão derivado do `name` |
| `type` | `text`, `email`, `password`, `number`… |
| `value` | valor inicial (sobreposto por `old()`); combine com `ignore-old` |
| `placeholder` | placeholder do input |
| `required` | adiciona `required` + marcador dourado no label |
| `disabled` | desabilita o input |
| `help` | texto de ajuda abaixo do campo |
| `ignore-old` | não aplica `old()` ao valor |

```blade
<x-ui.field name="email" label="E-mail" type="email"
            autocomplete="email" placeholder="voce@exemplo.com" required />
```

- Atributos não declarados (`autocomplete`, `autofocus`, `inputmode`,
  `maxlength`, `readonly`…) são repassados ao `<input>`.
- Em erro: `.is-invalid`, `aria-invalid="true"`, `aria-describedby` e
  `.invalid-feedback` visível (`d-block`).
- O label recebe `.required`, que desenha `*` em dourado via CSS.

### 7.7 Estado vazio — `<x-ui.empty-state>`

| Prop | Descrição |
|---|---|
| `icon` | glifo curto ou SVG já pronto (ex.: `✦`) |
| `title` | título do estado vazio |
| `text` | explicação e próximo passo |

```blade
<x-ui.empty-state icon="✦" title="Nenhuma consulta por aqui"
    text="Quando você agendar uma leitura, ela aparecerá nesta lista.">
    <a class="btn btn-primary mt-2" href="...">Conhecer atendentes</a>
</x-ui.empty-state>
```

Classe base `.empty-state` (flex em coluna, borda tracejada dourada). O slot é
usado para ações.

### 7.8 Stat card — classe `.stat-card`

Não é um componente Blade (é marcação + classes), usada nos dashboards:

```blade
<div class="stat-card">
    <p class="stat-card__label">Consultas</p>
    <p class="stat-card__value">0</p>
    <p class="stat-card__hint">Nenhuma consulta ainda</p>
</div>
```

### 7.9 Card público do atendente — `<x-public.attendant-card>`

```blade
<x-public.attendant-card :attendant="$attendant" :price="$pricePerMinute" />
```

Exibe foto opcional (fallback de iniciais), nome profissional, status, bio curta,
até três especialidades e preço por minuto. O cartão inteiro é clicável por meio
do `::after` no link do nome; `:focus-within` aplica o anel dourado. O componente
recebe apenas dados aprovados — nunca e-mail, comissão ou informações financeiras.

---

## 8. Utilitários e classes de apoio

### Cores

| Classe | Efeito |
|---|---|
| `.text-ivory` | texto marfim (`!important`) |
| `.text-ivory-muted` | texto marfim suave |
| `.text-gold` | texto dourado claro |
| `.bg-card` | fundo `--arcana-surface` |
| `.bg-deep-purple` | fundo `--arcana-purple` |
| `.bg-body` | fundo do app (sobrescreve o Bootstrap) |

### Páginas públicas

| Classe | Uso |
|---|---|
| `.container-wide` | container de até `1320px` |
| `.arcana-hero`, `.arcana-hero__title`, `.arcana-hero__divider` | hero |
| `.arcana-hero--compact` | hero reduzido de listagem/perfil |
| `.arcana-section`, `.arcana-section--alt`, `__title`, `__subtitle` | seções |
| `.arcana-feature`, `__icon`, `__title`, `__text` | cards de conteúdo |
| `.arcana-eyebrow`, `.arcana-lead` | rótulos e introdução |
| `.arcana-stars` | textura de estrelas via `radial-gradient` |
| `.breadcrumb-arcana` | trilha de navegação pública |
| `.attendant-card*`, `.attendant-avatar*` | cartão e avatar do diretório |
| `.profile-header`, `.profile-name`, `.profile-bio`, `.profile-price*` | perfil público |
| `.work-cta*` | chamada "Trabalhe conosco" |
| `.prose-arcana` | textos institucionais/placeholder |
| `.footer-arcana` | rodapé público |

### Movimento

| Classe | Efeito |
|---|---|
| `.arcana-fade-up` | entrada suave (fade + subida) |
| `.arcana-fade-up-delay-1/2/3` | atrasos de `80/160/240ms` |
| `.arcana-pulse` | pulso de opacidade (indicadores) |
| `.arcana-glow` | halo dourado pulsante |

Todas as animações são desativadas por `prefers-reduced-motion: reduce`
(`_utilities.scss`), que também força `scroll-behavior: auto`.

### Painel

| Classe | Uso |
|---|---|
| `.navbar-arcana` | navbar fixa com blur |
| `.offcanvas-sidebar` | sidebar offcanvas (coluna fixa ≥ `lg`) |
| `.sidebar-panel` | sidebar fixa sem offcanvas (reserva) |
| `.page-header*` | cabeçalho de página |
| `.stat-card*` | cartões de métrica |
| `.auth-shell`, `.auth-card*` | telas de autenticação |
| `.error-page*` | páginas de erro |

---

## 9. Acessibilidade

- **Contraste**: marfim (`#f4f1e8`) e marfim suave (`#b9b3ce`) sobre azul-noite;
  dourado é usado em destaques e nunca como única forma de comunicar estado.
- **Foco visível**: `:focus-visible` recebe contorno dourado de 2px com offset;
  botões e inputs usam `$arcana-ring-gold` no foco.
- **Pular para o conteúdo**: os layouts público e de painel incluem
  `.visually-hidden-focusable` apontando para `#conteudo-principal`.
- **Estados com texto**: `.status-indicator` sempre acompanha o ponto colorido de
  um rótulo textual (`Online`, `Ocupado`, `Offline`, `Em análise`…).
- **Formulários**: todo input tem `<label for>`; erros são associados via
  `aria-describedby` e `aria-invalid`; o texto de ajuda usa `.form-text`.
- **Navegação mobile**: o offcanvas tem `aria-label`, botão de fechar rotulado e
  o toggler usa `aria-controls`/`aria-label`.
- **Alertas**: `role="alert"`; ícones são decorativos (`aria-hidden="true"`).
- **Movimento**: qualquer animação respeita `prefers-reduced-motion`.

---

## 10. Responsividade

Breakpoints do Bootstrap 5.3: `sm 576px`, `md 768px`, `lg 992px`, `xl 1200px`.

- A navbar pública colapsa em `.navbar-expand-lg`.
- A sidebar do painel vira offcanvas abaixo de `lg` e coluna fixa a partir de `lg`.
- Os stat cards usam `col-6` no mobile e `col-lg-3` no desktop.
- O hero e as seções usam `clamp()` para tipografia fluida.
- O container público limita a leitura a `1320px` e as seções internas usam
  `max-width` em `ch` para textos longos.

---

## 11. Build e verificação

```sh
npm install
npm run dev      # desenvolvimento (HMR)
npm run build    # produção — gera public/build/manifest.json
```

- O `@vite(['resources/css/app.scss', 'resources/js/app.js'])` está nos quatro
  layouts; sem o build os testes de layout falham ao resolver o manifest.
- `tests/Feature/LayoutTest.php` cobre: layout público, telas de auth, os três
  painéis, componentes (botão, alerta, badge, status) e páginas de erro 403/404.
- `vendor/bin/pint` mantém o padrão de código PHP; `php artisan test` roda a suíte.

### Convenções

1. Use os componentes `<x-ui.*>` e layouts `<x-layouts.*>` em vez de repetir
   marcação.
2. Novas telas de negócio devem apenas preencher o slot do layout do papel
   correspondente; não crie um `<html>` novo.
3. Não reintroduza Tailwind nem dependências de UI externas.
4. Imagens de produto/baralho ficam em storage privado; o design não depende de
   imagens externas — ícones são SVG inline.
5. Textos de interface em português (pt-BR) e datas/moeda formatadas na
   apresentação, nunca no banco (ver `docs/DATA_MODEL.md`).




# Conteúdo do site (CMS)

Área administrativa em **Conteúdo do site** (`/admin/conteudo`), disponível
apenas para usuários com papel `admin`. O acesso é validado no backend por
`Gate::authorize('access-admin-panel')`; esconder o link no menu não é
suficiente.

## O que é editável

| Grupo | Persistência | Observações |
|---|---|---|
| Identidade | `settings` (`site_name`, `site_description`, `site_logo_path`, `site_favicon_path`) | Nome, descrição, logotipo e favicon usados na home e nos painéis |
| Seções da home | `site_sections` | Textos, imagem, CTAs, publicação e ordem |
| Navbar e rodapé | `site_navigation_links` | Grupos `primary` e `footer`, com rota, âncora ou URL |
| Perguntas frequentes | `faq_items` | Pergunta, resposta, ativo/inativo e ordem |

Enquanto não existe registro no banco, `App\Support\SiteContent` usa os
valores padrão de `config/site.php`. Ao salvar uma seção pela primeira vez,
um registro próprio é criado; a partir daí o banco é a fonte exibida.

### Seções e ordem

As chaves de seção são fixas (`hero`, `how_it_works`, `attendants`,
`work_with_us`, `faq`, `final_cta`). O administrador não edita HTML livre:
cada seção tem campos estruturados (título, subtítulo, texto, CTAs, imagem,
`alt`, publicação e `sort_order`). A home renderiza as seções publicadas na
ordem de `sort_order`; seções despublicadas não são exibidas.

O link **Ver no site** usa `config/site.section_anchors` para abrir a âncora
correta (`#como-funciona`, `#atendentes`, `#trabalhe-conosco`, `#faq`).

### Links e destinos

Destinos aceitos são validados por `SiteContent::resolveTarget()`:

- rotas da allowlist `config/site.allowed_routes` (ex.: `attendants.index`);
- âncoras da allowlist `config/site.allowed_anchors`;
- URLs externas **somente** com esquema `https://`.

Qualquer outro valor (por exemplo `admin.dashboard` ou `javascript:`) é
rejeitado na validação e, se já existir no banco, não é renderizado. Como as
rotas internas são resolvidas por nome, o domínio pode mudar sem reconfigurar
links.

## Publicação e edição

Não há fluxo separado de rascunho/pré-visualização: salvar já publica a
alteração. A validação ocorre no backend antes da gravação e o site público
passa a exibir o novo conteúdo imediatamente. O formulário mostra a
pré-visualização da imagem atual e permite removê-la.

## Imagens

Uploads usam o disco público do Laravel (`Storage::disk('public')`), no
diretório `storage/app/public/site` (configurável em
`config/site.image_directory`). Em produção, execute uma vez:

```bash
php artisan storage:link
```

Regras aplicadas por `SiteMediaStorage` e pelos Form Requests:

- formatos: JPEG, PNG e WebP (o tipo real é detectado pelo servidor);
- tamanho máximo: `config/site.max_image_kb` (2 MB por padrão);
- nome aleatório de 40 caracteres, com extensão derivada do MIME — nunca do
  nome enviado — evitando sobrescrita e execução de arquivos;
- upload gravado antes da transação; se o banco falhar, o arquivo novo é
  removido;
- a imagem substituída só é apagada depois do commit, preservando a anterior
  em caso de erro;
- URLs só são geradas se o arquivo existir no disco.

A autorização de upload é a mesma da área administrativa; visitantes e
clientes não conseguem alterar imagens.

## Auditoria

Toda alteração relevante grava `audit_logs` com ator, data, seção/item e
metadados textuais, sem senhas, tokens ou segredos:

- `site.identity_updated`;
- `site.section_updated`;
- `site.navigation_created`, `site.navigation_updated`, `site.navigation_deleted`;
- `site.faq_created`, `site.faq_updated`, `site.faq_deleted`.

## Testes

`tests/Feature/SiteContentCmsTest.php` cobre identidade, publicação de seção,
ordem das seções, CRUD de FAQ, allowlist de navegação, upload inválido/válido
e bloqueio de rotas para não administradores.

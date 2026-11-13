# Arcana Tarot — Registro de Decisões

> Documento produzido na etapa **Prompt 00** (2026-10-20).
> Formato leve de ADR: cada decisão tem ID, status, contexto, decisão e consequências.
> Status possíveis: **Aceita**, **Proposta**, **Pendente**.

---

## 1. Decisões iniciais

### D-001 — Monolito modular Laravel 13

- **Status**: Aceita
- **Contexto**: MVP com três perfis, chat, carteira e catálogo; equipe pequena; stack já definida.
- **Decisão**: manter um único aplicativo Laravel 13, organizado em módulos por responsabilidade (`Http`, `Services`, `Models`, `Policies`, `Events`, `Jobs`), sem separação em microsserviços.
- **Consequências**: simplicidade operacional e transações locais; exige disciplina de camadas para não concentrar regra em controllers.

### D-002 — Dinheiro em centavos inteiros

- **Status**: Aceita
- **Contexto**: regras do projeto proíbem `float` para dinheiro.
- **Decisão**: todos os valores monetários são armazenados e calculados em **centavos inteiros** (`BIGINT`/`unsignedBigInteger`). Formatação para exibição ocorre apenas na camada de apresentação.
- **Consequências**: evita erro de arredondamento binário; exige helpers de formatação e testes de borda.

### D-003 — Papéis com enum nativo + Policies (sem pacote de permissões)

- **Status**: Aceita
- **Contexto**: três perfis fixos no MVP; a stack pretendida não inclui `spatie/laravel-permission`.
- **Decisão**: `users.role` como enum (`admin`, `attendant`, `client`), autorização por **Policies/Gates** e middleware de papel.
- **Consequências**: menos dependências e complexidade; se o produto evoluir para múltiplos papéis por usuário, será necessária migração.

### D-004 — Créditos com livro-razão imutável e saldo como cache

- **Status**: Aceita e implementada no Prompt 13
- **Contexto**: regra proíbe saldo como campo livremente editável.
- **Decisão**: toda movimentação gera `credit_ledger_entries` imutável com `idempotency_key`; `wallets.balance_cents` é apenas cache atualizado na mesma transação. Débitos usam transação + lock de linha + `UPDATE` condicional para impedir saldo negativo. Ajustes administrativos usam operação dedicada com motivo, autor e auditoria.
- **Consequências**: auditoria e reprocessamento seguros; requer rotina de reconciliação e testes de idempotência. As invariantes estão em `docs/WALLET_LEDGER.md`.

### D-005 — Cobrança por minuto iniciado, controlada pelo servidor

- **Status**: Aceita e implementada no Prompt 15
- **Contexto**: cobrança por tempo sem confiar no navegador.
- **Decisão**: o servidor calcula a duração a partir de `started_at`/`ended_at`, cobra **por minuto iniciado** (`max(1, ceil(segundos/60))`, mínimo de 1 minuto e sem carência) e grava débitos idempotentes com a chave `consultation:{id}:minute:{n}`. A tarifa (hoje `price_per_minute_cents` do atendente; originalmente `rate_credits_per_minute × credit_unit_price_cents`, ver D-027) é congelada no aceite. O primeiro minuto é cobrado no aceite; os seguintes pelo comando `consultations:charge-minutes` a cada minuto; o último é liquidado no encerramento/interrupção. Sem saldo, não há débito parcial: a consulta é encerrada como `out_of_credits` e **nunca** fica negativa.
- **Consequências**: previsível para o cliente e independente do navegador; exige scheduler ativo e relógio controlável nos testes. O encerramento automático cobre saldo esgotado, inatividade e logout; tempo máximo de sessão continua fora do MVP. Regras detalhadas em `docs/TIME_BILLING.md` e `docs/SEGURANCA_E_USABILIDADE_CHAT.md`.

### D-006 — Comissão percentual individual com snapshot

- **Status**: Aceita e implementada no Prompt 16
- **Contexto**: comissão por atendente, configurável, sem valores arbitrários fixos.
- **Decisão**: taxa individual em **basis points inteiros** (0–10000) configurada pelo admin, com auditoria de valor anterior/novo; `commission_rate_bps` é copiada para a consulta na abertura (snapshot); comissão calculada com aritmética inteira e arredondamento **half-up** sobre o **valor monetário efetivamente debitado** e registrada em `commission_ledger_entries` imutável e idempotente. Estornos são lançamentos separados, positivos, com motivo e autor. Sem saque/pagamento no MVP.
- **Consequências**: histórico imutável; mudanças de taxa só afetam consultas futuras; o total é participação interna na receita, não lucro nem saldo sacável. Regras completas em `docs/COMMISSION_POLICY.md`.

### D-007 — Imagens de cartas em storage privado com snapshot no envio

- **Status**: Aceita
- **Contexto**: catálogo não pode ser exposto por caminho público; histórico precisa sobreviver a edições.
- **Decisão**: imagens em disco **privado**; ao enviar uma carta, o servidor cria um `card_image_snapshots` imutável; a exibição usa **rota autorizada** que valida a participação na consulta. A mensagem de carta (`type=card`) é persistida **antes** do broadcast.
- **Consequências**: privacidade e consistência histórica; custo de armazenamento de snapshots.

### D-008 — `busy` derivado de consulta ativa

- **Status**: Aceita
- **Contexto**: status do atendente não pode ser manipulado pelo cliente.
- **Decisão**: `online`/`offline` são controlados pelo atendente autenticado; `busy` é **derivado** da existência de consulta ativa e nunca escolhido manualmente. Ao autenticar (senha ou Google), o atendente entra **online automaticamente** (`AttendantAvailabilityManager::signIn()`), respeitando as mesmas regras do painel: perfil publicado, usuário ativo e nenhuma consulta ativa.
- **Consequências**: consistência de disponibilidade; exige cálculo/atualização confiável no servidor. O login nunca falha por causa do status: perfil ausente/não publicado ou consulta ativa mantêm a disponibilidade como está, e a transição automática é auditada com motivo `login`.

### D-009 — Uma consulta ativa por atendente (MVP)

- **Status**: Aceita
- **Contexto**: regra explícita do projeto.
- **Decisão**: garantir no servidor, com transação/bloqueio, no máximo **uma consulta em estado ativo por atendente**.
- **Consequências**: aceite concorrente deve falhar para o segundo solicitante; simplifica cobrança e presença.

### D-010 — Aprovação de candidatura não cria acesso

- **Status**: Aceita
- **Contexto**: dados públicos de candidatura e risco de escalada de privilégio.
- **Decisão**: aprovar uma candidatura altera apenas o estado da candidatura (`approved`), registrando revisor e data. A criação do usuário/atendente é uma ação administrativa separada e auditada.
- **Consequências**: fluxo com uma etapa manual a mais; elimina criação automática de acesso.

### D-011 — PIX somente em modo de teste no MVP

- **Status**: Aceita (sandbox implementado no Prompt 14; provedor real pendente — Q-01)
- **Contexto**: regra proíbe pagamentos reais e credenciais fictícias em produção.
- **Decisão**: implementar integração de PIX apenas em sandbox, com webhook validado no servidor e idempotência. Créditos só são liberados após confirmação do provedor. Nenhuma operação financeira real será executada nos testes.
- **Consequências**: o mock `pix_sandbox` cobre o fluxo e a troca de provedor exige nova implementação do contrato; detalhes em `docs/PIX_SANDBOX.md`.

### D-012 — Tempo real com Laravel Reverb + Echo

- **Status**: Aceita (implementada no Prompt 12)
- **Contexto**: stack pretendida inclui Reverb e cliente compatível com Pusher.
- **Decisão**: usar Reverb como servidor WebSocket e Laravel Echo com cliente Pusher no frontend; canais **privados** autorizados em `routes/channels.php`. Eventos só são emitidos após o commit; o histórico HTTP é o fallback e a fonte de verdade.
- **Consequências**: requer Redis, worker de filas e processo Reverb no Compose; a conexão WebSocket não substitui a persistência nem a autorização por participante. Ver `docs/REALTIME.md`.

### D-013 — Bootstrap 5.3 como framework visual; Tailwind removido

- **Status**: Aceita (implementada no Prompt 05)
- **Contexto**: o esqueleto Laravel 13 traz Tailwind 4; a stack pretendida exige Bootstrap 5.3.
- **Decisão**: adotar Bootstrap 5.3 via Vite/Sass como base visual e **remover** Tailwind do build, evitando dois sistemas de estilos concorrentes. O CSS de identidade (azul-noite, roxo, dourado, marfim) é próprio e documentado em `docs/DESIGN_SYSTEM.md`.
- **Consequências**: `package.json`/`vite.config.js` migrados para Sass; `resources/css/app.css` (Tailwind) substituído por `resources/css/app.scss`; `welcome.blade.php` reescrito com os layouts Blade.

### D-014 — Ambiente de execução reproduzível via Docker (fonte de verdade)

- **Status**: Aceita (implementação no Prompt 02)
- **Contexto**: host sem `pdo_mysql`/`pdo_sqlite`/`redis`; necessidade de paridade.
- **Decisão**: definir Docker Compose como ambiente oficial de desenvolvimento e testes; imagens com versões fixas (`php:8.4-fpm`, `mysql:8.4`, `redis:7`, `nginx` fixado). Testes com banco rodam no container.
- **Consequências**: exige Docker no fluxo; host continua útil para testes unitários sem banco.

### D-015 — PHPUnit (não Pest)

- **Status**: Aceita
- **Contexto**: PHPUnit 12.5 já instalado; Pest não está presente.
- **Decisão**: manter **PHPUnit** e as suítes `tests/Unit` e `tests/Feature` já configuradas em `phpunit.xml`.
- **Consequências**: sem nova dependência; estilo de teste segue o esqueleto atual.

### D-016 — Locale e fuso do produto

- **Status**: Proposta (confirmar — Q-07)
- **Contexto**: configuração atual `UTC`/`en`; produto em português.
- **Decisão proposta**: `APP_LOCALE=pt_BR`, `APP_FALLBACK_LOCALE=en`, `APP_TIMEZONE=America/Sao_Paulo`, mantendo timestamps em UTC no banco quando aplicável.
- **Consequências**: datas e valores exibidos corretamente; atenção a cálculos de cobrança entre fusos.

### D-017 — Design system próprio sobre Bootstrap + componentes Blade

- **Status**: Aceita (Prompt 05)
- **Contexto**: as telas de negócio precisam de consistência visual e acessibilidade sem introduzir um framework de componentes JavaScript.
- **Decisão**: usar tokens Sass + custom properties (`resources/css/`), layouts como componentes Blade anônimos (`x-layouts.public`, `x-layouts.guest`, `x-layouts.panel`, `x-layouts.error`) e componentes reutilizáveis (`x-ui.*`) para botões, cards, alertas, badges/status, campos e estados vazios. Estados de presença sempre exibem texto além da cor e animações respeitam `prefers-reduced-motion`.
- **Consequências**: telas novas devem reutilizar os componentes e layouts; a paleta e o catálogo ficam em `docs/DESIGN_SYSTEM.md`.

### D-018 — Perfil público: preço derivado do servidor e disponibilidade explícita

- **Status**: Aceita (Prompt 06)
- **Contexto**: o cartão público exige preço por minuto e status atual, mas o modelo não tinha preço individual nem presença persistida.
- **Decisão**: o preço público é calculado no servidor por `rate_credits_per_minute × credit_unit_price_cents`, sem coluna de preço por atendente. `attendant_profiles.availability` guarda apenas `offline`/`online`; `busy` é derivado de consulta ativa e nunca editável. As consultas do diretório selecionam apenas colunas aprovadas (nome, bio, foto, especialidades, disponibilidade), sem carregar e-mail, comissão ou dados financeiros.
- **Consequências**: sem configurações válidas, a UI mostra "Valor sob consulta"; o Prompt 08 reutiliza `availability` para o toggle do atendente; mudanças futuras na regra global de preço não exigem alterar o diretório.
- **Atualização (2026-11-06, D-027)**: a parte de preço foi substituída — o valor público vem de `attendant_modality_prices` (preço por atendente e modalidade), com "a partir de" calculado a partir do menor preço do perfil. A parte de `availability`/`busy` continua válida.

### D-019 — Candidaturas: transições auditadas, duplicidade e ativação manual

- **Status**: Aceita (Prompt 07)
- **Contexto**: o formulário público precisa impedir spam/duplicidade e o admin precisa revisar candidaturas sem transformar aprovação em acesso.
- **Decisão**:
  - Estados `pending`, `under_review`, `approved`, `rejected`. Qualquer admin ativo pode assumir uma candidatura `pending` (`under_review`) e aprovar/rejeitar a partir de `pending` ou `under_review`. A transição é feita com bloqueio de linha em transação e grava `reviewed_by`, `status_changed_at`, `review_started_at` (início da análise) e `reviewed_at` (decisão).
  - Cada transição gera um `audit_logs` com `action`, `actor_id` e `metadata` contendo apenas `from`/`to`; o conteúdo da candidatura e as notas internas não são copiados para a auditoria. `review_notes` guarda a nota interna atual.
  - Duplicidade por e-mail normalizado: candidaturas `pending`/`under_review` bloqueiam novo envio com erro explícito; `approved` também bloqueia; após `rejected`, é permitido reaplicar somente depois de **30 dias** da decisão. O candidato sempre recebe uma mensagem clara, sem expor IDs ou detalhes administrativos.
  - A aprovação **não** cria usuário, senha ou papel de atendente. Como ainda não há fluxo de convite/ativação, a criação de acesso é uma etapa administrativa manual e segura, documentada nesta decisão.
  - O telefone é normalizado para DDD + dígitos e o e-mail para minúsculas; a coleta fica limitada aos dados mínimos do formulário (sem CPF, documentos, dados bancários, senhas ou upload).
- **Consequências**: o histórico de decisões fica auditável sem reter payloads sensíveis; a política de 30 dias precisa ser reavaliada se o volume de reaplicações crescer; a ativação continua manual até existir convite seguro.

### D-020 — Anti-spam do formulário público

- **Status**: Aceita (Prompt 07)
- **Contexto**: o formulário é público e não há integração de CAPTCHA ou e-mail transacional no MVP.
- **Decisão**: validação integral no servidor, `throttle:5,10` por IP na rota `POST /trabalhe-conosco`, campo honeypot invisível (`website`) que responde com confirmação neutra sem persistir, e política de duplicidade por e-mail (D-019). Requisições acima do limite recebem a página 429 existente.
- **Consequências**: nenhuma dependência externa; o rate limit é por IP e o honeypot pode ser contornado por bots sofisticados, então a duplicidade e a revisão humana continuam sendo a barreira final.

### D-021 — Catálogo de cartas: imagens privadas, caminhos imutáveis e inativação

- **Status**: Aceita (Prompt 09)
- **Contexto**: o catálogo precisa aceitar uploads administrativos sem expor arquivos em `public/`, sem confiar no nome/MIME enviados pelo navegador e sem quebrar mensagens históricas quando uma imagem for substituída.
- **Decisão**:
  - Baralhos e cartas são gerenciados por rotas `role:admin` + `TarotCatalogPolicy`; a única exceção é a rota de imagem, que aceita admin ativo ou participante de consulta ativa para cartas ativas de baralhos ativos.
  - Imagens ficam no disco dedicado `tarot_private` (`storage/app/private/tarot/cards`), com nome aleatório de 40 caracteres e extensão derivada do formato final. São aceitos somente JPEG, PNG e WebP, com teto único `IMAGE_UPLOAD_MAX_KB` (padrão 25 MB). SVG, executáveis e arquivos cujo conteúdo não seja imagem são rejeitados. A normalização no upload está em D-028.
  - O banco guarda apenas `image_path`, `image_mime`, `image_size` e `image_checksum` (SHA-256); nunca o binário.
  - Cada substituição grava um novo caminho. O arquivo anterior **não é apagado**, pois pode estar referenciado em `consultation_messages.image_path`; qualquer limpeza futura deve provar ausência de referências e será auditada.
  - Não há exclusão de baralhos/cartas na interface. Inativar (`is_active = false`) preserva registros e arquivos; a seleção para o chat usa `availableForSelection()` (carta ativa + baralho ativo).
  - Criação, edição, status, reordenação e troca de imagem geram `audit_logs` com metadados textuais (sem binário). A troca registra `from_path`/`to_path`.
- **Consequências**: aumenta o custo de armazenamento ao longo do tempo, aceito em troca de histórico íntegro; a rotina de limpeza fica explicitamente adiada e condicionada a referências; o `php.ini` do container precisa permitir uploads acima de 25 MB (32 MB por arquivo e 40 MB por requisição) e o Nginx usa `client_max_body_size 40M` para não cortar antes do PHP.

### D-022 — Ciclo de vida da consulta com estados terminais e travas no banco

- **Status**: Aceita (Prompt 10)
- **Contexto**: a consulta precisa passar por solicitação, aceite e encerramento sem que duas requisições simultâneas criem estados incompatíveis, e sem confiar apenas em checagens da aplicação.
- **Decisão**:
  - `ConsultationStatus` passa a ter sete estados (`requested`, `active`, `ended`, `cancelled`, `rejected`, `expired`, `interrupted`), com `openValues()`/`finalValues()` e rótulos centralizados; somente `active` permite mensagens.
  - Transições ocorrem exclusivamente em `ConsultationLifecycleManager`, dentro de transação com lock pessimista, e repetir aceite/recusa/cancelamento/encerramento em estado compatível é idempotente.
  - Toda ação de estado é auditada em `audit_logs` com `from`/`to`/`reason`, sem conteúdo de conversa.
  - O banco mantém índices únicos em `open_client_id` (estados abertos) e `active_attendant_id` (`active`), preenchidos pelo model ao salvar; estados finais gravam `NULL` e liberam a vaga.
  - O aceite exige atendente publicado/online e saldo mínimo configurado; grava snapshots de tarifa, preço do crédito e comissão.
  - Presença é registrada em `attendant_profiles.last_seen_at`; ao encerrar, o atendente só permanece online se houver sinal recente, caso contrário volta para offline e a mudança é auditada.
  - Solicitações pendentes expiram por TTL e sessões ativas sem presença são interrompidas por comandos agendados a cada minuto (`consultations:expire-requests`, `consultations:interrupt-disconnected`), ambos com `withoutOverlapping()`.
- **Consequências**: o ciclo é previsível e seguro sob concorrência; a política de cobrança efetiva e o heartbeat em tempo real continuam para as etapas de chat/cobrança; estados finais não podem ser reabertos, então uma nova consulta sempre cria outro registro.

### D-023 — Chat persistente, cartas com snapshot e leitura por participante

- **Status**: Aceita (Prompt 11)
- **Contexto**: o chat precisa funcionar por HTTP antes de qualquer transmissão em tempo real, sem permitir forjar remetente, horário, caminho de imagem ou metadados de carta.
- **Decisão**:
  - Texto e carta são endpoints separados (`POST /consultas/{id}/mensagens` e `POST /consultas/{id}/cartas`), ambos em consulta `active`. O remetente vem da sessão; o horário é o do servidor.
  - Somente o atendente escalado envia cartas. O navegador envia apenas `tarot_card_id`; o servidor valida carta/baralho ativos e copia a imagem para `tarot/consultation-cards/{consultation_id}` com nome aleatório.
  - A mensagem guarda `deck_name`, `card_name` e o caminho do snapshot. Alterar, substituir ou inativar o catálogo não muda o histórico.
  - Histórico paginado (25 por página, ordem cronológica na tela), texto sempre escapado e limite de 2.000 caracteres. Frequência limitada por participante + consulta (30 mensagens/min e 15 cartas/min, configuráveis).
  - `viewMessages` restringe histórico e imagens de cartas enviadas a cliente/atendente participantes, inclusive em consultas finais. O admin não lê a conversa. Previews do catálogo continuam restritos a participantes de consulta ativa por `TarotCatalogPolicy`.
  - Não há anexos livres, áudio, vídeo, URLs arbitrárias nem conteúdo de mensagem em logs. A transmissão em tempo real foi adicionada em D-024; a persistência continua sendo a fonte de verdade.
  - `client_last_read_at`/`attendant_last_read_at` são atualizados ao abrir o chat e alimentam um indicador simples de novas mensagens nos painéis.
- **Consequências**: o snapshot aumenta o uso de disco, já previsto em D-021; a leitura é marcada ao carregar a página e também após mensagens recebidas em tempo real; a transmissão foi adicionada em D-024 sem alterar o modelo persistido.

### D-024 — Reverb privado, eventos pós-commit e fallback HTTP

- **Status**: Aceita (Prompt 12)
- **Contexto**: o chat persistente precisa de entrega imediata sem transformar o WebSocket em fonte de verdade, sem expor caminhos privados de imagem e sem abrir a conversa para o admin.
- **Decisão**:
  - Laravel Reverb + Echo/Pusher transportam eventos por um único canal privado `consultation.{id}`, autorizado em `routes/channels.php` apenas para cliente/atendente ativos da consulta. Admin, terceiros e contas inativas recebem 403.
  - `ConsultationMessageSent` e `ConsultationStateChanged` implementam `ShouldBroadcast` + `ShouldDispatchAfterCommit`; os managers só despacham depois do retorno da transação. Falha de fila/broadcast é logada e não reverte a persistência.
  - O payload de mensagem usa um presenter seguro com `image_url` da rota autorizada e nunca inclui `image_path`. Eventos de estado mudam somente quando o status muda; no-ops idempotentes não geram evento.
  - O navegador deduplica por ID, recupera lacunas com `GET /consultas/{id}/mensagens?after_id=...` na reconexão, ao voltar à aba e a cada 30 s, e atualiza fragmentos da consulta sem reload total. O evento WebSocket não é confirmação de gravação.
  - Eventos são enfileirados; o worker e o processo Reverb sobem no Compose (`8080` para o navegador, `reverb:8080` para o servidor). Segredos ficam apenas no `.env`, nunca em `VITE_*`.
- **Consequências**: a stack exige worker e Reverb ativos para latência mínima, mas o chat continua funcional por HTTP quando o WebSocket falha; a suíte automatizada cobre autorização de canal, contratos de evento e fallback, com a limitação de não executar um navegador WebSocket fim-a-fim (documentada em `docs/REALTIME.md`).

### D-025 — Ajustes de carteira como operação auditada

- **Status**: Aceita e implementada no Prompt 13
- **Contexto**: o saldo não pode ser editado livremente e toda correção financeira precisa ser rastreável.
- **Decisão**:
  - `CreditWalletManager` concentra crédito, débito e ajuste; lançamentos são imutáveis e `wallets.balance_cents` é apenas projeção na mesma transação.
  - Débitos usam transação, lock de linha e `UPDATE` condicional (`balance_cents >= valor`), impedindo saldo negativo mesmo sob concorrência.
  - `idempotency_key` é única e repetir a mesma operação retorna o lançamento existente; parâmetros divergentes são recusados.
  - Ajustes administrativos têm endpoint próprio, exigem administrador ativo, cliente-alvo, motivo, `operation_id` UUID, registram `created_by` e geram `audit_logs.action = wallet.adjustment` com valores antes/depois.
  - Não há bônus nem categoria promocional no MVP; cortesias são créditos auditados.
- **Consequências**: o histórico é auditável e reproduzível; novas fontes de crédito/débito (PIX e cobrança por tempo) devem reutilizar o manager e chaves determinísticas.

### D-026 — PIX sandbox atrás de contrato, confirmação somente por webhook

- **Status**: Aceita e implementada no Prompt 14
- **Contexto**: o provedor PIX definitivo ainda não foi escolhido (Q-01), mas o fluxo de recarga precisa ser implementado sem dinheiro real, sem credenciais inventadas e sem acoplar o domínio a um SDK.
- **Decisão**:
  - `PixProvider` isola criação e parsing de webhook; o único driver é `pix_sandbox`, que não faz chamadas externas e devolve instruções identificadas como sandbox.
  - `PaymentManager` cria o pedido com valor em centavos, preço do crédito *snapshot*, `external_reference`, `idempotency_key` e status `pending`. O crédito usa a chave `payment:{id}` e ocorre na mesma transação da confirmação.
  - Webhooks exigem HMAC-SHA256 do timestamp + event ID + corpo bruto, janela de tolerância e `provider_event_id` único em `payment_webhook_events`. Payload bruto e PII não são armazenados; apenas hash e metadados seguros.
  - Redirect, GET e query string nunca confirmam pagamento. Um comando de sandbox envia um webhook assinado para exercitar o fluxo completo sem alterar o banco diretamente.
  - O sandbox é desabilitado por padrão em produção; driver desconhecido falha na resolução em vez de usar fallback inseguro.
- **Consequências**: é possível testar ponta a ponta sem provedor real; a produção exige escolher o provedor, implementar o contrato e cumprir os requisitos específicos de assinatura/mTLS/conciliação documentados em `docs/PIX_SANDBOX.md`.

### D-027 — Preço por minuto definido por atendente e modalidade

- **Status**: Aceita e implementada em novembro de 2026
- **Contexto**: a tarifa global (`rate_credits_per_minute` × `credit_unit_price_cents`) impedia que cada profissional praticasse o próprio preço e obrigava a plataforma a manter uma tabela de créditos por modalidade para todos.
- **Decisão**:
  - `attendant_modality_prices` (`attendant_profile_id`, `modality`, `price_per_minute_cents`) é a única fonte de preço das novas consultas, com índice único por par e edição em **Admin → Modalidades** (auditoria `attendant.modalities_updated`).
  - O aceite resolve o preço no perfil do atendente e grava `consultations.price_per_minute_cents`; não há fallback global. Sem preço, o pedido e o aceite falham com mensagem explícita.
  - `credit_unit_price_cents` e `minimum_balance_minutes` continuam globais (recargas e saldo mínimo). O saldo mínimo é `minimum_balance_minutes × price_per_minute_cents` do atendente.
  - As colunas `rate_credits_per_minute` e `credit_unit_price_cents` da consulta permanecem apenas como registro legado/auditoria; a cobrança usa `price_per_minute_cents` (com fallback de leitura para consultas antigas).
  - A comissão continua sobre os débitos efetivamente cobrados, portanto acompanha o preço do atendente sem cálculo paralelo.
  - Perfis públicos e diretório exibem o menor preço disponível como "a partir de" e "Preço não configurado" quando falta valor.
- **Consequências**: cada atendente pode praticar preços diferentes por modalidade; mudanças de preço valem apenas para novas consultas (snapshot); a administração precisa manter os preços por atendente antes de publicá-lo.

### D-028 — Upload de imagens: normalização no servidor, perfis de saída e guardas de processamento

- **Status**: Aceita e implementada em novembro de 2026
- **Contexto**: os storages gravavam o arquivo exatamente como recebido. Fotos de celular (6–15 MB, 4000 px de largura e EXIF de orientação) chegavam ao catálogo, às fotos de atendentes e ao CMS, consumindo banda e armazenamento sem ganho visual. Os limites também divergiam: validação de 2–5 MB, PHP CLI com 2/8 MB, container com 8/12 MB e Nginx com 20 MB, então o erro aparecia antes da mensagem de validação. Não havia biblioteca de imagem (só GD puro em `ImageThumbnail`) nem remoção de metadados.
- **Decisão**:
  - `intervention/image` ^3.11 com driver GD é a camada de processamento; o binding de `ImageManager` é singleton em `AppServiceProvider` e só é resolvido quando GD/Imagick existe.
  - `ImageNormalizer` é a autoridade: detecta o tipo por `getimagesize()`, aplica a orientação do EXIF (`autoOrientation` do driver), reduz com `scaleDown()` até o perfil e recodifica em WebP (PNG no favicon). Recodificar descarta EXIF/GPS.
  - Perfis em `ImageProfile`: `tarot_card` 1200×1800 q85, `site_section` 2400×1600 q82, `site_logo` 1200×1200 q88, `site_favicon` 512×512 PNG, `attendant_photo` 1200×1200 q85. Reduzir nunca amplia imagens menores.
  - Teto único de recebimento `IMAGE_UPLOAD_MAX_KB` (25 MB), verificado no cabeçalho antes de decodificar. `IMAGE_MAX_PIXELS` (40 MP) e `IMAGE_MEMORY_LIMIT` (512 MB) protegem contra *decompression bombs* e estouro de memória; falhas de processamento viram `ValidationException` no campo, não erro 500.
  - `resources/js/image-upload.js` otimiza no navegador arquivos acima de 4 MB, usando os `data-*` do componente `x-ui.image-input`; se falhar, o original segue e o servidor resolve.
  - Limites alinhados: PHP `upload_max_filesize=32M`/`post_max_size=40M`, Nginx `client_max_body_size 40M`. `exif` foi adicionado ao Dockerfile para corrigir orientação.
  - Arquivos antigos continuam intactos; a allowlist de entrega já aceitava JPEG/PNG/WebP. Sem GD/Imagick, o upload é gravado sem reprocessar e um aviso é registrado no log.
- **Consequências**: novos uploads ficam menores em bytes e consistentes em orientação; a suíte de normalização exige GD (`RequiresPhpExtension`) e roda no container. HEIC continua fora de escopo — o navegador não o decodifica e o servidor o rejeita com mensagem clara. A conversão para WebP deve ser considerada ao gerar imagens em lote fora do painel.

---

## 2. Dúvidas que precisam de confirmação

> Estas decisões **não** serão inventadas. Quando não bloquearem a etapa atual, será adotada a opção mais simples e segura, registrada aqui.

| ID | Dúvida | Por que importa | Bloqueia |
|---|---|---|---|
| **Q-01** | Qual provedor de PIX será usado em sandbox (ex.: Asaas, Efí/Gerencianet, Mercado Pago, outro)? **Mitigada no Prompt 14**: o mock `pix_sandbox` foi implementado atrás de contrato; a decisão continua bloqueando produção. | Define SDK, formato de webhook e assinatura | Produção (Prompt 14 entregue em sandbox) |
| **Q-02** | Qual o valor de **1 crédito** (`credit_unit_price_cents`) e os **preços por minuto**? **Mitigada no Prompt 15**: o valor do crédito e o saldo mínimo são editáveis em `/admin/financeiro` com auditoria; **atualização (D-027)**: o preço por minuto passou a ser definido por atendente e modalidade em `/admin/modalidades`. O seed de desenvolvimento usa 1 crédito = 100 centavos, saldo mínimo de 1 minuto e preços demo de R$ 1,00 a R$ 3,00 por minuto. Os valores comerciais finais continuam sendo decisão de negócio. | Base da conversão crédito→dinheiro e da cobrança | Produção (Prompt 15 entregue configurável) |
| **Q-03** | Confirmar arredondamento: minuto iniciado, mínimo de 1 minuto, tolerância/grace period? **Resolvida por D-005**: minuto iniciado, mínimo de 1, sem tolerância/carência. | Disputas de cobrança e testes | Prompt 15 |
| **Q-04** | Comissão em **basis points** (0–10000) ou `decimal(5,2)`? Alguma taxa padrão? | Precisão e cálculo | Prompt 16 |
| **Q-05** | Atendente pode ficar `online` sem aceitar automaticamente? Há tempo limite para aceite? | Fluxo de solicitação/expiração | Prompt 10 |
| **Q-06** | Administrador pode ler conversas? Em quais hipóteses e com qual auditoria? **Resolvida por D-023**: o admin não lê a conversa no MVP; qualquer acesso futuro exigirá decisão própria e auditoria. | Privacidade/LGPD | Prompts 17/18 |
| **Q-07** | Confirmar `America/Sao_Paulo` e `pt_BR`? | Datas, cobrança e UX | Prompt 01 |
| **Q-08** | Formatos/tamanho máximo de imagem de carta; necessidade de redimensionamento (`gd`) e verificação de mime? **Resolvida por D-021/D-028**: JPEG/PNG/WebP, teto único de 25 MB, MIME real obrigatório e normalização no upload (orientação, redução por perfil e WebP). | Upload e segurança | Prompt 09 |
| **Q-09** | Cliente precisa confirmar e-mail antes de consultar? | Fluxo de cadastro | Prompt 04 |
| **Q-10** | Quais dados mínimos no cadastro do cliente (nome, e-mail, telefone, data de nascimento)? | LGPD/minimização | Prompt 04 |
| **Q-11** | Um usuário pode ter mais de um papel (ex.: admin também atendente)? | Modelo de autorização | Prompt 04 |
| **Q-12** | Cliente escolhe um atendente específico ou entra em fila? | Fluxo de consulta | Prompt 10 |
| **Q-13** | Condições de encerramento automático: inatividade, tempo máximo, saldo, desconexão? **Mitigada**: encerra por saldo esgotado (`out_of_credits`), inatividade (`inactivity`) e logout (`client_logged_out`/`attendant_logged_out`). Tempo máximo de sessão não foi implementado. | Cobrança e segurança | Produção (saldo e inatividade entregues) |
| **Q-14** | Comissão incide sobre valor bruto oficial ou líquido de taxas do provedor? | Valor da comissão | Prompt 16 |
| **Q-15** | Política de cancelamento/estorno de consulta e de recarga? | Financeiro/auditoria | Prompts 14/15 |
| **Q-16** | Haverá e-mail transacional (recuperação de senha, notificações)? Qual provedor? | Configuração de mail | Prompt 04+ |

---

## 3. Premissas adotadas nesta etapa

1. O repositório é um esqueleto Laravel 13 **sem código de domínio**; nada será reescrito sem necessidade.
2. O ambiente oficial passa a ser **Docker** (Prompt 02); o host atual não é suficiente para testes com banco.
3. Não haverá integração de pagamento real; todo PIX é **sandbox**.
4. Percentuais de comissão e valores de crédito são **configuráveis pelo admin**; nenhum valor de negócio é fixado neste plano.
5. O histórico de chat e as imagens enviadas são **fonte de verdade persistida**, não o WebSocket.
6. Aprovação de candidatura é independente da criação de acesso de atendente.
7. Testes não serão removidos/enfraquecidos; relatórios de teste serão sempre reais.

---

## 4. Histórico de revisões

| Data | Etapa | Alteração |
|---|---|---|
| 2026-10-20 | Prompt 00 | Criação do documento com decisões D-001–D-016 e dúvidas Q-01–Q-16 |
| 2026-10-20 | Prompt 05 | D-013 confirmada/implementada; D-017 (design system e componentes Blade) adicionada |
| 2026-10-20 | Prompt 06 | D-018 (preço público derivado e disponibilidade) adicionada; site público, diretório e placeholders institucionais |
| 2026-10-20 | Prompt 07 | D-019 (candidaturas, duplicidade e ativação manual) e D-020 (anti-spam) adicionadas; formulário público e painel de revisão |
| 2026-10-21 | Prompt 09 | D-021 (catálogo, imagens privadas e caminhos imutáveis) adicionada; Q-08 resolvida |
| 2026-10-21 | Prompt 10 | D-022 (ciclo de vida, transições idempotentes, travas no banco, presença e rotinas automáticas) adicionada; máquina de estados documentada em `docs/CONSULTATION_STATE_MACHINE.md` |
| 2026-10-21 | Prompt 11 | D-023 (chat persistente, snapshot de cartas, autorização somente por participantes e indicador de leitura) adicionada; Q-06 resolvida |
| 2026-10-21 | Prompt 12 | D-024 (Reverb/Echo, canal privado, eventos pós-commit, deduplicação e fallback HTTP) adicionada; D-012 implementada; `docs/REALTIME.md` criado |
| 2026-10-21 | Prompt 13 | D-025 (ajustes auditados, idempotência e prevenção de saldo negativo) adicionada; D-004 implementada; `docs/WALLET_LEDGER.md` criado |
| 2026-10-21 | Prompt 14 | D-026 (contrato PIX, sandbox, HMAC, replay e confirmação server-side) adicionada; D-011 implementada em sandbox; Q-01 mantida para produção; `docs/PIX_SANDBOX.md` criado |
| 2026-10-21 | Prompt 15 | D-005 implementada (minuto iniciado, snapshot de tarifa, cursor idempotente e encerramento por saldo); Q-03 resolvida; Q-02 e Q-13 mitigadas; `docs/TIME_BILLING.md` criado |
| 2026-10-21 | Prompt 16 | Comissão percentual por atendente, snapshot no aceite, base no ledger, arredondamento half-up, accrual idempotente e estornos auditados; `docs/COMMISSION_POLICY.md` criado |
| 2026-10-21 | Prompt 17 | Históricos paginados de cliente/atendente, dashboard com receita bruta e comissões líquidas, lista de pagamentos e auditoria administrativa; `docs/ADMIN_REPORTING.md` criado |
| 2026-10-21 | Prompt 18 | Revisão de segurança: throttle de solicitações/recargas, validação defensiva de caminhos de imagem, `no-store` no chat, headers Nginx e processos de retenção; `docs/SECURITY_PRIVACY.md` criado |
| 2026-10-21 | Prompt 19 | Fluxo integrado do MVP coberto por teste ponta a ponta e cenários negativos; suíte completa e build validados |
| 2026-10-21 | Prompt 20 | Runbook de implantação, backups/restauração, onboarding manual de atendente e avaliação de prontidão; `docs/DEPLOYMENT.md` e `docs/ATTENDANT_ONBOARDING.md` criados |
| 2026-11-04 | Segurança do chat | Presença por aba, inatividade configurável, avisos de saída, encerramento no logout, compra de créditos com retorno e limites administrativos auditados; `docs/SEGURANCA_E_USABILIDADE_CHAT.md` criado |
| 2026-11-06 | Preço por atendente | D-027 adicionada: `attendant_modality_prices` como fonte única do preço por minuto, edição em `/admin/modalidades`, snapshot em `consultations.price_per_minute_cents` e remoção da tarifa global de `/admin/financeiro`; D-018 e Q-02 atualizadas |
| 2026-11-11 | Upload de imagens | D-028 adicionada: `intervention/image`, `ImageNormalizer`, perfis de saída, teto único de 25 MB, guardas de pixels/memória, normalização no navegador e limites PHP/Nginx alinhados; D-021 e Q-08 atualizadas |

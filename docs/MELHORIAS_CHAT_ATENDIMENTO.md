# Melhorias do chat e do atendimento

Este documento reúne as sete melhorias aplicadas à experiência de atendimento
(tela de chat, workspace e telas ao redor). O fio condutor é o mesmo das etapas
anteriores: **o servidor decide e o navegador apenas apresenta**. Nenhum número
de cobrança, comissão ou identificação é calculado no JavaScript, e nenhum dado
novo de contato circula entre as partes.

| # | Melhoria | Onde vive |
|---|---|---|
| 1 | Cartões de identificação das partes (“do outro lado”) | `App\Support\ConsultationPartyPresenter`, `consultations/partials/parties.blade.php` |
| 2 | Data de nascimento obrigatória no cadastro (18+) | `App\Http\Requests\Auth\RegisterRequest`, `users.birth_date` |
| 3 | Envio de várias cartas do tarot por vez | `ConsultationMessageManager::sendCards()`, `StoreConsultationCardRequest` |
| 4 | Conversa com rolagem própria e atalho de mensagens novas | `resources/js/chat.js`, `consultations/partials/chat.blade.php` |
| 5 | Aviso “Voltar ao atendimento” em todo o painel | `App\Support\ActiveConsultationResolver`, `layouts/partials/active-consultation-banner.blade.php` |
| 6 | Saída acidental do atendimento (aviso do navegador + modal) | `resources/js/consultation-presence.js` |
| 7 | Mensagens claras de microfone/câmera nas chamadas | `resources/js/consultation-call.js` |

Os documentos vizinhos continuam válidos e são referenciados ao longo do texto:
[TIME_BILLING.md](TIME_BILLING.md), [COMMISSION_POLICY.md](COMMISSION_POLICY.md),
[SEGURANCA_E_USABILIDADE_CHAT.md](SEGURANCA_E_USABILIDADE_CHAT.md),
[MONITORAMENTO_E_PROTECAO_CHAT.md](MONITORAMENTO_E_PROTECAO_CHAT.md) e
[MODALIDADES_E_PRECIFICACAO.md](MODALIDADES_E_PRECIFICACAO.md).

## 1. Cartões de identificação das partes

Antes, a tela de atendimento mostrava apenas o nome de quem estava do outro
lado. Em uma leitura, o cliente não sabia se falava com a pessoa certa e o
atendente não tinha contexto algum sobre quem estava atendendo.

Agora as duas telas (workspace e página da consulta) exibem um cartão de
identificação:

- o **cartão principal é sempre o da outra parte** — o cliente vê o atendente, o
  atendente vê o cliente;
- abaixo dele, um bloco recolhível “Seus dados neste atendimento” mostra o que a
  outra pessoa está vendo, para conferência;
- todos os números nascem no servidor em `ConsultationPartyPresenter::toArray()`
  (idade, desde quando é cliente/atendente, histórico de atendimentos, tempo
  estimado de saldo, valor cobrado, preço por minuto e, para o atendente
  responsável, a taxa de comissão);
- os valores que mudam durante a consulta trazem `data-metric`, e
  `resources/js/consultation-workspace.js` os substitui quando
  `consultations.metrics` é atualizado — nunca o contrário;
- **e-mail e telefone não aparecem** no cartão. O chat bloqueia a troca de
  contatos ([MONITORAMENTO_E_PROTECAO_CHAT.md](MONITORAMENTO_E_PROTECAO_CHAT.md)) e
  o cartão não pode ser uma porta lateral para isso;
- a visão do **administrador não recebe o cartão**: `consultations/show.blade.php`
  inclui o parcial com `@unless ($isAdmin)`, porque não existe “lado próprio”
  para quem apenas audita.

A comissão só é publicada no cartão quando quem visualiza é o atendente da
consulta (`commission_rate_bps` presente no snapshot). O cliente e o
administrador nunca recebem esse campo do presenter.

## 2. Data de nascimento obrigatória no cadastro (18+)

O cartão da melhoria 1 mostra nascimento e idade, então o cadastro passou a
exigir a data:

- `RegisterRequest` valida `birth_date` como `required`, `date_format:Y-m-d`,
  `before:` hoje − 18 anos e `after:` hoje − 120 anos, com mensagens próprias
  (“É preciso ter 18 anos ou mais para se cadastrar.”);
- a migration `2026_11_05_115933_add_birth_date_to_users_table` adiciona a coluna
  e `User::birthDateLabel()`/`ageLabel()` formatam a exibição;
- no **cadastro com SMS em duas etapas**, a data viaja no payload guardado em
  `phone_verifications` — `PhoneVerification::registrationPayload()` a devolve e
  `PhoneVerificationController::complete()` grava na criação da conta. Sem isso a
  conta nasceria com `birth_date` nulo e o cartão mostraria “Não informado”;
- no cadastro em uma etapa (SMS desativado) o campo é gravado direto por
  `RegisteredUserController::registerDirectly()`;
- o login com Google não passa pelo formulário, então a coluna permanece
  opcional no banco (`nullable`) e o presenter trata o vazio.

## 3. Envio de várias cartas por vez

Uma tiragem quase nunca é uma carta só: antes o atendente precisava repetir o
mesmo formulário N vezes.

**Contrato HTTP** (`POST /consultas/{consultation}/cartas`, nome
`consultations.cards.store`):

- corpo novo: `tarot_card_ids[]`, de 1 a
  `ConsultationMessageManager::MAX_CARDS_PER_SEND` (10);
- o formato antigo `tarot_card_id` continua aceito:
  `StoreConsultationCardRequest::prepareForValidation()` o converte para lista
  antes de validar;
- cada item precisa existir e não pode repetir (`distinct`), com mensagens por
  índice (`tarot_card_ids.2`);
- a resposta JSON é `201` com `messages[]` na ordem de envio, `notice`
  (“3 cartas enviadas.”) e a chave legada `message` com a primeira mensagem, para
  clientes antigos;
- erros de validação usam a chave `tarot_card_ids` — inclusive em
  `ConsultationCardImageStorage`, que antes publicava `tarot_card_id` e deixava o
  erro invisível para o formulário novo.

**Transação**: `sendCards()` bloqueia a consulta, confere participante e papel
(só o atendente envia cartas), grava um snapshot privado por carta e recalcula o
total apenas uma vez. Se qualquer carta estiver inativa, o baralho estiver
inativo ou a imagem faltar, **nada** é gravado.

**Interface**: checkboxes por carta, contador de selecionadas com chips, limite
anunciado em `data-max-cards` (o JS barra a seleção excedente antes do POST),
filtro por baralho, botão de ampliar que abre um modal com a imagem e a ação
“Selecionar carta”, e o botão “Enviar selecionadas”. Depois do envio o formulário
é limpo (`resetCardPicker`), porque `form.reset()` não devolve os checkboxes ao
estado renderizado.

## 4. Conversa com rolagem própria e atalho de mensagens novas

A linha do tempo crescia com a página: o campo de digitação e o seletor de cartas
iam para fora da tela em conversas longas.

- o histórico fica em `.chat-stream` (`data-chat-stream`), com altura limitada e
  rolagem própria; o compositor continua visível;
- acompanhar o fim **só acontece quando o participante já está no fim**
  (`isNearStreamBottom()`, tolerância de 140 px). Quem subiu para reler o
  histórico não é arrastado para baixo a cada mensagem;
- mensagens que chegam com a rolagem fora do fim alimentam o botão
  `[data-chat-jump]` — “Nova mensagem” / “N novas mensagens” — que devolve a
  rolagem ao fim em um clique;
- o lote de sincronização (`messages[]` do polling) usa a mesma regra e toca o
  aviso sonoro **uma única vez** por lote, mesmo que várias mensagens tenham
  chegado;
- `insertMessage()` ignora IDs já exibidos, então o evento do Reverb que chega
  depois do POST não duplica a mensagem.

## 5. Aviso “Voltar ao atendimento” em todo o painel

O workspace já avisava ao sair do chat, mas quem navegava para histórico,
carteira, perfil ou recibo perdia o caminho de volta — com a consulta ativa e
sendo cobrada.

- `ActiveConsultationResolver::forUser()` escolhe, **no servidor**, a consulta
  ativa do próprio usuário (cliente ou atendente), ignorando administradores e
  usuários inativos e não aceitando nenhum ID vindo da URL;
- `layouts/partials/realtime-alerts.blade.php` resolve a consulta e inclui
  `active-consultation-banner`, que mostra “Atendimento #N em andamento”, o nome
  da outra parte e o botão **Voltar ao atendimento**;
- o bloco é renderizado no servidor (funciona sem JavaScript) e **nunca aparece
  dentro do workspace** nem para o administrador;
- `resources/js/user-channel.js` reconcilia o aviso com o canal privado e com o
  polling: quando o atendimento termina o aviso desaparece, e se outra consulta
  ficar ativa o link é reapontado. Nenhuma decisão de cobrança acontece no
  navegador;
- o aviso convive com o retorno baseado em sessão da carteira
  (`credits.return_consultation`, ver
  [SEGURANCA_E_USABILIDADE_CHAT.md](SEGURANCA_E_USABILIDADE_CHAT.md) §7). Os dois
  caminhos são independentes: o botão da carteira tem `data-credit-return` e só
  aparece quando a sessão guardou um retorno válido.

## 6. Saída acidental do atendimento

O modal “Sair do atendimento?” já interceptava cliques em links. Faltava o que
não passa por um clique: botão voltar do navegador, atalho de teclado, URL
digitada e recarregamento da página.

- `resources/js/consultation-presence.js` registra `beforeunload` e só bloqueia
  quando a consulta está ativa e a saída não foi declarada como intencional
  (`state.leaving` ou a marca de `resources/js/navigation-intent.js`);
- o texto exibido é o nativo do navegador — a página não pode personalizá-lo — e
  o aviso não encerra nada: a decisão sobre cobrança e encerramento continua no
  servidor;
- saídas confirmadas nos modais e os redirecionamentos automáticos marcam
  `leaving`/intenção antes de navegar, e os formulários que navegam de propósito
  (**Encerrar consulta** e **Sair da conta**, em `panel-sidebar`,
  `panel-navbar` e `navbar-public`) usam `data-allow-leave`, então não abrem o
  aviso;
- ao voltar pelo cache do navegador (`pageshow` com `event.persisted`) o estado é
  limpo, a intenção é descartada e a presença é reenviada.

## 7. Mensagens claras de microfone/câmera nas chamadas

Falhas de `getUserMedia()` chegavam como `DOMException` em inglês e viravam um
“não foi possível” genérico. `resources/js/consultation-call.js` agora separa as
causas e diz o que fazer:

| Grupo | Exemplos | Mensagem |
|---|---|---|
| Permissão | `NotAllowedError`, `PermissionDeniedError`, `SecurityError` | liberar o acesso no cadeado da barra de endereço |
| Dispositivo ausente | `NotFoundError`, `DevicesNotFoundError`, `OverconstrainedError` | conectar um microfone/câmera compatível |
| Dispositivo ocupado | `NotReadableError`, `TrackStartError`, `AbortError` | fechar o outro programa que usa o equipamento |

Complementos:

- **contexto seguro**: sem HTTPS o navegador nem expõe `navigator.mediaDevices`;
  a tela avisa que a chamada exige conexão segura em vez de culpar o navegador;
- **fallback só áudio**: se o vídeo falhar por ausência/uso do equipamento, a
  chamada é refeita só com áudio, `audioOnlyFallback` esconde o botão de câmera e
  o rodapé informa “Chamada em andamento, somente com áudio.”. Permissão negada
  não tenta de novo — o microfone está bloqueado pelo mesmo motivo;
- os avisos usam tons de alerta (`alert-warning`, `alert-danger`) para diferenciar
  informação de falha.

## 8. Pedido sem crédito e recusa encerrando o chat

Dois furos no começo do fluxo, ambos no caminho cliente → atendente:

- **pedido sem crédito chegava ao atendente.** O saldo mínimo
  (`preço do minuto × minimum_balance_minutes`) só era conferido no aceite, então
  a solicitação era criada, aparecia na fila (com sirene e modal) e falhava
  quando o atendente tentava aceitar — ou expirava sozinha.
  `ConsultationLifecycleManager::request()` agora valida o saldo dentro da
  transação com lock, antes de criar a consulta: nada é gravado e o evento
  `ConsultationRequested` não é disparado. O aceite continua revalidando, porque
  o saldo pode ser gasto nesse intervalo.
- **a recusa não encerrava nada na tela do cliente.** `rejected` já era um
  estado final no banco, mas a página do cliente seguia mostrando “Aguardando
  aceite” e o botão “Cancelar solicitação” (que passaria a devolver 403) até ele
  recarregar por conta própria. Agora:
  - `resources/views/consultations/show.blade.php` exibe um aviso explícito de
    que a solicitação foi recusada, o chat está encerrado e nada foi debitado,
    com link para escolher outro atendente; “Iniciada em” mostra `—` em vez de
    “Aguardando aceite” em consultas que nunca começaram;
  - `resources/js/user-channel.js` recarrega a tela em estados finais para
    cliente e atendente (`data-refresh-on-final`) e, no modo polling, detecta
    que `panel/eventos` deixou de devolver a consulta aberta
    (`handleClosedConsultation`) — sem laço, porque uma página sem consulta
    aberta não recarrega.

Avisos antes do envio (sem transferir a decisão para o navegador):

- o perfil público mostra “Seu saldo · mínimo para iniciar” e, ao enviar sem
  saldo suficiente, abre um modal com o mínimo exigido, o saldo atual e o link de
  compra de créditos (`data-balance-*` + `consultation-modalities.js`) em vez de
  deixar o erro chegar pela resposta do servidor;
- `@error('consultation')` passou a ser renderizado no formulário — o layout
  público usa `showErrors = false`, então o erro do servidor era invisível;
- diretório e seção de atendentes da home exibem os erros de validação que voltam
  do botão “Solicitar” do cartão.

## Cobertura de testes

| Arquivo | Cobre |
|---|---|
| `tests/Feature/ConsultationPartiesTest.php` | números do presenter, cartão do cliente e do atendente, comissão restrita ao atendente, visibilidade na página da consulta (inclusive exclusão do administrador) |
| `tests/Feature/ConsultationChatTest.php` | envio em lote (`messages[]`), duplicidade e limite, payload legado de uma carta, seletor de cartas |
| `tests/Feature/ConsultationWorkspaceTest.php` | aviso “Voltar ao atendimento” fora do workspace, `ActiveConsultationResolver`, métricas oficiais |
| `tests/Feature/AuthTest.php` | `birth_date` obrigatória, faixa etária 18+ e cadastro normalizado |
| `tests/Feature/SmsRegistrationTest.php` | data de nascimento preservada pelo cadastro em duas etapas |
| `tests/Feature/FirstAccessBonusTest.php` | bônus de primeiro acesso intacto após a nova validação |
| `tests/Feature/ConsultationSafetyTest.php` | retorno pela carteira separado do aviso global (`data-credit-return`) |
| `tests/Feature/ConsultationLifecycleTest.php` | pedido bloqueado sem saldo mínimo (sem consulta e sem evento), mínimo exato aceito, saldo revalidado no aceite, recusa encerrando o chat e liberando nova solicitação |
| `tests/Feature/ConsultationModalityTest.php` | mínimo por modalidade na solicitação e no aceite, cliente sem saldo não consegue nem pedir |
| `tests/Feature/RealtimePollingTest.php` | `panel/eventos` deixa de devolver a consulta aberta depois da recusa (sinal do recarregamento) |

## Como validar

```bash
# suíte completa (contêiner de desenvolvimento, que tem PDO disponível)
docker exec -w /var/www/html arcana-tarot-app-1 php artisan test

# estilo dos arquivos alterados
docker exec -w /var/www/html arcana-tarot-app-1 vendor/bin/pint --format agent <arquivos>

# assets
npm run build
```

## Limites conhecidos

- O aviso de saída do navegador não pode ter texto personalizado; é o nativo.
- Sem JavaScript o chat continua funcionando por formulário e recarregamento, mas
  não há atualização em tempo real, rolagem controlada nem aviso de saída.
- O cartão de identificação não substitui verificação de identidade: mostra o que
  a pessoa declarou no cadastro.
- A comissão exibida é o snapshot da consulta; mudanças de taxa em
  `/admin/comissoes` valem apenas para consultas futuras
  ([COMMISSION_POLICY.md](COMMISSION_POLICY.md)).
- Na tela de atendimento a comissão do atendente é **estimada** enquanto a
  consulta corre (taxa snapshot × valor já cobrado) e passa a ser o valor oficial
  do razão no encerramento; nenhuma estimativa é gravada
  ([COMMISSION_POLICY.md](COMMISSION_POLICY.md) §7.1).

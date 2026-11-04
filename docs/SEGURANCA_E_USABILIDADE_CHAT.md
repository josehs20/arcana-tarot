# Segurança e usabilidade do chat

Este documento descreve a política de presença, inatividade, saída da tela,
encerramento por logout e compra de créditos durante o atendimento. A cobrança
por minuto continua documentada em [TIME_BILLING.md](TIME_BILLING.md); aqui o
foco é manter a sessão íntegra e evitar cobrança silenciosa quando o navegador
ou a rede falham.

## 1. Princípios

- **O servidor é a fonte da verdade.** O navegador envia batimentos de presença,
  mas nunca informa há quanto tempo está inativo. O servidor compara
  `last_activity_at`, `last_seen_at` e os horários das próprias requisições.
- **Presença não é atividade.** Um batimento mantém a sessão do navegador
  marcada como viva, mas não reinicia o relógio de inatividade. Deixar a aba
  aberta sem interagir também encerra o atendimento.
- **Encerrar é diferente de sair da tela.** Sair do workspace registra a
  desconexão do navegador, mas não encerra a consulta. O encerramento acontece
  pelo botão de encerramento, pelo logout, por saldo insuficiente ou pela rotina
  de inatividade.
- **Toda decisão é auditável.** Limites administrativos, interrupções e
  encerramentos geram `audit_logs` sem conteúdo de conversa.

## 2. Sessões de presença

Cada aba aberta no workspace cria uma linha em
`consultation_presence_sessions` com:

| Coluna | Uso |
|---|---|
| `consultation_id` / `user_id` / `role` | Participante e papel (`client` ou `attendant`). |
| `session_token` | Token opaco de 40 a 64 caracteres alfanuméricos, gerado pelo servidor. |
| `connected_at` | Primeiro batimento daquela aba. |
| `last_seen_at` | Último batimento recebido. |
| `disconnected_at` / `disconnect_reason` | Saída registrada (`user_left`, `inactivity`, `client_logged_out`, `attendant_logged_out` etc.). |

O token é guardado no `sessionStorage` com a chave
`arcana.consultation.presence.{consultationId}.{userId}`. Reenviá-lo reabre a
mesma sessão depois de uma queda de rede; a consulta e o usuário do token são
conferidos no servidor. Um token de outra consulta/usuário recebe 403, o
navegador descarta o token e tenta uma única vez sem ele.

### 2.1 Endpoints

| Método | Rota | Efeito |
|---|---|---|
| `POST` | `/consultas/{id}/presenca` (`consultations.presence.heartbeat`) | Renova a presença e devolve estado, presença, créditos e limites. |
| `POST` | `/consultas/{id}/presenca/sair` (`consultations.presence.leave`) | Marca a sessão como desconectada com `user_left`, sem encerrar a consulta. |

O batimento exige que o usuário seja participante ativo da consulta e que ela
esteja `active`. Consulta finalizada responde 422 com
`errors.consultation`; o cliente redireciona para a tela de histórico/recibo. O
batimento é limitado pelo throttle `consultation-presence`.

A resposta do batimento inclui o estado da consulta, o token, a presença dos
dois papéis, os limites efetivos, o saldo do cliente e a URL de compra de
créditos com `return_consultation`. O cliente usa esses valores para atualizar os
badges e redirecionar quando o status deixa de ser `active`.

### 2.2 Estados de presença

Para cada papel, o servidor escolhe o estado mais favorável entre as sessões do
participante:

- **Online** — existe sessão não desconectada com `last_seen_at` dentro da
  tolerância de presença. A tolerância é `max(30, activity_ping_seconds × 3)`,
  ou seja, três batimentos perdidos por padrão (60 segundos com ping de 20).
- **Reconectando** — não há sessão online, mas o último sinal ainda está dentro
  da tolerância de presença somada à tolerância de reconexão. Isso cobre uma
  queda breve, uma troca de rede ou o retorno da aba.
- **Offline** — não há sessão recente.

O estado é exibido no cabeçalho do workspace e atualizado a cada batimento. Ele
não encerra a consulta por si só: a interrupção usa a atividade, não a presença.

## 3. Atividade do atendimento

`consultations.last_activity_at` é o relógio usado pela interrupção. Também são
gravados `client_last_activity_at` e `attendant_last_activity_at` para
diagnóstico e evolução futura.

O navegador envia `activity=true` somente em interações reais:

- abrir o workspace;
- `pointerdown`, `keydown` e `touchstart` (com limite de um envio a cada 5 s);
- envio de formulário/mensagem;
- retorno à aba (`visibilitychange`) e reconexão (`online`);
- clique em **Continuar no atendimento** no aviso de inatividade.

O batimento automático envia `activity=false`. Portanto, uma aba aberta e sem
interação não mantém o atendimento vivo para sempre: depois do limite
configurado mais a tolerância de reconexão, a consulta é interrompida.

A interrupção usa o `last_activity_at` agregado: **qualquer** interação de
qualquer participante renova o relógio. Mensagens, cartas e abertura da tela
também atualizam a atividade no servidor.

## 4. Limites e cálculo

| Regra | Origem | Padrão | Faixa |
|---|---|---|---|
| Tempo de inatividade | `settings.consultation_inactivity_timeout_minutes` (admin) | 5 min | 1 a 120 min |
| Tolerância de reconexão | `settings.consultation_reconnection_tolerance_seconds` (admin) | 90 s | 0 a 600 s |
| Batimento de presença | `CONSULTATION_ACTIVITY_PING_SECONDS` | 20 s | 5 a 60 s |
| Aviso local de inatividade | `CONSULTATION_NAVIGATION_WARNING_SECONDS` | 20 s | 5 a 120 s |

Cálculos derivados:

```text
tolerância de presença = max(30, batimento × 3)
interrupção automática = inatividade + tolerância de reconexão
```

Com os padrões, a sessão fica **online** por 60 s sem batimentos, entra em
**reconectando** e é interrompida após **390 s** (5 min + 90 s) sem atividade.
O aviso local aparece após 20 s sem interação, mas não encerra nada; ele apenas
lembra que a cobrança continua.

> `CONSULTATION_PRESENCE_TIMEOUT_MINUTES` (15 min) continua existindo para a
> liberação da disponibilidade do atendente, não para interromper consultas. A
> interrupção usa os dois valores administrativos acima.

## 5. Avisos na interface

### 5.1 Inatividade

Depois de `navigation_warning_seconds` sem interação, o workspace abre o modal
**“Você ainda está no atendimento?”**. O modal:

- informa que a consulta continua ativa e sendo cobrada;
- permite **Continuar no atendimento**, que registra atividade e fecha o aviso;
- permite **Sair do chat**, que registra a saída sem encerrar a consulta.

O modal é apenas uma proteção de usabilidade. A decisão final é do servidor, no
comando de interrupção.

### 5.2 Navegação para fora do chat

Ao clicar em um link interno que sairia do workspace, o navegador intercepta a
navegação e mostra **“Sair do atendimento?”**, explicando que a consulta e a
cobrança continuam. Confirmar registra a saída (`user_left`) e navega; cancelar
mantém a pessoa no chat.

Não há aviso para:

- links marcados com `data-allow-leave` (por exemplo, **Comprar créditos**);
- downloads, `target="_blank"`, abas e controles do Bootstrap;
- links externos, mesma página ou a tela de histórico/recibo da própria
  consulta.

Se o servidor responder que a consulta não está mais ativa, o cliente para os
batimentos e redireciona para a tela de histórico, onde o desfecho e o recibo
ficam visíveis.

## 6. Encerramento e logout

O logout em `AuthenticatedSessionController` chama
`ConsultationLifecycleManager::endActiveForLogout()` antes de invalidar a
sessão. A operação:

- procura a consulta `active` mais recente do usuário como cliente ou atendente;
- encerra com `client_logged_out` ou `attendant_logged_out`;
- liquida os minutos vencidos e gera a comissão pelo caminho normal;
- fecha as sessões de presença abertas;
- é idempotente: repetir o logout ou chamar o método de novo não duplica
  auditoria nem cobrança;
- nunca impede o logout: falhas são registradas e a sessão é encerrada mesmo
  assim.

Quando o usuário é atendente, o logout também executa
`AttendantAvailabilityManager::signOut()`. Se houver consulta ativa, a
disponibilidade permanece congelada para que a rotina de presença possa
interromper a sessão órfã.

Motivos de encerramento relevantes:

| Motivo | Origem |
|---|---|
| `client_logged_out` | Logout do cliente com consulta ativa. |
| `attendant_logged_out` | Logout do atendente com consulta ativa. |
| `inactivity` | Rotina de inatividade. |
| `out_of_credits` | Saldo insuficiente para o próximo minuto. |
| `client_ended` / `attendant_ended` | Encerramento manual. |

## 7. Compra de créditos e retorno ao atendimento

O workspace do cliente exibe o saldo e um link **Comprar créditos** para
`client.credits.index` com `return_consultation={id}`. O link usa
`data-allow-leave`, então a navegação não mostra o aviso de saída.

Fluxo:

1. A tela de créditos recebe `return_consultation` apenas como **intenção**.
2. O servidor resolve a consulta ativa do próprio cliente com
   `ActiveConsultationResolver` e confere se o ID pertence a ela e se o usuário
   é o cliente. Só então grava `credits.return_consultation` na sessão.
3. Se o ID for de outra pessoa, de uma consulta já finalizada ou inexistente, a
   chave é removida e nenhum retorno é oferecido.
4. Ao voltar para a tela de créditos ou para o pagamento PIX, o servidor
   revalida a consulta ativa e monta a URL **“Voltar ao atendimento”**. Se ela
   tiver sido encerrada nesse intervalo, a chave é descartada.
5. O token de presença permanece no `sessionStorage`; ao retornar ao workspace,
   o batimento reabre a mesma sessão.

O ID na URL nunca é usado diretamente como destino de redirecionamento. Isso
evita open redirect e impede que um cliente volte para o atendimento de outra
pessoa.

## 8. Administração dos limites

- Rota: `GET/PATCH /admin/atendimento/seguranca`
  (`admin.consultations.safety` / `.update`), exclusiva de administrador ativo.
- Campos: `inactivity_timeout_minutes` (1–120) e
  `reconnection_tolerance_seconds` (0–600).
- A atualização grava as duas chaves em `settings` e um
  `audit_logs.action = consultation_safety.updated` com `before` e `after` na
  mesma transação.
- O painel mostra o batimento, a tolerância de presença, o limite efetivo de
  interrupção e o aviso local.
- Os valores são lidos a cada requisição/comando; não é necessário reiniciar a
  aplicação. Consultas em andamento passam a usar o novo limite imediatamente.

## 9. Rotina automática

`routes/console.php` agenda `consultations:interrupt-disconnected` a cada minuto
com `withoutOverlapping()`. O nome do comando é histórico; desde esta etapa ele
interrompe consultas **inativas**, não apenas desconectadas.

O comando:

- seleciona consultas `active` com `last_activity_at` nulo ou anterior a
  `agora - (inatividade + tolerância de reconexão)`;
- chama `ConsultationLifecycleManager::interrupt()` com motivo `inactivity`;
- liquida os minutos vencidos, gera a comissão e fecha as sessões de presença;
- audita `consultation.interrupted` com `reason`, `billed_minutes` e
  `charged_cents`;
- reporta no console quantas consultas mudaram de estado.

Sem o scheduler em execução, a tela continua avisando e os batimentos continuam
funcionando, mas a interrupção automática não acontece. Em produção, mantenha o
`schedule:run` (ou `schedule:work`) ativo.

## 10. Arquivos principais

| Arquivo | Papel |
|---|---|
| `app/Support/ConsultationSafety.php` | Lê, normaliza e calcula os limites. |
| `app/Support/ConsultationActivityTracker.php` | Batimento, atividade, presença e fechamento de sessões. |
| `app/Support/ConsultationLifecycleManager.php` | Interrupção, encerramento e logout idempotente. |
| `app/Support/ActiveConsultationResolver.php` | Resolve a consulta aberta do usuário. |
| `app/Console/Commands/InterruptDisconnectedConsultations.php` | Comando agendado. |
| `app/Http/Controllers/ConsultationController.php` | Endpoints de batimento/saída e dados do workspace. |
| `app/Http/Controllers/Client/CreditPurchaseController.php` | Compra e retorno ao atendimento. |
| `resources/js/consultation-presence.js` | Batimentos, atividade, avisos e navegação. |
| `resources/views/consultations/workspace.blade.php` | Modais, métricas e links de crédito. |
| `resources/views/admin/consultations/safety.blade.php` | Formulário administrativo. |
| `routes/console.php` | Agendamento da rotina. |

## 11. Cobertura de testes

`tests/Feature/ConsultationSafetyTest.php` cobre:

- batimento cria sessão, devolve token opaco, presença e link de créditos;
- token de outra sessão/consulta é rejeitado e o cliente tenta novamente;
- saída registra `user_left` sem encerrar a consulta;
- logout do cliente e do atendente encerram uma única vez e fecham sessões;
- interrupção respeita inatividade + tolerância, liquida e audita;
- administrador lê e altera os limites, com auditoria e validação;
- retorno de créditos é revalidado contra a consulta ativa e o dono.

`tests/Feature/ConsultationLifecycleTest.php` e
`tests/Feature/ConsultationBillingTest.php` cobrem as transições, a liquidação e
a ausência de cobrança após o estado final.

## 12. Limitações conhecidas

- A presença é HTTP, não WebSocket. O batimento periódico é suficiente para a
  interface, mas não substitui monitoramento de rede do lado do servidor.
- O aviso de inatividade é local; se o navegador estiver suspenso, ele pode não
  aparecer antes da interrupção. A rotina do servidor continua valendo.
- Sem JavaScript, não há batimento nem aviso local. A consulta ainda é
  interrompida pela rotina de inatividade, e o usuário é redirecionado na
  próxima requisição.
- O `sessionStorage` é por aba e por origem. Fechar a aba remove o token; a
  sessão de presença correspondente fica sem batimentos e expira pela política
  do servidor.
- Não há encerramento automático por inatividade **de um único lado** enquanto
  o outro continua interagindo. A política atual considera a conversa como um
  todo.




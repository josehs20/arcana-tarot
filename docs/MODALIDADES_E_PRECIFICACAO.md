# Modalidades de atendimento e precificação

> Implementado em outubro de 2026. Este documento descreve as três
> modalidades do Arcana Tarot (texto, áudio e vídeo), a regra de cobrança por
> minuto já existente, os snapshots financeiros, as comissões, os requisitos
> técnicos das chamadas WebRTC e os comandos para validar cada fluxo.

## 1. Visão geral

| Modalidade | Valor `ConsultationModality` | Interface | Recurso de mídia |
| --- | --- | --- | --- |
| Texto | `text` | Chat em tempo real + envio de cartas | Nenhum |
| Áudio | `audio` | Chamada de voz | Microfone (WebRTC) |
| Vídeo | `video` | Chamada de áudio e vídeo | Microfone + câmera (WebRTC) |

O cliente escolhe a modalidade no perfil público do atendente, vê o preço por
minuto antes de confirmar e só então envia a solicitação. O atendente aceita a
solicitação e, em áudio/vídeo, aceita também a chamada. Toda validação de
preço, saldo e disponibilidade acontece no servidor.

O chat e o envio de cartas pertencem ao atendimento por **texto**. Consultas
por áudio/vídeo usam a interface de chamada e não aceitam mensagens.

## 2. Regra de cobrança

O preço do minuto é **por atendente e por modalidade**:

```
preço por minuto = attendant_modality_prices.price_per_minute_cents
                   (atendente + modalidade escolhida)
```

- A cobrança é **por minuto iniciado** (`ConsultationBillingManager`).
- O primeiro minuto é cobrado no aceite e o último no encerramento.
- O relógio é sempre `consultations.started_at` (servidor).
- Cada intervalo tem chave idempotente `consultation:{id}:minute:{n}` no
  livro-razão de créditos (`credit_ledger_entries`).
- Sem saldo para o próximo minuto, não há débito parcial: a consulta é
  encerrada com motivo `out_of_credits` pelo comando `consultations:charge-minutes`.
- O saldo nunca fica negativo: o débito usa `UPDATE` condicional
  (`balance_cents >= amount`) além do lock pessimista da carteira.
- `credit_unit_price_cents` continua global e serve para converter recargas em
  créditos; não define mais o preço do minuto.
- Consultas já aceitas guardam snapshots imutáveis de
  `price_per_minute_cents`, `credit_unit_price_cents` (legado),
  `rate_credits_per_minute` (espelho legado) e `commission_rate_bps`. Mudanças
  futuras de preço não alteram consultas anteriores.

### Onde o preço é configurado

Painel administrativo → **Modalidades** (`/admin/modalidades`) → cartão
**Preço por atendente**. Para cada atendente publicado e cada modalidade
oferecida a administração informa o valor em centavos por minuto; o botão
**Salvar modalidades e preços** grava em `attendant_modality_prices` e audita
`attendant.modalities_updated`.

Em **Financeiro** (`/admin/financeiro`) ficam apenas os parâmetros globais:

- valor do crédito em centavos (`credit_unit_price_cents`);
- saldo mínimo em minutos (`minimum_balance_minutes`).

Não existe tarifa global nem fallback de modalidade: se o atendente não tiver
preço para a modalidade escolhida, o servidor recusa a solicitação e o aceite
com a mensagem **“Preço não configurado”**. O perfil público e o diretório
mostram o menor preço disponível do atendente como “a partir de”, sem nunca
inventar valor.

### Valores de demonstração (somente ambiente demo)

O `AttendantSeeder`/`SettingsSeeder` (que exigem ambiente de demonstração)
usam `credit_unit_price_cents = 100` e derivam o preço por modalidade:

| Modalidade | Créditos/min (demo) | Preço/min |
| --- | --- | --- |
| Texto | 1 | R$ 1,00 |
| Áudio | 2 | R$ 2,00 |
| Vídeo | 3 | R$ 3,00 |

## 3. Modalidades por atendente

Cada perfil possui `attendant_profiles.offered_modalities` (JSON). Perfis sem
configuração oferecem apenas **texto** — áudio e vídeo nunca são presumidos.

A administração ajusta as modalidades e os preços em **Admin → Modalidades**
(`/admin/modalidades`), com auditoria `attendant.modalities_updated`. O texto é
sempre oferecido; áudio/vídeo só podem ser atribuídos quando habilitados na
infraestrutura. O servidor revalida no pedido e no aceite:

1. o atendente está online e publicado;
2. o atendente oferece a modalidade escolhida;
3. a modalidade está habilitada em `config/consultation.php`;
4. existe preço configurado para o par atendente/modalidade;
5. o cliente possui o saldo mínimo do preço do atendente (no aceite).

## 4. Chamadas de áudio e vídeo (WebRTC)

A implementação é real: o áudio/vídeo trafega direto entre os navegadores via
`RTCPeerConnection`. O Laravel transporta apenas a sinalização (oferta SDP,
resposta SDP e candidatos ICE). Não há mídia nem simulação no servidor.

### Endpoints

| Método | Rota | Função |
| --- | --- | --- |
| `GET` | `/consultas/{id}/chamada?after={signalId}` | Estado da chamada/consulta + sinais pendentes (polling) |
| `POST` | `/consultas/{id}/chamada` | Cliente solicita a chamada (idempotente) |
| `PATCH` | `/consultas/{id}/chamada/aceitar` | Atendente aceita |
| `PATCH` | `/consultas/{id}/chamada/recusar` | Atendente recusa e a consulta é encerrada |
| `PATCH` | `/consultas/{id}/chamada/conectada` | Marca peer connection estabelecida |
| `PATCH` | `/consultas/{id}/chamada/encerrar` | Desliga e encerra a consulta |
| `PATCH` | `/consultas/{id}/chamada/falhar` | Falha de mídia/conexão encerra a consulta |
| `POST` | `/consultas/{id}/chamada/sinais` | Envia oferta/resposta/candidato ICE |

Todas as rotas exigem autenticação, participação na consulta e limite de 120
requisições por minuto por participante. O administrador não participa da
conversa nem da chamada.

### Estados

`ringing → accepted → connected → ended`, com `failed` para recusa, timeout ou
falha técnica. A tabela `consultation_calls` tem índice único por consulta
(uma chamada por atendimento) e a tabela `consultation_call_signals` guarda a
sinalização com limite de tamanho e quantidade.

Encerrar, recusar ou falhar a chamada **finaliza a consulta** e liquida os
minutos já iniciados pelas regras existentes. Uma chamada sem resposta é
fechada pelo comando `consultations:close-stale-calls` (agendado a cada
minuto), com motivo `timeout`.

### Requisitos de infraestrutura

- **HTTPS** em produção: `getUserMedia` exige contexto seguro (exceto
  `localhost`).
- **STUN**: padrão `stun:stun.l.google.com:19302`.
- **TURN**: necessário para redes simétricas/corporativas. Configure
  `CONSULTATION_ICE_SERVERS` com um TURN próprio (credenciais incluídas no
  JSON). Sem TURN, chamadas podem falhar em parte das redes — é a principal
  pendência de infraestrutura.
- **Reverb/WebSocket**: acelera a troca de sinais e o estado em tempo real.
  Sem ele, o polling autenticado (padrão 2,5 s) mantém a chamada funcional.
- **Permissões do navegador**: microfone sempre; câmera no vídeo. Se negadas,
  a interface explica e não cria a chamada; o cliente pode tentar novamente.

### Variáveis de ambiente

```dotenv
CONSULTATION_AUDIO_ENABLED=true
CONSULTATION_VIDEO_ENABLED=true
CONSULTATION_ICE_SERVERS='[{"urls":"stun:stun.l.google.com:19302"}]'
CONSULTATION_CALL_RING_TIMEOUT_SECONDS=90
CONSULTATION_CALL_SIGNAL_POLL_MS=2500
```

Desligar `CONSULTATION_AUDIO_ENABLED`/`CONSULTATION_VIDEO_ENABLED` faz o
servidor recusar novas solicitações dessas modalidades e remove a opção da
interface.

## 5. Comissões e histórico

- A comissão usa o mesmo `CommissionManager`: base = débitos efetivamente
  registrados da consulta; taxa = snapshot `commission_rate_bps`; chave
  idempotente `consultation:{id}:commission:accrual`.
- Como a base é o valor realmente cobrado, a comissão acompanha a modalidade
  escolhida sem mecanismo financeiro paralelo.
- Históricos de cliente, atendente e administração exibem modalidade, preço
  aplicado, duração, total cobrado, comissão (quando aplicável) e status.
- Os dashboards mostram quantidade de atendimentos por modalidade e valores
  reais (`ModalityUsageReport`), calculados a partir do livro-razão.

## 6. Como testar

```bash
# Subir o ambiente
docker compose up -d
docker compose exec app php artisan migrate

# Dados de demonstração (somente ambiente demo)
docker compose exec app php artisan db:seed

# Front-end (necessário após mudanças em JS/SCSS)
npm run build
# ou, em desenvolvimento:
npm run dev
```

### Testes automatizados

```bash
# Modalidades, preços, cobrança, comissão, histórico e permissões
docker compose exec app ./vendor/bin/phpunit tests/Feature/ConsultationModalityTest.php

# Chamadas WebRTC: solicitação, aceite, sinais, conexão, recusa e timeout
docker compose exec app ./vendor/bin/phpunit tests/Feature/ConsultationCallTest.php

# Regressão financeira e de ciclo de vida
docker compose exec app ./vendor/bin/phpunit tests/Feature/ConsultationBillingTest.php
docker compose exec app ./vendor/bin/phpunit tests/Feature/ConsultationLifecycleTest.php
docker compose exec app ./vendor/bin/phpunit tests/Feature/CommissionTest.php

# Suíte completa
docker compose exec app php artisan test --compact
```

### Teste manual por modalidade

1. Entre como administrador e defina os preços por atendente em
   **Modalidades**; confira o valor do crédito e o saldo mínimo em
   **Financeiro**.
2. Em **Modalidades**, habilite texto/áudio/vídeo para um atendente e deixe-o
   online no painel dele.
3. Como cliente, abra o perfil do atendente, escolha a modalidade e confirme o
   preço exibido.
4. **Texto**: aceite a consulta, troque mensagens e envie uma carta.
5. **Áudio**: aceite a consulta, inicie a chamada, aceite no painel do
   atendente, permita o microfone e verifique o indicador “Em andamento”.
6. **Vídeo**: repita o fluxo, permita câmera e microfone e teste os controles
   de silenciar e desligar câmera.
7. Encerre a chamada e confira no histórico a modalidade, o preço, a duração,
   o total e a comissão.
8. Rode `docker compose exec app php artisan consultations:charge-minutes` para
   conferir a cobrança dos minutos e
   `docker compose exec app php artisan consultations:close-stale-calls` para
   chamadas sem resposta.

> Para testar dois navegadores na mesma máquina, use janelas anônimas distintas
> (uma por participante). Reverb ativo reduz a latência da sinalização; sem
> ele, o polling mantém a chamada funcionando.

## 7. Pendências de infraestrutura

- Configurar um servidor **TURN** em produção e informar as credenciais em
  `CONSULTATION_ICE_SERVERS`.
- Garantir **HTTPS** e os domínios permitidos no Reverb (`REVERB_ALLOWED_ORIGINS`).
- Executar o agendador (`php artisan schedule:work` ou cron) para
  `consultations:charge-minutes`, `consultations:interrupt-disconnected`,
  `consultations:expire-requests` e `consultations:close-stale-calls`.
- Validar a qualidade das chamadas em redes reais (Wi-Fi, 4G e NAT simétrico)
  antes de liberar vídeo para todos os atendentes.

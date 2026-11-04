# Fluxo "Trabalhe conosco" e ativação de atendente

> Implementado nos Prompts 07, 16 e 17. A aprovação de candidatura **não cria
> acesso** de atendente. A criação de conta/perfil é uma etapa operacional
> separada, registrada em auditoria.

## 1. Fluxo público

1. Visitante acessa `/trabalhe-conosco`.
2. O formulário exige nome, e-mail, telefone com DDD, experiência, apresentação,
   consentimento e aceita cidade, estado, especialidades e portfólio.
3. O servidor valida formato e tamanho, normaliza telefone/e-mail, aplica
   honeypot e limita a 5 envios por 10 minutos por IP.
4. A candidatura é criada como `pending`; nenhum usuário, senha, papel ou perfil
   de atendente é criado.
5. O público recebe uma confirmação genérica, sem revelar decisão ou notas.

Bloqueios de duplicidade:

- candidatura `pending` ou `under_review` para o mesmo e-mail é recusada;
- candidatura `approved` não pode ser reenviada pelo formulário;
- candidatura `rejected` permite nova tentativa após 30 dias.

## 2. Estados da candidatura

| Estado | Significado | Quem altera |
|---|---|---|
| `pending` | Recebida, aguardando análise | Sistema, no envio |
| `under_review` | Análise assumida por um administrador | Admin ativo |
| `approved` | Aprovada para a etapa operacional | Admin ativo |
| `rejected` | Recusada, com possível nota interna | Admin ativo |

Cada transição grava `reviewed_by`, `status_changed_at`, `reviewed_at` e um
`AuditLog` com origem/destino. As notas internas aparecem apenas no painel
administrativo e nunca na página pública.

## 3. Revisão administrativa

- `/admin/candidaturas` lista e filtra por busca, status e período.
- `/admin/candidaturas/{id}` mostra dados, consentimento, notas internas e
  histórico de auditoria.
- "Iniciar análise", "Aprovar" e "Rejeitar" são exclusivos de administrador
  ativo; o estado é bloqueado na transação para evitar decisão dupla.
- A aprovação não publica perfil, não define comissão e não permite login.

## 4. Ativação manual do atendente

Depois da aprovação e das verificações de identidade/qualificação definidas pela
operação, criar a conta com o comando abaixo **no servidor**:

```bash
docker compose exec app php artisan arcana:create-attendant \
  --name="Nome Completo" \
  --email="atendente@example.com" \
  --display-name="Nome público" \
  --commission-rate="25" \
  --publish
```

- `--commission-rate` aceita 0–100 com até duas casas; use o percentual aprovado
  pela administração.
- `--publish` publica o perfil no diretório. Sem essa opção, o perfil fica
  offline e não publicado.
- A senha pode ser informada via `--password` (evite deixar no histórico do
  shell) ou solicitada interativamente; em branco, o comando gera uma senha
  aleatória e a exibe uma única vez.
- O comando cria usuário `attendant` ativo, perfil profissional, taxa inicial e
  um `AuditLog` `attendant.created_by_operator`.
- Ele não lê nem altera a candidatura. Correlacione o e-mail manualmente no
  procedimento interno e documente a decisão.

Após criar:

1. o atendente entra em `/attendant`, revisa o perfil e fica online quando
   estiver pronto;
2. o administrador ajusta a taxa em `/admin/comissoes/atendentes/{user}` se
   necessário;
3. a comissão de cada consulta usa o snapshot do aceite;
4. não há edição manual de comissão histórica, saque ou transferência.

## 5. Privacidade e minimização

- A página pública nunca mostra e-mail, telefone, notas internas, comissão ou
  dados financeiros.
- O diretório público mostra apenas nome de exibição, bio, especialidades, foto
  e status de disponibilidade.
- O consentimento da candidatura é registrado com data.
- A retenção de candidaturas e dados pessoais ainda depende de decisão do
  responsável (ver `docs/SECURITY_PRIVACY.md`).
- A aprovação não deve ser usada como autorização automática para publicar dados
  pessoais; a publicação exige ação explícita.

## 6. Fora do escopo

- Criação automática de credenciais a partir da candidatura.
- Portal do candidato, acompanhamento público de status ou recurso de decisão.
- Verificação de antecedentes, contratos ou pagamento ao atendente.
- Edição de bio/foto pelo próprio atendente (o perfil é criado pela operação).

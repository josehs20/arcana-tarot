# Documentação do Arcana Tarot

Índice da documentação técnica do projeto.

| Documento | Descrição |
|---|---|
| [PROJECT_PLAN.md](PROJECT_PLAN.md) | Arquitetura, módulos, decisões, riscos e ordem de execução do MVP |
| [DECISIONS.md](DECISIONS.md) | Decisões iniciais (ADR) e dúvidas pendentes de confirmação |
| [DATA_MODEL.md](DATA_MODEL.md) | Modelo de dados, relações e invariantes do domínio |
| [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md) | Paleta, tipografia, componentes e layouts da interface |
| [REDESIGN_VISUAL_E_CMS.md](REDESIGN_VISUAL_E_CMS.md) | Redesign, painéis, dashboards, gráficos, CMS e limitações da etapa 22 |
| [SITE_CONTENT.md](SITE_CONTENT.md) | CMS de identidade, seções, navegação, FAQ, imagens e auditoria |
| [TAROT_CATALOG.md](TAROT_CATALOG.md) | Catálogo de baralhos/cartas, imagens privadas, autorização e retenção |
| [CONSULTATION_STATE_MACHINE.md](CONSULTATION_STATE_MACHINE.md) | Estados, transições, presença, chat persistente e rotinas automáticas |
| [SEGURANCA_E_USABILIDADE_CHAT.md](SEGURANCA_E_USABILIDADE_CHAT.md) | Presença, inatividade, avisos de saída, logout, compra de créditos e limites administrativos |
| [MELHORIAS_CHAT_ATENDIMENTO.md](MELHORIAS_CHAT_ATENDIMENTO.md) | Cartões das partes, data de nascimento 18+, envio de várias cartas, rolagem do chat, aviso de retorno, saída acidental e erros de microfone/câmera |
| [MONITORAMENTO_E_PROTECAO_CHAT.md](MONITORAMENTO_E_PROTECAO_CHAT.md) | Detecção e bloqueio de dado pessoal no chat, mascaramento, ocorrências e painel de histórico |
| [REALTIME.md](REALTIME.md) | Reverb/Echo, canais privados, eventos, reconexão, portas e deploy |
| [WALLET_LEDGER.md](WALLET_LEDGER.md) | Carteira, livro-razão imutável, idempotência e invariantes financeiras |
| [PIX_SANDBOX.md](PIX_SANDBOX.md) | Contrato PIX, mock sandbox, webhook HMAC, estados e troca para produção |
| [INTEGRACAO_INFINITEPAY.md](INTEGRACAO_INFINITEPAY.md) | Checkout Integrado InfinitePay: pacotes, `payment_check`, webhook, idempotência e operação |
| [TIME_BILLING.md](TIME_BILLING.md) | Cobrança por minuto iniciado, snapshot de tarifa, idempotência e encerramento por saldo |
| [MODALIDADES_E_PRECIFICACAO.md](MODALIDADES_E_PRECIFICACAO.md) | Texto, áudio e vídeo: preços por modalidade, chamadas WebRTC, cobrança e requisitos |
| [COMMISSION_POLICY.md](COMMISSION_POLICY.md) | Taxa por atendente, snapshot, base, arredondamento, estornos e lançamentos imutáveis |
| [ADMIN_REPORTING.md](ADMIN_REPORTING.md) | Definição dos indicadores do dashboard, pagamentos, comissões e auditoria |
| [ATTENDANT_ONBOARDING.md](ATTENDANT_ONBOARDING.md) | Fluxo Trabalhe conosco, estados da candidatura e ativação manual do atendente |
| [SECURITY_PRIVACY.md](SECURITY_PRIVACY.md) | Revisão de segurança, controles, riscos residuais e proposta de retenção |
| [AUTENTICACAO_GOOGLE_SMS_BONUS.md](AUTENTICACAO_GOOGLE_SMS_BONUS.md) | Login com Google, cadastro com código SMS (Twilio Verify) e bônus de primeiro acesso |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Runbook de implantação, Docker, migrations, backups, monitoramento e checklist |
| [ARQUITETURA_PRODUCAO_HOSTOO.md](ARQUITETURA_PRODUCAO_HOSTOO.md) | Produção em hospedagem compartilhada: polling no lugar do Reverb, fila/cache no MySQL e crons obrigatórios |
| [TESTE_LOCAL_MODO_HOSTOO.md](TESTE_LOCAL_MODO_HOSTOO.md) | Reproduzir localmente o modo Hostoo (sem Redis/Reverb), cron emulado, checklist de fumaça e limites do teste |
| [SEEDERS_E_DADOS_DE_TESTE.md](SEEDERS_E_DADOS_DE_TESTE.md) | Seeders, contas fictícias, dados de demonstração, cenários manuais e idempotência |
| [IMPORTACAO_DADOS_LEGADO.md](IMPORTACAO_DADOS_LEGADO.md) | Importação de usuários, senhas compatíveis e saldos do banco legado (`arcana:import-legacy-users`) |

Os documentos são atualizados a cada etapa do roteiro incremental em `arcana-tarot-prompts/prompts/`.

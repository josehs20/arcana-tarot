# Prompt 20 — Documentação e preparação para implantação

Prepare documentação operacional para o MVP Arcana Tarot. Não faça deploy em produção sem autorização explícita.

## Documentar
- Requisitos e versões.
- Como configurar `.env` a partir de `.env.example`.
- Como iniciar/parar Docker Compose.
- Como executar migrations e seeders seguros.
- Como criar o primeiro administrador.
- Como executar worker de filas e Reverb.
- Como compilar assets.
- Como rodar testes.
- Como configurar o provedor PIX em sandbox.
- Como configurar domínio, HTTPS e variáveis de produção.
- Como aplicar migrations com backup e plano de rollback.
- Como executar backups e testar restauração.
- Como monitorar logs e serviços.
- Como reiniciar workers/Reverb após deploy.
- Checklist de lançamento.
- Limitações conhecidas e funcionalidades fora do MVP.

## Checklist mínimo
- `APP_DEBUG=false` em produção.
- Segredos fora do Git.
- HTTPS configurado.
- MySQL e Redis não expostos publicamente.
- Worker e Reverb gerenciados por processo/serviço apropriado.
- Webhooks acessíveis e validados.
- Backups testados.
- Permissões revisadas.
- Fluxo de pagamento testado em sandbox.
- Regras de cobrança e política de comissão documentadas em `docs/COMMISSION_POLICY.md`.
- Fluxo Trabalhe conosco, status das candidaturas, privacidade e processo manual de ativação do atendente documentados.
- Testes automatizados aprovados.

Não invente configurações específicas do servidor sem inspecionar o ambiente. Termine com uma avaliação objetiva: pronto, pronto com ressalvas ou não pronto para produção, justificando.

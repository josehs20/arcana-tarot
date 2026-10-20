# Arcana Tarot — Pacote de prompts para Cursor

Este pacote orienta a construção incremental do MVP de uma plataforma de consultas de Tarot por chat em tempo real, incluindo catálogo de cartas, candidaturas de profissionais e comissões por consulta.

## Nome do projeto
**Arcana Tarot** — nome provisório, relacionado ao universo dos arcanos e às consultas de Tarot. Antes de publicar, verifique disponibilidade de domínio, marca e redes sociais.

## Stack planejada
- Laravel 13
- PHP 8.4
- Docker Compose
- Nginx
- MySQL 8.4 LTS
- Redis
- Laravel Reverb
- Blade
- Bootstrap 5.3
- JavaScript
- Laravel Echo + cliente compatível com protocolo Pusher
- Vite
- PHPUnit ou Pest

## Como usar
1. Abra a pasta do projeto no Cursor.
2. Copie os arquivos `.mdc` para a pasta `.cursor/rules/` do projeto, se ainda não estiverem lá.
3. Comece pelo `prompts/00-analise-e-plano.md`.
4. Execute **um prompt por vez**, na ordem numérica.
5. Depois de cada prompt, revise os arquivos modificados, rode os testes e confirme o resultado antes de avançar.
6. Se um prompt encontrar problemas de ambiente ou dependências, peça ao Cursor para registrar o bloqueio e não inventar resultados.
7. Faça commits Git após etapas estáveis.

## Regras importantes
- Não implemente todos os prompts de uma só vez.
- Não pule testes nem remova testes existentes para obter sucesso artificial.
- Não coloque segredos reais em `.env`, no Git ou nos prompts.
- Não implemente pagamentos reais antes de configurar e testar o provedor escolhido.
- Não permita cobrança apenas pelo cronômetro do navegador.
- Não considere uma recarga paga até que o servidor valide a confirmação do provedor.
- Não armazene saldo como um campo livremente editável sem histórico de lançamentos.
- Não exponha o conteúdo das conversas ao administrador sem uma política de acesso definida.
- Não disponibilize imagens do catálogo por caminhos públicos diretos; proteja o acesso por autorização e preserve snapshots das cartas enviadas ao chat.
- O formulário Trabalhe conosco deve coletar apenas dados necessários; candidatura aprovada não cria automaticamente acesso como atendente.
- A comissão usa a base monetária real calculada pela cobrança, taxa congelada por consulta e lançamentos auditáveis. Não assuma que 1 crédito equivale a R$ 1 sem regra documentada.
- Não implementar pagamento/saque de comissões no MVP; a aplicação registra e apresenta os valores de comissão ganhos.
- Use migrations, seeders e factories para manter o ambiente reproduzível.
- Fixe versões de imagens e dependências adequadamente; não use `latest` em produção.
- Ao terminar cada prompt, entregue resumo, arquivos alterados, comandos executados, testes e pendências.

## Ordem sugerida
00. Análise do ambiente e plano
01. Fundação Laravel e qualidade do repositório
02. Docker e serviços locais
03. Modelagem do banco de dados — inclui estrutura prevista para candidaturas e comissões
04. Autenticação e autorização
05. Layout visual e componentes Blade
06. Site público, perfis e CTA Trabalhe conosco
07. Formulário público de candidatura e análise administrativa
08. Painéis e status do atendente
09. Cadastro de baralhos e cartas de Tarot
10. Sessões de consulta e regras de estado
11. Chat persistente, incluindo mensagens de carta com imagem
12. Reverb e eventos em tempo real para mensagens textuais e cartas
13. Carteira e livro-razão de créditos
14. Pagamentos PIX em modo de teste
15. Cobrança por tempo e encerramento
16. Comissão por atendente e lançamentos auditáveis
17. Histórico, indicadores e administração
18. Segurança, privacidade e auditoria
19. Testes integrados e correções
20. Documentação e preparação para implantação

O projeto deve ser desenvolvido na ordem acima. Se a aplicação já tiver arquivos ou decisões, o Cursor deve inspecionar antes de modificar e preservar o que estiver correto.

# Prompt 00 — Análise do ambiente e plano

Atue como engenheiro de software sênior. Vamos construir **Arcana Tarot**, um MVP de consultas de Tarot com chat em tempo real e créditos pré-pagos.

## Escopo do MVP
- Três perfis: administrador, atendente e cliente.
- Atendente com status online, offline e ocupado; ocupado deve ser derivado de consulta ativa.
- Página inicial pública e listagem/perfil de atendentes.
- Cliente cadastra-se, compra créditos por PIX e inicia uma consulta por chat.
- O consumo começa somente quando o atendente aceita e o servidor confirma a sessão.
- Cobrança baseada no tempo controlada pelo servidor.
- Histórico persistente de mensagens e consultas.
- Catálogo de baralhos e cartas gerenciado pelo administrador, com imagem por carta.
- Durante a consulta ativa, o atendente escolhe uma carta do catálogo e a envia como mensagem visual no chat; o cliente recebe a imagem junto da mensagem.
- Painéis simples para os três perfis.
- Stack pretendida: Laravel 13, PHP 8.4, Docker Compose, Nginx, MySQL 8.4, Redis, Laravel Reverb, Blade, Bootstrap 5.3, JavaScript, Echo e Vite.

## Tarefa
1. Inspecione o repositório e o ambiente antes de alterar qualquer arquivo.
2. Identifique se já existe aplicação, versão de PHP/Laravel, dependências, testes, Docker e configuração.
3. Compare o estado atual com a stack pretendida.
4. Produza `docs/PROJECT_PLAN.md` com arquitetura, módulos, decisões, riscos e ordem de execução.
5. Produza `docs/DECISIONS.md` registrando decisões iniciais e dúvidas que precisam de confirmação.
6. Não implemente os módulos ainda. Não sobrescreva trabalho existente.
7. Informe dependências incompatíveis ou limitações reais.

## Critérios de conclusão
- Plano específico para este repositório, não um texto genérico.
- Nenhuma funcionalidade implementada nesta etapa.
- Informe os comandos de inspeção executados e o resultado.


## Requisitos adicionais do produto
Inclua no levantamento e no plano as candidaturas de profissionais pela página pública “Trabalhe conosco”, com revisão pelo administrador, e a comissão percentual individual de cada atendente. A comissão precisa de taxa snapshot por consulta, livro-razão imutável e uma definição documentada da conversão de créditos cobrados para valor monetário. Não fixe percentuais de negócio arbitrários; a taxa deve ser configurável pelo admin.

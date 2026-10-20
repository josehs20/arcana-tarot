# Prompt 19 — Testes integrados e correções

Faça uma auditoria funcional do MVP sem implementar novas funcionalidades de escopo futuro.

## Fluxo principal a testar
1. Criar cliente e atendente.
2. Atendente fica online.
3. Cliente visualiza o perfil e solicita consulta.
4. Atendente aceita.
5. Sessão é ativada pelo servidor.
6. Administrador cadastra um baralho, cadastra cartas e envia imagens válidas.
7. Cliente e atendente trocam mensagens textuais em tempo real ou, quando teste WebSocket não for possível, pela camada de persistência/eventos.
8. Durante consulta ativa, o atendente seleciona uma carta cadastrada e envia; a mensagem com imagem persiste e chega ao cliente.
9. O cliente e o atendente autorizados conseguem abrir a imagem; um usuário externo recebe acesso negado.
10. Alterar/inativar a carta original não quebra a imagem histórica já enviada.
11. O consumo de créditos acontece conforme a regra documentada.
12. Consulta termina por ação do usuário ou saldo insuficiente.
13. O histórico mostra duração e valores corretos.
14. Atendente retorna a online/offline de acordo com a regra.
15. Recarga de sandbox é confirmada e creditada uma única vez.

16. Visitante acessa Trabalhe conosco, envia candidatura válida e recebe confirmação sem obter papel de atendente.
17. Administrador visualiza, filtra, assume análise, aprova/rejeita candidatura e a decisão fica auditada.
18. Candidato não autenticado não acessa painel, notas internas ou candidaturas de terceiros.
19. Administrador define percentual de comissão individual e o atendente vê apenas os seus próprios dados.
20. Ao finalizar uma consulta faturada, o sistema cria exatamente um lançamento de comissão com base, percentual congelado e valor correto; o histórico/admin mostra os mesmos valores.
21. Alterar o percentual do atendente não altera o snapshot ou comissão de consultas antigas/em andamento.
22. Consulta recusada/cancelada antes de começar não gera comissão; consulta iniciada e cobrada parcialmente segue a regra financeira documentada.

## Cenários negativos
- Cliente tenta acessar consulta alheia.
- Atendente tenta acessar sessão não atribuída.
- Usuário não autorizado tenta acessar painel administrativo.
- Pagamento/webhook repetido.
- Duplo clique para aceitar/encerrar.
- Saldo insuficiente.
- Reconexão WebSocket.
- Duas solicitações simultâneas para um atendente.
- Falha no processamento de cobrança.
- Sessão encerrada não recebe mensagens nem cartas novas.
- Upload de SVG, arquivo disfarçado, MIME incorreto e imagem acima do limite.
- Cliente tenta enviar carta como atendente ou escolher carta inativa.
- Usuário externo tenta abrir imagem de carta enviada em outra consulta.
- Spam/duplicação abusiva do formulário Trabalhe conosco ou tentativa de enviar campos administrativos pelo formulário público.
- Usuário tenta aprovar/rejeitar candidatura sem papel admin ou ler notas internas.
- Tentativa de definir comissão negativa, acima de 100%, alterar comissão histórica ou gerar lançamento duplicado em retries/concorrência.
- Arredondamento de comissão em centavos e ajuste/estorno sem apagar o registro original.
- Tentativa de forjar caminho da imagem ou ID da carta.

Rode a suíte completa e build do frontend. Corrija defeitos reais sem remover testes ou reduzir as proteções. Relate testes passados/falhos e qualquer teste que não pôde ser executado.

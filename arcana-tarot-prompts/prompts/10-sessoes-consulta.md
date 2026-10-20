# Prompt 10 — Ciclo de vida da consulta

Implemente o domínio de sessões de consulta antes do chat completo.

## Fluxo
1. Cliente autenticado escolhe um atendente online.
2. Servidor valida que o cliente não possui outra consulta ativa e que o atendente está disponível.
3. Cria uma solicitação pendente.
4. Atendente aceita ou recusa.
5. Ao aceitar, o servidor verifica novamente disponibilidade e saldo mínimo conforme a regra configurada.
6. A sessão passa a ativa com timestamp oficial no servidor.
7. Durante a sessão, o atendente fica ocupado.
8. Ao encerrar, sistema grava timestamp, motivo e estado final.
9. O atendente volta a online somente se ainda estiver conectado/disponível; caso contrário, fica offline.

## Regras de segurança e concorrência
- As transições devem ser feitas por serviços de domínio e transações.
- Prevenir duas aceitações simultâneas para o mesmo atendente.
- Prevenir múltiplas sessões ativas incompatíveis para o mesmo cliente.
- Toda operação de aceitar/encerrar deve ser idempotente.
- Não confiar em timestamps do navegador.
- Definir estados explícitos: pending, active, completed, cancelled, rejected e interrupted, ajustando nomenclatura ao padrão do projeto.
- Registrar motivo de encerramento sem conteúdo íntimo desnecessário.
- Definir política de desconexão sem implementar cobrança automática indefinida.

Apenas uma consulta ativa permite enviar mensagens e cartas de Tarot. A escolha/envio de carta será habilitada para o atendente atribuído à consulta ativa; o cliente recebe a carta no chat, mas não pode forjar o remetente nem enviar uma carta como se fosse o atendente.

Crie testes de concorrência quando viável, transições inválidas, autorização e encerramento repetido. Documente a máquina de estados.

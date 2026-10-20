# Prompt 11 — Chat persistente

Implemente o chat em nível de aplicação e persistência, antes da transmissão em tempo real.

## Recursos
- Envio de mensagens textuais em consulta ativa.
- Envio de uma carta de Tarot como mensagem especial no chat: o atendente abre um seletor com cartas ativas dos baralhos ativos, escolhe uma carta e confirma o envio. O cliente vê a imagem da carta, o nome do baralho/carta e o horário, integrados à linha do tempo da conversa.
- Mensagens de carta usam o tipo `tarot_card`; não são anexos de imagem livres nem URLs arbitrárias fornecidas pelo navegador.
- Histórico paginado.
- Identificação de remetente.
- Data/hora do servidor.
- Indicador de mensagens novas/unread se simples e útil.
- Interface responsiva.
- Mensagem de estado vazio.
- Limite razoável de tamanho e frequência.
- Texto sempre escapado na renderização.

## Segurança
- Apenas cliente e atendente participantes da consulta podem ler/enviar mensagens.
- Consulta encerrada não aceita novas mensagens nem envio de cartas, salvo se houver regra explícita de mensagem final; por padrão, bloquear.
- Não permitir que usuário forje remetente, consulta, horário, ID/caminho de imagem ou metadados da carta.
- Apenas o atendente atribuído pode enviar uma carta no MVP; o cliente e o atendente podem consultar as imagens das cartas já enviadas se forem participantes autorizados da conversa.
- O armazenamento do catálogo e das cópias/snapshots das imagens enviadas deve ser privado. Sirva previews do catálogo somente a participantes autorizados de uma consulta ativa, e imagens de cartas enviadas a participantes autorizados daquela consulta (inclusive no histórico); nunca exponha caminho interno de storage. Defina cabeçalhos de cache privados/adequados para evitar que conteúdo de consulta seja compartilhado em caches públicos.
- Ao enviar uma carta, grave mensagem, referência e snapshots históricos (nome do baralho, nome da carta e caminho da imagem enviada). Preserve o arquivo/snapshot usado pela mensagem mesmo que o administrador edite, substitua a imagem ou inative o cadastro original.
- Validar no servidor que a carta e o baralho estão ativos e que a carta pertence ao baralho selecionado. Copiar a imagem do catálogo para um caminho de snapshot gerado pelo servidor; nunca aceitar arquivo ou caminho arbitrário do cliente.
- Não implementar anexos de usuário, áudio ou vídeo no MVP.
- Evitar registrar conteúdo das mensagens em logs de aplicação.

## Testes
Cobrir envio textual e envio de carta, persistência, ordenação, paginação, tamanho máximo, sessão inativa, imagem acessível somente aos participantes, carta/baralho inativo, snapshot histórico e acesso cruzado. Não implementar Reverb ainda, salvo preparação de eventos sem publicação.

# Prompt 12 — Laravel Reverb e eventos em tempo real

Integre Laravel Reverb e Laravel Echo ao chat persistente.

## Requisitos
- Confirmar compatibilidade de versões com Laravel instalado e documentar instalação.
- Configurar broadcasting e variáveis de ambiente sem segredos versionados.
- Usar canais privados por consulta.
- Autorizar cada canal no servidor conferindo que o usuário participa da consulta ou possui permissão administrativa explícita.
- Transmitir evento de nova mensagem textual e de nova mensagem `tarot_card`, além dos eventos necessários para atualização do estado da consulta.
- O evento de carta contém somente os dados necessários para atualizar a interface e uma URL de imagem protegida por autorização; nunca inclui o caminho físico/privado do arquivo. O destinatário busca a imagem por endpoint autorizado.
- Não publicar conteúdo da conversa em canais públicos.
- Cliente e atendente recebem atualização sem recarregar a página.
- Tratar reconexão e recuperar mensagens ausentes consultando o servidor.
- Não considerar a confirmação WebSocket como prova de persistência: gravar no banco antes de anunciar mensagem enviada.
- Não duplicar mensagens quando houver retry.
- Documentar portas, processos e configuração para desenvolvimento/produção.

## Testes
Testar autorização de canais, eventos textuais e de carta, autorização da URL de imagem e fallback de histórico. Se não for possível testar WebSocket end-to-end no ambiente atual, documente a limitação e faça testes de unidade/integração disponíveis.

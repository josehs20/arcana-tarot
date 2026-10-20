# Prompt 09 — Cadastro de baralhos e cartas de Tarot

Implemente o módulo administrativo para configurar os baralhos e as cartas que poderão ser usadas durante uma consulta. Leia a modelagem de banco e os padrões já criados antes de alterar arquivos.

## Funcionalidades administrativas
- Criar, listar, editar, ativar e inativar baralhos de Tarot. Cada baralho deve ter nome, identificador/slug único, descrição opcional e estado.
- Dentro de cada baralho, criar, listar, editar, reordenar, ativar e inativar cartas individuais. Cada carta deve ter nome, número/código ou ordem de exibição e imagem frontal obrigatória; significados normal/invertido podem ser campos opcionais se isso não complicar o MVP.
- Apresentar pré-visualização das imagens e mensagens claras para lista vazia, erro de validação e sucesso.
- Exibir apenas baralhos/cartas ativos no seletor que será implementado no chat. Cadastros inativos permanecem no histórico e não podem ser selecionados para novos envios.
- Proteger todas as rotas e ações com middleware e autorização explícita de administrador. Não basta ocultar os links da interface.
- Registrar auditoria das mudanças administrativas relevantes (criação, edição, ativação/inativação e substituição de imagem), sem registrar o conteúdo binário.

## Imagens e armazenamento seguro
- Aceitar somente JPEG, PNG e WebP; rejeitar SVG e formatos ativos/executáveis. Validar tipo real do arquivo e tamanho no servidor; limite inicial sugerido: 5 MB configurável.
- Gerar nomes de arquivo aleatórios no servidor. Não usar nome de arquivo, caminho ou URL recebido do navegador como destino de storage.
- Guardar imagens em storage privado, fora de `public/`, persistindo no banco apenas caminho e metadata necessária. Não armazenar binários no MySQL.
- Criar uma rota autorizada de pré-visualização que devolva a imagem ao administrador sem revelar o caminho físico do storage. Para uso futuro no seletor do chat, permitir preview de catálogo somente a participantes autorizados de uma consulta ativa e apenas para cartas/baralhos ativos.
- Ao substituir a imagem, não apagar arquivo que possa estar referenciado por mensagem histórica. Use caminhos imutáveis e política segura de limpeza; documente a estratégia em `docs/TAROT_CATALOG.md`.
- Desativar/arquivar cartões já usados em mensagens em vez de apagar registros ou arquivos necessários ao histórico.

## Interface
- Integrar ao layout administrativo existente e manter a identidade mística/premium sem prejudicar legibilidade.
- Ter telas simples para lista de baralhos, formulário do baralho, lista de cartas por baralho e formulário de carta.
- Confirmar ações destrutivas/inativação e preferir inativação quando houver vínculos históricos.
- Não implementar ainda o seletor dentro do chat neste prompt; ele será integrado no prompt de chat persistente.

## Testes
Cobrir CRUD/autorização, inativação, associação entre carta e baralho, upload válido, MIME/tamanho inválido, arquivo armazenado fora de `public/`, substituição sem apagar arquivo histórico e tentativa de acesso por usuário não administrador. Rode testes e build do frontend. Não avance para o chat.

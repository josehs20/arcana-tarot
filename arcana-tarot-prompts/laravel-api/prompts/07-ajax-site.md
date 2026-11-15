# 07 — Site Blade + AJAX

Leia ANALISE/STATUS. Mantenha todas as páginas, URLs, sessão, CSRF e experiência atual. Adapte progressivamente as ações web necessárias para AJAX, reutilizando os mesmos Services/Actions da API.

- AJAX do site pode usar rotas web autenticadas por sessão; não obrigue o browser a chamar `/api/v1`.
- Mantenha rotas de páginas/Blade e compatibilidade de formulários existentes.
- Use JSON somente nas rotas de ação AJAX, com validação e erros consistentes; CSRF ativo.
- Evite duplicar regras entre controller web e API.
- Não reescreva telas sem necessidade nem transforme respostas HTML existentes em JSON.
- Trate loading, erro e sucesso sem expor exceções.

Faça mudanças pequenas e testes de regressão para login, painel, créditos, consultas e chat. Se uma tela já usa AJAX corretamente, preserve-a. Registre quais ações continuam HTML e quais usam AJAX.

# 00 — Auditoria e plano (somente leitura)

Inspecione o Laravel existente para prepará-lo como backend do site e de futuros apps Flutter. **Não altere código nem instale dependências.**

Verifique apenas o necessário: versões PHP/Laravel e pacotes; rotas web/api; autenticação atual e Google; papéis/permissões; modelos/tabelas de usuário, créditos, pagamentos, atendentes, modalidades, consultas, mensagens/cartas; controllers e regras críticas; AJAX/Blade; eventos, broadcasting/Reverb; testes; `.env.example` e configuração de deploy (nunca mostre valores secretos).

Decisões fixas: Blade/site e admin permanecem; clientes e atendentes terão app; web usa sessão, app Sanctum Bearer Token; Google nos dois; API REST `/api/v1` acessível na Hostoo sem Docker; Reverb opcional conforme hospedagem; web AJAX e API compartilham Services/Actions, mas AJAX não precisa chamar endpoints REST.

Entregue:
1. `docs/api-mobile/ANALISE.md`: versões, arquitetura atual, componentes reutilizáveis, lacunas e riscos concretos (referencie caminhos/classes).
2. `docs/api-mobile/STATUS.md`: etapas 00–08, estado inicial e próximos passos.
3. Plano curto de implementação na ordem dos prompts, citando arquivos existentes a reutilizar.

Não proponha reescrita geral. Se faltar informação, registre como pendência e escolha a alternativa compatível mais conservadora. Resposta final objetiva.

# 01 — Serviços compartilhados

Leia `docs/api-mobile/ANALISE.md` e STATUS. Implemente somente a camada necessária para compartilhar casos de uso entre controllers web e futuros controllers API.

- Identifique primeiro regras já isoladas e reutilize-as.
- Extraia gradualmente apenas regras hoje acopladas a redirect, view ou Request HTTP; mantenha respostas web atuais.
- Services/Actions recebem dados validados/identidade explicitamente e não dependem de sessão global quando forem usados pela API.
- Preserve transações, eventos, cobrança, ledger, comissões e efeitos colaterais atuais.
- Não altere telas nem crie endpoints nesta etapa.
- Adicione testes unitários/feature para regras extraídas e regressão web.

Não faça refatoração cosmética nem mova tudo para uma arquitetura nova. Atualize STATUS e liste os casos de uso que API poderá reutilizar.

# Prompt 03 — Modelagem inicial do banco

Modele o domínio mínimo do Arcana Tarot usando migrations, modelos, relações, factories e seeders de demonstração.

## Entidades esperadas
- `users`: autenticação, dados básicos e papel.
- Perfil profissional do atendente: apresentação, especialidade, preço/configuração necessária, percentual de comissão individual e status de publicação. A taxa usada em cada consulta também precisa de snapshot imutável.
- Consultas/sessões: cliente, atendente, estado, timestamps de início/fim, motivo de encerramento e snapshot do percentual de comissão vigente no início oficial da sessão.
- Mensagens: consulta, remetente, tipo (`text` ou `tarot_card`), conteúdo textual opcional, timestamps e metadados mínimos. Mensagens de carta devem referenciar a carta quando possível e preservar snapshots do nome do baralho, nome da carta e caminho privado da imagem enviada.
- Baralhos de Tarot: nome, slug, descrição opcional, estado ativo/inativo e auditoria básica.
- Cartas de Tarot: baralho, nome, código/ordem de exibição, significado opcional (normal/invertido se o projeto adotar isso), caminho privado da imagem e estado ativo/inativo.
- Carteira/conta de créditos ou equivalente.
- Lançamentos imutáveis de crédito/débito.
- Recargas/pagamentos: provedor, referência externa, valor em centavos, status e idempotência.
- Candidaturas de "Trabalhe conosco": nome, e-mail, telefone, campos profissionais estritamente necessários, status (`pending`, `under_review`, `approved`, `rejected`), datas de envio/revisão, administrador revisor e notas internas. Não armazenar documentos sensíveis no MVP.
- Lançamentos de comissão do atendente por consulta: atendente, consulta, percentual aplicado, base monetária em centavos, valor calculado em centavos, tipo do lançamento, chave idempotente e referência/motivo de ajuste quando aplicável.
- Auditoria de ações administrativas relevantes, sem registrar segredos ou conteúdo privado desnecessário.

## Regras
- Inspecione a estrutura existente e evite duplicar tabelas.
- Use chaves estrangeiras, índices e constraints coerentes.
- Armazene dinheiro em centavos inteiros ou tipo decimal preciso; não use float.
- Defina enums/constantes de estados.
- Não guarde apenas o saldo sem livro-razão de lançamentos.
- Não crie tabelas para agenda, avaliações, fidelidade ou blog no MVP.
- Preserve o histórico de candidaturas e decisões administrativas; aprovação de candidatura não concede automaticamente acesso à plataforma.
- Comissão deve ser baseada no valor monetário efetivamente cobrado pela consulta, calculada por serviço do servidor e registrada em lançamentos imutáveis; não usar float nem depender de um campo editável de total acumulado.
- Não armazene os arquivos binários das imagens diretamente no MySQL; mantenha os arquivos em storage e persista apenas caminhos/metadata.
- Uma mensagem que contenha carta deve conservar sua referência histórica mesmo que a carta ou o baralho seja desativado. Prefira arquivar/inativar ao invés de apagar registros utilizados; documente a política de exclusão e a estratégia de snapshot de imagem.
- Não faça lógica de cobrança nem cálculo de comissão ainda; apenas prepare um modelo consistente e documente as relações previstas.

## Entrega
Migrations, modelos e relações, factories/seeders seguros e `docs/DATA_MODEL.md` com um diagrama textual e explicação das invariantes. Rode migrations e testes disponíveis sem apagar dados existentes.

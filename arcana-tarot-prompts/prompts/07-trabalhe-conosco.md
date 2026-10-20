# Prompt 07 — Trabalhe conosco: candidatura e revisão administrativa

Implemente o fluxo público de candidatura para profissionais de Tarot e a análise dessas candidaturas pelo administrador. Antes de alterar arquivos, inspecione as rotas, layout público, autenticação, políticas e componentes já criados.

## Página pública e formulário
- A CTA **Trabalhe conosco** da página inicial (Prompt 06) deve abrir uma página responsiva `/trabalhe-conosco`.
- Formulário mínimo: nome completo, e-mail, telefone/WhatsApp, cidade/estado opcionais, experiência com Tarot, apresentação/abordagem de atendimento e áreas de especialidade opcionais. Um link de portfólio ou rede social é opcional.
- Incluir checkbox obrigatório de ciência/consentimento para uso dos dados com a finalidade de analisar a candidatura, link para a política de privacidade e aviso de que enviar candidatura não garante aprovação.
- Não solicitar CPF, dados bancários, documentos de identidade, senhas ou upload de arquivos nesta primeira versão.
- Validar todos os campos no servidor, limitar tamanho dos textos, normalizar e-mail/telefone quando adequado, aplicar rate limiting e medida anti-spam simples compatível com o stack existente (por exemplo, honeypot). Não depender apenas de validação JavaScript.
- Após enviar, mostrar confirmação neutra; não revelar IDs internos, notas ou detalhes administrativos. Nunca criar conta/role de atendente automaticamente.

## Persistência e estados
- Usar a entidade de candidatura prevista em `docs/DATA_MODEL.md` e no Prompt 03; se a modelagem existente diferir, adaptar com migrations seguras sem apagar dados.
- Estados: `pending`, `under_review`, `approved`, `rejected`.
- Persistir submissão, timestamps de mudança, administrador revisor e notas internas. Registrar histórico/auditoria das decisões sem guardar payloads desnecessários.
- Política de duplicidade: evitar muitas candidaturas repetidas com mesmo e-mail; permitir nova candidatura após rejeição apenas conforme regra documentada. Não rejeitar silenciosamente sem informar a pessoa.
- As notas internas e os dados de revisão nunca devem ser expostos na página pública.

## Painel administrativo
- Adicionar item de menu **Candidaturas / Trabalhe conosco** com contador de pendentes.
- Listagem paginada, busca simples e filtro por status/data; detalhe da candidatura com os dados fornecidos.
- Ações protegidas para assumir análise (`under_review`), aprovar ou rejeitar, com confirmação e nota interna opcional. Registrar quem decidiu e quando.
- Apenas `admin` pode listar, ver, alterar estado ou notas; garantir policies e testes contra acesso horizontal/vertical.
- Aprovação significa apenas que a candidatura foi aprovada. Ela não cria acesso por si só. A criação de conta/perfil de atendente deve usar o fluxo administrativo seguro existente; se ainda não houver convite/ativação segura, documentar essa etapa como manual e não inventar senha compartilhada.
- Não enviar e-mails automáticos a menos que exista configuração de mail funcional e testada; a página deve funcionar sem integração externa.

## Interface
Manter o visual premium do Arcana Tarot, com formulário claro, estados acessíveis, erros junto aos campos e layout móvel. Reutilizar componentes Blade já existentes; não introduzir um framework JavaScript novo.

## Testes obrigatórios
- Submissão válida cria candidatura `pending`.
- Erros de validação, rate limit e campos extras enviados não permitem manipular estado/revisor.
- Candidato não pode consultar candidatura de outra pessoa nem visualizar notas internas.
- Usuário não admin recebe 403/404 apropriado no painel administrativo.
- Admin pode filtrar, revisar, aprovar e rejeitar; autor e timestamp da decisão são gravados.
- Aprovação não cria automaticamente conta autenticável nem atribui papel `attendant`.
- Duplicidade e nova candidatura após rejeição seguem a política documentada.

Entregue resumo, arquivos alterados, migrations se necessárias, testes executados e pendências de e-mail/ativação se existirem. Não prossiga para outros prompts.
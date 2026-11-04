# PROMPT 22 — REDESIGN VISUAL PREMIUM, DASHBOARDS E GERENCIAMENTO DO SITE

## 1. Objetivo

Trabalhe no projeto existente Arcana Tarot.

Sua missão é reformular o design de toda a aplicação para transmitir uma experiência premium, mística, elegante e profissional, inspirada no universo do Tarot, da astrologia e da simbologia esotérica.

Além da reformulação visual, reorganize os painéis de clientes, atendentes e administradores utilizando sidebars, dashboards com indicadores e gráficos e seções organizadas por funcionalidade.

Implemente também um sistema de gerenciamento de conteúdo no painel administrativo, permitindo que o administrador altere os textos, imagens e conteúdos das páginas públicas sem precisar editar arquivos Blade ou código-fonte.

**Não crie apenas uma proposta visual. Implemente as alterações no projeto existente, respeitando a arquitetura, as funcionalidades e as regras de negócio já estabelecidas.**

Execute somente esta tarefa. Não avance para outros prompts do projeto.

---

## 2. Regras obrigatórias antes de começar

Antes de modificar qualquer arquivo:

1. Inspecione a estrutura atual do projeto.
2. Identifique as versões de Laravel, PHP, Bootstrap e demais bibliotecas utilizadas.
3. Examine os layouts Blade, componentes, CSS, JavaScript e assets existentes.
4. Identifique as rotas públicas, administrativas, de clientes e de atendentes.
5. Identifique os middlewares, policies, permissões e regras de autenticação.
6. Verifique quais dashboards e gráficos já existem.
7. Verifique como os textos e imagens da página pública são atualmente definidos.
8. Identifique quais funcionalidades já estão implementadas e quais ainda estão pendentes.
9. Consulte as regras existentes em `.cursor/rules/`.
10. Examine os testes automatizados relacionados às páginas e aos painéis.

Não presuma que arquivos, componentes, rotas, tabelas ou funcionalidades existem sem verificar.

Não recrie funcionalidades que já funcionam.

Não altere as regras financeiras, a lógica das consultas, o cálculo de comissões, as permissões ou o funcionamento do chat apenas para facilitar a implementação visual.

Não substitua a arquitetura atual por React, Vue ou outra tecnologia sem necessidade e sem autorização.

Utilize Blade, Bootstrap 5.3 e JavaScript quando forem compatíveis com a implementação existente.

Preserve Laravel, Vite e os mecanismos de autenticação e autorização já utilizados.

Se a versão instalada de alguma biblioteca diferir da prevista, adapte a solução à versão real do projeto.

---

# PARTE 1 — IDENTIDADE VISUAL PREMIUM

## 3. Direção artística

Transforme o Arcana Tarot em uma plataforma visualmente sofisticada, com uma identidade reconhecível.

A aparência deve transmitir:

- mistério;
- espiritualidade;
- exclusividade;
- tranquilidade;
- confiança;
- elegância;
- profissionalismo;
- acolhimento.

Evite uma aparência genérica de painel administrativo ou template Bootstrap sem personalização.

Não exagere em efeitos, símbolos ou ornamentos.

O resultado deve parecer um produto digital premium, e não uma página temática improvisada.

### 3.1. Paleta de cores

Utilize uma paleta consistente baseada em:

- azul-noite profundo;
- roxo-ameixa;
- violeta escuro;
- dourado discreto;
- marfim;
- cinza quente;
- tons neutros para superfícies e textos.

Defina as cores como variáveis CSS globais, evitando cores espalhadas aleatoriamente pelos arquivos.

Crie tokens para:

- cor principal;
- cor secundária;
- fundo principal;
- superfícies e cartões;
- texto principal;
- texto secundário;
- destaque dourado;
- bordas;
- sucesso;
- alerta;
- erro;
- foco;
- estados desabilitados.

O dourado deve funcionar como detalhe de destaque, sem comprometer a legibilidade.

Não utilize combinações com baixo contraste.

### 3.2. Tipografia

Utilize uma combinação de tipografias que transmita sofisticação.

Direção sugerida:

- títulos: Cormorant Garamond ou Cinzel;
- textos e interface: Inter ou Manrope.

Verifique a estratégia atual de carregamento das fontes.

Se houver dependência de fontes externas, considere fallback local e uma estratégia que não prejudique a renderização caso o serviço externo esteja indisponível.

A tipografia deve manter boa legibilidade em dispositivos móveis.

### 3.3. Elementos visuais

Crie uma linguagem visual consistente utilizando, com moderação:

- símbolos lunares;
- estrelas;
- constelações;
- círculos astrológicos;
- linhas finas douradas;
- padrões geométricos discretos;
- molduras inspiradas em cartas de Tarot;
- gradientes profundos;
- texturas sutis;
- ilustrações simbólicas.

Esses elementos devem complementar o conteúdo, sem competir com ele.

Evite emojis como substitutos de ícones profissionais.

Utilize a biblioteca de ícones já existente ou uma solução compatível com o projeto.

### 3.4. Microinterações

Implemente transições discretas para:

- hover de botões;
- abertura de menus;
- seleção de cartas;
- navegação entre abas;
- exibição de cartões;
- notificações;
- carregamento de conteúdo.

As animações devem ser leves e respeitar `prefers-reduced-motion`.

Evite animações constantes que distraiam o usuário.

Não utilize animações pesadas ou bibliotecas adicionais desnecessárias.

---

# PARTE 2 — REFORMULAÇÃO DA PÁGINA PÚBLICA

## 4. Página inicial

Reformule completamente a página inicial, preservando as funcionalidades e os destinos de navegação existentes.

A página deve possuir uma hierarquia visual clara.

### 4.1. Navbar

Organize a navegação pública com aparência premium.

A estrutura atual contém os seguintes destinos:

- Início;
- Atendentes;
- Como funciona;
- Dúvidas;
- Trabalhe conosco.

Preserve esses destinos, adaptando a aparência e o comportamento ao novo design.

Utilize uma navbar que possa apresentar:

- logotipo do Arcana Tarot;
- links principais;
- botão de acesso à conta;
- botão de cadastro, quando apropriado;
- menu responsivo para dispositivos móveis.

O estado ativo deve corresponder à página ou seção atual.

O menu deve funcionar corretamente tanto em páginas independentes quanto em links para âncoras da página inicial.

Não utilize URLs absolutas de `localhost` no código.

Utilize rotas nomeadas do Laravel quando disponíveis.

Os links de navegação devem funcionar em ambientes locais, de homologação e de produção.

### 4.2. Hero principal

Crie um hero visualmente marcante.

Direção visual:

- fundo azul-noite ou roxo profundo;
- imagem ou composição artística relacionada ao Tarot;
- iluminação suave;
- detalhes dourados;
- título expressivo;
- subtítulo acolhedor;
- chamada principal para iniciar uma consulta;
- chamada secundária para conhecer os atendentes.

O hero deve ter boa composição e leitura em diferentes tamanhos de tela.

Não utilize imagens externas aleatórias como dependência obrigatória da interface.

Priorize assets locais e imagens cuja utilização seja autorizada.

Se o projeto possuir imagens existentes, avalie seu reaproveitamento.

### 4.3. Seção de apresentação

Reformule a apresentação do Arcana Tarot para explicar o propósito da plataforma de maneira clara.

Organize o conteúdo em blocos com boa hierarquia tipográfica.

Não invente promessas comerciais, depoimentos, certificações ou garantias.

### 4.4. Seção de atendentes

Crie cartões sofisticados para apresentar os atendentes disponíveis.

Cada cartão poderá mostrar, conforme os dados existentes:

- imagem ou avatar;
- nome;
- especialidades;
- apresentação resumida;
- status de disponibilidade;
- botão para visualizar o perfil;
- botão para iniciar uma consulta, quando autorizado e disponível.

Os estados online, offline e ocupado devem respeitar as regras reais do sistema.

Não apresente um atendente offline como disponível para iniciar uma consulta.

### 4.5. Seção "Como funciona"

Apresente visualmente as principais etapas da experiência:

1. Cadastro do cliente.
2. Escolha do atendente.
3. Compra ou utilização de créditos.
4. Início da consulta.
5. Interação pelo chat e envio de cartas.
6. Encerramento e consulta ao histórico.

Adapte o conteúdo às funcionalidades realmente implementadas.

### 4.6. Seção de cartas de Tarot

Quando houver cartas públicas ou conteúdo autorizado para exibição, crie uma seção visual com cartas selecionadas.

Utilize imagens locais e mantenha uma composição coerente com a identidade visual.

Não exponha dados privados das consultas nem cartas pertencentes a históricos privados de clientes.

### 4.7. Dúvidas frequentes

Reformule a seção de perguntas frequentes.

Utilize componentes acessíveis, com abertura e fechamento claros.

O conteúdo deve ser administrável pelo painel, conforme descrito na Parte 4.

### 4.8. Trabalhe conosco

Mantenha a página de candidatura de atendentes.

Reformule seu layout para combinar com a nova identidade visual.

Preserve o formulário, as validações, os estados da candidatura e o processo de análise administrativa existentes.

Não remova campos obrigatórios nem altere as regras de aprovação.

### 4.9. Footer

Crie um rodapé organizado contendo os links institucionais e os destinos já existentes.

Considere:

- navegação;
- contato, se cadastrado;
- políticas e termos, quando existentes;
- links para páginas públicas;
- identificação da plataforma.

Não invente endereços, redes sociais ou informações institucionais.

---

# PARTE 3 — PAINÉIS COM SIDEBAR E DASHBOARDS

## 5. Arquitetura visual dos painéis

Reorganize os painéis de administrador, atendente e cliente utilizando uma estrutura visual consistente.

Cada painel deve possuir:

- sidebar;
- cabeçalho;
- área de conteúdo;
- título e descrição da página;
- navegação por seções;
- indicadores relevantes;
- estados vazios;
- mensagens de erro;
- indicadores de carregamento;
- notificações de sucesso;
- responsividade.

Crie layouts e componentes reutilizáveis quando isso fizer sentido.

Evite duplicar toda a estrutura em vários arquivos Blade.

### 5.1. Sidebar

A sidebar deve apresentar:

- logotipo ou identificação do Arcana Tarot;
- nome do usuário autenticado;
- avatar, se disponível;
- links organizados por grupos;
- indicação da seção ativa;
- acesso ao perfil;
- botão de sair.

Em telas grandes, a sidebar pode permanecer fixa.

Em telas pequenas, transforme-a em um menu lateral recolhível, acessível por botão.

A navegação deve funcionar por teclado e possuir identificação acessível.

Os links devem respeitar as permissões do usuário.

Não dependa apenas de esconder links no frontend: as rotas e operações precisam continuar protegidas no backend.

### 5.2. Cabeçalho dos painéis

O cabeçalho deve incluir, quando apropriado:

- título da seção;
- descrição curta;
- notificações;
- informações da conta;
- ações principais da página.

Evite excesso de elementos e mantenha boa hierarquia visual.

### 5.3. Dashboard do administrador

Crie uma dashboard administrativa organizada por cartões de indicadores e gráficos.

Utilize apenas informações disponíveis no banco e calculadas corretamente.

Indicadores sugeridos:

- total de clientes cadastrados;
- total de atendentes ativos;
- atendentes online;
- consultas em andamento;
- consultas concluídas;
- candidaturas pendentes;
- volume financeiro efetivamente cobrado;
- comissões calculadas;
- pagamentos pendentes;
- evolução de consultas ao longo do tempo.

Os indicadores financeiros devem distinguir claramente valores brutos, taxas, comissões e demais valores, conforme as regras existentes.

Não apresente créditos comprados como se fossem necessariamente receita reconhecida.

Não misture valores de pagamentos pendentes com pagamentos confirmados.

Não some valores incompatíveis.

Os gráficos devem apresentar períodos e unidades de medida claros.

Utilize a biblioteca de gráficos já instalada, se houver.

Caso não exista uma biblioteca, escolha uma solução compatível e leve, justificando a dependência.

Os gráficos devem possuir:

- títulos;
- legendas;
- tooltips;
- eixos ou valores compreensíveis;
- tratamento para ausência de dados;
- layout responsivo.

Não utilize valores fictícios para preencher os gráficos em produção.

Se o banco estiver vazio, apresente um estado vazio bem projetado.

Adicione filtros de período quando isso for compatível com a arquitetura existente.

### 5.4. Dashboard do atendente

Crie uma dashboard voltada para a rotina profissional.

Indicadores sugeridos:

- status de disponibilidade;
- consultas disponíveis;
- consultas em andamento;
- consultas concluídas;
- quantidade de consultas realizadas;
- comissões geradas;
- saldo ou total financeiro do atendente, conforme a modelagem atual;
- histórico recente de atendimentos.

Os dados devem pertencer exclusivamente ao atendente autenticado.

Não permita que um atendente consulte ganhos, conversas ou informações privadas de outros atendentes.

Não apresente uma comissão calculada como valor efetivamente pago se o sistema não registrar o pagamento.

### 5.5. Dashboard do cliente

Crie uma dashboard voltada para o cliente.

Indicadores sugeridos:

- saldo de créditos;
- consultas recentes;
- consultas em andamento;
- histórico de consultas;
- movimentações da carteira;
- opção de encontrar atendentes;
- acesso rápido para iniciar uma consulta.

O saldo apresentado deve vir da fonte oficial de dados financeiros do sistema.

Não permita alterações de saldo por meio da interface.

### 5.6. Organização das funcionalidades

Distribua as funcionalidades em páginas independentes.

Exemplo para o administrador:

- Dashboard;
- Clientes;
- Atendentes;
- Candidaturas;
- Consultas;
- Baralhos;
- Cartas;
- Financeiro;
- Comissões;
- Pagamentos;
- Conteúdo do site;
- Configurações;
- Perfil.

Exemplo para o atendente:

- Dashboard;
- Consultas disponíveis;
- Consulta em andamento;
- Histórico;
- Comissões e ganhos;
- Perfil.

Exemplo para o cliente:

- Dashboard;
- Encontrar atendentes;
- Minhas consultas;
- Carteira;
- Histórico financeiro;
- Perfil.

Essa lista é uma referência, não uma autorização para criar páginas fictícias.

Mantenha somente os destinos que existirem ou que possam ser implementados de forma completa e coerente com a arquitetura atual.

Se uma funcionalidade ainda não estiver implementada, não crie um link que leve a uma página vazia sem explicação.

---

# PARTE 4 — PAINEL ADMINISTRATIVO DE CONTEÚDO DO SITE

## 6. Objetivo do gerenciamento de conteúdo

O administrador deve conseguir editar o conteúdo da página pública sem precisar alterar arquivos Blade ou modificar o código-fonte.

Implemente uma área administrativa chamada:

"Conteúdo do site"

Essa área deve permitir gerenciar os elementos públicos que fizerem parte do escopo abaixo.

O sistema deve persistir as alterações no banco de dados e refletir o conteúdo atualizado na página pública.

Não implemente apenas campos visuais sem persistência real.

## 7. Conteúdo administrável

### 7.1. Identidade da página

Permitir gerenciar, conforme apropriado:

- nome público da plataforma;
- logotipo;
- favicon;
- descrição institucional;
- imagens de identidade visual utilizadas no site.

Não permitir que o usuário envie arquivos executáveis ou tipos de arquivo não autorizados.

### 7.2. Navbar

Permitir administrar os links de navegação pública:

- texto exibido;
- destino;
- tipo de destino, como rota interna ou âncora;
- ordem de exibição;
- status ativo/inativo;
- indicação de abertura na mesma aba ou em nova aba, quando apropriado.

Preserve a segurança e valide destinos externos.

Não permita links com esquemas perigosos, como `javascript:`.

As rotas internas devem continuar funcionando quando o domínio mudar.

O administrador não deve precisar digitar `http://localhost:8000` para configurar links internos.

### 7.3. Hero principal

Permitir editar:

- título;
- subtítulo;
- texto do botão principal;
- destino do botão principal;
- texto do botão secundário;
- destino do botão secundário;
- imagem ou ilustração;
- imagem alternativa;
- status de publicação.

### 7.4. Seções da página inicial

Permitir editar as seções existentes, como:

- apresentação;
- como funciona;
- atendentes;
- cartas;
- dúvidas frequentes;
- trabalhe conosco;
- chamadas para ação;
- rodapé.

Para cada seção, permitir gerenciar, conforme necessário:

- título;
- subtítulo;
- conteúdo;
- imagem;
- ordem;
- status ativo/inativo.

Não transforme todo o conteúdo em um campo HTML livre.

Prefira campos estruturados e componentes Blade controlados.

### 7.5. Perguntas frequentes

Permitir:

- cadastrar pergunta;
- cadastrar resposta;
- editar;
- ativar ou desativar;
- ordenar;
- excluir quando permitido pelas regras de negócio.

Preserve a integridade dos dados e evite que o conteúdo seja renderizado sem a devida sanitização.

### 7.6. Imagens

Permitir que o administrador envie novas imagens para os conteúdos administráveis.

Requisitos:

- validar o tipo real do arquivo;
- limitar tamanho;
- limitar formatos aceitos;
- gerar nomes de arquivo seguros;
- evitar execução de conteúdo enviado;
- utilizar o mecanismo de armazenamento já implementado;
- validar permissões;
- mostrar pré-visualização;
- tratar falhas de upload;
- preservar imagens anteriores quando uma atualização falhar;
- impedir que usuários não autorizados alterem imagens.

Não armazene imagens em diretórios públicos sem verificar as necessidades de acesso e o comportamento do armazenamento atual.

Quando uma imagem pública precisar ser acessível pelo navegador, utilize o mecanismo público de storage do Laravel ou outra solução compatível com a arquitetura existente.

### 7.7. Pré-visualização e publicação

Se for compatível com a arquitetura atual, disponibilize uma pré-visualização do conteúdo antes da publicação.

Permita salvar alterações sem publicá-las imediatamente, desde que seja possível implementar essa separação de maneira consistente.

Se a arquitetura atual não suportar rascunhos, priorize um fluxo seguro de edição e publicação, sem introduzir complexidade desnecessária.

As alterações devem ser validadas antes de serem publicadas.

### 7.8. Auditoria

Registre, quando compatível com a arquitetura existente:

- usuário administrador responsável;
- data da alteração;
- conteúdo alterado;
- publicação ou despublicação.

Não armazene senhas, tokens ou segredos no histórico de auditoria.

Não permita que visitantes modifiquem o conteúdo administrativo.

---

# PARTE 5 — IMPLEMENTAÇÃO TÉCNICA DO CMS

## 8. Modelagem e persistência

Verifique se o projeto já possui tabelas de configurações ou gerenciamento de conteúdo.

Reutilize a estrutura existente quando apropriado.

Se for necessário criar novas tabelas, implemente migrations adequadas para representar o conteúdo administrável.

Não altere migrations já aplicadas de maneira incompatível com ambientes existentes.

Avalie a necessidade de entidades como:

- configurações do site;
- seções da página;
- itens de navegação;
- perguntas frequentes;
- imagens ou mídias;
- histórico de alterações.

Esses nomes são sugestões, não uma estrutura obrigatória.

Escolha a modelagem mais simples que atenda aos requisitos.

Evite criar tabelas genéricas excessivamente complexas.

Implemente models, relacionamentos, validações, policies e serviços quando necessários.

Não concentre toda a lógica do CMS nos controllers.

## 9. Segurança

O gerenciamento de conteúdo deve possuir:

- autenticação;
- autorização administrativa;
- validação no backend;
- proteção CSRF;
- proteção contra XSS;
- sanitização de conteúdo quando aplicável;
- validação de uploads;
- controle de destinos de navegação;
- tratamento adequado de erros.

Apenas administradores autorizados podem alterar os conteúdos.

Não dependa de verificações JavaScript para proteger operações.

Não permita que o CMS exponha informações financeiras, dados de clientes ou mensagens privadas.

---

# PARTE 6 — DESIGN SYSTEM E COMPONENTES

## 10. Componentes reutilizáveis

Crie ou reorganize componentes Blade para elementos repetidos, quando apropriado:

- navbar pública;
- footer;
- sidebar;
- cabeçalho de painel;
- cartão de indicador;
- cartão de atendente;
- cartão de consulta;
- cartão de carta de Tarot;
- gráfico;
- tabela;
- paginação;
- formulário;
- modal;
- alerta;
- badge de status;
- estado vazio;
- carregamento;
- mensagens de validação.

Padronize:

- espaçamento;
- tipografia;
- bordas;
- sombras;
- cores;
- estados de interação;
- tamanhos de controles;
- responsividade.

Evite duplicação excessiva de CSS e JavaScript.

Não introduza um sistema de componentes que complique desnecessariamente um projeto baseado em Blade.

## 11. Formulários e tabelas

Reformule os formulários e tabelas dos painéis existentes.

Formulários devem possuir:

- labels claros;
- campos adequados;
- indicação de obrigatoriedade;
- mensagens de validação;
- foco visível;
- estados de envio;
- tratamento de erros.

Tabelas devem possuir:

- cabeçalhos claros;
- alinhamento consistente;
- ações identificáveis;
- paginação;
- tratamento de ausência de registros;
- comportamento adequado em telas pequenas.

Não remova filtros, ações ou validações existentes.

## 12. Responsividade

A interface deve funcionar em:

- celulares;
- tablets;
- notebooks;
- monitores maiores.

Verifique especialmente:

- sidebar;
- navbar;
- dashboards;
- gráficos;
- tabelas;
- chat;
- seleção de cartas;
- formulários;
- modais.

Não resolva problemas móveis simplesmente reduzindo o tamanho de todos os elementos.

Reorganize os componentes conforme o espaço disponível.

## 13. Acessibilidade

Implemente ou preserve:

- contraste adequado;
- navegação por teclado;
- labels;
- textos alternativos;
- foco visível;
- semântica HTML;
- mensagens de erro acessíveis;
- botões com nomes claros;
- suporte a movimento reduzido.

Não utilize apenas cores para comunicar estados.

---

# PARTE 7 — REGRAS ESPECÍFICAS DO ARCANА TAROT

## 14. Chat e seleção de cartas

O redesign deve respeitar o funcionamento do chat existente.

Se o chat já estiver implementado, preserve:

- envio e recebimento de mensagens;
- atualizações em tempo real;
- histórico;
- autorização de acesso;
- controle da consulta ativa.

A interface do chat deve seguir a nova identidade visual sem comprometer a legibilidade.

As cartas enviadas pelo atendente devem aparecer como mensagens visuais, com imagem e nome da carta.

A seleção de cartas deve ser intuitiva, com pré-visualização e indicação clara da carta selecionada.

Não permita que o cliente selecione ou envie cartas como se fosse o atendente, a menos que essa funcionalidade esteja expressamente prevista nas regras existentes.

Não exponha imagens ou mensagens de consultas de outros usuários.

Se o chat ainda não estiver implementado, não invente uma implementação completa dentro desta tarefa visual. Registre a dependência e trabalhe somente nos componentes que possam ser preparados de forma coerente.

## 15. Financeiro e comissões

A reformulação dos dashboards não pode modificar as regras financeiras.

Preserve:

- origem dos créditos;
- consumo de créditos;
- regras de cobrança;
- histórico financeiro;
- comissões;
- estados de pagamentos;
- integridade dos registros.

Os gráficos e cartões financeiros devem consumir os dados reais disponíveis.

Não crie valores fixos para simular receita, ganhos ou saldo.

---

# PARTE 8 — TESTES E VALIDAÇÃO

## 16. Testes automatizados

Crie ou atualize testes para verificar, conforme a implementação existente:

1. A página pública carrega corretamente.
2. A navbar utiliza destinos válidos.
3. O conteúdo público é carregado a partir das configurações persistidas.
4. O administrador consegue editar conteúdo autorizado.
5. Um cliente não consegue acessar o CMS.
6. Um atendente não consegue acessar operações administrativas.
7. Os uploads de imagem respeitam as validações.
8. Conteúdo não autorizado ou malicioso não é renderizado de forma insegura.
9. As dashboards exibem dados compatíveis com o usuário autenticado.
10. Os dados financeiros não são misturados entre usuários.
11. Os gráficos apresentam corretamente o estado sem dados.
12. Os componentes responsivos não introduzem regressões nas funcionalidades existentes.
13. As rotas e permissões anteriores continuam funcionando.
14. O chat mantém seu comportamento anterior.
15. O sistema compila os assets corretamente.

Execute os testes existentes e os novos testes pertinentes.

Não altere testes apenas para fazê-los passar sem corrigir o problema real.

Não declare testes aprovados sem executá-los.

## 17. Verificação visual

Inspecione as páginas implementadas em navegador, se a infraestrutura de desenvolvimento permitir.

Verifique:

- página inicial;
- página de atendentes;
- trabalhe conosco;
- login e cadastro;
- dashboard do cliente;
- dashboard do atendente;
- dashboard administrativo;
- gerenciamento de conteúdo;
- formulários e tabelas;
- chat, caso já esteja implementado.

Verifique pelo menos uma resolução móvel e uma resolução desktop.

Corrija problemas evidentes de alinhamento, contraste, overflow, navegação ou hierarquia visual.

Se não for possível abrir um navegador, registre essa limitação e execute as verificações alternativas disponíveis.

---

# PARTE 9 — DOCUMENTAÇÃO

## 18. Documentar o redesign

Crie o arquivo:

docs/REDESIGN_VISUAL_E_CMS.md

Documente:

- identidade visual;
- paleta de cores;
- tipografia;
- componentes criados;
- layouts dos painéis;
- organização das sidebars;
- indicadores e gráficos implementados;
- funcionalidades do CMS;
- estrutura de persistência;
- gerenciamento de imagens;
- permissões;
- testes executados;
- limitações conhecidas.

Explique como o administrador pode editar e publicar o conteúdo da página inicial.

Atualize o README principal com um link para essa documentação.

Não duplique todo o documento no README.

---

# PARTE 10 — CRITÉRIOS DE CONCLUSÃO

## 19. Definição de pronto

A tarefa só estará concluída quando:

- a identidade visual estiver consistente;
- a página pública tiver sido reformulada;
- a navbar funcionar corretamente;
- os painéis possuírem layouts organizados com sidebar;
- as dashboards utilizarem dados reais disponíveis;
- os gráficos apresentarem estados vazios e valores corretos;
- o CMS permitir editar os conteúdos públicos previstos;
- as imagens puderem ser gerenciadas com segurança;
- as permissões administrativas estiverem protegidas;
- as funcionalidades existentes tiverem sido preservadas;
- os testes pertinentes tiverem sido executados;
- a documentação Markdown estiver criada;
- o README apontar para a documentação;
- as limitações ainda existentes estiverem registradas.

## 20. Relatório final obrigatório

Ao terminar, apresente:

1. Resumo das alterações.
2. Arquivos criados e modificados.
3. Páginas reformuladas.
4. Dashboards implementadas.
5. Gráficos implementados e suas fontes de dados.
6. Funcionalidades de CMS implementadas.
7. Migrations adicionadas, se houver.
8. Testes executados e resultados reais.
9. Problemas encontrados e corrigidos.
10. Pendências que dependem de funcionalidades inexistentes.
11. Caminho do documento `docs/REDESIGN_VISUAL_E_CMS.md`.
12. Comandos necessários para instalar dependências, executar migrations e compilar os assets, quando aplicável.

Não faça deploy.

Não altere configurações de produção.

Não execute comandos destrutivos no banco.

Não implemente funcionalidades alheias ao escopo.

Execute somente este prompt e aguarde a próxima instrução após apresentar o relatório final.
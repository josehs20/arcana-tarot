# Prompt 17 — Histórico e dashboard administrativo

Finalize telas operacionais mínimas para o MVP.

## Cliente
- Histórico das próprias consultas com data, duração, estado e valor consumido.
- Histórico das próprias recargas e lançamentos.
- Detalhe de consulta sem expor informações de terceiros.

## Atendente
- Histórico das próprias consultas.
- Duração, percentual de comissão aplicado, base de cálculo e ganhos calculados a partir dos registros oficiais.
- Distinguir valores ganhos, estornos e valores líquidos acumulados.
- Não permitir edição manual dos ganhos ou da taxa histórica.

## Administrador
- Dashboard com total de clientes, atendentes online/ocupados, consultas ativas/concluídas, recargas confirmadas, candidaturas pendentes, comissões acumuladas e receita calculada conforme definição documentada.
- Gestão e revisão de candidaturas da área Trabalhe conosco.
- Lista financeira de comissões por atendente/consulta, mostrando base, percentual congelado, valor da comissão, ajustes/estornos e status de lançamento.
- Listagem e filtros simples de usuários, consultas e pagamentos.
- Acesso a logs financeiros/auditoria segundo permissões.
- Não mostrar conteúdo de mensagens por padrão; qualquer acesso excepcional deve ser autorizado, justificado e auditado.

## Regras
- Indicadores devem ser calculados a partir de dados reais.
- Distinguir receita bruta de consultas, comissões dos atendentes e receita líquida operacional antes de impostos/taxas de terceiros; não chamar o último valor de lucro líquido.
- As comissões são lançamentos contábeis internos do MVP; não implementar transferência bancária/pagamento ao atendente sem escopo específico.
- Não criar números fictícios em ambiente de produção.
- Paginação e validação dos filtros.
- Proteger todas as rotas.
- Criar estados vazios e mensagens de erro úteis.
- Testar isolamento de dados entre usuários.

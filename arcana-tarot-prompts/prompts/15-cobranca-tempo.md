# Prompt 15 — Cobrança por tempo e encerramento automático

Implemente cobrança por tempo de consulta usando as sessões, carteira e lançamentos já criados.

## Antes de codificar
- Leia `docs/DECISIONS.md`.
- Defina/documente a regra de cobrança. Para o MVP, proponha cobrança por minuto completo ou por blocos e escolha a regra mais simples; não invente preço final. Preço por minuto deve ser configurável pelo administrador.
- Explicite o tratamento de frações, quando começa a primeira cobrança, saldo insuficiente, falha de conexão e encerramento manual.
- Solicite decisão do usuário somente se existir ambiguidade que impeça implementação segura; caso contrário, escolha uma regra conservadora e registre.

## Requisitos
- Início oficial no servidor após aceitação da consulta.
- O browser mostra cronômetro informativo; não calcula o débito autoritativo.
- Cobrar em intervalos idempotentes com registro de cada lançamento e referência à sessão/intervalo.
- Proteger concorrência e impedir duplicidade.
- Nunca permitir saldo negativo.
- Se o saldo não for suficiente para o próximo intervalo, encerrar de forma idempotente antes de gerar débito inválido e avisar as partes.
- Encerrar cobrança imediatamente ao finalizar a sessão.
- Garantir que tarefas atrasadas/retries não cobrem períodos após o fim da sessão.
- Implementar uma estratégia de processamento confiável (fila/scheduler ou cálculo transacional em eventos definidos), documentando limitações.
- Não manter cobrança indefinida durante desconexão.

## Testes críticos
- Sessão inicia e encerra.
- Cobrança repetida não duplica.
- Saldo insuficiente.
- Encerramento concorrente.
- Falha/retry de worker.
- Desconexão e reconexão.
- Valor e duração consistentes.

/**
 * Intenção de navegação do atendimento.
 *
 * O guard de saída de `consultation-presence.js` usa `beforeunload` para avisar
 * quando a pessoa pode estar deixando o chat sem querer. O diálogo exibido é o
 * nativo do navegador e não pode ser personalizado, então a única forma de
 * evitar que ele apareça em ações planejadas é sinalizar antes da navegação.
 *
 * Este módulo é o ponto único desse sinal: quem vai navegar ou recarregar de
 * propósito (encerrar consulta, sair da conta, redirecionamento automático
 * depois do fim do atendimento) chama `markIntentionalNavigation()` e o
 * `beforeunload` deixa a página sair em silêncio.
 *
 * O estado vive em memória, por aba, e expira rápido: uma navegação abortada
 * não deixa o guard desligado até o próximo carregamento.
 */

const INTENT_TTL_MS = 15000;

let markedAt = 0;

/**
 * Declara que a próxima navegação desta aba é intencional.
 */
export function markIntentionalNavigation() {
    markedAt = Date.now();
}

/**
 * `true` enquanto a última marcação ainda vale.
 */
export function hasIntentionalNavigation() {
    return markedAt > 0 && Date.now() - markedAt < INTENT_TTL_MS;
}

/**
 * Descarta a marcação. Chamado ao descarregar a página e ao voltar do bfcache,
 * para que nenhuma navegação futura herde a intenção de uma anterior.
 */
export function clearIntentionalNavigation() {
    markedAt = 0;
}

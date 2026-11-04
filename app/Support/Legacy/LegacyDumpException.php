<?php

namespace App\Support\Legacy;

use RuntimeException;

/**
 * Falha ao ler ou interpretar o dump SQL legado.
 *
 * A mensagem sempre descreve o problema em português e nunca inclui trechos
 * de dados pessoais do arquivo.
 */
final class LegacyDumpException extends RuntimeException {}

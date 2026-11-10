<?php

namespace App\Support\Legacy;

/**
 * Tabelas do banco legado que o Arcana importa.
 *
 * A migração é de um banco único (`astros21`), então o mapeamento das tabelas é
 * fixo em vez de configurável por linha de comando. O
 * `LegacySchemaInspector` valida estes nomes contra o dump: se o `CREATE TABLE`
 * não estiver presente, a importação é bloqueada com a mensagem indicando qual
 * tabela exportar.
 *
 * Dentro de cada tabela as colunas continuam sendo identificadas por heurística
 * (nome do arquivo), sem exigir análise manual do `CREATE TABLE`.
 *
 * Se o legado mudar de nome, altere apenas as constantes abaixo.
 */
final class LegacySchemaPreset
{
    /** Credenciais e identificação do usuário (`email`, `senha`, `nome`, `id`). */
    public const USERS_TABLE = 'eam_usuarios';

    /** Dados cadastrais complementares (`usuario_id`, `telefone`, `data_nascimento`, `sexo`). */
    public const PROFILE_TABLE = 'eam_clientes';

    /** Usuários que não devem ser importados como cliente (atendentes têm fluxo próprio). */
    public const EXCLUDE_TABLE = 'eam_atendentes';

    /**
     * Colunas que identificam cada tabela quando o arquivo não informa o nome
     * dela — é o caso da exportação CSV, que traz apenas os blocos de registros.
     *
     * Cada tabela lista grupos de colunas e **todos** os grupos precisam ter ao
     * menos uma coluna presente no bloco. A ordem das entradas é também a
     * prioridade de desempate quando um bloco casa com mais de uma assinatura.
     *
     * @var array<string, array<int, array<int, string>>>
     */
    public const SIGNATURES = [
        self::USERS_TABLE => [
            ['email', 'e_mail', 'mail', 'email_principal'],
            ['senha', 'password', 'senha_hash', 'password_hash', 'passwd'],
        ],
        self::EXCLUDE_TABLE => [
            ['usuario_id', 'user_id', 'id_usuario'],
            ['comissao'],
        ],
        self::PROFILE_TABLE => [
            ['usuario_id', 'user_id', 'id_usuario', 'cliente_id', 'id_cliente', 'email', 'e_mail'],
            [
                'telefone', 'phone', 'celular', 'fone', 'whatsapp',
                'data_nascimento', 'nascimento', 'dt_nascimento', 'birth_date',
                'sexo', 'genero', 'gender',
            ],
        ],
    ];

    /**
     * Tabelas do legado na ordem em que são procuradas no arquivo.
     *
     * @return array<int, string>
     */
    public static function tableNames(): array
    {
        return [self::USERS_TABLE, self::PROFILE_TABLE, self::EXCLUDE_TABLE];
    }
}

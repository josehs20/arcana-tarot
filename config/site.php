<?php

/**
 * Conteúdo padrão e limites da área "Conteúdo do site".
 *
 * O banco guarda apenas o que o administrador editou. Enquanto não houver
 * registro, o site usa estes valores — o CMS funciona sem seed obrigatório.
 */
return [
    // Rotas internas que podem ser usadas como destino de links e CTAs.
    // A allowlist evita rotas que exigem parâmetros (ex.: consultas.show).
    'allowed_routes' => [
        'home',
        'attendants.index',
        'pages.how-it-works',
        'pages.faq',
        'work-with-us.index',
        'contact',
        'legal.terms',
        'legal.privacy',
        'login',
        'register',
    ],

    // Âncoras existentes na home. Mantidas para links antigos criados no
    // CMS antes da criação das páginas dedicadas.
    'allowed_anchors' => [
        'inicio',
        'como-funciona',
        'atendentes',
        'cartas',
        'trabalhe-conosco',
        'faq',
    ],

    'section_labels' => [
        'hero' => 'Topo da home',
        'attendants' => 'Atendentes',
        'how_it_works' => 'Como funciona',
        'tarot_cards' => 'Cartas e baralhos',
        'faq' => 'Perguntas frequentes',
        'work_with_us' => 'Trabalhe conosco',
        'final_cta' => 'Chamada final',
    ],

    // Âncoras usadas pelo link "Ver no site" de cada seção. Seções sem
    // âncora apontam para o topo da home.
    'section_anchors' => [
        'hero' => 'inicio',
        'attendants' => 'atendentes',
        'how_it_works' => 'como-funciona',
        'tarot_cards' => 'cartas',
        'faq' => 'faq',
        'work_with_us' => 'trabalhe-conosco',
    ],

    // Aviso exibido no formulário da seção quando parte do conteúdo exibido na
    // home vem de outra área do painel, e não dos campos de texto desta tela.
    'section_hints' => [
        'attendants' => 'Os perfis listados vêm do diretório de atendentes. Aqui você edita o cabeçalho, o texto e a imagem da seção.',
        'tarot_cards' => 'Os baralhos listados são gerenciados em Baralhos. Aqui você edita o cabeçalho, o texto e a imagem da seção.',
        'faq' => 'As perguntas listadas são gerenciadas em Perguntas frequentes. Aqui você edita o cabeçalho, o texto e a imagem da seção.',
    ],

    'sections' => [
        'hero' => [
            'eyebrow' => 'Leitura de cartas · Oráculo · Conselhos',
            'title' => 'As respostas que você busca já esperam na mesa.',
            'subtitle' => 'Converse com atendentes especializados em tarô, tarô cigano e cartas ancestrais. Escolha quem combina com a sua pergunta, agende e receba a leitura completa em um ambiente reservado.',
            'body' => 'Ambiente reservado, valor por minuto exibido antes da solicitação e perfis analisados pela curadoria.',
            'image_alt' => null,
            'primary_cta_label' => 'Começar agora',
            'primary_cta_target' => 'register',
            'secondary_cta_label' => 'Entrar na conta',
            'secondary_cta_target' => 'login',
            'sort_order' => 10,
        ],
        'how_it_works' => [
            'eyebrow' => 'Como funciona',
            'title' => 'Três passos, uma leitura',
            'subtitle' => 'Do agendamento à mensagem final, o caminho é simples e transparente.',
            'body' => null,
            'image_alt' => null,
            'primary_cta_label' => null,
            'primary_cta_target' => null,
            'secondary_cta_label' => null,
            'secondary_cta_target' => null,
            'sort_order' => 30,
        ],
        'attendants' => [
            'eyebrow' => 'Atendentes',
            'title' => 'Quem está online agora',
            'subtitle' => 'Perfis publicados, especialidades e disponibilidade vêm direto do servidor. Escolha com quem conversar.',
            'body' => null,
            'image_alt' => null,
            'primary_cta_label' => 'Ver diretório completo',
            'primary_cta_target' => 'attendants.index',
            'secondary_cta_label' => null,
            'secondary_cta_target' => null,
            'sort_order' => 20,
        ],
        'tarot_cards' => [
            'eyebrow' => 'Cartas e baralhos',
            'title' => 'Tradições que abrem caminhos diferentes',
            'subtitle' => 'Cada baralho tem uma linguagem própria. Veja as cartas usadas pelos atendentes e escolha a leitura que combina com a sua pergunta.',
            'body' => 'Os baralhos abaixo fazem parte do catálogo do Arcana Tarot. A escolha final é sempre do atendente, conforme o método que ele domina.',
            'image_alt' => null,
            'primary_cta_label' => 'Conversar com um atendente',
            'primary_cta_target' => 'attendants.index',
            'secondary_cta_label' => null,
            'secondary_cta_target' => null,
            'sort_order' => 40,
        ],
        'work_with_us' => [
            'eyebrow' => 'Para profissionais',
            'title' => 'Seu dom também tem lugar aqui',
            'subtitle' => 'Se você lê cartas e quer atender em um ambiente reservado, conheça o processo de candidatura do Arcana Tarot.',
            'body' => 'Enviar uma candidatura não garante aprovação automática; cada perfil passa por análise.',
            'image_alt' => null,
            'primary_cta_label' => 'Trabalhe conosco',
            'primary_cta_target' => 'work-with-us.index',
            'secondary_cta_label' => null,
            'secondary_cta_target' => null,
            'sort_order' => 60,
        ],
        'faq' => [
            'eyebrow' => 'Dúvidas',
            'title' => 'Antes de perguntar',
            'subtitle' => 'As perguntas mais comuns de quem está chegando. Se a sua não estiver aqui, a equipe de atendimento ajuda.',
            'body' => null,
            'image_alt' => null,
            'primary_cta_label' => 'Falar com a equipe',
            'primary_cta_target' => 'contact',
            'secondary_cta_label' => null,
            'secondary_cta_target' => null,
            'sort_order' => 50,
        ],
        'final_cta' => [
            'eyebrow' => null,
            'title' => 'Sua próxima resposta começa aqui',
            'subtitle' => 'Crie sua conta gratuita e converse com o primeiro atendente em poucos minutos.',
            'body' => null,
            'image_alt' => null,
            'primary_cta_label' => 'Criar conta grátis',
            'primary_cta_target' => 'register',
            'secondary_cta_label' => 'Já tenho conta',
            'secondary_cta_target' => 'login',
            'sort_order' => 70,
        ],
    ],

    'navigation' => [
        'primary' => [
            ['label' => 'Início', 'target_type' => 'route', 'target' => 'home'],
            ['label' => 'Atendentes', 'target_type' => 'route', 'target' => 'attendants.index'],
            ['label' => 'Como funciona', 'target_type' => 'route', 'target' => 'pages.how-it-works'],
            ['label' => 'Dúvidas', 'target_type' => 'route', 'target' => 'pages.faq'],
            ['label' => 'Trabalhe conosco', 'target_type' => 'route', 'target' => 'work-with-us.index'],
        ],
        'footer' => [
            ['label' => 'Início', 'target_type' => 'route', 'target' => 'home'],
            ['label' => 'Atendentes', 'target_type' => 'route', 'target' => 'attendants.index'],
            ['label' => 'Como funciona', 'target_type' => 'route', 'target' => 'pages.how-it-works'],
            ['label' => 'Dúvidas frequentes', 'target_type' => 'route', 'target' => 'pages.faq'],
            ['label' => 'Trabalhe conosco', 'target_type' => 'route', 'target' => 'work-with-us.index'],
            ['label' => 'Contato', 'target_type' => 'route', 'target' => 'contact'],
        ],
    ],

    'faqs' => [
        [
            'question' => 'Preciso saber de tarô?',
            'answer' => 'Não. O método é com o atendente: alguns preferem conduzir a leitura, outros pedem apenas o tema e deixam a interpretação livre.',
        ],
        [
            'question' => 'Como funciona a cobrança?',
            'answer' => 'A consulta é contratada no ato do agendamento e o valor aparece claro antes de confirmar.',
        ],
        [
            'question' => 'Posso cancelar a consulta?',
            'answer' => 'Sim. Se a consulta ainda não começou, o cancelamento é liberado integralmente pela central de atendimento.',
        ],
        [
            'question' => 'Minhas mensagens ficam privadas?',
            'answer' => 'Sim. Todo o histórico da leitura fica visível apenas para você e para o atendente que a conduz.',
        ],
    ],

    // Identidade visual editável.
    'profile_keys' => [
        'site_name' => 'Arcana Tarot',
        'site_description' => 'Consultas de tarô online com atendentes especializados. Conecte-se com o místico.',
        'site_logo_path' => null,
        'site_favicon_path' => null,
    ],

    // Uploads do CMS ficam no disco público (não são conteúdo sensível).
    'image_directory' => 'site',
    'allowed_image_mimes' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ],
    'max_image_kb' => 2048,
];

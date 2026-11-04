<?php

namespace App\Support\Tarot;

use Illuminate\Support\Str;

/**
 * Catálogo inicial de baralhos reais com as respectivas frentes de carta.
 *
 * Cada carta é descrita como [nome, significado, significado invertido]. O
 * arquivo da imagem é o slug do nome, dentro do diretório do baralho em
 * database/seeders/assets/tarot. Os scans são digitalizações em domínio
 * público: o Tarot de Marseille de Jean Dodal (Lyon, 1701-1715) e uma
 * litografia oitocentista do Petit Lenormand.
 *
 * É dado de produto, não de demonstração: a seeder e o comando
 * `arcana:import-tarot-catalog` leem esta mesma fonte, e o comando pode ser
 * executado em produção.
 */
final class TarotCatalog
{
    /**
     * Arcanos maiores do Tarot de Marseille, do Louco (0) ao Mundo (21).
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const MARSEILLE_MAJORS = [
        ['O Louco', 'Impulso de começar, fé no desconhecido e liberdade de escolher o próprio caminho.', 'Ingenuidade, dispersão e fuga de responsabilidades.'],
        ['O Bateleur', 'Iniciativa, habilidade manual e recursos disponíveis para realizar o projeto.', 'Improviso sem método, esperteza vã e promessas que não se sustentam.'],
        ['A Papisa', 'Estudo, discrição e escuta da intuição antes de se pronunciar.', 'Isolamento, desconfiança excessiva e intuição ignorada.'],
        ['A Imperatriz', 'Criação, fertilidade e cuidado que faz o projeto crescer.', 'Dependência afetiva, ciúme estéril e cuidado sufocante.'],
        ['O Imperador', 'Estrutura, autoridade e limites claros que sustentam a obra.', 'Tirania, rigidez e dificuldade de delegar.'],
        ['O Papa', 'Orientação, transmissão de saber e confiança em quem ensina.', 'Dogmatismo, conselho duvidoso e falta de referência.'],
        ['O Namorado', 'Escolha afetiva consciente e alinhamento entre desejo e valores.', 'Indecisão, triangulação e escolha movida pela aparência.'],
        ['O Carro', 'Progresso, domínio do próprio rumo e vitória no movimento.', 'Pressa, rumo tomado por impulso e obstáculo no caminho.'],
        ['A Justiça', 'Equilíbrio, honestidade e decisão baseada em fatos.', 'Injustiça, processo travado e decisão parcial.'],
        ['O Eremita', 'Pausa para reflexão, prudência e busca de sentido.', 'Isolamento prolongado, atraso e recusa de ajuda.'],
        ['A Roda da Fortuna', 'Mudança de ciclo e oportunidade que chega sem aviso.', 'Repetição de padrões, resistência à mudança e azar momentâneo.'],
        ['A Força', 'Coragem serena, autocontrole e domínio dos impulsos.', 'Força usada com violência, desânimo e perda de autoridade.'],
        ['O Enforcado', 'Suspensão voluntária, outro ponto de vista e entrega.', 'Estagnação, sacrifício inútil e teimosia.'],
        ['A Morte', 'Fim de um ciclo, transformação necessária e limpeza.', 'Resistência ao fim, luto arrastado e mudança evitada.'],
        ['A Temperança', 'Equilíbrio, paciência e medida certa entre os extremos.', 'Excesso, impaciência e perda de medida.'],
        ['O Diabo', 'Desejo intenso, apego material e sombra que pede para ser encarada.', 'Libertação de um vício ou laço que se aperta até romper.'],
        ['A Torre', 'Ruptura súbita, queda do que era falso e reconstrução.', 'Crise adiada e estrutura frágil que ainda resiste.'],
        ['A Estrela', 'Esperança, inspiração e caminho que se abre com clareza.', 'Desânimo, promessa irrealista e inspiração bloqueada.'],
        ['A Lua', 'Imaginação, sonhos e emoções que pedem escuta.', 'Ilusão, medo sem forma e confusão.'],
        ['O Sol', 'Sucesso, vitalidade e clareza nas relações.', 'Vaidade, alegria adiada e brilho ofuscado.'],
        ['O Julgamento', 'Chamado, renovação e decisão que muda o rumo.', 'Autocrítica paralisante, culpa e recusa do chamado.'],
        ['O Mundo', 'Realização, viagem e ciclo concluído com maestria.', 'Ciclo incompleto, fuga do mundo e reconhecimento adiado.'],
    ];

    /**
     * Posições do Ás ao Rei, na ordem em que as cartas numeradas e as figuras
     * aparecem no baralho.
     *
     * @var list<string>
     */
    private const MARSEILLE_RANKS = [
        'Ás',
        'Dois',
        'Três',
        'Quatro',
        'Cinco',
        'Seis',
        'Sete',
        'Oito',
        'Nove',
        'Dez',
        'Valete',
        'Cavaleiro',
        'Rainha',
        'Rei',
    ];

    /**
     * Significados dos naipes menores, na ordem de self::MARSEILLE_RANKS.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const MARSEILLE_SUITS = [
        'Paus' => [
            ['Faísca inicial, impulso criativo e força para começar.', 'Energia dispersa e projeto que não engata.'],
            ['Parceria que abre caminho e acordo em construção.', 'Desacordo por falta de escuta.'],
            ['Primeiros resultados e expansão cuidadosa.', 'Atraso por falta de planejamento.'],
            ['Base firme, casa em ordem e colheita do que foi construído.', 'Instabilidade doméstica e bases abaladas.'],
            ['Movimento, contato social e ação física.', 'Correria sem direção e atrito.'],
            ['Reconhecimento do caminho já percorrido.', 'Orgulho e mérito contestado.'],
            ['Desafio enfrentado com coragem e defesa da própria posição.', 'Desistência diante do esforço.'],
            ['Avanço rápido e notícias que aceleram decisões.', 'Pressa que atropela e mensagem desencontrada.'],
            ['Persistência no fim da jornada, energia para concluir.', 'Cansaço acumulado e esforço tardio.'],
            ['Conquista madura e o peso do que se carrega.', 'Sobrecarga e excesso de responsabilidade.'],
            ['Curiosidade ativa, notícia nova e jovem inconstante.', 'Impulsividade juvenil e palavras vazias.'],
            ['Mudança veloz, movimento e decisão firme.', 'Pressa desastrada e direção instável.'],
            ['Criatividade madura, autonomia e hospitalidade.', 'Intensidade sem medida e cansaço.'],
            ['Autoridade criativa e visão de longo prazo.', 'Arrogância e imposição sem escuta.'],
        ],
        'Copas' => [
            ['Nascimento do afeto e coração aberto.', 'Emoção reprimida e bloqueio afetivo.'],
            ['Troca sincera e parceria amorosa em formação.', 'Desencontro e ciúme.'],
            ['Celebração, amizade e alegria compartilhada.', 'Excesso, fofoca e união que desgasta.'],
            ['Pausa afetiva e necessidade de estabilidade.', 'Tédio, acomodação e oportunidade ignorada.'],
            ['Desilusão, luto e o que resta depois da perda.', 'Reconciliação e retorno de algo perdido.'],
            ['Memória afetiva, nostalgia e reencontro.', 'Passado que prende e saudade estéril.'],
            ['Escolhas afetivas, sonho e fantasia.', 'Ilusão sobre sentimentos e decisão postergada.'],
            ['Partida em busca de outra vida e desapego.', 'Retorno e medo de partir.'],
            ['Satisfação pessoal, prazer e confiança no próprio desejo.', 'Vaidade e excesso.'],
            ['Família, casa emocional e bênção duradoura.', 'Rompimento familiar e harmonia perdida.'],
            ['Mensagem sentimental e gesto delicado.', 'Sedução sem compromisso e imaturidade afetiva.'],
            ['Proposta afetiva e aproximação romântica.', 'Promessa não cumprida e instabilidade.'],
            ['Empatia, escuta e intuição madura.', 'Drama e insegurança afetiva.'],
            ['Serenidade, generosidade e maturidade emocional.', 'Frieza e mágoa guardada.'],
        ],
        'Espadas' => [
            ['Clareza mental, decisão cortante e verdade dita.', 'Confusão mental e palavra hostil.'],
            ['Escolha entre duas opções, trégua e neutralidade.', 'Indecisão crônica e recusa de olhar a verdade.'],
            ['Separação que dói e afastamento necessário.', 'Dor que se arrasta e reconciliação mal resolvida.'],
            ['Descanso, retiro e recolhimento.', 'Inquietação e isolamento imposto.'],
            ['Divisão, conflito e orgulho ferido.', 'Trégua e reconciliação com perdas.'],
            ['Caminho aberto, viagem e pensamento em transformação.', 'Rota travada e passado difícil de deixar.'],
            ['Estratégia, discrição e astúcia.', 'Armadilha e esperteza que se volta contra.'],
            ['Cerco, saída estreita e olhar limitado.', 'Libertação e saída encontrada.'],
            ['Insônia, angústia e ansiedade mental.', 'Alívio e pesadelo que se resolve.'],
            ['Fim de um ciclo doloroso e entrega.', 'Ciclo que se repete e recusa do fim.'],
            ['Curiosidade, mensagem reveladora e vigilância.', 'Fofoca e informação distorcida.'],
            ['Ação decidida e defesa de uma causa.', 'Ataque impulsivo e palavra violenta.'],
            ['Lucidez, limites claros e honestidade.', 'Frieza e crítica destrutiva.'],
            ['Justiça, autoridade intelectual e decisão serena.', 'Abuso de poder e julgamento sem empatia.'],
        ],
        'Ouros' => [
            ['Oferta concreta, semente material e chance de prosperar.', 'Investimento precipitado e ganho que escapa.'],
            ['Equilíbrio financeiro, troca justa e flexibilidade.', 'Desorganização material e dívida que cresce.'],
            ['Primeiros frutos do trabalho e reconhecimento prático.', 'Esforço sem retorno e trabalho desperdiçado.'],
            ['Segurança material, reserva e posse consolidada.', 'Apego, mesquinhez e medo de perder.'],
            ['Falta momentânea, desgaste material e dependência.', 'Recuperação e ajuda que chega.'],
            ['Generosidade, partilha e prosperidade distribuída.', 'Dívida, caridade interessada e desequilíbrio.'],
            ['Colheita e paciência com o tempo das coisas.', 'Ansiedade, resultado adiado e impaciência.'],
            ['Trabalho artesanal, ofício e resultado do esforço.', 'Rotina sem sentido e perfeccionismo travado.'],
            ['Autossuficiência, renda estável e conquista pessoal.', 'Ganho instável e dependência financeira.'],
            ['Herança, patrimônio e solidez construída no tempo.', 'Perda material e patrimônio em disputa.'],
            ['Aprendiz aplicado, estudo que rende e proposta concreta.', 'Preguiça e promessa material não cumprida.'],
            ['Progresso lento e constante, responsabilidade assumida.', 'Estagnação, teimosia e rotina pesada.'],
            ['Administração generosa, segurança e acolhimento prático.', 'Materialismo, controle e cansaço.'],
            ['Prosperidade consolidada, gestão madura e estabilidade.', 'Avareza e abuso de poder econômico.'],
        ],
    ];

    /**
     * Petit Lenormand: nome, palavra-chave e advertência da carta invertida.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const LENORMAND_CARDS = [
        ['Cavaleiro', 'Notícia que chega rápido, visita e mensagem de longe.', 'Notícia atrasada e visita que não vem.'],
        ['Trevo', 'Pequena sorte, alívio passageiro e chance rápida.', 'Sorte adiada e oportunidade perdida.'],
        ['Navio', 'Viagem, comércio, distância e estrangeiro.', 'Viagem suspensa e negócio travado.'],
        ['Casa', 'Lar, família, estabilidade e propriedade.', 'Instabilidade doméstica e mudança de endereço.'],
        ['Árvore', 'Saúde, crescimento lento e raízes firmes.', 'Saúde a cuidar e crescimento estagnado.'],
        ['Nuvens', 'Confusão, dúvida e clima passageiro.', 'Esclarecimento e nuvens que se dissipam.'],
        ['Serpente', 'Sedução, rival e desvio de caminho.', 'Perigo evitado e rival revelado.'],
        ['Caixão', 'Pausa, encerramento e luto.', 'Melhora lenta e retomada.'],
        ['Buquê', 'Convite, gentileza e presente.', 'Convite recusado e gentileza mal interpretada.'],
        ['Foice', 'Corte, decisão rápida e colheita.', 'Corte que fere e ruptura desnecessária.'],
        ['Chicote', 'Repetição, esforço físico e discussão.', 'Conflito cessado e rotina retomada.'],
        ['Pássaros', 'Conversas, ansiedade e contatos rápidos.', 'Conversa inconveniente e nervosismo.'],
        ['Criança', 'Início, inocência e algo pequeno.', 'Infantilidade e atraso no começo.'],
        ['Raposa', 'Astúcia, cautela e trabalho.', 'Trapaça desmascarada e confiança recuperada.'],
        ['Urso', 'Força, proteção e poder.', 'Força excessiva e proteção sufocante.'],
        ['Estrela', 'Inspiração, esperança e clareza noturna.', 'Desânimo e plano que se desfaz.'],
        ['Cegonha', 'Mudança, renovação e gravidez.', 'Mudança adiada e resistência ao novo.'],
        ['Cão', 'Amizade leal, confiança e companhia.', 'Amizade em dúvida e abandono.'],
        ['Torre', 'Instituição, isolamento e autoridade.', 'Solidão e estrutura que se fecha.'],
        ['Jardim', 'Encontro, vida social e espaço público.', 'Desencontro e exposição indesejada.'],
        ['Montanha', 'Obstáculo, atraso e bloqueio.', 'Obstáculo superado e caminho que se abre.'],
        ['Caminhos', 'Escolha, alternativa e decisão.', 'Escolha forçada e direção imposta.'],
        ['Ratos', 'Perda, desgaste e roubo.', 'Perda contida e recuperação.'],
        ['Coração', 'Amor, sentimento sincero e reconciliação.', 'Mágoa e amor em dúvida.'],
        ['Anel', 'Compromisso, contrato e aliança.', 'Compromisso rompido e ciclo que se encerra.'],
        ['Carta', 'Documento, notícia por escrito e comunicação.', 'Notícia atrasada e documento pendente.'],
        ['Livro', 'Segredo, estudo e informação oculta.', 'Segredo revelado e estudo concluído.'],
        ['Homem', 'O consultante ou o homem central da questão.', 'Homem que se afasta e perde influência.'],
        ['Mulher', 'A consultante ou a mulher central da questão.', 'Mulher que se afasta e perde influência.'],
        ['Lírios', 'Paz, maturidade e apoio antigo.', 'Conflito familiar e paz abalada.'],
        ['Sol', 'Sucesso, energia e reconhecimento.', 'Sucesso adiado e energia baixa.'],
        ['Lua', 'Reconhecimento, emoção e honra.', 'Fama passageira e ilusão.'],
        ['Chave', 'Solução, abertura e certeza.', 'Porta fechada e solução adiada.'],
        ['Peixes', 'Dinheiro, autonomia e fluxo.', 'Ganho instável e negócio arriscado.'],
        ['Âncora', 'Estabilidade, trabalho e permanência.', 'Estagnação e peso que prende.'],
        ['Cruz', 'Destino, provação e fé.', 'Peso aliviado e provação encerrada.'],
    ];

    /**
     * Baralhos do catálogo inicial, na ordem em que aparecem no seletor.
     *
     * @return list<array{name: string, slug: string, description: string, assets: string, cards: list<array{name: string, slug: string, upright: string, reversed: string}>}>
     */
    public static function decks(): array
    {
        return [
            [
                'name' => 'Tarot de Marseille',
                'slug' => 'tarot-de-marseille',
                'description' => 'Baralho de 78 cartas do Tarot de Marseille de Jean Dodal (Lyon, 1701-1715), com imagens digitalizadas em domínio público.',
                'assets' => 'marseille',
                'cards' => self::marseilleCards(),
            ],
            [
                'name' => 'Lenormand',
                'slug' => 'lenormand',
                'description' => 'Oráculo Petit Lenormand de 36 cartas, com litografia oitocentista em domínio público e leitura por palavra-chave.',
                'assets' => 'lenormand',
                'cards' => self::lenormandCards(),
            ],
        ];
    }

    /**
     * @return list<array{name: string, slug: string, upright: string, reversed: string}>
     */
    private static function marseilleCards(): array
    {
        $cards = [];

        foreach (self::MARSEILLE_MAJORS as [$name, $upright, $reversed]) {
            $cards[] = self::card($name, $upright, $reversed);
        }

        foreach (self::MARSEILLE_SUITS as $suit => $meanings) {
            foreach (self::MARSEILLE_RANKS as $index => $rank) {
                [$upright, $reversed] = $meanings[$index];

                $cards[] = self::card($rank.' de '.$suit, $upright, $reversed);
            }
        }

        return $cards;
    }

    /**
     * @return list<array{name: string, slug: string, upright: string, reversed: string}>
     */
    private static function lenormandCards(): array
    {
        $cards = [];

        foreach (self::LENORMAND_CARDS as [$name, $upright, $reversed]) {
            $cards[] = self::card($name, $upright, $reversed);
        }

        return $cards;
    }

    /**
     * O slug também nomeia o arquivo da frente de carta no acervo versionado.
     *
     * @return array{name: string, slug: string, upright: string, reversed: string}
     */
    private static function card(string $name, string $upright, string $reversed): array
    {
        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'upright' => $upright,
            'reversed' => $reversed,
        ];
    }
}

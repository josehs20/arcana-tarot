<x-layouts.public
    title="Trabalhe conosco — Arcana Tarot"
    description="Quer atender no Arcana Tarot? Conheça o processo de candidatura para profissionais de tarô."
>
    <section class="arcana-hero arcana-hero--compact arcana-stars">
        <div class="container-wide">
            <nav aria-label="Trilha de navegação">
                <ol class="breadcrumb-arcana">
                    <li><a href="{{ route('home') }}">Início</a></li>
                    <li aria-hidden="true">/</li>
                    <li aria-current="page">Trabalhe conosco</li>
                </ol>
            </nav>

            <p class="arcana-eyebrow mb-2">Para profissionais</p>
            <h1 class="arcana-hero__title">Seu dom também tem lugar aqui</h1>
            <div class="arcana-hero__divider" aria-hidden="true"></div>
            <p class="arcana-lead mb-0">
                Estamos preparando um canal para receber candidaturas de tarólogos e
                cartomantes que queiram atender no Arcana Tarot.
            </p>
        </div>
    </section>

    <section class="arcana-section">
        <div class="container-wide">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <x-ui.alert type="warning">
                        <strong>Página em preparação.</strong>
                        O formulário de candidatura será publicado em uma próxima etapa.
                        Nenhuma candidatura é recebida por esta página no momento.
                    </x-ui.alert>

                    <div class="prose-arcana mt-4">
                        <h2>Como será o processo</h2>
                        <p>
                            A candidatura vai reunir seus dados de contato, sua experiência
                            com tarô, sua abordagem de atendimento e suas áreas de
                            especialidade. O envio será analisado pela equipe administrativa.
                        </p>

                        <h2>O que você precisa saber</h2>
                        <ul>
                            <li>Enviar uma candidatura não garante aprovação automática.</li>
                            <li>Não será necessário enviar documentos, dados bancários ou senhas nesta etapa.</li>
                            <li>O retorno sobre a análise será comunicado pelos canais oficiais.</li>
                            <li>Somente perfis aprovados e publicados aparecem no diretório público.</li>
                        </ul>

                        <h2>Enquanto isso</h2>
                        <p>
                            Conheça o diretório de atendentes e veja como os perfis públicos
                            são apresentados. As dúvidas frequentes estão na página inicial;
                            o canal oficial de contato está em preparação.
                        </p>
                    </div>

                    <div class="d-flex flex-wrap gap-3 mt-4">
                        <a class="btn btn-primary" href="{{ route('attendants.index') }}">Conhecer atendentes</a>
                        <a class="btn btn-ghost" href="{{ route('contact') }}">Ver página de contato</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>

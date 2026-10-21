<x-layouts.public
    title="Contato — Arcana Tarot"
    description="Canais de contato do Arcana Tarot. Página provisória em preparação."
>
    <section class="arcana-hero arcana-hero--compact arcana-stars">
        <div class="container-wide">
            <nav aria-label="Trilha de navegação">
                <ol class="breadcrumb-arcana">
                    <li><a href="{{ route('home') }}">Início</a></li>
                    <li aria-hidden="true">/</li>
                    <li aria-current="page">Contato</li>
                </ol>
            </nav>

            <p class="arcana-eyebrow mb-2">Institucional</p>
            <h1 class="arcana-hero__title">Fale com a equipe</h1>
            <div class="arcana-hero__divider" aria-hidden="true"></div>
            <p class="arcana-lead mb-0">
                O canal oficial de contato será publicado nesta página.
            </p>
        </div>
    </section>

    <section class="arcana-section">
        <div class="container-wide">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <x-ui.alert type="info">
                        <strong>Página em preparação.</strong>
                        Nenhum e-mail, telefone ou formulário de contato foi publicado ainda.
                        Para não direcionar você a um canal incorreto, esta página permanece
                        apenas informativa nesta versão.
                    </x-ui.alert>

                    <div class="prose-arcana mt-4">
                        <h2>Enquanto o canal oficial não é publicado</h2>
                        <ul>
                            <li>
                                Consulte as <a href="{{ route('home') }}#faq">dúvidas frequentes</a>
                                na página inicial.
                            </li>
                            <li>
                                Profissionais interessados podem acompanhar a
                                <a href="{{ route('work-with-us.index') }}">página Trabalhe conosco</a>.
                            </li>
                            <li>
                                Usuários com conta podem acessar o painel para acompanhar
                                consultas e dados cadastrais.
                            </li>
                        </ul>

                        <h2>Privacidade e termos</h2>
                        <p>
                            Consulte a <a href="{{ route('legal.privacy') }}">política de privacidade</a>
                            e os <a href="{{ route('legal.terms') }}">termos de uso</a>, ambos
                            ainda em revisão.
                        </p>
                    </div>

                    <a class="btn btn-ghost mt-4" href="{{ route('home') }}">Voltar ao início</a>
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>

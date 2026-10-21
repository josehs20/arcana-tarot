<x-layouts.public
    title="Termos de uso — Arcana Tarot"
    description="Termos de uso do Arcana Tarot. Conteúdo provisório em revisão jurídica."
>
    <section class="arcana-hero arcana-hero--compact arcana-stars">
        <div class="container-wide">
            <nav aria-label="Trilha de navegação">
                <ol class="breadcrumb-arcana">
                    <li><a href="{{ route('home') }}">Início</a></li>
                    <li aria-hidden="true">/</li>
                    <li aria-current="page">Termos de uso</li>
                </ol>
            </nav>

            <p class="arcana-eyebrow mb-2">Institucional</p>
            <h1 class="arcana-hero__title">Termos de uso</h1>
            <p class="text-ivory-dim mt-3 mb-0">Última atualização: pendente de revisão.</p>
        </div>
    </section>

    <section class="arcana-section">
        <div class="container-wide">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <x-ui.alert type="warning">
                        <strong>Documento provisório.</strong>
                        Este texto é um espaço reservado para revisão jurídica. Nenhuma
                        política definitiva foi publicada.
                    </x-ui.alert>

                    <div class="prose-arcana mt-4">
                        <h2>1. Aceitação dos termos</h2>
                        <p>Conteúdo a ser definido pela revisão jurídica.</p>

                        <h2>2. Uso da plataforma</h2>
                        <p>Conteúdo a ser definido pela revisão jurídica.</p>

                        <h2>3. Consultas e pagamentos</h2>
                        <p>Conteúdo a ser definido pela revisão jurídica.</p>

                        <h2>4. Responsabilidades</h2>
                        <p>Conteúdo a ser definido pela revisão jurídica.</p>

                        <h2>5. Contato</h2>
                        <p>
                            Dúvidas sobre estes termos podem ser encaminhadas pela
                            <a href="{{ route('contact') }}">página de contato</a>.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>

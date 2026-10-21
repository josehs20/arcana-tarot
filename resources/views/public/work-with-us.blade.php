<x-layouts.public
    title="Trabalhe conosco — Arcana Tarot"
    description="Candidate-se para atender como tarólogo no Arcana Tarot. Conte sua experiência e sua abordagem de atendimento."
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
                Quer atender no Arcana Tarot? Conte sua experiência com Tarot e como você
                conduz seus atendimentos. Nossa equipe analisa cada candidatura com atenção.
            </p>
        </div>
    </section>

    <section class="arcana-section">
        <div class="container-wide">
            <div class="row g-4 justify-content-center">
                <div class="col-lg-8">
                    @if (session('application_submitted'))
                        <x-ui.alert type="success" :dismissible="false" class="mb-4">
                            <strong>Recebemos sua candidatura.</strong>
                            A equipe vai analisar as informações enviadas e entrar em contato
                            pelos dados informados. O envio não garante aprovação automática.
                        </x-ui.alert>
                    @endif

                    @if ($errors->any())
                        <x-ui.alert type="danger" :dismissible="false" class="mb-4">
                            <strong>Revise os campos abaixo.</strong>
                            <ul class="mb-0 ps-3">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </x-ui.alert>
                    @endif

                    <x-ui.card>
                        <x-ui.card-header>
                            <h2 class="h5 mb-1">Formulário de candidatura</h2>
                            <p class="form-text mb-0">
                                Todos os campos marcados com * são obrigatórios.
                            </p>
                        </x-ui.card-header>

                        <x-ui.card-body>
                            <form method="POST" action="{{ route('work-with-us.store') }}" class="application-form">
                                @csrf

                                <div class="hp-field" aria-hidden="true">
                                    <label for="website">Não preencha este campo</label>
                                    <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <x-ui.field
                                            name="name"
                                            label="Nome completo"
                                            type="text"
                                            placeholder="Como você se apresenta?"
                                            autocomplete="name"
                                            maxlength="120"
                                            required
                                        />
                                    </div>
                                    <div class="col-md-6">
                                        <x-ui.field
                                            name="email"
                                            label="E-mail"
                                            type="email"
                                            placeholder="voce@exemplo.com"
                                            autocomplete="email"
                                            maxlength="255"
                                            required
                                        />
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <x-ui.field
                                            name="phone"
                                            label="Telefone / WhatsApp"
                                            type="tel"
                                            placeholder="(11) 99999-9999"
                                            autocomplete="tel"
                                            inputmode="tel"
                                            maxlength="20"
                                            required
                                        />
                                    </div>
                                    <div class="col-md-6">
                                        <x-ui.field
                                            name="city"
                                            label="Cidade (opcional)"
                                            type="text"
                                            placeholder="Sua cidade"
                                            autocomplete="address-level2"
                                            maxlength="120"
                                        />
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4">
                                        <x-ui.field
                                            name="state"
                                            label="Estado / UF (opcional)"
                                            type="text"
                                            placeholder="SP"
                                            autocomplete="address-level1"
                                            maxlength="2"
                                            help="Duas letras."
                                        />
                                    </div>
                                </div>

                                <x-ui.textarea
                                    name="experience_summary"
                                    label="Experiência com Tarot"
                                    rows="4"
                                    maxlength="2000"
                                    placeholder="Há quanto tempo você trabalha com Tarot? Como foi sua formação e prática?"
                                    help="Conte o essencial: tempo de prática, formação, tipos de leitura e o que você já realizou."
                                    required
                                />

                                <x-ui.textarea
                                    name="message"
                                    label="Apresentação e abordagem de atendimento"
                                    rows="5"
                                    maxlength="2000"
                                    placeholder="Como você acolhe e conduz uma consulta?"
                                    help="Este texto ajuda a equipe a entender seu jeito de atender. Ele não será publicado automaticamente."
                                    required
                                />

                                <x-ui.field
                                    name="specialties"
                                    label="Áreas de especialidade (opcional)"
                                    type="text"
                                    placeholder="Amor, Carreira, Autoconhecimento"
                                    maxlength="300"
                                    help="Separe por vírgulas. Informe no máximo 8 áreas."
                                />

                                <x-ui.field
                                    name="portfolio_url"
                                    label="Link de portfólio ou rede social (opcional)"
                                    type="url"
                                    placeholder="https://..."
                                    autocomplete="url"
                                    maxlength="255"
                                    help="Um perfil profissional ou página que apresente seu trabalho."
                                />

                                <div class="form-check mb-2 {{ $errors->has('consent') ? 'has-error' : '' }}">
                                    <input
                                        class="form-check-input @error('consent') is-invalid @enderror"
                                        type="checkbox"
                                        name="consent"
                                        id="consent"
                                        value="1"
                                        @checked(old('consent'))
                                        @if ($errors->has('consent'))
                                            aria-invalid="true"
                                            aria-describedby="consent-error"
                                        @endif
                                        required
                                    >
                                    <label class="form-check-label" for="consent">
                                        Autorizo o uso dos dados enviados para analisar esta candidatura,
                                        conforme a
                                        <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener noreferrer">Política de Privacidade</a>.
                                    </label>
                                    @error('consent')
                                        <div class="invalid-feedback d-block" id="consent-error">{{ $message }}</div>
                                    @enderror
                                </div>

                                <p class="form-text mb-4">
                                    Enviar uma candidatura não garante aprovação. Não solicitamos CPF,
                                    dados bancários, documentos ou senhas nesta etapa.
                                </p>

                                <div class="d-flex flex-wrap align-items-center gap-3">
                                    <x-ui.button type="submit" variant="primary" size="lg">
                                        Enviar candidatura
                                    </x-ui.button>
                                    <a class="btn btn-ghost" href="{{ route('home') }}">Voltar ao início</a>
                                </div>
                            </form>
                        </x-ui.card-body>
                    </x-ui.card>
                </div>

                <div class="col-lg-4">
                    <x-ui.card>
                        <x-ui.card-header>
                            <h2 class="h6 mb-0">Como funciona</h2>
                        </x-ui.card-header>
                        <x-ui.card-body>
                            <div class="prose-arcana">
                                <p>
                                    A equipe administrativa revisa cada candidatura antes de qualquer
                                    publicação de perfil no diretório.
                                </p>
                                <ul>
                                    <li>Preencha seus dados e conte sobre sua prática.</li>
                                    <li>A candidatura entra na fila de análise interna.</li>
                                    <li>O retorno é feito pelos canais informados no formulário.</li>
                                    <li>Somente perfis aprovados e publicados aparecem no site.</li>
                                </ul>
                                <p class="mb-0">
                                    A aprovação da candidatura não cria automaticamente uma conta
                                    de atendente. O acesso é tratado separadamente pela equipe.
                                </p>
                            </div>
                        </x-ui.card-body>
                    </x-ui.card>

                    <x-ui.card class="mt-4">
                        <x-ui.card-header>
                            <h2 class="h6 mb-0">Privacidade</h2>
                        </x-ui.card-header>
                        <x-ui.card-body>
                            <p class="mb-0">
                                Usamos os dados apenas para analisar sua candidatura. Notas e
                                informações internas de revisão não são exibidas publicamente.
                            </p>
                        </x-ui.card-body>
                    </x-ui.card>
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>

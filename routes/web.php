<?php

use App\Http\Controllers\Admin\ApplicationReviewController;
use App\Http\Controllers\Admin\AttendantController as AdminAttendantController;
use App\Http\Controllers\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Admin\CommissionController as AdminCommissionController;
use App\Http\Controllers\Admin\ConsultationController as AdminConsultationController;
use App\Http\Controllers\Admin\ConsultationSafetyController as AdminConsultationSafetyController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\FinanceController as AdminFinanceController;
use App\Http\Controllers\Admin\ModalityController as AdminModalityController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\PaymentSettingsController as AdminPaymentSettingsController;
use App\Http\Controllers\Admin\RegistrationBonusController as AdminRegistrationBonusController;
use App\Http\Controllers\Admin\SiteContentController as AdminSiteContentController;
use App\Http\Controllers\Admin\SiteFaqController as AdminSiteFaqController;
use App\Http\Controllers\Admin\SiteNavigationController as AdminSiteNavigationController;
use App\Http\Controllers\Admin\TarotCardController as AdminTarotCardController;
use App\Http\Controllers\Admin\TarotDeckController as AdminTarotDeckController;
use App\Http\Controllers\Admin\TestimonialController as AdminTestimonialController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Attendant\AvailabilityController as AttendantAvailabilityController;
use App\Http\Controllers\Attendant\ConsultationController as AttendantConsultationController;
use App\Http\Controllers\Attendant\DashboardController as AttendantDashboardController;
use App\Http\Controllers\Attendant\HistoryController as AttendantHistoryController;
use App\Http\Controllers\AttendantApplicationController;
use App\Http\Controllers\AttendantDirectoryController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\GoogleAuthenticationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\PhoneVerificationController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\ChatPreferenceController;
use App\Http\Controllers\Client\ConsultationController as ClientConsultationController;
use App\Http\Controllers\Client\CreditCheckoutController;
use App\Http\Controllers\Client\CreditPurchaseController;
use App\Http\Controllers\Client\DashboardController as ClientDashboardController;
use App\Http\Controllers\Client\HistoryController as ClientHistoryController;
use App\Http\Controllers\Client\TestimonialController as ClientTestimonialController;
use App\Http\Controllers\ConsultationCallController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\ConsultationMessageController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InfinitePayWebhookController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PanelEventsController;
use App\Http\Controllers\PixWebhookController;
use App\Http\Controllers\TarotCardImageController;
use App\Http\Controllers\TarotCardThumbnailController;
use App\Http\Controllers\ThemePreferenceController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('atendentes', [AttendantDirectoryController::class, 'index'])->name('attendants.index');
Route::get('atendentes/{attendant}', [AttendantDirectoryController::class, 'show'])
    ->whereNumber('attendant')
    ->name('attendants.show');

Route::get('trabalhe-conosco', [AttendantApplicationController::class, 'create'])->name('work-with-us.index');
Route::post('trabalhe-conosco', [AttendantApplicationController::class, 'store'])
    ->middleware('throttle:5,10')
    ->name('work-with-us.store');
Route::get('como-funciona', [PageController::class, 'howItWorks'])->name('pages.how-it-works');
Route::get('duvidas', [PageController::class, 'faq'])->name('pages.faq');
Route::get('termos', [PageController::class, 'terms'])->name('legal.terms');
Route::get('privacidade', [PageController::class, 'privacy'])->name('legal.privacy');
Route::get('contato', [PageController::class, 'contact'])->name('contact');

/*
 * Webhook do provedor PIX. A autenticidade é validada pelo adaptador
 * (assinatura HMAC + janela de timestamp) e a idempotência pelo evento único.
 * Não usa sessão nem CSRF: é uma chamada server-to-server.
 */
Route::post('webhooks/pix', PixWebhookController::class)
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.pix');

/*
 * Webhook do Checkout Integrado da InfinitePay. Também é server-to-server (sem
 * sessão e sem CSRF); a autenticidade vem da conferência `payment_check` feita
 * antes de qualquer liberação de crédito.
 */
Route::post('webhooks/infinitepay', InfinitePayWebhookController::class)
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.infinitepay');

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store']);

    /*
     * Segunda etapa do cadastro: confirmação do celular por SMS. A conta só é
     * criada no POST de confirmação, depois do código aprovado. O reenvio tem
     * limite próprio e o cooldown é aplicado pelo PhoneVerificationService.
     */
    Route::get('register/confirmar-celular', [PhoneVerificationController::class, 'show'])
        ->name('register.verify');
    Route::post('register/confirmar-celular', [PhoneVerificationController::class, 'verify'])
        ->middleware('throttle:10,10')
        ->name('register.verify.store');
    Route::post('register/confirmar-celular/reenviar', [PhoneVerificationController::class, 'resend'])
        ->middleware('throttle:5,10')
        ->name('register.verify.resend');

    /*
     * Login/cadastro com Google. As rotas existem sempre, mas os controllers
     * recusam o acesso quando as credenciais não estão configuradas.
     */
    Route::get('auth/google', [GoogleAuthenticationController::class, 'redirect'])
        ->middleware('throttle:20,1')
        ->name('auth.google.redirect');
    Route::get('auth/google/callback', [GoogleAuthenticationController::class, 'callback'])
        ->middleware('throttle:20,1')
        ->name('auth.google.callback');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::patch('preferencias/tema', ThemePreferenceController::class)->name('preferences.theme.update');

    /*
     * Preferências do chat (Enter envia / aviso sonoro). Valem para o usuário e
     * não para uma consulta específica, por isso ficam fora das rotas do chat.
     */
    Route::patch('preferencias/chat', ChatPreferenceController::class)->name('preferences.chat.update');

    Route::get('admin', AdminDashboardController::class)->middleware('role:admin')->name('admin.dashboard');
    Route::get('attendant', AttendantDashboardController::class)->middleware('role:attendant')->name('attendant.dashboard');
    Route::get('client', ClientDashboardController::class)->middleware('role:client')->name('client.dashboard');

    Route::get('client/historico', ClientHistoryController::class)
        ->middleware('role:client')
        ->name('client.history');

    Route::get('client/depoimentos', [ClientTestimonialController::class, 'index'])
        ->middleware('role:client')
        ->name('client.testimonials.index');

    // Depoimento pós-consulta: um por atendimento concluído, sem edição depois
    // do envio. A policy revalida participante, status `ended` e duplicidade.
    Route::post('consultas/{consultation}/depoimento', [ClientTestimonialController::class, 'store'])
        ->whereNumber('consultation')
        ->middleware(['role:client', 'throttle:consultation-testimonials'])
        ->name('client.testimonials.store');

    Route::get('attendant/historico', AttendantHistoryController::class)
        ->middleware('role:attendant')
        ->name('attendant.history');

    Route::get('creditos/comprar', [CreditPurchaseController::class, 'index'])
        ->middleware('role:client')
        ->name('client.credits.index');

    Route::post('creditos/comprar', [CreditPurchaseController::class, 'store'])
        ->middleware(['role:client', 'throttle:credit-purchases'])
        ->name('client.credits.store');

    /*
     * Checkout InfinitePay: o POST cria (ou reaproveita) o pedido e redireciona
     * para o link do provedor. O GET é o retorno do navegador e apenas
     * reconcilia o status — nunca libera crédito por parâmetro de URL.
     */
    Route::post('creditos/checkout', [CreditCheckoutController::class, 'store'])
        ->middleware(['role:client', 'throttle:credit-purchases'])
        ->name('client.credits.checkout.store');

    Route::get('creditos/checkout/retorno', [CreditCheckoutController::class, 'return'])
        ->middleware('role:client')
        ->name('client.credits.checkout.return');

    Route::get('creditos/recargas/{payment}', [CreditPurchaseController::class, 'show'])
        ->whereNumber('payment')
        ->middleware('role:client')
        ->name('client.credits.payments.show');

    Route::patch('attendant/disponibilidade', [AttendantAvailabilityController::class, 'update'])
        ->middleware('role:attendant')
        ->name('attendant.availability.update');

    Route::get('consultas/{consultation}', [ConsultationController::class, 'show'])
        ->whereNumber('consultation')
        ->name('consultations.show');

    /*
     * Tela dedicada de atendimento e seus indicadores oficiais. Ambas existem
     * apenas durante o atendimento ativo e revalidam a participação no
     * servidor (cliente, atendente ou administrador com acesso de leitura).
     */
    Route::get('consultas/{consultation}/atendimento', [ConsultationController::class, 'workspace'])
        ->whereNumber('consultation')
        ->name('consultations.workspace');

    Route::get('consultas/{consultation}/metricas', [ConsultationController::class, 'metrics'])
        ->whereNumber('consultation')
        ->name('consultations.metrics');

    /*
     * Presença do atendimento: o batimento mantém a sessão viva, mas só
     * interações reais atualizam `last_activity_at`. O token é gerado no
     * servidor e permite reconectar na mesma sessão depois de uma queda.
     */
    Route::post('consultas/{consultation}/presenca', [ConsultationController::class, 'heartbeat'])
        ->whereNumber('consultation')
        ->middleware('throttle:consultation-presence')
        ->name('consultations.presence.heartbeat');

    Route::post('consultas/{consultation}/presenca/sair', [ConsultationController::class, 'disconnect'])
        ->whereNumber('consultation')
        ->name('consultations.presence.leave');

    Route::get('consultas/{consultation}/mensagens', [ConsultationMessageController::class, 'index'])
        ->whereNumber('consultation')
        ->middleware('throttle:consultation-feed')
        ->name('consultations.messages.index');

    Route::post('consultas/{consultation}/mensagens', [ConsultationMessageController::class, 'store'])
        ->whereNumber('consultation')
        ->middleware('throttle:consultation-messages')
        ->name('consultations.messages.store');

    Route::post('consultas/{consultation}/mensagens/lidas', [ConsultationMessageController::class, 'markRead'])
        ->whereNumber('consultation')
        ->name('consultations.messages.read');

    Route::post('consultas/{consultation}/cartas', [ConsultationMessageController::class, 'storeCard'])
        ->whereNumber('consultation')
        ->middleware('throttle:consultation-cards')
        ->name('consultations.cards.store');

    Route::get('consultas/{consultation}/mensagens/{message}/imagem', [ConsultationMessageController::class, 'image'])
        ->whereNumber(['consultation', 'message'])
        ->name('consultations.messages.image');

    Route::patch('consultas/{consultation}/encerrar', [ConsultationController::class, 'end'])
        ->whereNumber('consultation')
        ->name('consultations.end');

    Route::prefix('consultas/{consultation}/chamada')
        ->whereNumber('consultation')
        ->name('consultations.call.')
        ->middleware('throttle:consultation-calls')
        ->group(function () {
            Route::get('/', [ConsultationCallController::class, 'state'])->name('state');
            Route::post('/', [ConsultationCallController::class, 'store'])->name('store');
            Route::patch('aceitar', [ConsultationCallController::class, 'accept'])->name('accept');
            Route::patch('recusar', [ConsultationCallController::class, 'reject'])->name('reject');
            Route::patch('conectada', [ConsultationCallController::class, 'connected'])->name('connected');
            Route::patch('encerrar', [ConsultationCallController::class, 'end'])->name('end');
            Route::patch('falhar', [ConsultationCallController::class, 'fail'])->name('fail');
            Route::post('sinais', [ConsultationCallController::class, 'signal'])->name('signal');
        });

    Route::post('consultas', [ClientConsultationController::class, 'store'])
        ->middleware(['role:client', 'throttle:consultation-requests'])
        ->name('client.consultations.store');

    Route::patch('consultas/{consultation}/cancelar', [ClientConsultationController::class, 'cancel'])
        ->whereNumber('consultation')
        ->middleware('role:client')
        ->name('client.consultations.cancel');

    Route::patch('consultas/{consultation}/aceitar', [AttendantConsultationController::class, 'accept'])
        ->whereNumber('consultation')
        ->middleware('role:attendant')
        ->name('attendant.consultations.accept');

    Route::patch('consultas/{consultation}/recusar', [AttendantConsultationController::class, 'reject'])
        ->whereNumber('consultation')
        ->middleware('role:attendant')
        ->name('attendant.consultations.reject');

    /*
     * Recuperação das solicitações pendentes do atendente. Usada quando o canal
     * privado é (re)conectado, para não perder pedidos feitos enquanto a aba
     * estava fechada, suspensa ou sem rede.
     */
    Route::get('attendant/solicitacoes', [AttendantConsultationController::class, 'pending'])
        ->middleware('role:attendant')
        ->name('attendant.requests.pending');

    /*
     * Feed do painel no modo polling. Quando não há WebSocket (hospedagem
     * compartilhada), cliente e atendente leem aqui as solicitações pendentes e
     * o estado da consulta aberta. A rota é somente leitura: não registra
     * atividade, presença, leitura de mensagens nem cobrança.
     */
    Route::get('painel/eventos', PanelEventsController::class)
        ->middleware('throttle:panel-events')
        ->name('panel.events');

    Route::prefix('admin/usuarios')
        ->name('admin.users.')
        ->middleware('role:admin')
        ->group(function () {
            Route::get('/', [AdminUserController::class, 'index'])->name('index');

            /*
             * Cadastro de atendente pelo painel. A rota fica antes das rotas com
             * `{user}` apenas por legibilidade: os parâmetros são numéricos e não
             * colidem com "novo-atendente".
             */
            Route::get('novo-atendente', [AdminUserController::class, 'create'])->name('create');
            Route::post('/', [AdminUserController::class, 'store'])->name('store');

            Route::get('{user}/editar', [AdminUserController::class, 'edit'])
                ->whereNumber('user')
                ->name('edit');
            Route::patch('{user}', [AdminUserController::class, 'update'])
                ->whereNumber('user')
                ->name('update');

            /*
             * Ajuste de créditos pela ficha do cliente: mesma regra da tesouraria
             * (motivo obrigatório, operação idempotente e auditoria).
             */
            Route::post('{user}/creditos', [AdminUserController::class, 'adjustCredit'])
                ->whereNumber('user')
                ->name('credit.store');

            Route::patch('{user}/status', [AdminUserController::class, 'updateStatus'])
                ->whereNumber('user')
                ->name('status');
        });

    Route::prefix('admin/atendentes')
        ->name('admin.attendants.')
        ->middleware('role:admin')
        ->group(function () {
            Route::get('/', [AdminAttendantController::class, 'index'])->name('index');
            Route::patch('{user}/som', [AdminAttendantController::class, 'updateSound'])
                ->whereNumber('user')
                ->name('sound.update');
            Route::post('{user}/som/arquivo', [AdminAttendantController::class, 'uploadSound'])
                ->whereNumber('user')
                ->name('sound.upload');
            Route::delete('{user}/som/arquivo', [AdminAttendantController::class, 'destroySound'])
                ->whereNumber('user')
                ->name('sound.destroy');
        });

    Route::prefix('admin/consultas')
        ->name('admin.consultations.')
        ->middleware('role:admin')
        ->group(function () {
            Route::get('/', [AdminConsultationController::class, 'index'])->name('index');

            /*
             * Detalhe administrativo do atendimento. A leitura do histórico é
             * um acesso a dado sensível: a política exige administrador ativo e
             * cada abertura é registrada na auditoria.
             */
            Route::get('{consultation}', [AdminConsultationController::class, 'show'])
                ->whereNumber('consultation')
                ->name('show');

            // Novos itens da linha do tempo, lidos em intervalos curtos pela tela.
            Route::get('{consultation}/historico', [AdminConsultationController::class, 'entries'])
                ->whereNumber('consultation')
                ->middleware('throttle:admin-consultation-history')
                ->name('entries');

            Route::get('{consultation}/mensagens/{message}/imagem', [AdminConsultationController::class, 'image'])
                ->whereNumber(['consultation', 'message'])
                ->name('messages.image');

            Route::patch('{consultation}/ocorrencias/{incident}', [AdminConsultationController::class, 'reviewIncident'])
                ->whereNumber(['consultation', 'incident'])
                ->name('incidents.review');
        });

    Route::prefix('admin/modalidades')
        ->name('admin.modalities.')
        ->middleware('role:admin')
        ->group(function () {
            Route::get('/', [AdminModalityController::class, 'index'])->name('index');
            Route::patch('{user}', [AdminModalityController::class, 'update'])
                ->whereNumber('user')
                ->name('update');
        });

    Route::get('admin/pagamentos', [AdminPaymentController::class, 'index'])
        ->middleware('role:admin')
        ->name('admin.payments.index');

    Route::get('admin/auditoria', [AdminAuditLogController::class, 'index'])
        ->middleware('role:admin')
        ->name('admin.audit.index');

    Route::prefix('admin/conteudo')
        ->name('admin.site.')
        ->middleware('role:admin')
        ->group(function () {
            Route::get('/', [AdminSiteContentController::class, 'index'])->name('index');
            Route::patch('identidade', [AdminSiteContentController::class, 'updateIdentity'])
                ->name('identity.update');
            Route::get('secoes/{key}/editar', [AdminSiteContentController::class, 'editSection'])
                ->where('key', '[a-z_]+')
                ->name('sections.edit');
            Route::patch('secoes/{key}', [AdminSiteContentController::class, 'updateSection'])
                ->where('key', '[a-z_]+')
                ->name('sections.update');

            Route::get('navegacao', [AdminSiteNavigationController::class, 'index'])
                ->name('navigation.index');
            Route::get('navegacao/nova', [AdminSiteNavigationController::class, 'create'])
                ->name('navigation.create');
            Route::post('navegacao', [AdminSiteNavigationController::class, 'store'])
                ->name('navigation.store');
            Route::get('navegacao/{link}/editar', [AdminSiteNavigationController::class, 'edit'])
                ->whereNumber('link')
                ->name('navigation.edit');
            Route::patch('navegacao/{link}', [AdminSiteNavigationController::class, 'update'])
                ->whereNumber('link')
                ->name('navigation.update');
            Route::delete('navegacao/{link}', [AdminSiteNavigationController::class, 'destroy'])
                ->whereNumber('link')
                ->name('navigation.destroy');

            Route::get('perguntas', [AdminSiteFaqController::class, 'index'])
                ->name('faqs.index');
            Route::get('perguntas/nova', [AdminSiteFaqController::class, 'create'])
                ->name('faqs.create');
            Route::post('perguntas', [AdminSiteFaqController::class, 'store'])
                ->name('faqs.store');
            Route::get('perguntas/{faq}/editar', [AdminSiteFaqController::class, 'edit'])
                ->whereNumber('faq')
                ->name('faqs.edit');
            Route::patch('perguntas/{faq}', [AdminSiteFaqController::class, 'update'])
                ->whereNumber('faq')
                ->name('faqs.update');
            Route::delete('perguntas/{faq}', [AdminSiteFaqController::class, 'destroy'])
                ->whereNumber('faq')
                ->name('faqs.destroy');
        });

    Route::prefix('admin/comissoes')
        ->name('admin.commissions.')
        ->middleware('role:admin')
        ->group(function () {
            Route::get('/', [AdminCommissionController::class, 'index'])->name('index');
            Route::get('atendentes/{user}', [AdminCommissionController::class, 'attendant'])
                ->whereNumber('user')
                ->name('attendant');
            Route::patch('atendentes/{user}', [AdminCommissionController::class, 'updateRate'])
                ->whereNumber('user')
                ->name('rate.update');
            Route::post('lancamentos/{entry}/estornar', [AdminCommissionController::class, 'reverse'])
                ->whereNumber('entry')
                ->name('reverse');
        });

    Route::prefix('admin/financeiro')
        ->name('admin.finance.')
        ->middleware('role:admin')
        ->group(function () {
            Route::get('/', [AdminFinanceController::class, 'index'])->name('index');
            Route::get('carteiras/{user}', [AdminFinanceController::class, 'show'])
                ->whereNumber('user')
                ->name('wallets.show');
            Route::post('carteiras/{user}/ajustes', [AdminFinanceController::class, 'adjust'])
                ->whereNumber('user')
                ->name('wallets.adjust');
            Route::patch('tarifa', [AdminFinanceController::class, 'updatePricing'])
                ->name('pricing.update');

            /*
             * Página própria (item "Pacotes e recarga" no menu) para os valores
             * que a administração define no checkout: catálogo de pacotes e
             * faixa de recarga da carteira.
             */
            Route::get('pacotes', [AdminPaymentSettingsController::class, 'index'])
                ->name('packages.index');
            Route::patch('pacotes', [AdminPaymentSettingsController::class, 'updatePackages'])
                ->name('packages.update');
            Route::patch('pacotes/limites', [AdminPaymentSettingsController::class, 'updateLimits'])
                ->name('packages.limits.update');

            /*
             * Bônus de primeiro acesso: créditos dados uma única vez a cada
             * cliente que conclui o cadastro. O valor é calculado pela tarifa
             * vigente do crédito, nunca por um campo do formulário.
             */
            Route::get('bonus', [AdminRegistrationBonusController::class, 'edit'])
                ->name('bonus.edit');
            Route::patch('bonus', [AdminRegistrationBonusController::class, 'update'])
                ->name('bonus.update');
        });

    Route::prefix('admin/atendimento')
        ->name('admin.consultations.')
        ->middleware('role:admin')
        ->group(function () {
            Route::get('seguranca', [AdminConsultationSafetyController::class, 'index'])
                ->name('safety');
            Route::patch('seguranca', [AdminConsultationSafetyController::class, 'update'])
                ->name('safety.update');
        });

    Route::prefix('admin/depoimentos')
        ->name('admin.testimonials.')
        ->middleware('role:admin')
        ->group(function () {
            Route::get('/', [AdminTestimonialController::class, 'index'])->name('index');
            Route::patch('{testimonial}/ocultar', [AdminTestimonialController::class, 'hide'])
                ->whereNumber('testimonial')
                ->name('hide');
            Route::patch('{testimonial}/publicar', [AdminTestimonialController::class, 'publish'])
                ->whereNumber('testimonial')
                ->name('publish');
        });

    Route::prefix('admin/baralhos')
        ->name('admin.decks.')
        ->middleware('role:admin')
        ->group(function () {
            Route::get('/', [AdminTarotDeckController::class, 'index'])->name('index');
            Route::get('novo', [AdminTarotDeckController::class, 'create'])->name('create');
            Route::post('/', [AdminTarotDeckController::class, 'store'])->name('store');
            Route::get('{deck}/editar', [AdminTarotDeckController::class, 'edit'])
                ->whereNumber('deck')
                ->name('edit');
            Route::patch('{deck}', [AdminTarotDeckController::class, 'update'])
                ->whereNumber('deck')
                ->name('update');
            Route::patch('{deck}/status', [AdminTarotDeckController::class, 'updateStatus'])
                ->whereNumber('deck')
                ->name('status');

            Route::prefix('{deck}/cartas')
                ->name('cards.')
                ->whereNumber('deck')
                ->group(function () {
                    Route::get('/', [AdminTarotCardController::class, 'index'])->name('index');
                    Route::get('nova', [AdminTarotCardController::class, 'create'])->name('create');
                    Route::post('/', [AdminTarotCardController::class, 'store'])->name('store');
                    Route::get('{card}/editar', [AdminTarotCardController::class, 'edit'])
                        ->whereNumber('card')
                        ->name('edit');
                    Route::patch('{card}', [AdminTarotCardController::class, 'update'])
                        ->whereNumber('card')
                        ->name('update');
                    Route::patch('{card}/status', [AdminTarotCardController::class, 'updateStatus'])
                        ->whereNumber('card')
                        ->name('status');
                    Route::patch('{card}/mover', [AdminTarotCardController::class, 'move'])
                        ->whereNumber('card')
                        ->name('move');
                });
        });

    Route::get('catalogo/cartas/{card}/imagem', TarotCardImageController::class)
        ->whereNumber('card')
        ->name('tarot.cards.image');

    /*
     * Miniatura da mesma carta. A grade do seletor exibe dezenas de imagens de
     * uma vez; servir o original ali custava uma requisição PHP e ~50 KB por
     * célula. A rota entrega o WebP reduzido, gerado uma única vez no servidor.
     */
    Route::get('catalogo/cartas/{card}/miniatura', TarotCardThumbnailController::class)
        ->whereNumber('card')
        ->name('tarot.cards.thumb');

    Route::prefix('admin/candidaturas')
        ->name('admin.applications.')
        ->middleware('role:admin')
        ->group(function () {
            Route::get('/', [ApplicationReviewController::class, 'index'])->name('index');
            Route::get('{application}', [ApplicationReviewController::class, 'show'])
                ->whereNumber('application')
                ->name('show');
            Route::patch('{application}/iniciar-analise', [ApplicationReviewController::class, 'start'])
                ->whereNumber('application')
                ->name('start');
            Route::patch('{application}/aprovar', [ApplicationReviewController::class, 'approve'])
                ->whereNumber('application')
                ->name('approve');
            Route::patch('{application}/rejeitar', [ApplicationReviewController::class, 'reject'])
                ->whereNumber('application')
                ->name('reject');
        });
});

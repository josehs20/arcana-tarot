<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ModuleController extends Controller
{
    public function finance(): View
    {
        Gate::authorize('access-admin-panel');

        return view('admin.modules.placeholder', [
            'title' => 'Financeiro',
            'description' => 'Indicadores financeiros, pagamentos Pix e repasses por atendente serão implementados nas próximas etapas.',
            'upcoming' => [
                'Histórico de pagamentos Pix em modo de teste (Prompt 14).',
                'Lançamentos de crédito e estorno derivados do pagamento (Prompt 14).',
                'Razão de comissões com taxa congelada por consulta (prompt de faturamento).',
                'Configuração individual da taxa de comissão por atendente.',
            ],
        ]);
    }
}

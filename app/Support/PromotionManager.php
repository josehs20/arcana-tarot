<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\CreditLedgerEntry;
use App\Models\Payment;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Campanhas "Compre X créditos e ganhe Y".
 *
 * A promoção aplicável é decidida no servidor a partir da quantidade de
 * créditos do pagamento — nunca de um campo do formulário. O pagamento guarda
 * a promoção escolhida no início do checkout e, na confirmação, o bônus é
 * lançado no livro-razão com a chave idempotente
 * `promotion_bonus:payment:{id}`, então webhooks repetidos não duplicam saldo.
 */
final class PromotionManager
{
    public const IDEMPOTENCY_PREFIX = 'promotion_bonus:payment:';

    public function __construct(
        private readonly CreditWalletManager $wallets,
    ) {}

    public static function idempotencyKeyFor(Payment $payment): string
    {
        return self::IDEMPOTENCY_PREFIX.$payment->getKey();
    }

    /**
     * Promoções exibidas na home e na carteira.
     *
     * @return Collection<int, Promotion>
     */
    public function active(int $limit = 3): Collection
    {
        return Promotion::query()
            ->active()
            ->orderByDesc('bonus_credits')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function findActiveById(int $id): ?Promotion
    {
        return Promotion::query()->active()->find($id);
    }

    /**
     * Promoção vigente para uma compra com a quantidade exata de créditos.
     * Quando há mais de uma, o maior bônus vence.
     */
    public function forPurchase(int $credits): ?Promotion
    {
        if ($credits < 1) {
            return null;
        }

        return Promotion::query()
            ->active()
            ->where('buy_credits', $credits)
            ->orderByDesc('bonus_credits')
            ->orderBy('id')
            ->first();
    }

    /**
     * Promoção registrada no pedido no momento da compra. Se ela foi removida
     * antes da confirmação, o servidor tenta a promoção vigente equivalente.
     */
    public function promotionForPayment(Payment $payment): ?Promotion
    {
        if ($payment->promotion_id !== null) {
            $snapshot = Promotion::query()->find($payment->promotion_id);

            if ($snapshot !== null) {
                return $snapshot;
            }
        }

        return $this->forPurchase($payment->credits);
    }

    /**
     * Concede o bônus da promoção de um pagamento já confirmado.
     *
     * Deve ser chamado dentro da mesma transação que registra os créditos da
     * compra. Retorna null quando não há promoção aplicável ou quando o bônus
     * já foi concedido.
     */
    public function applyToPayment(Payment $payment): ?CreditLedgerEntry
    {
        if ($payment->status !== PaymentStatus::Confirmed || $payment->promotion_bonus_credits > 0) {
            return null;
        }

        $unitPriceCents = $payment->credit_unit_price_cents;

        if ($unitPriceCents < 1) {
            return null;
        }

        $promotion = $this->promotionForPayment($payment);

        if ($promotion === null) {
            return null;
        }

        $bonusCredits = $promotion->bonus_credits;
        $amountCents = $promotion->bonusAmountCents($unitPriceCents);

        if ($bonusCredits < 1 || $amountCents < 1) {
            return null;
        }

        return DB::transaction(function () use ($payment, $promotion, $bonusCredits, $amountCents): ?CreditLedgerEntry {
            $locked = Payment::query()->lockForUpdate()->find($payment->getKey());

            if (
                $locked === null
                || $locked->status !== PaymentStatus::Confirmed
                || $locked->promotion_bonus_credits > 0
            ) {
                return null;
            }

            $entry = $this->wallets->credit(
                $locked->user,
                $amountCents,
                self::idempotencyKeyFor($locked),
                $locked,
                'Bônus da promoção: '.$promotion->headline(),
            );

            $locked->forceFill([
                'promotion_id' => $promotion->getKey(),
                'promotion_bonus_credits' => $bonusCredits,
                'promotion_bonus_cents' => $amountCents,
            ])->save();

            AuditLog::query()->create([
                'actor_id' => null,
                'action' => 'payment.promotion_applied',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'metadata' => [
                    'user_id' => $locked->user_id,
                    'promotion_id' => $promotion->getKey(),
                    'promotion_title' => $promotion->title,
                    'promotion_buy_credits' => $promotion->buy_credits,
                    'promotion_bonus_credits' => $bonusCredits,
                    'bonus_amount_cents' => $amountCents,
                    'ledger_entry_id' => $entry->getKey(),
                ],
            ]);

            return $entry;
        });
    }
}

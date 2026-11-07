<?php

namespace App\Models;

use App\Enums\EmailRecipientStatus;
use Database\Factories\EmailCampaignRecipientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Destinatário de um disparo de e-mail, com o status de entrega individual.
 *
 * O par (disparo, e-mail) é único no banco: uma seleção repetida nunca envia
 * duas vezes para a mesma caixa postal dentro da mesma campanha.
 */
#[Fillable(['email_campaign_id', 'user_id', 'name', 'email', 'status', 'error', 'sent_at'])]
class EmailCampaignRecipient extends Model
{
    /** @use HasFactory<EmailCampaignRecipientFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EmailRecipientStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

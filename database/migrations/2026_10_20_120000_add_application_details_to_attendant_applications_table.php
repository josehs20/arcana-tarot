<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendant_applications', function (Blueprint $table) {
            $table->string('city', 120)->nullable();
            $table->char('state', 2)->nullable();
            $table->json('specialties')->nullable();
            $table->string('portfolio_url', 255)->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('review_started_at')->nullable();

            $table->index(['email', 'status']);
            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('attendant_applications', function (Blueprint $table) {
            $table->dropIndex(['email', 'status']);
            $table->dropIndex(['status', 'submitted_at']);
            $table->dropColumn([
                'city',
                'state',
                'specialties',
                'portfolio_url',
                'consent_at',
                'status_changed_at',
                'review_started_at',
            ]);
        });
    }
};

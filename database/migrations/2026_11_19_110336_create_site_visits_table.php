<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma linha por visita (sessão) ao site.
     *
     * Não há IP, user agent ou sessão do Laravel em texto claro: o IP é
     * pseudonimizado com hash e do navegador ficam apenas os rótulos
     * derivados. O cookie `visitor_id` é aleatório e não contém dado pessoal;
     * ele apenas permite reconhecer o mesmo navegador entre visitas.
     */
    public function up(): void
    {
        Schema::create('site_visits', function (Blueprint $table) {
            $table->id();
            $table->uuid('visitor_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_hash', 64)->nullable();
            $table->string('browser', 40)->nullable();
            $table->string('platform', 40)->nullable();
            $table->string('device_type', 20)->nullable();
            $table->string('referrer_host', 120)->nullable();
            $table->string('entry_path', 255);
            $table->string('last_path', 255);
            $table->unsignedInteger('page_views')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['started_at']);
            $table->index(['visitor_id', 'ended_at', 'last_activity_at']);
            $table->index(['user_id', 'started_at']);
            $table->index(['ended_at', 'last_activity_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_visits');
    }
};

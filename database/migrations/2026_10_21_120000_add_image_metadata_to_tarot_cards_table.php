<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tarot_cards', function (Blueprint $table) {
            $table->string('image_mime', 40)->nullable()->after('image_path');
            $table->unsignedBigInteger('image_size')->nullable()->after('image_mime');
            $table->string('image_checksum', 64)->nullable()->after('image_size');
        });
    }

    public function down(): void
    {
        Schema::table('tarot_cards', function (Blueprint $table) {
            $table->dropColumn(['image_mime', 'image_size', 'image_checksum']);
        });
    }
};

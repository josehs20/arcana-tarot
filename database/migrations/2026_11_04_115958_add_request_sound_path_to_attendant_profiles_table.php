<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendant_profiles', function (Blueprint $table) {
            $table->string('request_sound_path')->nullable()->after('request_sound');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendant_profiles', function (Blueprint $table) {
            $table->dropColumn('request_sound_path');
        });
    }
};

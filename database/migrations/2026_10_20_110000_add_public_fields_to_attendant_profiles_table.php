<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendant_profiles', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('bio');
            $table->string('availability', 20)->default('offline')->after('is_published');
            $table->index('availability');
        });
    }

    public function down(): void
    {
        Schema::table('attendant_profiles', function (Blueprint $table) {
            $table->dropIndex(['availability']);
            $table->dropColumn(['photo_path', 'availability']);
        });
    }
};

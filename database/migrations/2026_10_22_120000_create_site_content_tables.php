<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_sections', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('eyebrow', 80)->nullable();
            $table->string('title', 160);
            $table->string('subtitle', 255)->nullable();
            $table->text('body')->nullable();
            $table->string('image_path', 255)->nullable();
            $table->string('image_alt', 160)->nullable();
            $table->string('primary_cta_label', 60)->nullable();
            $table->string('primary_cta_target', 160)->nullable();
            $table->string('secondary_cta_label', 60)->nullable();
            $table->string('secondary_cta_target', 160)->nullable();
            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('site_navigation_links', function (Blueprint $table) {
            $table->id();
            $table->string('label', 60);
            $table->string('group', 24)->default('primary');
            $table->string('target_type', 16)->default('route');
            $table->string('target', 160);
            $table->boolean('opens_in_new_tab')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['group', 'is_active', 'sort_order']);
        });

        Schema::create('faq_items', function (Blueprint $table) {
            $table->id();
            $table->string('question', 200);
            $table->text('answer');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_items');
        Schema::dropIfExists('site_navigation_links');
        Schema::dropIfExists('site_sections');
    }
};

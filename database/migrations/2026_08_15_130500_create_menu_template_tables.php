<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Шаблон тижня або двотижневого циклу — застосовується на діапазон дат одним натисканням.
        Schema::create('menu_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('cycle_length')->default(7);  // 7 або 14 днів
            $table->timestamps();
        });

        Schema::create('menu_template_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_index');   // 1..cycle_length
            $table->boolean('is_working_day')->default(true);
            $table->timestamps();

            $table->unique(['menu_template_id', 'day_index']);
        });

        Schema::create('menu_template_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_template_day_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['complex', 'choice', 'extra']);
            $table->string('title');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('menu_template_section_dishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_template_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dish_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['menu_template_section_id', 'dish_id'], 'mtsd_section_dish_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_template_section_dishes');
        Schema::dropIfExists('menu_template_sections');
        Schema::dropIfExists('menu_template_days');
        Schema::dropIfExists('menu_templates');
    }
};

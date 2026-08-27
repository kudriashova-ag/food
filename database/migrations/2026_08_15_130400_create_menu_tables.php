<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Меню одного постачальника на одну дату.
        Schema::create('menu_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // Неробочий день (свято, канікули) — меню учням не показується.
            $table->boolean('is_working_day')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'date']);
            $table->index(['date', 'published_at']);
        });

        // Секція дня: комплекс / група вибору / додаткові страви.
        Schema::create('menu_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_day_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['complex', 'choice', 'extra']);
            $table->string('title');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['menu_day_id', 'sort']);
        });

        // Страви всередині секції. Ціна не дублюється — береться з dishes.price.
        Schema::create('menu_section_dishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dish_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['menu_section_id', 'dish_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_section_dishes');
        Schema::dropIfExists('menu_sections');
        Schema::dropIfExists('menu_days');
    }
};

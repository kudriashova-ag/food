<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Додати ціну комплексу на рівень menu_sections і menu_template_sections.
        // Фіксована ціна застосовна тільки коли type = 'complex'.

        Schema::table('menu_sections', function (Blueprint $table) {
            $table->decimal('price', 8, 2)->nullable()->after('title');
        });

        Schema::table('menu_template_sections', function (Blueprint $table) {
            $table->decimal('price', 8, 2)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('menu_sections', function (Blueprint $table) {
            $table->dropColumn('price');
        });

        Schema::table('menu_template_sections', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};

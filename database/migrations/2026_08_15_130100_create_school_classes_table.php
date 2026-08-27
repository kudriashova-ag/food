<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('grade');          // 1..11
            $table->string('letter', 4);                   // А, Б, В
            // Рік початку навчального року: 2026 = 2026/27. Переведення створює клас наступного року.
            $table->unsignedSmallInteger('academic_year');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['academic_year', 'grade', 'letter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_classes');
    }
};

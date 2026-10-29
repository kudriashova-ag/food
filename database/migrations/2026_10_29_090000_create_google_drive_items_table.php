<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Папки й таблиці, які сайт створив на Google Диску: Food, папка постачальника,
        // файл тижня. Шукати їх на Диску за назвою з доступом drive.file не вийде
        // (і назву можуть змінити), тож ID запам'ятовуємо тут.
        Schema::create('google_drive_items', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();      // root | supplier:{id} | week:{supplier_id}:{monday}
            $table->string('drive_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_drive_items');
    }
};

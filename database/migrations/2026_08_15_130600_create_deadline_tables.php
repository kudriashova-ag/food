<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Відносне правило на кожен день тижня, окремо для кожного постачальника.
        // offset_days = за скільки днів до дня харчування закривається приймання:
        // 1 + 09:00 → «нд, 09:00» для понеділка; 0 + 09:00 → «того ж дня до 09:00».
        Schema::create('deadline_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');            // 1 = понеділок … 7 = неділя (ISO-8601)
            $table->unsignedTinyInteger('order_offset_days')->default(1);
            $table->time('order_time')->default('09:00:00');
            $table->unsignedTinyInteger('cancel_offset_days')->default(1);
            $table->time('cancel_time')->default('09:00:00');
            $table->timestamps();

            $table->unique(['supplier_id', 'weekday']);
        });

        // Виняток на конкретну дату (наприклад, перед святом) — не змінює загального правила.
        Schema::create('deadline_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->dateTime('order_deadline_at')->nullable();
            $table->dateTime('cancel_deadline_at')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['supplier_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deadline_overrides');
        Schema::dropIfExists('deadline_rules');
    }
};

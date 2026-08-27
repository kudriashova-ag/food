<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Шапка: один номер на весь чек, навіть якщо в ньому кілька постачальників і дат.
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            // Клас на момент оформлення — щоб звіти минулих періодів не «переїжджали» після переведення.
            $table->foreignId('school_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->timestamp('placed_at');
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->timestamps();

            $table->index(['student_id', 'placed_at']);
        });

        // Позиція — центральна сутність: по ній працюють звіти для кухні, дедлайни й скасування.
        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('service_date');
            $table->foreignId('dish_id')->constrained()->restrictOnDelete();
            $table->foreignId('menu_section_id')->nullable()->constrained()->nullOnDelete();
            // Знімок на момент оформлення: назва й ціна не змінюються заднім числом.
            $table->string('dish_name');
            $table->enum('section_type', ['complex', 'choice', 'extra'])->nullable();
            $table->string('section_title')->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price', 8, 2);
            $table->enum('status', ['active', 'cancelled'])->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'service_date', 'status']);  // звіти для кухні
            $table->index(['student_id', 'service_date']);             // кабінет учня
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};

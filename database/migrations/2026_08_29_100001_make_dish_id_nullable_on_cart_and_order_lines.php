<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Комплекс у кошику та замовленні зберігається як один рядок з dish_id = null
        // (прив'язка лише через menu_section_id). Для choice/extra dish_id залишається
        // обов'язковим на логіці контролера, але БД-рівня це більше не вимагаємо.

        // SQLite не підтримує ALTER COLUMN / MODIFY, тому використовуємо dropForeign+recreate.
        // Для MySQL можна було б MODIFY, але спосіб нижче універсальний для обох БД.

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: dropForeign, dropColumn, addColumn (у транзакції для атомарності).
            DB::transaction(function (): void {
                Schema::table('cart_items', function ($table): void {
                    $table->dropForeign(['dish_id']);
                    $table->dropColumn('dish_id');
                });

                Schema::table('cart_items', function ($table): void {
                    $table->foreignId('dish_id')->nullable()->constrained()->restrictOnDelete();
                });

                Schema::table('order_lines', function ($table): void {
                    $table->dropForeign(['dish_id']);
                    $table->dropColumn('dish_id');
                });

                Schema::table('order_lines', function ($table): void {
                    $table->foreignId('dish_id')->nullable()->constrained()->restrictOnDelete();
                });
            });
        } else {
            // MySQL та інші: MODIFY.
            DB::statement('ALTER TABLE cart_items MODIFY dish_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE order_lines MODIFY dish_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        // При reversal — поставити dish_id BIGINT UNSIGNED NOT NULL.
        // Це спалить приватні дані (complex-рядки без dish_id), але це рекавері,
        // тому допускаємо розумне знищення даних.

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::transaction(function (): void {
                Schema::table('cart_items', function ($table): void {
                    $table->dropForeign(['dish_id']);
                    $table->dropColumn('dish_id');
                });

                Schema::table('cart_items', function ($table): void {
                    $table->foreignId('dish_id')->constrained()->restrictOnDelete();
                });

                Schema::table('order_lines', function ($table): void {
                    $table->dropForeign(['dish_id']);
                    $table->dropColumn('dish_id');
                });

                Schema::table('order_lines', function ($table): void {
                    $table->foreignId('dish_id')->constrained()->restrictOnDelete();
                });
            });
        } else {
            DB::statement('ALTER TABLE cart_items MODIFY dish_id BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE order_lines MODIFY dish_id BIGINT UNSIGNED NOT NULL');
        }
    }
};

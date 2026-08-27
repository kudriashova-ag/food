<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Бібліотека страв постачальника: страва створюється один раз і додається в будь-який день.
        Schema::create('dishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('composition')->nullable();
            $table->string('portion', 64)->nullable();   // вага / об'єм порції
            $table->decimal('price', 8, 2);              // ціна глобальна, лежить на страві
            $table->boolean('is_archived')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['supplier_id', 'is_archived']);
        });

        Schema::create('dish_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dish_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['dish_id', 'is_primary']);
        });

        Schema::create('allergens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('allergen_dish', function (Blueprint $table) {
            $table->foreignId('dish_id')->constrained()->cascadeOnDelete();
            $table->foreignId('allergen_id')->constrained()->cascadeOnDelete();

            $table->primary(['dish_id', 'allergen_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allergen_dish');
        Schema::dropIfExists('allergens');
        Schema::dropIfExists('dish_photos');
        Schema::dropIfExists('dishes');
    }
};

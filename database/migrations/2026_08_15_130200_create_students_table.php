<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->foreignId('school_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            // Деактивований учень (випускник) не входить, але його історія лишається.
            $table->boolean('is_active')->default(true);
            // Згода батьків на обробку персональних даних — фіксуємо факт, дату й IP.
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->timestamp('first_login_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['school_class_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};

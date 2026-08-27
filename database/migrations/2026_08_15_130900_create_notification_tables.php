<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // До одного акаунта можна прив'язати кілька Telegram (мама, тато).
        Schema::create('telegram_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('chat_id', 32);
            $table->string('username')->nullable();
            // При помилці 403 (бота заблоковано) прив'язка позначається неактивною.
            $table->boolean('is_active')->default(true);
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'chat_id']);
        });

        // Одноразовий токен для deep-link, живе 15 хвилин.
        Schema::create('telegram_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        // Журнал фактичних відправок (сама черга — стандартна таблиця jobs).
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('channel', ['mail', 'telegram']);
            $table->string('event');
            $table->string('recipient')->nullable();
            $table->json('payload')->nullable();
            $table->enum('status', ['sent', 'failed'])->default('sent');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('telegram_link_tokens');
        Schema::dropIfExists('telegram_links');
    }
};

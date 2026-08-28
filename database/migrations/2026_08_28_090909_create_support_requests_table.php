<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_requests', function (Blueprint $table): void {
            $table->id();

            // Звернення лишається в базі, навіть якщо пошта чи Telegram відмовили:
            // інакше питання просто зникло б.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->text('message');
            $table->string('ip', 45)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_requests');
    }
};

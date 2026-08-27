<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Календар школи: свята й канікули. Один на всіх постачальників —
        // інакше кожен проставляв би ті самі дати окремо (ТЗ, п. 5.4).
        Schema::create('non_working_days', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('title');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_working_days');
    }
};

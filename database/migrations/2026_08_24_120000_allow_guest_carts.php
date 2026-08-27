<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Меню й кошик відкриті без входу: гостьовий кошик живе за токеном із сесії,
        // а при вході переноситься в кошик учня.
        Schema::table('carts', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable()->change();
            $table->string('session_token', 64)->nullable()->unique()->after('student_id');
        });
    }

    public function down(): void
    {
        // Гостьові кошики без учня в старій схемі неможливі — прибираємо їх.
        DB::table('carts')->whereNull('student_id')->delete();

        Schema::table('carts', function (Blueprint $table) {
            $table->dropUnique(['session_token']);
            $table->dropColumn('session_token');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable(false)->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Пароль учня, який адміністратор має бачити в картці. Лежить зашифрованим
        // ключем застосунку (APP_KEY), для входу й далі використовується хеш у password.
        // Для вже наявних учнів порожній — хеш назад у пароль не перетворити.
        Schema::table('users', function (Blueprint $table) {
            $table->text('plain_password')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('plain_password');
        });
    }
};

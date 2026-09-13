<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // Реквізити для оплати — довільний текст (рахунок, ЄДРПОУ тощо),
            // редагує і сам постачальник, і адміністратор школи.
            $table->text('payment_details')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('payment_details');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // Пошта кухні може відрізнятися від пошти, під якою постачальник входить.
            $table->string('report_emails')->nullable()->after('phone');
            $table->time('digest_time')->default('18:00:00')->after('report_emails');
            $table->boolean('digest_enabled')->default(true)->after('digest_time');
            $table->boolean('cancellation_alerts_enabled')->default(true)->after('digest_enabled');
        });

        // Прив'язки Telegram тепер бувають двох видів: учнівські та постачальника.
        Schema::table('telegram_links', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('student_id')
                ->constrained()->cascadeOnDelete();

            $table->unique(['supplier_id', 'chat_id']);
        });

        Schema::table('telegram_link_tokens', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('student_id')
                ->constrained()->cascadeOnDelete();
        });

        // student_id більше не обов'язковий — прив'язка може належати постачальнику.
        // Змінюємо саме тип колонки, щоб не чіпати наявний зовнішній ключ.
        Schema::table('telegram_links', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable()->change();
        });

        Schema::table('telegram_link_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable()->change();
        });

        // Журнал відправлених дайджестів: за ним визначаємо, чи вже показували
        // кухні цифри на цю дату — і чи треба повідомляти про пізніші скасування.
        Schema::create('supplier_digests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->date('service_date');
            $table->boolean('is_final')->default(false);   // надіслано вже після дедлайну
            $table->unsignedInteger('positions')->default(0);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['supplier_id', 'service_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_digests');

        Schema::table('telegram_link_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
        });

        Schema::table('telegram_links', function (Blueprint $table) {
            $table->dropUnique(['supplier_id', 'chat_id']);
            $table->dropConstrainedForeignId('supplier_id');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn([
                'report_emails',
                'digest_time',
                'digest_enabled',
                'cancellation_alerts_enabled',
            ]);
        });
    }
};

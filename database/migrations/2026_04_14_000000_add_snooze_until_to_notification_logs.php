<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    //untuk menentukan kapan reminder yang sudah disnooze akan muncul lagi
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            // When the snoozed reminder should be shown again
            $table->dateTime('snooze_until')->nullable()->after('snooze_minutes');
        });
    }

    //untuk menghapus kolom snooze_until jika rollback migration
    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropColumn(['snooze_until']);
        });
    }
};

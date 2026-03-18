<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Segundos que el agente debe llevar offline antes de disparar la alerta.
            // 0 = alertar inmediatamente (comportamiento por defecto anterior).
            $table->unsignedInteger('offline_alert_delay_seconds')->default(0)->after('offline_after_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('offline_alert_delay_seconds');
        });
    }
};

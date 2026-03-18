<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            // Solo aplica a reglas agent_offline.
            // Segundos que el agente debe llevar offline antes de que esta regla dispare la alerta.
            // NULL o 0 = disparar en cuanto se detecte offline (comportamiento anterior).
            $table->unsignedInteger('offline_alert_delay_seconds')->nullable()->after('cooldown_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropColumn('offline_alert_delay_seconds');
        });
    }
};

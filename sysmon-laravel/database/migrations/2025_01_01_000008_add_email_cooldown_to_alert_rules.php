<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            // Tiempo mínimo entre emails para la misma alerta abierta (independiente del cooldown de comprobación).
            // Minimum time between emails for the same open alert (independent from check cooldown).
            // null = sin límite de tiempo entre emails (se envía en cada comprobación que pase el cooldown de regla)
            $table->unsignedInteger('email_cooldown_seconds')->nullable()->after('max_email_count');
        });
    }

    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropColumn('email_cooldown_seconds');
        });
    }
};

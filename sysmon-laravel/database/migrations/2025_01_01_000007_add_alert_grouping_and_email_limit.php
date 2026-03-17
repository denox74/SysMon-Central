<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            // Agrupación de ocurrencias: en lugar de crear N alertas del mismo tipo,
            // se acumulan como ocurrencias dentro de una alerta abierta.
            $table->json('occurrences')->nullable()->after('message');
            $table->unsignedInteger('occurrences_count')->default(0)->after('occurrences');
            // Contador de emails enviados para esta alerta abierta (se reinicia en 0 al crear nueva)
            $table->unsignedInteger('email_sent_count')->default(0)->after('occurrences_count');
        });

        Schema::table('alert_rules', function (Blueprint $table) {
            // Máximo de emails que puede enviar una alerta abierta de esta regla (null = ilimitado)
            $table->unsignedInteger('max_email_count')->nullable()->after('notify_email');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn(['occurrences', 'occurrences_count', 'email_sent_count']);
        });

        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropColumn('max_email_count');
        });
    }
};

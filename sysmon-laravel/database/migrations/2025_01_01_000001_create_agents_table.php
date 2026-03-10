<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();

            // Identificación
            $table->string('name', 100);                        // Nombre descriptivo ("web-server-01")
            $table->string('hostname', 255)->nullable();        // Hostname real del sistema
            $table->string('ip_address', 45)->nullable();       // IPv4 o IPv6
            $table->string('token', 80)->unique();              // Bearer token de autenticación
            $table->string('token_name', 100)->default('default');

            // Información del sistema (se actualiza con cada ping)
            $table->string('os', 100)->nullable();
            $table->string('distro', 150)->nullable();
            $table->string('arch', 30)->nullable();
            $table->integer('cpu_cores')->nullable();
            $table->decimal('ram_total_gb', 8, 2)->nullable();

            // Estado
            $table->enum('status', ['online', 'warning', 'critical', 'offline'])
                  ->default('offline');
            $table->timestamp('last_seen_at')->nullable();      // Último ping recibido
            $table->integer('offline_after_seconds')->default(120); // Tiempo sin ping → offline

            // Config por agente (puede sobreescribir los umbrales globales)
            $table->json('custom_thresholds')->nullable();

            // Notificaciones
            $table->boolean('notify_email')->default(true);
            $table->string('notify_email_to')->nullable();      // Si null, usa el email global

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};

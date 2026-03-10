<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada fila = un ciclo de recolección del agente
        Schema::create('metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();

            // Timestamp del agente (puede diferir del servidor)
            $table->timestamp('collected_at');

            // ── CPU ──────────────────────────────────────────────
            $table->decimal('cpu_usage_percent', 5, 2)->nullable();
            $table->decimal('cpu_load_1m',  5, 2)->nullable();
            $table->decimal('cpu_load_5m',  5, 2)->nullable();
            $table->decimal('cpu_load_15m', 5, 2)->nullable();
            $table->decimal('cpu_freq_mhz', 8, 2)->nullable();
            $table->json('cpu_per_core')->nullable();            // [72.1, 61.4, …]

            // ── RAM ──────────────────────────────────────────────
            $table->decimal('ram_usage_percent', 5, 2)->nullable();
            $table->decimal('ram_used_gb',       8, 2)->nullable();
            $table->decimal('ram_total_gb',      8, 2)->nullable();
            $table->decimal('swap_usage_percent', 5, 2)->nullable();
            $table->decimal('swap_used_gb',       8, 2)->nullable();

            // ── RED ──────────────────────────────────────────────
            $table->decimal('net_sent_mb',  12, 2)->nullable();
            $table->decimal('net_recv_mb',  12, 2)->nullable();
            $table->integer('net_connections')->nullable();

            // ── RESUMEN DISCOS (máx uso entre particiones) ───────
            $table->decimal('disk_max_usage_percent', 5, 2)->nullable();

            // ── TEMPERATURA (máxima registrada en el ciclo) ──────
            $table->decimal('temp_max_celsius', 6, 2)->nullable();
            $table->string('temp_max_sensor', 100)->nullable();

            // ── DATOS COMPLETOS EN JSON ───────────────────────────
            // Para consultas detalladas desde el panel sin tocar otras tablas
            $table->json('disks')->nullable();
            $table->json('network_interfaces')->nullable();
            $table->json('temperatures')->nullable();
            $table->json('processes')->nullable();           // Top N procesos

            // ── META ─────────────────────────────────────────────
            $table->integer('uptime_secs')->nullable();

            $table->timestamps();  // created_at = cuando llegó al servidor

            $table->index(['agent_id', 'collected_at']);
            $table->index('collected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_snapshots');
    }
};

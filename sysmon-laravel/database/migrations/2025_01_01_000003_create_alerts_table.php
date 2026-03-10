<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignId('metric_snapshot_id')->nullable()
                  ->constrained('metric_snapshots')->nullOnDelete();

            // Identificación de la regla
            $table->string('rule_name', 100);       // e.g. "cpu_critical"
            $table->string('metric', 100);           // e.g. "cpu.usage_percent"
            $table->enum('severity', ['info', 'warning', 'critical']);
            $table->string('source', 20)->default('agent');  // 'agent' | 'server'

            // Valores
            $table->decimal('value',     10, 4);
            $table->decimal('threshold', 10, 4);
            $table->text('message');

            // Estado
            $table->enum('status', ['open', 'acknowledged', 'resolved'])
                  ->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();

            // Notificaciones enviadas
            $table->boolean('notified_email')->default(false);
            $table->timestamp('notified_at')->nullable();

            // Timestamp del agente
            $table->timestamp('fired_at');

            $table->timestamps();

            $table->index(['agent_id', 'status']);
            $table->index(['severity', 'status']);
            $table->index('fired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};

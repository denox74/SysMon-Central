<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Umbrales configurables desde el panel (se sincronizan al agente opcionalmente)
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();

            // null = regla global; id = regla específica para ese agente
            $table->foreignId('agent_id')->nullable()
                  ->constrained('agents')->cascadeOnDelete();

            $table->string('name', 100);            // Nombre legible
            $table->string('rule_key', 100);        // Clave única, e.g. "cpu_critical"
            $table->string('metric_path', 100);     // e.g. "cpu.usage_percent"
            $table->enum('operator', ['gt', 'gte', 'lt', 'lte']);
            $table->decimal('threshold', 10, 4);
            $table->enum('severity', ['info', 'warning', 'critical']);
            $table->string('message_template', 255);
            $table->integer('cooldown_seconds')->default(300);
            $table->boolean('notify_email')->default(true);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['agent_id', 'rule_key']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};

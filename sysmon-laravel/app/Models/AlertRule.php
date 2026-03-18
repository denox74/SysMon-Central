<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regla de umbral que dispara alertas cuando una métrica supera el valor configurado.
 * Threshold rule that fires alerts when a metric exceeds the configured value.
 *
 * agent_id = NULL → regla global (aplica a todos los agentes)
 *                    global rule (applies to all agents)
 * agent_id = X    → regla específica de un agente (sobreescribe la global del mismo rule_key)
 *                    agent-specific rule (overrides global rule with same rule_key)
 *
 * metric_path usa notación punto para navegar el payload del agente:
 * metric_path uses dot notation to navigate the agent payload:
 *   "cpu.usage_percent"       → $payload['cpu']['usage_percent']
 *   "disk_max_usage_percent"  → campo calculado por MetricSnapshot::fromAgentPayload()
 */
class AlertRule extends Model
{
    protected $fillable = [
        'agent_id', 'name', 'rule_key', 'metric_path',
        'operator', 'threshold', 'severity', 'message_template',
        'cooldown_seconds', 'offline_alert_delay_seconds', 'notify_email', 'max_email_count', 'email_cooldown_seconds', 'is_active',
    ];

    protected $casts = [
        'threshold'              => 'float',
        'notify_email'           => 'boolean',
        'is_active'              => 'boolean',
        'max_email_count'        => 'integer',
        'email_cooldown_seconds' => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** Devuelve todas las reglas globales activas (sin agente asignado).
     *  Returns all active global rules (without assigned agent). */
    public static function globalRules()
    {
        return self::whereNull('agent_id')->where('is_active', true)->get();
    }
}

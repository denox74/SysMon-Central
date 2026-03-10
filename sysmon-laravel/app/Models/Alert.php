<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alerta disparada cuando una métrica supera un umbral.
 * Fired alert when a metric exceeds a threshold.
 *
 * Ciclo de vida / Lifecycle: open → acknowledged → resolved → (archived)
 *
 * source = 'agent'  → detectada y enviada por el agente Python
 * source = 'server' → evaluada por Laravel al procesar el snapshot
 */
class Alert extends Model
{
    protected $fillable = [
        'agent_id', 'metric_snapshot_id',
        'rule_name', 'metric', 'severity', 'source',
        'value', 'threshold', 'message',
        'status', 'resolved_at', 'resolution_note',
        'archived_at',
        'notified_email', 'notified_at',
        'fired_at',
    ];

    protected $casts = [
        'fired_at'      => 'datetime',
        'resolved_at'   => 'datetime',
        'archived_at'   => 'datetime',
        'notified_at'   => 'datetime',
        'value'         => 'float',
        'threshold'     => 'float',
        'notified_email'=> 'boolean',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(MetricSnapshot::class, 'metric_snapshot_id');
    }

    // ── Acciones de ciclo de vida / Lifecycle actions ───────────────

    /** Marca la alerta como resuelta con nota opcional. / Marks alert as resolved with optional note. */
    public function resolve(string $note = ''): void
    {
        $this->update([
            'status'          => 'resolved',
            'resolved_at'     => now(),
            'resolution_note' => $note,
        ]);
    }

    /** Marca la alerta como vista sin cerrarla. / Marks alert as seen without closing it. */
    public function acknowledge(): void
    {
        $this->update(['status' => 'acknowledged']);
    }

    /** Archiva la alerta para ocultarla de la vista principal. / Archives alert to hide from main view. */
    public function archive(): void
    {
        $this->update(['archived_at' => now()]);
    }

    /** Crea una alerta desde el array que manda el agente. */
    public static function fromAgentAlert(int $agentId, int $snapshotId, array $data): self
    {
        return self::create([
            'agent_id'           => $agentId,
            'metric_snapshot_id' => $snapshotId,
            'rule_name'          => $data['rule'],
            'metric'             => $data['metric'],
            'severity'           => $data['severity'],
            'source'             => 'agent',
            'value'              => $data['value'],
            'threshold'          => $data['threshold'],
            'message'            => $data['message'],
            'fired_at'           => now(),
            'status'             => 'open',
        ]);
    }
}

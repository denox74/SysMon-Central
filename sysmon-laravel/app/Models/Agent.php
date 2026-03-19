<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Agent extends Model
{
    protected $fillable = [
        'name', 'hostname', 'ip_address', 'token', 'token_name',
        'os', 'distro', 'arch', 'cpu_cores', 'ram_total_gb',
        'status', 'last_seen_at', 'offline_after_seconds',
        'custom_thresholds', 'notify_email', 'notify_email_to',
        'is_active', 'notes',
    ];

    protected $casts = [
        'custom_thresholds' => 'array',
        'notify_email'      => 'boolean',
        'is_active'         => 'boolean',
        'last_seen_at'      => 'datetime',
        'ram_total_gb'      => 'float',
    ];

    protected $hidden = ['token'];

    // ── Relaciones ──────────────────────────────────────────────────

    public function snapshots(): HasMany
    {
        return $this->hasMany(MetricSnapshot::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public static function generateToken(): string
    {
        return Str::random(60);

    }
    /** Devuelve el último snapshot o null. */
    public function latestSnapshot(): ?MetricSnapshot
    {
        return $this->snapshots()->latest('collected_at')->first();
    }

    /** Marca el agente como visto ahora y actualiza su estado. */
    public function markSeen(string $newStatus = 'online'): void
    {
        $this->update([
            'last_seen_at' => now(),
            'status'       => $newStatus,
        ]);
    }

    /** Devuelve si el agente está offline (sin ping reciente). */
    public function isOffline(): bool
    {
        if (! $this->last_seen_at) {
            return true;
        }
        return $this->last_seen_at->diffInSeconds(now()) > $this->offline_after_seconds;
    }

/** Alertas abiertas no resueltas. */
    public function openAlerts(): HasMany
    {
        return $this->alerts()->where('status', 'open');
    }
}

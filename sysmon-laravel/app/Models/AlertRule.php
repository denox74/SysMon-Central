<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertRule extends Model
{
    protected $fillable = [
        'agent_id', 'name', 'rule_key', 'metric_path',
        'operator', 'threshold', 'severity', 'message_template',
        'cooldown_seconds', 'notify_email', 'is_active',
    ];

    protected $casts = [
        'threshold'    => 'float',
        'notify_email' => 'boolean',
        'is_active'    => 'boolean',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public static function globalRules()
    {
        return self::whereNull('agent_id')->where('is_active', true)->get();
    }
}

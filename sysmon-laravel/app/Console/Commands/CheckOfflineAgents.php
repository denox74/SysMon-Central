<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Services\AlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckOfflineAgents extends Command
{
    protected $signature   = 'sysmon:check-offline';
    protected $description = 'Marca como offline los agentes que no han enviado ping en el tiempo configurado';

    public function handle(AlertService $alertService): int
    {
        $agents = Agent::where('is_active', true)
            ->where('status', '!=', 'offline')
            ->get();

        $markedOffline = 0;

        foreach ($agents as $agent) {
            if ($agent->isOffline()) {
                $agent->update(['status' => 'offline']);
                $markedOffline++;
                $this->warn("Agente offline: {$agent->name} (último ping: {$agent->last_seen_at})");

                // Disparar alerta offline si existe una regla configurada para ello
                $this->fireOfflineAlert($agent, $alertService);
            }
        }

        $this->info("Revisados: {$agents->count()} agentes. Marcados offline: {$markedOffline}");
        return self::SUCCESS;
    }

    private function fireOfflineAlert(Agent $agent, AlertService $alertService): void
    {
        // Buscar regla agent_offline aplicable (específica del agente o global)
        $rule = AlertRule::where('metric_path', 'agent_offline')
            ->where('is_active', true)
            ->where(function ($q) use ($agent) {
                $q->whereNull('agent_id')->orWhere('agent_id', $agent->id);
            })
            ->orderByRaw('agent_id IS NOT NULL DESC') // específica tiene prioridad
            ->first();

        if (! $rule) {
            return;
        }

        // Cooldown: un email por agente cada $rule->cooldown_seconds (mínimo 10 min)
        $cacheKey = "offline_alert:{$agent->id}:{$rule->rule_key}";
        if (Cache::has($cacheKey)) {
            return;
        }

        $lastSeen = $agent->last_seen_at
            ? $agent->last_seen_at->diffForHumans()
            : 'nunca';

        $alert = Alert::create([
            'agent_id'           => $agent->id,
            'metric_snapshot_id' => null,
            'rule_name'          => $rule->rule_key,
            'metric'             => 'agent_offline',
            'severity'           => $rule->severity,
            'source'             => 'server',
            'value'              => 0,
            'threshold'          => 1,
            'message'            => "El agente {$agent->name} está offline. Último contacto: {$lastSeen}.",
            'fired_at'           => now(),
            'status'             => 'open',
        ]);

        Cache::put($cacheKey, true, now()->addSeconds($rule->cooldown_seconds));

        if ($rule->notify_email) {
            $alertService->notify($agent, $alert);
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Services\AlertService;
use Illuminate\Console\Command;

class CheckOfflineAgents extends Command
{
    protected $signature   = 'sysmon:check-offline';
    protected $description = 'Marca como offline los agentes que no han enviado ping en el tiempo configurado';

    public function handle(AlertService $alertService): int
    {
        $agents = Agent::where('is_active', true)->get();

        $markedOffline = 0;

        foreach ($agents as $agent) {
            if ($agent->isOffline()) {
                // Actualizar estado solo si no estaba ya marcado como offline
                if ($agent->status !== 'offline') {
                    $agent->update(['status' => 'offline']);
                    $markedOffline++;
                    $this->warn("Agente offline: {$agent->name} (último ping: {$agent->last_seen_at})");
                }

                // Disparar alerta (el cooldown y el delay mínimo se comprueban dentro)
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

        // Comprobar si el agente lleva offline el tiempo mínimo configurado en la regla
        $delay = (int) ($rule->offline_alert_delay_seconds ?? 0);
        if ($delay > 0 && $agent->last_seen_at) {
            $offlineSeconds = $agent->last_seen_at->diffInSeconds(now());
            if ($offlineSeconds < $delay) {
                return;
            }
        }

        // Buscar alerta abierta o acknowledged del mismo tipo (agrupación)
        $existing = Alert::where('agent_id', $agent->id)
            ->where('rule_name', $rule->rule_key)
            ->whereIn('status', ['open', 'acknowledged'])
            ->first();

        // Cooldown basado en BD (updated_at de la alerta existente)
        $lastActivity = $existing?->updated_at ?? now()->subDays(999);
        if (abs(now()->diffInSeconds($lastActivity)) < $rule->cooldown_seconds) {
            return;
        }

        $lastSeen = $agent->last_seen_at
            ? $agent->last_seen_at->diffForHumans()
            : 'nunca';

        $message = "El agente {$agent->name} está offline. Último contacto: {$lastSeen}.";

        if ($existing) {
            $occurrences   = $existing->occurrences ?? [];
            $occurrences[] = [
                'value'    => 0,
                'fired_at' => now()->toISOString(),
                'message'  => $message,
            ];
            $existing->update([
                'occurrences'       => $occurrences,
                'occurrences_count' => count($occurrences),
                'message'           => $message,
                'status'            => 'open',
            ]);
            $alert = $existing;
        } else {
            $alert = Alert::create([
                'agent_id'           => $agent->id,
                'metric_snapshot_id' => null,
                'rule_name'          => $rule->rule_key,
                'metric'             => 'agent_offline',
                'severity'           => $rule->severity,
                'source'             => 'server',
                'value'              => 0,
                'threshold'          => 1,
                'message'            => $message,
                'fired_at'           => now(),
                'status'             => 'open',
                'occurrences_count'  => 0,
                'email_sent_count'   => 0,
            ]);
        }

        if ($rule->notify_email) {
            $alertService->notify($agent, $alert, $rule);
        }
    }
}

<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Alert;
use App\Models\MetricSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MetricsService
{
    public function __construct(
        private readonly AlertService $alertService
    ) {}

    /**
     * Procesa el payload completo de un agente:
     *  1. Actualiza la información del agente
     *  2. Guarda el snapshot de métricas
     *  3. Procesa las alertas enviadas por el agente
     *  4. Evalúa umbrales del servidor (si los hay)
     *
     * Todo en una transacción para mantener consistencia.
     */
    public function process(Agent $agent, array $payload): MetricSnapshot
    {
        return DB::transaction(function () use ($agent, $payload) {

            // 1. Actualizar info del agente con los datos del sistema
            $this->updateAgentInfo($agent, $payload);

            // 2. Persistir el snapshot
            $snapshot = MetricSnapshot::create(
                MetricSnapshot::fromAgentPayload($agent->id, $payload)
            );

            // 3. Alertas enviadas por el propio agente
            $agentAlerts = $payload['alerts'] ?? [];
            foreach ($agentAlerts as $alertData) {
                $this->alertService->saveFromAgent($agent, $snapshot, $alertData);
            }

            // 4. Evaluar umbrales configurados en el servidor
            //    Enriquecer payload con campos calculados del snapshot (ej. disk_max_usage_percent)
            //    para que las reglas con esos metric_path puedan resolverse.
            $enrichedPayload = array_merge($payload, array_filter([
                'disk_max_usage_percent' => $snapshot->disk_max_usage_percent,
                'temp_max_celsius'       => $snapshot->temp_max_celsius,
            ], fn($v) => $v !== null));
            $this->alertService->evaluateServerRules($agent, $snapshot, $enrichedPayload);

            // 5. Actualizar estado del agente según severidad actual
            $this->updateAgentStatus($agent, $snapshot);

            Log::info("Snapshot guardado", [
                'agent' => $agent->name,
                'cpu'   => $snapshot->cpu_usage_percent . '%',
                'ram'   => $snapshot->ram_usage_percent . '%',
                'alerts'=> count($agentAlerts),
            ]);

            return $snapshot;
        });
    }

    // ── Privados ────────────────────────────────────────────────────

    private function updateAgentInfo(Agent $agent, array $payload): void
    {
        $system = $payload['system'] ?? [];
        $cpu    = $payload['cpu']    ?? [];
        $ram    = $payload['ram']    ?? [];

        $agent->update(array_filter([
            'hostname'     => $system['hostname']    ?? null,
            'ip_address'   => $system['ip']          ?? null,
            'distro'       => $system['distro']      ?? null,
            'arch'         => $system['arch']        ?? null,
            'cpu_cores'    => $cpu['core_count']     ?? null,
            'ram_total_gb' => $ram['total_gb']       ?? null,
            'last_seen_at' => now(),
            // El nombre sólo se sobreescribe si el agente envía uno
            ...( isset($payload['agent_name']) ? ['name' => $payload['agent_name']] : [] ),
        ], fn($v) => $v !== null));
    }

    private function updateAgentStatus(Agent $agent, MetricSnapshot $snap): void
    {
        $openCritical = $agent->openAlerts()->where('severity', 'critical')->exists();
        $openWarning  = $agent->openAlerts()->where('severity', 'warning')->exists();

        $status = match(true) {
            $openCritical => 'critical',
            $openWarning  => 'warning',
            default       => 'online',
        };

        $agent->update(['status' => $status, 'last_seen_at' => now()]);
    }
}

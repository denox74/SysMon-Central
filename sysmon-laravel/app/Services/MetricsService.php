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
    public function process(Agent $agent, array $payload, ?string $connectionIp = null): MetricSnapshot
    {
        // Transacción mínima: solo guardar snapshot y alertas del agente.
        // La evaluación de reglas del servidor va FUERA para que un fallo SMTP
        // no revierta el snapshot ni bloquee la transacción.
        $snapshot = DB::transaction(function () use ($agent, $payload, $connectionIp) {

            // 1. Actualizar info del agente con los datos del sistema
            $this->updateAgentInfo($agent, $payload, $connectionIp);

            // 2. Persistir el snapshot
            $snapshot = MetricSnapshot::create(
                MetricSnapshot::fromAgentPayload($agent->id, $payload)
            );

            // 3. Alertas enviadas por el propio agente
            $agentAlerts = $payload['alerts'] ?? [];
            foreach ($agentAlerts as $alertData) {
                try {
                    $this->alertService->saveFromAgent($agent, $snapshot, $alertData);
                } catch (\Throwable $e) {
                    Log::error("saveFromAgent failed for {$agent->name}: {$e->getMessage()}");
                }
            }

            Log::info("Snapshot guardado", [
                'agent' => $agent->name,
                'cpu'   => $snapshot->cpu_usage_percent . '%',
                'ram'   => $snapshot->ram_usage_percent . '%',
                'alerts'=> count($agentAlerts),
            ]);

            return $snapshot;
        });

        // 4. Evaluar umbrales del servidor FUERA de la transacción
        //    (el email se envía aquí; si falla, el snapshot ya está guardado)
        try {
            $enrichedPayload = array_merge($payload, array_filter([
                'disk_max_usage_percent' => $snapshot->disk_max_usage_percent,
                'temp_max_celsius'       => $snapshot->temp_max_celsius,
            ], fn($v) => $v !== null));
            $this->alertService->evaluateServerRules($agent, $snapshot, $enrichedPayload);
        } catch (\Throwable $e) {
            Log::error("evaluateServerRules failed for {$agent->name}: {$e->getMessage()}");
        }

        // 5. Actualizar estado del agente (después de crear alertas)
        $this->updateAgentStatus($agent, $snapshot);

        return $snapshot;
    }

    // ── Privados ────────────────────────────────────────────────────

    private function updateAgentInfo(Agent $agent, array $payload, ?string $connectionIp = null): void
    {
        $system = $payload['system'] ?? [];
        $cpu    = $payload['cpu']    ?? [];
        $ram    = $payload['ram']    ?? [];

        // Preferimos la IP real de conexión (ZeroTier, VPN…) sobre la que reporta
        // el agente desde su propio sistema, que suele ser la IP local o loopback.
        $ip = $connectionIp ?? $system['ip'] ?? null;

        $agent->update(array_filter([
            'hostname'     => $system['hostname']    ?? null,
            'ip_address'   => $ip,
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

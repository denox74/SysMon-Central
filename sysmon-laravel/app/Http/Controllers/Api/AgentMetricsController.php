<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMetricsRequest;
use App\Models\Agent;
use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints consumidos por el agente Python.
 * Todos requieren el middleware AuthenticateAgent.
 */
class AgentMetricsController extends Controller
{
    public function __construct(
        private readonly MetricsService $metricsService
    ) {}

    /**
     * POST /api/agent/metrics
     * Recibe el payload completo del agente.
     */
    public function store(StoreMetricsRequest $request): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->get('_agent');

        $snapshot = $this->metricsService->process($agent, $request->validated());

        return response()->json([
            'ok'          => true,
            'snapshot_id' => $snapshot->id,
            'server_time' => now()->toISOString(),
        ], 201);
    }

    /**
     * GET /api/agent/ping
     * Heartbeat — el agente puede usarlo para comprobar conectividad.
     */
    public function ping(Request $request): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->get('_agent');
        $agent->update(['last_seen_at' => now()]);

        return response()->json([
            'ok'         => true,
            'agent'      => $agent->name,
            'server_time'=> now()->toISOString(),
        ]);
    }

    /**
     * GET /api/agent/config
     * El agente puede pedir sus umbrales actualizados desde el panel.
     */
    public function config(Request $request): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->get('_agent');

        // Reglas globales + reglas del agente fusionadas
        $globalRules = \App\Models\AlertRule::whereNull('agent_id')
            ->where('is_active', true)
            ->get(['rule_key', 'metric_path', 'operator', 'threshold', 'severity', 'cooldown_seconds']);

        $agentRules = $agent->alertRules()
            ->where('is_active', true)
            ->get(['rule_key', 'metric_path', 'operator', 'threshold', 'severity', 'cooldown_seconds']);

        // Las reglas del agente sobreescriben las globales por rule_key
        $merged = $globalRules->keyBy('rule_key')
            ->merge($agentRules->keyBy('rule_key'))
            ->values();

        return response()->json([
            'interval_seconds' => 30,      // Aquí podrías hacer esto configurable por agente
            'rules'            => $merged,
            'custom'           => $agent->custom_thresholds ?? [],
        ]);
    }
}

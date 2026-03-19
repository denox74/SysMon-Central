<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\EmailSetting;
use App\Models\MetricSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints consumidos por el panel Vue.
 * Requieren autenticación de usuario (Laravel Sanctum / session).
 */
class PanelController extends Controller
{
    // ── Dashboard ──────────────────────────────────────────────────

    /**
     * GET /api/panel/dashboard
     * Resumen general: todos los agentes con su último snapshot.
     */
    public function dashboard(): JsonResponse
    {
        $agents = Agent::with(['snapshots' => function ($q) {
            $q->latest('collected_at')->limit(1);
        }])
        ->where('is_active', true)
        ->get()
        ->map(function (Agent $agent) {
            $snap = $agent->snapshots->first();
            return [
                'id'              => $agent->id,
                'name'            => $agent->name,
                'hostname'        => $agent->hostname,
                'ip_address'      => $agent->ip_address,
                'distro'          => $agent->distro,
                'notes'           => $agent->notes,
                'notify_email'    => $agent->notify_email,
                'notify_email_to' => $agent->notify_email_to,
                'status'          => $agent->isOffline() ? 'offline' : $agent->status,
                'last_seen_at'    => $agent->last_seen_at?->toISOString(),
                'open_alerts'     => $agent->openAlerts()->count(),
                'metrics'      => $snap ? [
                    'cpu_percent'  => $snap->cpu_usage_percent,
                    'ram_percent'  => $snap->ram_usage_percent,
                    'temp_max'     => $snap->temp_max_celsius,
                    'disk_max'     => $snap->disk_max_usage_percent,
                    'load_5m'      => $snap->cpu_load_5m,
                    'collected_at' => $snap->collected_at?->toISOString(),
                ] : null,
            ];
        });

        $openAlerts = Alert::where('status', 'open')
            ->with('agent:id,name')
            ->latest('fired_at')
            ->limit(10)
            ->get();

        return response()->json([
            'agents'      => $agents,
            'open_alerts' => $openAlerts,
            'totals'      => [
                'agents_online'   => $agents->where('status', 'online')->count(),
                'agents_warning'  => $agents->where('status', 'warning')->count(),
                'agents_critical' => $agents->where('status', 'critical')->count(),
                'agents_offline'  => $agents->where('status', 'offline')->count(),
                'open_alerts'     => Alert::where('status', 'open')->count(),
            ],
        ]);
    }

    // ── Agentes ────────────────────────────────────────────────────

    /** GET /api/panel/agents */
    public function agents(): JsonResponse
    {
        $agents = Agent::withCount([
            'alerts as open_alerts_count' => fn($q) => $q->where('status', 'open'),
        ])
        ->orderBy('name')
        ->get();

        return response()->json($agents);
    }

    /** GET /api/panel/agents/{agent} */
    public function agent(Agent $agent): JsonResponse
    {
        $latest = $agent->latestSnapshot();

        return response()->json([
            'agent'   => $agent,
            'latest'  => $latest,
            'rules'   => $agent->alertRules,
        ]);
    }

    /** POST /api/panel/agents — crear nuevo agente */
    public function createAgent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:100'],
            'notify_email'      => ['boolean'],
            'notify_email_to'   => ['nullable', 'email'],
            'notes'             => ['nullable', 'string'],
            'offline_after_seconds' => ['integer', 'min:30'],
        ]);

        $agent = Agent::create([
            ...$data,
            'token'      => Agent::generateToken(),
            'token_name' => 'default',
            'status'     => 'offline',
        ]);

        return response()->json([
            'agent' => $agent,
            'token' => $agent->token,  // sólo se devuelve al crear
        ], 201);
    }

    /** PUT /api/panel/agents/{agent} */
    public function updateAgent(Agent $agent, Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:100'],
            'notes'           => ['nullable', 'string', 'max:500'],
            'notify_email'    => ['sometimes', 'boolean'],
            'notify_email_to' => ['sometimes', 'nullable', 'email'],
        ]);
        $agent->update($data);
        return response()->json($agent);
    }

    /** DELETE /api/panel/agents/{agent} */
    public function deleteAgent(Agent $agent): JsonResponse
    {
        $agent->update(['is_active' => false]);
        return response()->json(['ok' => true]);
    }

    /** GET /api/panel/agents/{agent}/token */
    public function getToken(Agent $agent): JsonResponse
    {
        // El token está oculto en $hidden, lo recuperamos explícitamente
        $token = Agent::where('id', $agent->id)->value('token');
        return response()->json(['token' => $token]);
    }

    /** POST /api/panel/agents/{agent}/regenerate-token */
    public function regenerateToken(Agent $agent): JsonResponse
    {
        $newToken = Agent::generateToken();
        $agent->update(['token' => $newToken]);
        return response()->json(['token' => $newToken]);
    }

    // ── Métricas / Historial ───────────────────────────────────────

    /**
     * GET /api/panel/agents/{agent}/metrics
     * Historial para las gráficas del panel.
     * Parámetros: ?hours=24 &interval=5 (minutos de agrupación)
     */
    public function metrics(Agent $agent, Request $request): JsonResponse
    {
        $hours    = (int) $request->get('hours', 24);
        $hours    = min(max($hours, 1), 168);   // entre 1h y 7 días

        $since = now()->subHours($hours);

        $snapshots = MetricSnapshot::where('agent_id', $agent->id)
            ->where('collected_at', '>=', $since)
            ->orderBy('collected_at')
            ->get([
                'collected_at',
                'cpu_usage_percent',
                'cpu_load_5m',
                'ram_usage_percent',
                'swap_usage_percent',
                'temp_max_celsius',
                'disk_max_usage_percent',
                'net_sent_mb',
                'net_recv_mb',
            ]);

        return response()->json([
            'agent_id' => $agent->id,
            'hours'    => $hours,
            'count'    => $snapshots->count(),
            'data'     => $snapshots,
        ]);
    }

    /**
     * GET /api/panel/agents/{agent}/metrics/latest
     * Última lectura (para actualización en tiempo real por polling desde Vue).
     */
    public function latestMetrics(Agent $agent): JsonResponse
    {
        $snap = $agent->latestSnapshot();

        if (! $snap) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data'       => $snap,
            'agent_status' => $agent->isOffline() ? 'offline' : $agent->status,
        ]);
    }

    // ── Alertas ────────────────────────────────────────────────────

    /**
     * GET /api/panel/alerts
     * Alertas globales o filtradas por agente.
     */
    public function alerts(Request $request): JsonResponse
    {
        $query = Alert::with('agent:id,name,hostname')
            ->latest('fired_at');

        if ($agentId = $request->get('agent_id')) {
            $query->where('agent_id', $agentId);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($severity = $request->get('severity')) {
            $query->where('severity', $severity);
        }

        // Por defecto ocultar archivadas; pasar ?archived=1 para verlas
        if ($request->get('archived')) {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        $alerts = $query->paginate(50);

        return response()->json($alerts);
    }

    /** POST /api/panel/alerts/{alert}/archive */
    public function archiveAlert(Alert $alert): JsonResponse
    {
        $alert->archive();
        return response()->json(['ok' => true]);
    }

    /** POST /api/panel/alerts/resolve-all — resuelve todas las alertas abiertas o acknowledged */
    public function resolveAll(Request $request): JsonResponse
    {
        $query = Alert::whereIn('status', ['open', 'acknowledged']);

        if ($agentId = $request->get('agent_id')) {
            $query->where('agent_id', $agentId);
        }

        $count = $query->update(['status' => 'resolved', 'resolved_at' => now()]);

        return response()->json(['ok' => true, 'resolved' => $count]);
    }

    /** POST /api/panel/alerts/archive-resolved — archiva todas las resueltas */
    public function archiveResolved(Request $request): JsonResponse
    {
        $query = Alert::where('status', 'resolved')->whereNull('archived_at');

        if ($agentId = $request->get('agent_id')) {
            $query->where('agent_id', $agentId);
        }

        $count = $query->update(['archived_at' => now()]);

        return response()->json(['ok' => true, 'archived' => $count]);
    }

    /** GET /api/panel/agents/{agent}/alerts */
    public function agentAlerts(Agent $agent, Request $request): JsonResponse
    {
        $alerts = $agent->alerts()
            ->latest('fired_at')
            ->when($request->get('status'), fn($q, $s) => $q->where('status', $s))
            ->paginate(50);

        return response()->json($alerts);
    }

    /** POST /api/panel/alerts/{alert}/acknowledge */
    public function acknowledgeAlert(Alert $alert): JsonResponse
    {
        $alert->acknowledge();
        return response()->json(['ok' => true, 'alert' => $alert]);
    }

    /** POST /api/panel/alerts/{alert}/resolve */
    public function resolveAlert(Alert $alert, Request $request): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $alert->resolve($data['note'] ?? '');
        return response()->json(['ok' => true, 'alert' => $alert]);
    }

    // ── Reglas de alerta ───────────────────────────────────────────

    /** GET /api/panel/alert-rules */
    public function alertRules(Request $request): JsonResponse
    {
        $rules = AlertRule::with('agent:id,name')
            ->when($request->get('agent_id'), fn($q, $id) => $q->where('agent_id', $id))
            ->orderByRaw('agent_id IS NULL DESC')  // globales primero
            ->get();

        return response()->json($rules);
    }

    /** POST /api/panel/alert-rules */
    public function createAlertRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent_id'         => ['nullable', 'exists:agents,id'],
            'name'             => ['required', 'string', 'max:100'],
            'rule_key'         => ['required', 'string', 'max:100'],
            'metric_path'      => ['required', 'string', 'max:100'],
            'operator'         => ['required', 'in:gt,gte,lt,lte'],
            'threshold'        => ['required', 'numeric'],
            'severity'         => ['required', 'in:info,warning,critical'],
            'message_template' => ['required', 'string', 'max:255'],
            'cooldown_seconds'            => ['integer', 'min:60'],
            'offline_alert_delay_seconds' => ['nullable', 'integer', 'min:0'],
            'notify_email'                => ['boolean'],
            'max_email_count'             => ['nullable', 'integer', 'min:1'],
            'email_cooldown_seconds'      => ['nullable', 'integer', 'min:60'],
        ]);

        $rule = AlertRule::create($data);
        return response()->json($rule, 201);
    }

    /** PUT /api/panel/alert-rules/{rule} */
    public function updateAlertRule(AlertRule $rule, Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'             => ['string', 'max:100'],
            'threshold'        => ['numeric'],
            'severity'         => ['in:info,warning,critical'],
            'message_template' => ['string', 'max:255'],
            'cooldown_seconds'            => ['integer', 'min:60'],
            'offline_alert_delay_seconds' => ['nullable', 'integer', 'min:0'],
            'notify_email'                => ['boolean'],
            'is_active'                   => ['boolean'],
            'max_email_count'             => ['nullable', 'integer', 'min:1'],
            'email_cooldown_seconds'      => ['nullable', 'integer', 'min:60'],
        ]);

        $rule->update($data);
        return response()->json($rule);
    }

    /** DELETE /api/panel/alert-rules/{rule} */
    public function deleteAlertRule(AlertRule $rule): JsonResponse
    {
        $rule->delete();
        return response()->json(['ok' => true]);
    }

    // ── Configuración de email ─────────────────────────────────────

    /** GET /api/panel/settings/email */
    public function getEmailSettings(): JsonResponse
    {
        $s = EmailSetting::current();

        // La contraseña está cifrada con APP_KEY; si cambió entre reinicios la ignoramos
        try {
            $hasPassword = ! empty($s->smtp_password);
        } catch (\Throwable) {
            $hasPassword = false;
        }

        return response()->json([
            'smtp_host'         => $s->smtp_host,
            'smtp_port'         => $s->smtp_port,
            'smtp_username'     => $s->smtp_username,
            'smtp_password'     => $hasPassword ? '••••••' : '',
            'smtp_encryption'   => $s->smtp_encryption,
            'from_address'      => $s->from_address,
            'from_name'         => $s->from_name,
            'recipients'        => $s->recipients ?? [],
            'notify_severities' => $s->notify_severities ?? ['warning', 'critical'],
        ]);
    }

    /** PUT /api/panel/settings/email */
    public function updateEmailSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'smtp_host'           => ['required', 'string', 'max:255'],
            'smtp_port'           => ['required', 'integer', 'min:1', 'max:65535'],
            'smtp_username'       => ['nullable', 'string', 'max:255'],
            'smtp_password'       => ['nullable', 'string', 'max:500'],
            'smtp_encryption'     => ['required', 'in:tls,ssl,none'],
            'from_address'        => ['required', 'email'],
            'from_name'           => ['required', 'string', 'max:100'],
            'recipients'          => ['required', 'array'],
            'recipients.*'        => ['email'],
            'notify_severities'   => ['required', 'array'],
            'notify_severities.*' => ['in:info,warning,critical'],
        ]);

        $setting = EmailSetting::current();

        // Si la contraseña cifrada en BD es inválida (APP_KEY cambió), limpiar el valor
        // original para que Eloquent no intente descifrarla al comparar cambios
        try {
            $setting->smtp_password;
        } catch (\Throwable) {
            $setting->setRawAttributes(
                array_merge($setting->getAttributes(), ['smtp_password' => null]),
                true   // sync originals
            );
        }

        // Si el password viene como '••••••' (no editado), conservar el existente
        if (($data['smtp_password'] ?? '') === '••••••' || ($data['smtp_password'] ?? '') === '') {
            unset($data['smtp_password']);
        }

        $setting->update($data);

        return response()->json(['ok' => true]);
    }

    /** POST /api/panel/settings/email/test */
    public function testEmailSettings(Request $request): JsonResponse
    {
        $s = EmailSetting::current();

        if (empty($s->smtp_host) || empty($s->from_address)) {
            return response()->json(['ok' => false, 'message' => 'Configura y guarda el servidor SMTP antes de probar.'], 422);
        }

        $to = $request->input('to') ?: ($s->recipients[0] ?? $s->from_address);

        try {
            $smtpPassword = $s->smtp_password;
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'message' => 'La contraseña SMTP no se puede leer (APP_KEY cambió). Vuelve a guardar la contraseña en Configuración Email.'], 422);
        }

        try {
            // Usar Symfony Mailer directamente para garantizar las credenciales de BD
            $encryption = $s->smtp_encryption === 'none' ? '' : $s->smtp_encryption;
            $dsn = sprintf(
                'smtp://%s:%s@%s:%d',
                rawurlencode($s->smtp_username),
                rawurlencode($smtpPassword),
                $s->smtp_host,
                $s->smtp_port,
            );
            if ($encryption) {
                $dsn .= '?encryption=' . $encryption;
            }

            $transport = \Symfony\Component\Mailer\Transport::fromDsn($dsn);
            $mailer    = new \Symfony\Component\Mailer\Mailer($transport);

            $email = (new \Symfony\Component\Mime\Email())
                ->from(new \Symfony\Component\Mime\Address($s->from_address, $s->from_name))
                ->to($to)
                ->subject('[SysMon] Email de prueba')
                ->text('Este es un email de prueba enviado desde SysMon para verificar que la configuración SMTP es correcta.');

            $mailer->send($email);

            return response()->json(['ok' => true, 'message' => "Email de prueba enviado a {$to}"]);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /** DELETE /api/panel/alerts/archived */
    public function deleteArchivedAlerts(Request $request): JsonResponse
    {
        $query = Alert::whereNotNull('archived_at');
        if ($agentId = $request->get('agent_id')) {
            $query->where('agent_id', $agentId);
        }
        $count = $query->delete();
        return response()->json(['ok' => true, 'deleted' => $count]);
    }
}

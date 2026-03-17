<?php

namespace App\Services;

use App\Mail\AlertNotificationMail;
use App\Models\Agent;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\EmailSetting;
use App\Models\MetricSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AlertService
{
    /**
     * Guarda una alerta enviada por el agente.
     * Evita duplicados usando cooldown en cache.
     */
    public function saveFromAgent(Agent $agent, MetricSnapshot $snapshot, array $data): ?Alert
    {
        $cacheKey = "alert_cooldown:{$agent->id}:{$data['rule']}";

        // Si está en cooldown, no crear otra alerta igual
        if (Cache::has($cacheKey)) {
            return null;
        }

        $alert = Alert::fromAgentAlert($agent->id, $snapshot->id, $data);

        // Cooldown de 5 minutos por defecto (en producción vendrá de la regla)
        Cache::put($cacheKey, true, now()->addMinutes(5));

        // Notificar si corresponde
        $this->notify($agent, $alert);

        return $alert;
    }

    /**
     * Evalúa las reglas configuradas en el servidor contra el snapshot.
     * Estas reglas complementan (o sustituyen) las del agente.
     */
    public function evaluateServerRules(Agent $agent, MetricSnapshot $snapshot, array $payload): void
    {
        // Reglas globales + reglas específicas del agente
        $rules = AlertRule::where('is_active', true)
            ->where(function ($q) use ($agent) {
                $q->whereNull('agent_id')
                  ->orWhere('agent_id', $agent->id);
            })
            ->get();

        foreach ($rules as $rule) {
            // Las reglas de tipo agent_offline se evalúan en CheckOfflineAgents, no aquí
            if ($rule->metric_path === 'agent_offline') {
                continue;
            }

            $value = $this->extractValue($payload, $rule->metric_path);

            if ($value === null) {
                continue;
            }

            if (! $this->evaluate($value, $rule->operator, $rule->threshold)) {
                continue;
            }

            $cacheKey = "server_alert_cooldown:{$agent->id}:{$rule->rule_key}";
            if (Cache::has($cacheKey)) {
                continue;
            }

            $message = str_replace(
                ['{value}', '{threshold}'],
                [round($value, 2), $rule->threshold],
                $rule->message_template
            );

            $alert = Alert::create([
                'agent_id'           => $agent->id,
                'metric_snapshot_id' => $snapshot->id,
                'rule_name'          => $rule->rule_key,
                'metric'             => $rule->metric_path,
                'severity'           => $rule->severity,
                'source'             => 'server',
                'value'              => $value,
                'threshold'          => $rule->threshold,
                'message'            => $message,
                'fired_at'           => now(),
                'status'             => 'open',
            ]);

            Cache::put($cacheKey, true, now()->addSeconds($rule->cooldown_seconds));

            if ($rule->notify_email) {
                $this->notify($agent, $alert);
            }
        }
    }

    /**
     * Envía notificación por email.
     * Preparado para añadir más canales (Slack, Telegram, webhook…).
     */
    public function notify(Agent $agent, Alert $alert): void
    {
        // Configuración de email almacenada en BD (editable desde el panel)
        $settings = EmailSetting::current();

        // Filtrar por severidades habilitadas para email
        $allowedSeverities = $settings->notify_severities ?? ['warning', 'critical'];
        if (! in_array($alert->severity, $allowedSeverities)) {
            return;
        }

        // Email
        if ($agent->notify_email) {
            // Destinatario: email propio del agente, o los destinatarios globales
            $recipients = $agent->notify_email_to
                ? [$agent->notify_email_to]
                : array_values(array_filter($settings->recipients ?? []));

            if (! empty($recipients) && ! empty($settings->from_address)) {
                try {
                    // Aplicar SMTP y remitente desde BD en tiempo real
                    config([
                        'mail.mailers.smtp.host'       => $settings->smtp_host,
                        'mail.mailers.smtp.port'       => $settings->smtp_port,
                        'mail.mailers.smtp.username'   => $settings->smtp_username,
                        'mail.mailers.smtp.password'   => $settings->smtp_password,
                        'mail.mailers.smtp.encryption' => $settings->smtp_encryption === 'none'
                                                            ? null
                                                            : $settings->smtp_encryption,
                        'mail.from.address'            => $settings->from_address,
                        'mail.from.name'               => $settings->from_name,
                    ]);

                    Mail::to($recipients)->queue(new AlertNotificationMail($agent, $alert));
                    $alert->update([
                        'notified_email' => true,
                        'notified_at'    => now(),
                    ]);
                } catch (\Exception $e) {
                    Log::error("Error enviando email de alerta: {$e->getMessage()}");
                }
            }
        }

        // 🔌 Panel (preparado — en el futuro emitir evento para Vue via WebSocket)
        // broadcast(new AlertFired($agent, $alert));

        // 🔌 Slack (preparado — descomentar cuando configures)
        // if (config('sysmon.notifications.slack_webhook')) {
        //     SlackNotificationService::send($agent, $alert);
        // }

        // 🔌 Telegram (preparado)
        // if (config('sysmon.notifications.telegram_bot_token')) {
        //     TelegramNotificationService::send($agent, $alert);
        // }
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function extractValue(array $payload, string $path): ?float
    {
        $parts = explode('.', $path);
        $obj   = $payload;

        foreach ($parts as $part) {
            if (! is_array($obj) || ! array_key_exists($part, $obj)) {
                return null;
            }
            $obj = $obj[$part];
        }

        return is_numeric($obj) ? (float) $obj : null;
    }

    private function evaluate(float $value, string $operator, float $threshold): bool
    {
        return match($operator) {
            'gt'  => $value >  $threshold,
            'gte' => $value >= $threshold,
            'lt'  => $value <  $threshold,
            'lte' => $value <= $threshold,
            default => false,
        };
    }
}

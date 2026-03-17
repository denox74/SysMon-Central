<?php

namespace App\Services;

use App\Mail\AlertNotificationMail;
use App\Models\Agent;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\EmailSetting;
use App\Models\MetricSnapshot;
use Illuminate\Support\Facades\Log;

class AlertService
{
    /**
     * Guarda una alerta enviada por el agente (grouping + DB-based cooldown).
     */
    public function saveFromAgent(Agent $agent, MetricSnapshot $snapshot, array $data): ?Alert
    {
        $ruleName = $data['rule'] ?? 'unknown';

        // Buscar alerta abierta o acknowledged del mismo tipo
        $existing = Alert::where('agent_id', $agent->id)
            ->where('rule_name', $ruleName)
            ->whereIn('status', ['open', 'acknowledged'])
            ->first();

        // Cooldown basado en BD (updated_at de la alerta existente)
        $lastActivity = $existing?->updated_at ?? now()->subDays(999);
        if (now()->diffInSeconds($lastActivity) < 300) { // 5 min por defecto para alertas de agente
            return null;
        }

        if ($existing) {
            $occurrences   = $existing->occurrences ?? [];
            $occurrences[] = [
                'value'    => round($data['value'] ?? 0, 2),
                'fired_at' => now()->toISOString(),
                'message'  => $data['message'] ?? '',
            ];
            $existing->update([
                'occurrences'       => $occurrences,
                'occurrences_count' => count($occurrences),
                'value'             => $data['value'] ?? $existing->value,
                'message'           => $data['message'] ?? $existing->message,
                'status'            => 'open',
            ]);
            $alert = $existing;
        } else {
            $alert = Alert::fromAgentAlert($agent->id, $snapshot->id, $data);
        }

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

            // Buscar alerta abierta o acknowledged del mismo tipo (agrupación)
            $existing = Alert::where('agent_id', $agent->id)
                ->where('rule_name', $rule->rule_key)
                ->whereIn('status', ['open', 'acknowledged'])
                ->first();

            // Cooldown basado en BD: tiempo desde la última actividad de la alerta
            $lastActivity = $existing?->updated_at ?? now()->subDays(999);
            if (now()->diffInSeconds($lastActivity) < $rule->cooldown_seconds) {
                continue;
            }

            $message = str_replace(
                ['{value}', '{threshold}'],
                [round($value, 2), $rule->threshold],
                $rule->message_template
            );

            if ($existing) {
                // Añadir ocurrencia al grupo existente
                $occurrences   = $existing->occurrences ?? [];
                $occurrences[] = [
                    'value'    => round($value, 2),
                    'fired_at' => now()->toISOString(),
                    'message'  => $message,
                ];
                $existing->update([
                    'occurrences'        => $occurrences,
                    'occurrences_count'  => count($occurrences),
                    'value'              => $value,
                    'message'            => $message,
                    'metric_snapshot_id' => $snapshot->id,
                    'status'             => 'open',
                ]);
                $alert = $existing;
            } else {
                // Crear nueva alerta (sin ocurrencias previas)
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
                    'occurrences_count'  => 0,
                    'email_sent_count'   => 0,
                ]);
            }

            if ($rule->notify_email) {
                $this->notify($agent, $alert, $rule);
            }
        }
    }

    /**
     * Envía notificación por email.
     * $rule opcional para verificar el límite de emails por alerta abierta.
     */
    public function notify(Agent $agent, Alert $alert, ?AlertRule $rule = null): void
    {
        // Configuración de email almacenada en BD (editable desde el panel)
        $settings = EmailSetting::current();

        // Filtrar por severidades habilitadas para email
        $allowedSeverities = $settings->notify_severities ?? ['warning', 'critical'];
        if (! in_array($alert->severity, $allowedSeverities)) {
            return;
        }

        // Límite de emails por alerta abierta (max_email_count en la regla)
        if ($rule && $rule->max_email_count !== null
            && $alert->email_sent_count >= $rule->max_email_count) {
            return;
        }

        // Email
        if ($agent->notify_email) {
            // Destinatario: email propio del agente, o los destinatarios globales
            $recipients = $agent->notify_email_to
                ? [$agent->notify_email_to]
                : array_values(array_filter($settings->recipients ?? []));

            if (! empty($recipients) && ! empty($settings->from_address)) {
                // Obtener contraseña SMTP (puede fallar si APP_KEY cambió)
                try {
                    $smtpPassword = $settings->smtp_password;
                } catch (\Throwable $e) {
                    Log::error("AlertService::notify — no se puede leer smtp_password: {$e->getMessage()}");
                    return;
                }

                try {
                    // Usar Symfony Mailer directamente (bypass Laravel Mail facade cacheada)
                    $encryption = $settings->smtp_encryption === 'none' ? '' : $settings->smtp_encryption;
                    $dsn = sprintf(
                        'smtp://%s:%s@%s:%d',
                        rawurlencode($settings->smtp_username ?? ''),
                        rawurlencode($smtpPassword ?? ''),
                        $settings->smtp_host,
                        $settings->smtp_port,
                    );
                    if ($encryption) {
                        $dsn .= '?encryption=' . $encryption;
                    }

                    $transport = \Symfony\Component\Mailer\Transport::fromDsn($dsn);
                    $mailer    = new \Symfony\Component\Mailer\Mailer($transport);
                    $mailable  = new AlertNotificationMail($agent, $alert);

                    foreach ($recipients as $to) {
                        $email = (new \Symfony\Component\Mime\Email())
                            ->from(new \Symfony\Component\Mime\Address(
                                $settings->from_address,
                                $settings->from_name ?? 'SysMon'
                            ))
                            ->to($to)
                            ->subject($mailable->getSubject())
                            ->html($mailable->buildHtml());

                        $mailer->send($email);
                    }

                    $alert->update([
                        'notified_email' => true,
                        'notified_at'    => now(),
                    ]);

                    // Incrementar contador de emails enviados para esta alerta abierta
                    $alert->increment('email_sent_count');
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

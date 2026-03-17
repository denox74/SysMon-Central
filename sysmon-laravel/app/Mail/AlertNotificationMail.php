<?php

namespace App\Mail;

use App\Models\Agent;
use App\Models\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlertNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Agent $agent,
        public readonly Alert $alert,
    ) {}

    public function envelope(): Envelope
    {
        $emoji = match($this->alert->severity) {
            'critical' => '🔴',
            'warning'  => '🟡',
            default    => '🔵',
        };

        return new Envelope(
            subject: "{$emoji} [{$this->alert->severity}] {$this->agent->name} — {$this->alert->message}",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    public function getSubject(): string
    {
        $emoji = match($this->alert->severity) {
            'critical' => '🔴',
            'warning'  => '🟡',
            default    => '🔵',
        };
        return "{$emoji} [{$this->alert->severity}] {$this->agent->name} — {$this->alert->message}";
    }

    public function buildHtml(): string
    {
        $severityColor = match($this->alert->severity) {
            'critical' => '#ff3d5a',
            'warning'  => '#ffaa00',
            default    => '#00d4ff',
        };

        $severityLabel = strtoupper($this->alert->severity);
        $agent         = $this->agent;
        $alert         = $this->alert;
        $firedAt       = $alert->fired_at->format('d/m/Y H:i:s');
        $panelUrl      = config('app.url') . '/agents/' . $agent->id . '/alerts';

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"></head>
        <body style="margin:0;padding:0;background:#f5f5f5;font-family:monospace">
          <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0">
            <tr><td align="center">
              <table width="580" cellpadding="0" cellspacing="0" style="background:#0d1117;border-radius:12px;overflow:hidden;border:1px solid #1e2d3d">

                <!-- Header -->
                <tr>
                  <td style="background:{$severityColor};padding:4px 0"></td>
                </tr>
                <tr>
                  <td style="padding:28px 32px 20px">
                    <p style="color:#4d6174;font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px">
                      SYSMON ALERT
                    </p>
                    <h1 style="color:#ffffff;font-size:20px;margin:0;font-weight:700">
                      <span style="color:{$severityColor}">{$severityLabel}</span>
                      — {$agent->name}
                    </h1>
                  </td>
                </tr>

                <!-- Message -->
                <tr>
                  <td style="padding:0 32px 24px">
                    <div style="background:#131920;border:1px solid #1e2d3d;border-left:3px solid {$severityColor};border-radius:6px;padding:16px 20px">
                      <p style="color:#c9d1d9;font-size:14px;margin:0;line-height:1.6">
                        {$alert->message}
                      </p>
                    </div>
                  </td>
                </tr>

                <!-- Details -->
                <tr>
                  <td style="padding:0 32px 24px">
                    <table width="100%" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="padding:6px 0;border-bottom:1px solid #1e2d3d">
                          <span style="color:#4d6174;font-size:11px">AGENTE</span>
                          <span style="color:#c9d1d9;font-size:12px;float:right">{$agent->hostname} ({$agent->ip_address})</span>
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:6px 0;border-bottom:1px solid #1e2d3d">
                          <span style="color:#4d6174;font-size:11px">MÉTRICA</span>
                          <span style="color:#00d4ff;font-size:12px;float:right">{$alert->metric}</span>
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:6px 0;border-bottom:1px solid #1e2d3d">
                          <span style="color:#4d6174;font-size:11px">VALOR</span>
                          <span style="color:{$severityColor};font-size:12px;font-weight:bold;float:right">{$alert->value}</span>
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:6px 0;border-bottom:1px solid #1e2d3d">
                          <span style="color:#4d6174;font-size:11px">UMBRAL</span>
                          <span style="color:#c9d1d9;font-size:12px;float:right">{$alert->threshold}</span>
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:6px 0">
                          <span style="color:#4d6174;font-size:11px">HORA</span>
                          <span style="color:#c9d1d9;font-size:12px;float:right">{$firedAt}</span>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>

                <!-- CTA -->
                <tr>
                  <td style="padding:0 32px 32px;text-align:center">
                    <a href="{$panelUrl}"
                       style="display:inline-block;background:{$severityColor};color:#fff;text-decoration:none;
                              padding:12px 28px;border-radius:6px;font-size:12px;font-weight:bold;
                              letter-spacing:1px">
                      VER EN EL PANEL →
                    </a>
                  </td>
                </tr>

                <!-- Footer -->
                <tr>
                  <td style="padding:16px 32px;border-top:1px solid #1e2d3d;text-align:center">
                    <p style="color:#4d6174;font-size:10px;margin:0">
                      SysMon · {$agent->distro} · Regla: {$alert->rule_name}
                    </p>
                  </td>
                </tr>

              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }
}

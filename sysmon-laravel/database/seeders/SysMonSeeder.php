<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\AlertRule;
use Illuminate\Database\Seeder;

class SysMonSeeder extends Seeder
{
    public function run(): void
    {
        // ── Reglas globales por defecto ────────────────────────────
        $rules = [
            [
                'rule_key'         => 'cpu_warning',
                'name'             => 'CPU — Aviso',
                'metric_path'      => 'cpu.usage_percent',
                'operator'         => 'gte',
                'threshold'        => 75.0,
                'severity'         => 'warning',
                'message_template' => 'CPU al {value}% (umbral: {threshold}%)',
                'cooldown_seconds' => 300,
                'notify_email'     => false,  // warning no envía email por defecto
            ],
            [
                'rule_key'         => 'cpu_critical',
                'name'             => 'CPU — Crítico',
                'metric_path'      => 'cpu.usage_percent',
                'operator'         => 'gte',
                'threshold'        => 90.0,
                'severity'         => 'critical',
                'message_template' => 'CPU CRÍTICA: {value}% (umbral: {threshold}%)',
                'cooldown_seconds' => 120,
                'notify_email'     => true,
            ],
            [
                'rule_key'         => 'ram_warning',
                'name'             => 'RAM — Aviso',
                'metric_path'      => 'ram.usage_percent',
                'operator'         => 'gte',
                'threshold'        => 80.0,
                'severity'         => 'warning',
                'message_template' => 'RAM al {value}% (umbral: {threshold}%)',
                'cooldown_seconds' => 300,
                'notify_email'     => false,
            ],
            [
                'rule_key'         => 'ram_critical',
                'name'             => 'RAM — Crítica',
                'metric_path'      => 'ram.usage_percent',
                'operator'         => 'gte',
                'threshold'        => 95.0,
                'severity'         => 'critical',
                'message_template' => 'RAM CRÍTICA: {value}% (umbral: {threshold}%)',
                'cooldown_seconds' => 120,
                'notify_email'     => true,
            ],
            [
                'rule_key'         => 'swap_high',
                'name'             => 'SWAP alta',
                'metric_path'      => 'ram.swap_percent',
                'operator'         => 'gte',
                'threshold'        => 60.0,
                'severity'         => 'warning',
                'message_template' => 'SWAP al {value}% — posible presión de memoria',
                'cooldown_seconds' => 600,
                'notify_email'     => false,
            ],
            [
                'rule_key'         => 'disk_warning',
                'name'             => 'Disco — Aviso',
                'metric_path'      => 'disk_max_usage_percent',
                'operator'         => 'gte',
                'threshold'        => 85.0,
                'severity'         => 'warning',
                'message_template' => 'Disco al {value}% de capacidad (umbral: {threshold}%)',
                'cooldown_seconds' => 3600,
                'notify_email'     => false,
            ],
            [
                'rule_key'         => 'disk_critical',
                'name'             => 'Disco — Crítico',
                'metric_path'      => 'disk_max_usage_percent',
                'operator'         => 'gte',
                'threshold'        => 95.0,
                'severity'         => 'critical',
                'message_template' => 'Disco CRÍTICO: {value}% de capacidad',
                'cooldown_seconds' => 1800,
                'notify_email'     => true,
            ],
        ];

        foreach ($rules as $rule) {
            AlertRule::updateOrCreate(
                ['agent_id' => null, 'rule_key' => $rule['rule_key']],
                $rule
            );
        }

        $this->command->info('Reglas de alerta globales creadas/actualizadas (' . count($rules) . ')');
    }
}

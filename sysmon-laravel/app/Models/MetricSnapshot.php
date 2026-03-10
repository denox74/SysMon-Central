<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Captura puntual de todas las métricas de un agente (~cada 30 segundos).
 * Point-in-time capture of all metrics from an agent (~every 30 seconds).
 *
 * Los campos JSON (disks, temperatures, processes, network_interfaces) guardan
 * el detalle completo; los campos escalares (cpu_usage_percent, disk_max_usage_percent…)
 * son los valores calculados para facilitar queries y gráficas.
 *
 * JSON fields (disks, temperatures, processes, network_interfaces) store full detail;
 * scalar fields (cpu_usage_percent, disk_max_usage_percent…) are calculated values
 * for easy querying and charting.
 *
 * IMPORTANTE: Todos los campos numéricos deben estar en $casts como 'float'.
 * Sin esto llegan como string desde MySQL y rompen .toFixed() en Vue.
 * IMPORTANT: All numeric fields must be in $casts as 'float'.
 * Without this they arrive as strings from MySQL and break .toFixed() in Vue.
 */
class MetricSnapshot extends Model
{
    protected $fillable = [
        'agent_id', 'collected_at',
        'cpu_usage_percent', 'cpu_load_1m', 'cpu_load_5m', 'cpu_load_15m',
        'cpu_freq_mhz', 'cpu_per_core',
        'ram_usage_percent', 'ram_used_gb', 'ram_total_gb',
        'swap_usage_percent', 'swap_used_gb',
        'net_sent_mb', 'net_recv_mb', 'net_connections',
        'disk_max_usage_percent',
        'temp_max_celsius', 'temp_max_sensor',
        'disks', 'network_interfaces', 'temperatures', 'processes',
        'uptime_secs',
    ];

    protected $casts = [
        'collected_at'       => 'datetime',
        'cpu_per_core'       => 'array',
        'disks'              => 'array',
        'network_interfaces' => 'array',
        'temperatures'       => 'array',
        'processes'          => 'array',
        'cpu_usage_percent'  => 'float',
        'cpu_load_1m'        => 'float',
        'cpu_load_5m'        => 'float',
        'cpu_load_15m'       => 'float',
        'cpu_freq_mhz'       => 'float',
        'ram_usage_percent'  => 'float',
        'ram_used_gb'        => 'float',
        'ram_total_gb'       => 'float',
        'swap_usage_percent' => 'float',
        'swap_used_gb'       => 'float',
        'net_sent_mb'        => 'float',
        'net_recv_mb'        => 'float',
        'disk_max_usage_percent' => 'float',
        'temp_max_celsius'   => 'float',
        'uptime_secs'        => 'integer',
        'net_connections'    => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Convierte el payload raw del agente Python al array de columnas de BD.
     * Converts the raw Python agent payload to the DB columns array.
     *
     * También calcula campos derivados que no existen en el payload original:
     * Also calculates derived fields that don't exist in the original payload:
     *   - disk_max_usage_percent → máximo uso entre todas las particiones no excluidas
     *                              max usage across all non-excluded partitions
     *   - temp_max_celsius       → temperatura máxima entre todos los sensores
     *                              max temperature across all sensors
     *   - temp_max_sensor        → nombre del sensor más caliente / hottest sensor name
     *
     * NOTA: disk_max_usage_percent es el campo que usan las reglas de disco del servidor.
     *       Si no se calcula aquí, las reglas de disco nunca disparan.
     * NOTE: disk_max_usage_percent is what server disk rules use.
     *       If not calculated here, disk rules never fire.
     */
    public static function fromAgentPayload(int $agentId, array $data): array
    {
        $cpu  = $data['cpu']  ?? [];
        $ram  = $data['ram']  ?? [];
        $net  = $data['network'] ?? [];
        $temps = $data['temperatures'] ?? [];
        $disks = $data['disks'] ?? [];

        // Temperatura máxima entre todos los sensores
        $maxTemp   = null;
        $maxSensor = null;
        foreach ($temps as $sensorName => $readings) {
            foreach ($readings as $reading) {
                $val = $reading['current'] ?? null;
                if ($val !== null && ($maxTemp === null || $val > $maxTemp)) {
                    $maxTemp   = $val;
                    $maxSensor = $sensorName . '/' . ($reading['label'] ?? '?');
                }
            }
        }

        // Disco más lleno
        $maxDisk = null;
        foreach ($disks as $disk) {
            $pct = $disk['usage_percent'] ?? null;
            if ($pct !== null && ($maxDisk === null || $pct > $maxDisk)) {
                $maxDisk = $pct;
            }
        }

        return [
            'agent_id'     => $agentId,
            'collected_at' => $data['timestamp'] ?? now(),

            'cpu_usage_percent' => $cpu['usage_percent']    ?? null,
            'cpu_load_1m'       => $cpu['load_1m']          ?? null,
            'cpu_load_5m'       => $cpu['load_5m']          ?? null,
            'cpu_load_15m'      => $cpu['load_15m']         ?? null,
            'cpu_freq_mhz'      => $cpu['freq_current_mhz'] ?? null,
            'cpu_per_core'      => $cpu['per_core_percent']  ?? null,

            'ram_usage_percent'  => $ram['usage_percent']  ?? null,
            'ram_used_gb'        => $ram['used_gb']         ?? null,
            'ram_total_gb'       => $ram['total_gb']        ?? null,
            'swap_usage_percent' => $ram['swap_percent']    ?? null,
            'swap_used_gb'       => $ram['swap_used_gb']    ?? null,

            'net_sent_mb'    => $net['total_sent_mb']    ?? null,
            'net_recv_mb'    => $net['total_recv_mb']    ?? null,
            'net_connections'=> $net['connections_count'] ?? null,

            'disk_max_usage_percent' => $maxDisk,
            'temp_max_celsius'       => $maxTemp,
            'temp_max_sensor'        => $maxSensor,

            'disks'              => $disks ?: null,
            'network_interfaces' => $net['interfaces'] ?? null,
            'temperatures'       => $temps ?: null,
            'processes'          => $data['processes'] ?? null,
            'uptime_secs'        => $data['system']['uptime_secs'] ?? null,
        ];
    }
}

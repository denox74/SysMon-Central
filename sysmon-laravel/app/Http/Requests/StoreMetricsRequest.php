<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMetricsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La autorización la hace el middleware AuthenticateAgent
    }

    public function rules(): array
    {
        return [
            'timestamp'          => ['required', 'string'],

            // Sistema
            'system'             => ['required', 'array'],
            'system.hostname'    => ['required', 'string', 'max:255'],
            'system.ip'          => ['nullable', 'string', 'max:45'],
            'system.distro'      => ['nullable', 'string', 'max:150'],
            'system.arch'        => ['nullable', 'string', 'max:30'],
            'system.uptime_secs' => ['nullable', 'integer'],

            // CPU
            'cpu'                       => ['required', 'array'],
            'cpu.usage_percent'         => ['required', 'numeric', 'min:0', 'max:100'],
            'cpu.load_1m'               => ['nullable', 'numeric'],
            'cpu.load_5m'               => ['nullable', 'numeric'],
            'cpu.load_15m'              => ['nullable', 'numeric'],
            'cpu.freq_current_mhz'      => ['nullable', 'numeric'],
            'cpu.per_core_percent'      => ['nullable', 'array'],
            'cpu.per_core_percent.*'    => ['nullable', 'numeric'],

            // RAM
            'ram'                    => ['required', 'array'],
            'ram.usage_percent'      => ['required', 'numeric', 'min:0', 'max:100'],
            'ram.used_gb'            => ['nullable', 'numeric'],
            'ram.total_gb'           => ['nullable', 'numeric'],
            'ram.swap_percent'       => ['nullable', 'numeric'],
            'ram.swap_used_gb'       => ['nullable', 'numeric'],

            // Discos
            'disks'                  => ['nullable', 'array'],
            'disks.*.device'         => ['nullable', 'string'],
            'disks.*.mountpoint'     => ['nullable', 'string'],
            'disks.*.usage_percent'  => ['nullable', 'numeric'],
            'disks.*.total_gb'       => ['nullable', 'numeric'],
            'disks.*.used_gb'        => ['nullable', 'numeric'],

            // Red
            'network'                => ['nullable', 'array'],
            'network.total_sent_mb'  => ['nullable', 'numeric'],
            'network.total_recv_mb'  => ['nullable', 'numeric'],
            'network.connections_count' => ['nullable', 'integer'],
            'network.interfaces'     => ['nullable', 'array'],

            // Temperaturas
            'temperatures'           => ['nullable', 'array'],

            // Procesos
            'processes'              => ['nullable', 'array'],
            'processes.*.pid'        => ['nullable', 'integer'],
            'processes.*.name'       => ['nullable', 'string', 'max:100'],
            'processes.*.cpu_percent'=> ['nullable', 'numeric'],
            'processes.*.ram_mb'     => ['nullable', 'numeric'],

            // Alertas enviadas por el agente
            'alerts'                 => ['nullable', 'array'],
            'alerts.*.rule'          => ['required_with:alerts', 'string'],
            'alerts.*.severity'      => ['required_with:alerts', 'in:info,warning,critical'],
            'alerts.*.metric'        => ['required_with:alerts', 'string'],
            'alerts.*.value'         => ['required_with:alerts', 'numeric'],
            'alerts.*.threshold'     => ['required_with:alerts', 'numeric'],
            'alerts.*.message'       => ['required_with:alerts', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'timestamp.required'        => 'El timestamp es obligatorio.',
            'cpu.usage_percent.required' => 'El uso de CPU es obligatorio.',
            'ram.usage_percent.required' => 'El uso de RAM es obligatorio.',
        ];
    }
}

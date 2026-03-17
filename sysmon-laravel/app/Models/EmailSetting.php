<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSetting extends Model
{
    protected $fillable = [
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'from_address',
        'from_name',
        'recipients',
        'notify_severities',
    ];

    protected $casts = [
        'smtp_port'         => 'integer',
        'smtp_password'     => 'encrypted',   // cifrado con APP_KEY
        'recipients'        => 'array',
        'notify_severities' => 'array',
    ];

    /**
     * Devuelve la única fila de configuración (singleton).
     */
    public static function current(): static
    {
        return static::firstOrCreate([], [
            'smtp_host'         => '',
            'smtp_port'         => 587,
            'smtp_username'     => '',
            'smtp_password'     => null,
            'smtp_encryption'   => 'tls',
            'from_address'      => '',
            'from_name'         => 'SysMon',
            'recipients'        => [],
            'notify_severities' => ['warning', 'critical'],
        ]);
    }

    /**
     * Indica si la configuración SMTP está suficientemente completa para enviar.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->smtp_host)
            && ! empty($this->smtp_username)
            && ! empty($this->from_address)
            && ! empty($this->recipients);
    }
}

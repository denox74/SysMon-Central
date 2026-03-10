<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SysMon — Configuración general
    |--------------------------------------------------------------------------
    | Estas opciones se leen con config('sysmon.xxx').
    | Configúralas en .env con el prefijo SYSMON_
    */

    'notifications' => [

        // Email que recibe las alertas si el agente no tiene uno propio configurado
        'default_email' => env('SYSMON_ALERT_EMAIL', ''),

        // Slack webhook (preparado para el futuro)
        'slack_webhook' => env('SYSMON_SLACK_WEBHOOK', ''),

        // Telegram (preparado para el futuro)
        'telegram_bot_token' => env('SYSMON_TELEGRAM_BOT_TOKEN', ''),
        'telegram_chat_id'   => env('SYSMON_TELEGRAM_CHAT_ID', ''),
    ],

    'retention' => [
        // Días que se conservan los snapshots (usado por sysmon:prune)
        'snapshots_days' => env('SYSMON_RETENTION_DAYS', 30),
    ],

    'agent' => [
        // Segundos sin ping para considerar un agente offline
        'offline_after' => env('SYSMON_OFFLINE_AFTER', 120),
    ],

];

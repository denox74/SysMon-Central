<?php

use Illuminate\Support\Facades\Schedule;

// Marca como offline los agentes que no han enviado ping en el tiempo configurado
Schedule::command('sysmon:check-offline')->everyMinute();

// Elimina snapshots de más de 30 días para mantener la BD limpia
Schedule::command('sysmon:prune --days=30 --force')->daily();

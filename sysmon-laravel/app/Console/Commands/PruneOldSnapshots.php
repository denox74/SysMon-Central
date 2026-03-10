<?php

namespace App\Console\Commands;

use App\Models\MetricSnapshot;
use Illuminate\Console\Command;

class PruneOldSnapshots extends Command
{
    protected $signature   = 'sysmon:prune {--days=30 : Días de retención} {--force : Omitir confirmación (requerido para el scheduler)}';
    protected $description = 'Elimina snapshots antiguos para mantener la BD limpia';

    public function handle(): int
    {
        $days    = (int) $this->option('days');
        $cutoff  = now()->subDays($days);

        $count = MetricSnapshot::where('collected_at', '<', $cutoff)->count();

        if ($count === 0) {
            $this->info("No hay snapshots anteriores a hace {$days} días.");
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("¿Eliminar {$count} snapshots anteriores a {$cutoff->toDateString()}?", true)) {
            $this->info('Operación cancelada.');
            return self::SUCCESS;
        }

        $deleted = MetricSnapshot::where('collected_at', '<', $cutoff)->delete();
        $this->info("Eliminados {$deleted} snapshots.");

        return self::SUCCESS;
    }
}

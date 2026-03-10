<?php

namespace App\Console\Commands;

use App\Models\Agent;
use Illuminate\Console\Command;

class CheckOfflineAgents extends Command
{
    protected $signature   = 'sysmon:check-offline';
    protected $description = 'Marca como offline los agentes que no han enviado ping en el tiempo configurado';

    public function handle(): int
    {
        $agents = Agent::where('is_active', true)
            ->where('status', '!=', 'offline')
            ->get();

        $markedOffline = 0;

        foreach ($agents as $agent) {
            if ($agent->isOffline()) {
                $agent->update(['status' => 'offline']);
                $markedOffline++;
                $this->warn("Agente offline: {$agent->name} (último ping: {$agent->last_seen_at})");
            }
        }

        $this->info("Revisados: {$agents->count()} agentes. Marcados offline: {$markedOffline}");
        return self::SUCCESS;
    }
}

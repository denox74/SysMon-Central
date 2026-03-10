<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgent
{
    /**
     * Valida el Bearer token del agente.
     * Añade el agente resuelto como $request->agent para usarlo en los controllers.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'error' => 'Token no proporcionado.',
                'hint'  => 'Incluye el header Authorization: Bearer {token}',
            ], 401);
        }

        $agent = Agent::where('token', $token)
                      ->where('is_active', true)
                      ->first();

        if (! $agent) {
            return response()->json([
                'error' => 'Token inválido o agente desactivado.',
            ], 401);
        }

        // Inyectar el agente en el request para usarlo en el controller
        $request->merge(['_agent' => $agent]);
        $request->setUserResolver(fn() => $agent);

        return $next($request);
    }
}

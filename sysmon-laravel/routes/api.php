<?php

use App\Http\Controllers\Api\AgentMetricsController;
use App\Http\Controllers\Api\PanelController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SysMon API Routes
|--------------------------------------------------------------------------
*/

// ── Rutas del Agente Python ──────────────────────────────────────────────
// Autenticación: Bearer token del agente (middleware AuthenticateAgent)
//
Route::prefix('agent')
    ->middleware(['api', 'auth.agent'])
    ->group(function () {

        // Enviar métricas (el agente llama a esto en cada ciclo)
        Route::post('metrics', [AgentMetricsController::class, 'store']);

        // Heartbeat / comprobación de conectividad
        Route::get('ping', [AgentMetricsController::class, 'ping']);

        // El agente descarga su configuración actualizada (umbrales, intervalo…)
        Route::get('config', [AgentMetricsController::class, 'config']);
    });


// ── Rutas del Panel Vue ──────────────────────────────────────────────────
// Autenticación: Sanctum (sesión o token de usuario)
// En desarrollo puedes usar ->middleware('api') sin Sanctum para pruebas rápidas
//
Route::prefix('panel')
    ->middleware(['api'])   // ← cambiar a ['api', 'auth:sanctum'] en producción
    ->group(function () {

        // Dashboard general
        Route::get('dashboard', [PanelController::class, 'dashboard']);

        // ── Agentes ──────────────────────────────────────────────
        Route::get('agents',                    [PanelController::class, 'agents']);
        Route::post('agents',                   [PanelController::class, 'createAgent']);
        Route::get('agents/{agent}',            [PanelController::class, 'agent']);
        Route::put('agents/{agent}',            [PanelController::class, 'updateAgent']);
        Route::delete('agents/{agent}',         [PanelController::class, 'deleteAgent']);
        Route::get('agents/{agent}/token',             [PanelController::class, 'getToken']);
        Route::post('agents/{agent}/regenerate-token', [PanelController::class, 'regenerateToken']);

        // ── Métricas ──────────────────────────────────────────────
        Route::get('agents/{agent}/metrics',        [PanelController::class, 'metrics']);
        Route::get('agents/{agent}/metrics/latest', [PanelController::class, 'latestMetrics']);

        // ── Alertas ───────────────────────────────────────────────
        Route::get('alerts',                            [PanelController::class, 'alerts']);
        Route::get('agents/{agent}/alerts',             [PanelController::class, 'agentAlerts']);
        Route::delete('alerts/archived',                [PanelController::class, 'deleteArchivedAlerts']);
        Route::post('alerts/archive-resolved',          [PanelController::class, 'archiveResolved']);
        Route::post('alerts/{alert}/acknowledge',       [PanelController::class, 'acknowledgeAlert']);
        Route::post('alerts/{alert}/resolve',           [PanelController::class, 'resolveAlert']);
        Route::post('alerts/{alert}/archive',           [PanelController::class, 'archiveAlert']);

        // ── Reglas de alerta ──────────────────────────────────────
        Route::get('alert-rules',               [PanelController::class, 'alertRules']);
        Route::post('alert-rules',              [PanelController::class, 'createAlertRule']);
        Route::put('alert-rules/{rule}',        [PanelController::class, 'updateAlertRule']);
        Route::delete('alert-rules/{rule}',     [PanelController::class, 'deleteAlertRule']);

        // ── Configuración de email ────────────────────────────────
        Route::get('settings/email',            [PanelController::class, 'getEmailSettings']);
        Route::put('settings/email',            [PanelController::class, 'updateEmailSettings']);
        Route::post('settings/email/test',      [PanelController::class, 'testEmailSettings']);
    });

# SysMon — Backend Laravel (API)

API REST que recibe las métricas del agente Python y las sirve al panel Vue.

---

## Instalación rápida (VM / desarrollo)

```bash
# 1. Clonar dentro de tu proyecto Laravel existente
#    (o crear un Laravel nuevo: composer create-project laravel/laravel sysmon-backend)

# 2. Copiar los archivos de este paquete a tu proyecto:
#    - app/Http/Controllers/Api/  → tus controllers
#    - app/Http/Middleware/        → AuthenticateAgent.php
#    - app/Http/Requests/          → StoreMetricsRequest.php
#    - app/Models/                 → Agent, Alert, AlertRule, MetricSnapshot
#    - app/Services/               → MetricsService, AlertService
#    - app/Mail/                   → AlertNotificationMail.php
#    - app/Console/Commands/       → CheckOfflineAgents, PruneOldSnapshots
#    - database/migrations/        → las 4 migraciones
#    - database/seeders/           → SysMonSeeder.php
#    - config/sysmon.php
#    - routes/api.php              → añadir al api.php de tu proyecto

# 3. Registrar el middleware en bootstrap/app.php (Laravel 11):
```

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'auth.agent' => \App\Http\Middleware\AuthenticateAgent::class,
    ]);
})
```

```bash
# 4. Configurar .env
cp .env.example .env
# Editar .env con tus datos de BD y email (ver sección de variables)

# 5. Ejecutar migraciones y seeder
php artisan migrate
php artisan db:seed --class=SysMonSeeder

# 6. El seeder imprime el token del agente de prueba:
#    Token: dev-token-vm-test-01-change-in-production
#    Úsalo en /etc/sysmon/agent.env → AGENT_TOKEN=...

# 7. Arrancar el servidor de desarrollo
php artisan serve
```

---

## Variables de entorno (.env)

```env
# BD
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sysmon
DB_USERNAME=root
DB_PASSWORD=secret

# Mail (para alertas por email)
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com      # o Mailtrap para desarrollo
MAIL_PORT=587
MAIL_USERNAME=tu@email.com
MAIL_PASSWORD=tu-password
MAIL_FROM_ADDRESS=sysmon@tudominio.com
MAIL_FROM_NAME="SysMon Alerts"

# SysMon
SYSMON_ALERT_EMAIL=admin@tudominio.com   # email que recibe las alertas
SYSMON_RETENTION_DAYS=30                 # días de retención de snapshots
SYSMON_OFFLINE_AFTER=120                 # segundos sin ping → offline
```

> **Para desarrollo** usa [Mailtrap](https://mailtrap.io) como servidor SMTP —
> ves los emails en el navegador sin enviar nada real.

---

## Endpoints del agente Python

| Método | Ruta                  | Descripción                           |
|--------|-----------------------|---------------------------------------|
| POST   | `/api/agent/metrics`  | Recibir payload de métricas           |
| GET    | `/api/agent/ping`     | Heartbeat                             |
| GET    | `/api/agent/config`   | El agente descarga sus umbrales       |

**Header requerido:** `Authorization: Bearer {AGENT_TOKEN}`

---

## Endpoints del panel Vue

| Método | Ruta                                      | Descripción                      |
|--------|-------------------------------------------|----------------------------------|
| GET    | `/api/panel/dashboard`                    | Resumen de todos los agentes     |
| GET    | `/api/panel/agents`                       | Lista de agentes                 |
| POST   | `/api/panel/agents`                       | Crear agente (devuelve token)    |
| GET    | `/api/panel/agents/{id}`                  | Detalle de un agente             |
| DELETE | `/api/panel/agents/{id}`                  | Desactivar agente                |
| POST   | `/api/panel/agents/{id}/regenerate-token` | Nuevo token                      |
| GET    | `/api/panel/agents/{id}/metrics`          | Historial de métricas (`?hours=24`) |
| GET    | `/api/panel/agents/{id}/metrics/latest`   | Última lectura (polling Vue)     |
| GET    | `/api/panel/alerts`                       | Alertas globales (filtros: `?status=open&severity=critical`) |
| GET    | `/api/panel/agents/{id}/alerts`           | Alertas de un agente             |
| POST   | `/api/panel/alerts/{id}/acknowledge`      | Marcar como vista                |
| POST   | `/api/panel/alerts/{id}/resolve`          | Resolver alerta                  |
| GET    | `/api/panel/alert-rules`                  | Reglas configuradas              |
| POST   | `/api/panel/alert-rules`                  | Crear regla                      |
| PUT    | `/api/panel/alert-rules/{id}`             | Editar umbral                    |
| DELETE | `/api/panel/alert-rules/{id}`             | Eliminar regla                   |

---

## Comandos Artisan

```bash
# Marcar agentes sin ping como offline (añadir al Scheduler cada minuto)
php artisan sysmon:check-offline

# Limpiar snapshots antiguos (añadir al Scheduler diariamente)
php artisan sysmon:prune --days=30
```

Añade al Scheduler en `routes/console.php`:

```php
Schedule::command('sysmon:check-offline')->everyMinute();
Schedule::command('sysmon:prune --days=30 --force')->daily();
```

---

## Flujo completo de datos

```
[Agente Python en Ubuntu]
        │
        │  POST /api/agent/metrics
        │  Authorization: Bearer {token}
        │  Body: { cpu, ram, disks, network, temps, processes, alerts? }
        ▼
[Laravel API]
    ├─ AuthenticateAgent middleware (valida token)
    ├─ StoreMetricsRequest (valida payload)
    ├─ MetricsService::process()
    │    ├─ Actualiza info del agente (hostname, IP, distro…)
    │    ├─ MetricSnapshot::create() — guarda en BD
    │    ├─ AlertService::saveFromAgent() — guarda alertas del agente
    │    ├─ AlertService::evaluateServerRules() — evalúa umbrales del panel
    │    └─ Actualiza status del agente (online/warning/critical)
    └─ Responde 201 { ok: true, snapshot_id: X }

[Panel Vue]
    ├─ GET /api/panel/dashboard  (polling cada 10s)
    ├─ GET /api/panel/agents/{id}/metrics/latest
    └─ GET /api/panel/alerts?status=open
```

---

## Estructura de archivos

```
sysmon-laravel/
├── app/
│   ├── Console/Commands/
│   │   ├── CheckOfflineAgents.php
│   │   └── PruneOldSnapshots.php
│   ├── Http/
│   │   ├── Controllers/Api/
│   │   │   ├── AgentMetricsController.php   ← endpoints del agente
│   │   │   └── PanelController.php          ← endpoints del panel Vue
│   │   ├── Middleware/
│   │   │   └── AuthenticateAgent.php
│   │   └── Requests/
│   │       └── StoreMetricsRequest.php
│   ├── Mail/
│   │   └── AlertNotificationMail.php
│   ├── Models/
│   │   ├── Agent.php
│   │   ├── Alert.php
│   │   ├── AlertRule.php
│   │   └── MetricSnapshot.php
│   └── Services/
│       ├── AlertService.php
│       └── MetricsService.php
├── config/
│   └── sysmon.php
├── database/
│   ├── migrations/  (4 migraciones)
│   └── seeders/
│       └── SysMonSeeder.php
└── routes/
    └── api.php
```

https://www.paypal.com/donate/?business=YUXRZATJCAHS2&no_recurring=0&currency_code=EUR

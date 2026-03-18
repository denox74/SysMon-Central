# SysMon Central

**Sistema de monitorización de servidores** — Performance monitoring system for Linux servers.

Panel web en tiempo real + Agente Python ligero + API Laravel + MySQL.
Real-time web dashboard + Lightweight Python agent + Laravel API + MySQL.

---

## Índice / Table of Contents

1. [Arquitectura / Architecture](#arquitectura)
2. [Requisitos / Requirements](#requisitos)
3. [Instalación rápida / Quick setup](#instalación-rápida)
4. [Estructura de archivos / File structure](#estructura-de-archivos)
5. [Backend Laravel — Guía de archivos clave / Key files guide](#backend-laravel)
6. [Agente Python — Guía de archivos clave / Key files guide](#agente-python)
7. [Panel Vue — Guía de archivos clave / Key files guide](#panel-vue)
8. [Base de datos / Database schema](#base-de-datos)
9. [Flujo de datos / Data flow](#flujo-de-datos)
10. [Variables de entorno / Environment variables](#variables-de-entorno)
11. [Añadir un nuevo agente / Add a new agent](#añadir-un-nuevo-agente)
12. [Alertas / Alerts](#alertas)
13. [Producción / Production notes](#producción)

---

## Arquitectura

```
┌──────────────────┐        Bearer Token        ┌─────────────────────┐
│  Agente Python   │ ──── POST /api/agent/metrics ──▶ │  Laravel API        │
│  (Linux VM)      │                                   │  (Docker :8000)     │
│  sysmon-agent/   │ ◀─── GET  /api/agent/config ───  │  sysmon-laravel/    │
└──────────────────┘                                   └──────────┬──────────┘
                                                                  │
                                                              MySQL 8
                                                           (Docker :3308)
                                                                  │
                                                       ┌──────────▼──────────┐
                                                       │  Panel Vue 3        │
                                                       │  (Docker :5173)     │
                                                       │  sysmon-vue/        │
                                                       │  Pinia + Chart.js   │
                                                       └─────────────────────┘
```

**Arquitectura de tres capas / Three-tier architecture:**
- **Agente / Agent**: Corre en cada servidor Linux, recolecta métricas cada 30s y las envía a la API. *Runs on each Linux server, collects metrics every 30s and sends them to the API.*
- **API**: Recibe métricas, evalúa reglas, dispara alertas, expone datos al panel. *Receives metrics, evaluates rules, fires alerts, exposes data to the panel.*
- **Panel**: Dashboard web en tiempo real, polling cada 10s, sin necesidad de WebSockets. *Real-time web dashboard, polling every 10s, no WebSockets needed.*

---

## Requisitos

| Componente / Component | Versión mínima / Min version |
|---|---|
| Docker Desktop | 24+ |
| Docker Compose | v2+ |
| Python (agente / agent) | 3.9+ |
| Sistema del agente / Agent OS | Ubuntu 20.04+ / Debian 11+ |

---

## Instalación rápida

### 1. Clonar y arrancar Docker / Clone and start Docker

```bash
git clone https://github.com/denox74/SysMon-Central.git
cd SysMon-Central
docker compose up -d
```

La primera vez tarda ~2-3 minutos porque:
- Se descarga MySQL 8 + Node 20 + PHP 8.3
- Se ejecuta `composer install` (dependencias Laravel)
- Se ejecutan migrations y seeders automáticamente

*First run takes ~2-3 minutes because:*
- *Downloads MySQL 8 + Node 20 + PHP 8.3*
- *Runs `composer install` (Laravel dependencies)*
- *Runs migrations and seeders automatically*

```bash
# Ver logs / Check logs
docker compose logs -f api
docker compose logs -f panel
```

**Acceso / Access:**
- Panel web: `http://localhost:5173`
- API: `http://localhost:8000/api/panel/dashboard`

### 2. Instalar agente en un servidor Linux / Install agent on Linux server

```bash
# Copiar archivos al servidor / Copy files to server
scp -r sysmon-agent/ usuario@ip-servidor:~/

# Ejecutar instalador (requiere sudo) / Run installer (requires sudo)
cd sysmon-agent
sudo ./scripts/install.sh
```

El instalador / *The installer*:
1. Crea el usuario `sysmon` (sin login) / *Creates the `sysmon` user (no login)*
2. Instala Python + psutil + requests / *Installs Python + psutil + requests*
3. Copia el agente a `/opt/sysmon/` / *Copies the agent to `/opt/sysmon/`*
4. Crea el servicio systemd `sysmon-agent` / *Creates the `sysmon-agent` systemd service*
5. Crea `/etc/sysmon/agent.env` (configuración) / *Creates `/etc/sysmon/agent.env` (config)*

```bash
# Editar configuración / Edit configuration
sudo nano /etc/sysmon/agent.env
```

```env
API_URL=http://YOUR-PC-IP:8000
AGENT_TOKEN=token-generated-in-panel
```

```bash
# Arrancar y verificar / Start and verify
sudo systemctl start sysmon-agent
sudo systemctl status sysmon-agent
sudo journalctl -u sysmon-agent -f
```

---

## Estructura de archivos

```
SysMon-Central/
│
├── docker-compose.yml          # Orquestación de servicios / Service orchestration
├── GUIA-INSTALACION.md         # Guía detallada de instalación / Detailed install guide (ES)
├── INSTALLATION-GUIDE.md       # Detailed installation guide (EN)
│
├── sysmon-agent/               # Agente Python para servidores Linux / Python agent for Linux servers
│   ├── sysmon/
│   │   ├── agent.py            # Bucle principal / Main loop
│   │   ├── collector.py        # Recolección de métricas / Metrics collection
│   │   ├── config.py           # Gestión de configuración / Config management
│   │   ├── sender.py           # Cliente HTTP con cola / HTTP client with queue
│   │   └── alerts.py           # Evaluación local de alertas / Local alert evaluation
│   ├── scripts/
│   │   └── install.sh          # Instalador automático / Auto installer
│   ├── systemd/
│   │   └── sysmon-agent.service # Definición del servicio systemd / systemd service definition
│   └── agent.env.example       # Plantilla de configuración / Config template
│
├── sysmon-laravel/             # API backend Laravel 11
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/Api/
│   │   │   │   ├── AgentMetricsController.php  # Endpoints del agente / Agent endpoints
│   │   │   │   └── PanelController.php          # Endpoints del panel Vue / Vue panel endpoints
│   │   │   ├── Middleware/
│   │   │   │   └── AuthenticateAgent.php        # Autenticación Bearer token / Bearer token auth
│   │   │   └── Requests/
│   │   │       └── StoreMetricsRequest.php      # Validación de payload / Payload validation
│   │   ├── Models/
│   │   │   ├── Agent.php           # Servidor monitorizado / Monitored server
│   │   │   ├── MetricSnapshot.php  # Datos de una captura (~30s) / Data from one capture (~30s)
│   │   │   ├── Alert.php           # Alerta disparada / Fired alert
│   │   │   └── AlertRule.php       # Regla de umbral / Threshold rule
│   │   ├── Services/
│   │   │   ├── MetricsService.php  # Lógica principal de procesamiento / Main processing logic
│   │   │   └── AlertService.php    # Evaluación y notificación de alertas / Alert evaluation & notification
│   │   └── Console/Commands/
│   │       ├── CheckOfflineAgents.php  # Cron: marcar agentes offline / Cron: mark agents offline
│   │       └── PruneOldSnapshots.php   # Cron: limpiar datos viejos / Cron: clean old data
│   ├── routes/
│   │   ├── api.php             # Definición de rutas API / API route definitions
│   │   └── console.php         # Schedule de tareas programadas / Scheduled task definitions
│   └── database/
│       ├── migrations/         # Esquema de base de datos / Database schema
│       └── seeders/
│           └── SysMonSeeder.php # Reglas de alerta globales por defecto / Default global alert rules
│
└── sysmon-vue/                 # Panel web Vue 3 / Vue 3 web panel
    └── src/
        ├── App.vue             # Shell principal (sidebar + header) / Main shell
        ├── router/index.js     # Rutas del panel / Panel routes
        ├── stores/index.js     # Estado global Pinia / Global Pinia state
        ├── services/api.js     # Cliente Axios para la API / Axios API client
        └── views/
            ├── DashboardView.vue    # Vista principal / Main dashboard view
            ├── AgentsView.vue       # Listado de agentes / Agents list
            ├── AgentDetailView.vue  # Detalle + gráficas de un agente / Agent detail + charts
            ├── AlertsView.vue       # Gestión de alertas / Alert management
            └── RulesView.vue        # Configuración de umbrales / Threshold configuration
```

---

## Backend Laravel

### `routes/api.php` — Definición de rutas / Route definitions

Dos grupos de rutas / Two route groups:

```
/api/agent/*    → Autenticación: Bearer token del agente / Auth: agent Bearer token
/api/panel/*    → Autenticación: ninguna en dev (activar auth:sanctum en producción)
                  Auth: none in dev (enable auth:sanctum in production)
```

**Rutas del agente (Python → Laravel) / Agent routes (Python → Laravel):**
```
POST /api/agent/metrics         → Enviar métricas de una captura / Send metrics from one capture
GET  /api/agent/ping            → Heartbeat de conectividad / Connectivity heartbeat
GET  /api/agent/config          → Descargar configuración actualizada (reglas, umbrales)
                                  Download updated config (rules, thresholds)
```

**Rutas del panel (Vue → Laravel) / Panel routes (Vue → Laravel):**
```
GET  /api/panel/dashboard                    → Dashboard general con totales y alertas recientes
                                               General dashboard with totals and recent alerts
GET  /api/panel/agents                       → Listado de todos los agentes / All agents list
POST /api/panel/agents                       → Crear nuevo agente (devuelve token) / Create agent (returns token)
GET  /api/panel/agents/{id}                  → Detalle de un agente / Agent detail
PUT  /api/panel/agents/{id}                  → Renombrar/actualizar agente / Rename/update agent
DEL  /api/panel/agents/{id}                  → Desactivar agente (soft delete) / Deactivate agent
GET  /api/panel/agents/{id}/token            → Ver token actual del agente / View agent's current token
POST /api/panel/agents/{id}/regenerate-token → Generar nuevo token / Generate new token
GET  /api/panel/agents/{id}/metrics          → Serie temporal de métricas (para gráficas) / Metric time series (for charts)
GET  /api/panel/agents/{id}/metrics/latest   → Última métrica del agente / Agent's latest metric
GET  /api/panel/alerts                       → Alertas paginadas con filtros / Paginated alerts with filters
POST /api/panel/alerts/{id}/acknowledge      → Marcar alerta como vista / Mark alert as seen
POST /api/panel/alerts/{id}/resolve          → Resolver alerta con nota / Resolve alert with note
POST /api/panel/alerts/{id}/archive          → Archivar alerta / Archive alert
POST /api/panel/alerts/archive-resolved      → Archivar todas las resueltas / Archive all resolved
GET  /api/panel/alert-rules                  → Listado de reglas de alerta / Alert rules list
POST /api/panel/alert-rules                  → Crear nueva regla / Create new rule
PUT  /api/panel/alert-rules/{id}             → Modificar regla / Update rule
DEL  /api/panel/alert-rules/{id}             → Eliminar regla / Delete rule
```

---

### `app/Services/MetricsService.php` — Procesamiento principal / Main processing

**La función más importante del backend / The most important backend function.**

`process(Agent $agent, array $payload): void`

Ejecuta todo en una **transacción atómica** / Runs everything in an **atomic transaction**:

```
1. updateAgentInfo()        → Actualiza hostname, distro, cores, RAM desde el payload
                              Updates hostname, distro, cores, RAM from the payload
2. MetricSnapshot::fromAgentPayload() → Guarda la captura en BD / Saves the snapshot to DB
3. AlertService::saveFromAgent() → Guarda alertas enviadas por el agente (cooldown 5 min)
                                   Saves alerts sent by the agent (5 min cooldown)
4. AlertService::evaluateServerRules() → Evalúa reglas de backend contra el snapshot
                                         Evaluates backend rules against the snapshot
5. updateAgentStatus()      → Cambia status del agente: critical > warning > online
                              Updates agent status: critical > warning > online
```

`evaluateServerRules` vs alertas del agente / vs agent alerts:
- **Alertas del agente / Agent alerts**: El propio agente Python detecta el problema y lo reporta. *The Python agent itself detects and reports the issue.*
- **Reglas del servidor / Server rules**: El backend evalúa umbrales por su cuenta, como segunda capa de seguridad. *The backend evaluates thresholds independently, as a second safety layer.*

---

### `app/Services/AlertService.php` — Alertas y notificaciones / Alerts & notifications

**Funciones clave / Key functions:**

```php
saveFromAgent(Agent $agent, MetricSnapshot $snapshot, array $data)
// Guarda una alerta enviada por el agente Python.
// Usa Cache para evitar spam: una alerta igual no se repite en 5 minutos.
// Saves alert sent by Python agent. Uses Cache to prevent spam (5 min cooldown).

evaluateServerRules(Agent $agent, MetricSnapshot $snapshot, array $payload)
// Para cada regla activa (global + específica del agente):
// 1. Extrae el valor de la métrica del payload (notación punto: "cpu.usage_percent")
// 2. Evalúa el operador (gte, gt, lt, lte) contra el umbral
// 3. Si supera el umbral y no está en cooldown → crea alerta
// 4. Si notify_email=true → encola email
// For each active rule (global + agent-specific):
// 1. Extracts metric value from payload (dot notation: "cpu.usage_percent")
// 2. Evaluates operator (gte, gt, lt, lte) against threshold
// 3. If threshold exceeded and not in cooldown → creates alert
// 4. If notify_email=true → queues email

notify(Agent $agent, Alert $alert)
// Envía email de notificación a agent.notify_email_to.
// Usa la configuración SMTP guardada en BD (gestionada desde el panel → Configuración → Email).
// Sends email to agent.notify_email_to using SMTP settings stored in DB (panel → Settings → Email).
```

`extractValue(array $payload, string $path)` — navega por notación punto / navigates dot notation, e.g.:
- `"cpu.usage_percent"` → `$payload['cpu']['usage_percent']`
- `"disk_max_usage_percent"` → campo calculado en / calculated field in `MetricSnapshot::fromAgentPayload()`

---

### `app/Models/MetricSnapshot.php` — Captura de métricas / Metric snapshot

`fromAgentPayload(Agent $agent, array $payload): self`

Transforma el payload raw del agente en una fila de base de datos.
*Transforms the raw agent payload into a database row.*

**Calcula campos derivados / Calculates derived fields:**
```php
// disk_max_usage_percent → máximo uso de disco entre todas las particiones
//                          max disk usage across all partitions
$diskMax = collect($payload['disks'] ?? [])
    ->max('usage_percent') ?? 0.0;

// temp_max_celsius → temperatura máxima entre todos los sensores
//                    max temperature across all sensors
```

> **Importante / Important**: Si no se calcula `disk_max_usage_percent` aquí, las reglas de disco del servidor nunca disparan porque no existe el campo en el payload raw.
> *If `disk_max_usage_percent` is not calculated here, server disk rules never fire because the field doesn't exist in the raw payload.*

---

### `app/Http/Middleware/AuthenticateAgent.php` — Autenticación del agente / Agent authentication

Valida el Bearer token en cada petición del agente Python.
*Validates the Bearer token on every Python agent request.*

```php
// Extrae token del header: "Authorization: Bearer <token>"
// Busca en BD: Agent where token = $token AND is_active = true
// Si no existe → 401 Unauthorized
// Si existe → inyecta el agente en $request->_agent para usarlo en el controlador

// Extracts Bearer token from header, validates against DB, injects agent into request.
```

---

### `app/Http/Controllers/Api/AgentMetricsController.php` — Endpoints del agente / Agent endpoints

```php
store(StoreMetricsRequest $request)
// POST /api/agent/metrics
// Punto de entrada para cada ciclo del agente.
// Entry point for each agent cycle (every ~30s).

ping()
// GET /api/agent/ping
// El agente puede llamar a esto para verificar conectividad sin enviar métricas.
// Agent can call this to verify connectivity without sending metrics.

config()
// GET /api/agent/config
// Devuelve las reglas activas combinadas (globales + específicas del agente).
// Las reglas específicas del agente sobreescriben las globales por rule_key.
// Returns combined active rules. Agent-specific rules override global ones by rule_key.
```

---

### `app/Http/Controllers/Api/PanelController.php` — Endpoints del panel / Panel endpoints

```php
dashboard()
// GET /api/panel/dashboard
// Devuelve agentes + última métrica + 10 alertas recientes + totales.
// El campo 'status' se recalcula en tiempo real: isOffline() tiene prioridad.
// Returns agents + latest metric + 10 recent alerts + totals. Status recalculated live.

createAgent(Request $request)
// POST /api/panel/agents
// Genera un token único (60 chars random) y lo devuelve UNA SOLA VEZ.
// Generates unique token (60 random chars) and returns it ONLY ONCE at creation.

getToken(Agent $agent)
// GET /api/panel/agents/{id}/token
// Obtiene el token directamente de BD, saltándose $hidden del modelo.
// Needed because Agent model has 'token' in $hidden to prevent accidental exposure.
// Gets token directly from DB, bypassing model $hidden.

regenerateToken(Agent $agent)
// POST /api/panel/agents/{id}/regenerate-token
// Genera un nuevo token. El anterior queda inválido inmediatamente.
// ¡Hay que actualizar /etc/sysmon/agent.env en el servidor!
// Generates new token. Old one becomes invalid immediately. Must update agent.env on server!
```

---

### `app/Console/Commands/` — Tareas programadas / Scheduled tasks

**`CheckOfflineAgents.php`** — Se ejecuta cada minuto / Runs every minute:
```php
// Para cada agente activo y no-offline:
//   if agent.isOffline() → agent.status = 'offline'
// isOffline() compara: now() - last_seen_at > offline_after_seconds (120s por defecto / default)

// For each active non-offline agent:
//   if agent.isOffline() → marks status as 'offline'
```

**`PruneOldSnapshots.php`** — Se ejecuta a diario / Runs daily:
```php
// Elimina snapshots con collected_at < 30 días atrás.
// Sin esto la tabla metric_snapshots crecería indefinidamente.
// Deletes snapshots older than 30 days. Prevents unbounded table growth.
```

Estas tareas se registran en / *These tasks are registered in* `routes/console.php`:
```php
Schedule::command('sysmon:check-offline')->everyMinute();
Schedule::command('sysmon:prune')->dailyAt('00:00');
```

---

### `database/seeders/SysMonSeeder.php` — Reglas de alerta por defecto / Default alert rules

Crea las reglas globales (aplican a todos los agentes) / Creates global rules (apply to all agents):

| Regla / Rule | Métrica / Metric | Umbral / Threshold | Severidad / Severity | Email |
|---|---|---|---|---|
| CPU — Aviso / Warning | `cpu.usage_percent` | ≥ 75% | warning | No |
| CPU — Crítico / Critical | `cpu.usage_percent` | ≥ 90% | critical | Yes |
| RAM — Aviso / Warning | `ram.usage_percent` | ≥ 80% | warning | No |
| RAM — Crítica / Critical | `ram.usage_percent` | ≥ 95% | critical | Yes |
| SWAP alta / High SWAP | `ram.swap_percent` | ≥ 60% | warning | No |
| Disco — Aviso / Disk Warning | `disk_max_usage_percent` | ≥ 85% | warning | No |
| Disco — Crítico / Disk Critical | `disk_max_usage_percent` | ≥ 95% | critical | Yes |

```bash
# Ejecutar seeder manualmente / Run seeder manually
docker compose exec api php artisan db:seed --class=SysMonSeeder
```

---

## Agente Python

### `sysmon/agent.py` — Bucle principal / Main loop

```
main() → cada interval_seconds (30s por defecto / default 30s):
  1. collect_all()         → recolectar todas las métricas / collect all metrics
  2. AlertChecker.check()  → evaluar alertas localmente / evaluate alerts locally
  3. MetricSender.send()   → enviar a la API (con cola si falla) / send to API (queued on failure)
  4. sleep(restante)       → esperar hasta el próximo ciclo / wait until next cycle
```

Manejo de señales para apagado limpio / Signal handling for graceful shutdown:
```python
signal.signal(signal.SIGTERM, _handle_signal)  # systemctl stop
signal.signal(signal.SIGINT, _handle_signal)   # Ctrl+C
```

---

### `sysmon/collector.py` — Recolección de métricas / Metrics collection

| Función / Function | Qué recolecta / Collects | Librería / Library |
|---|---|---|
| `get_cpu_metrics()` | Uso %, por-núcleo, frecuencia, carga 1/5/15m / Usage %, per-core, freq, load avg | psutil |
| `get_ram_metrics()` | RAM total/usada/libre, swap / RAM total/used/free, swap | psutil |
| `get_disk_metrics()` | Por partición: uso %, I/O (excluye snap/tmpfs) / Per partition: usage %, I/O | psutil |
| `get_network_metrics()` | MB enviados/recibidos, conexiones activas / MB sent/received, active connections | psutil |
| `get_temperatures()` | Temperatura por sensor (fallback a /sys/class/thermal) / Temp per sensor | psutil |
| `get_top_processes(limit=10)` | Top procesos por CPU: pid, usuario, % / Top CPU processes: pid, user, % | psutil |
| `get_system_info()` | hostname, IP, distro, arch, uptime | socket, /etc/os-release |
| `collect_all()` | Combina todo en un único dict / Combines everything into one dict | — |

**Exclusión de particiones snap / Snap partition exclusion:**
```python
_EXCLUDE_FSTYPES = {'squashfs', 'tmpfs', 'devtmpfs', 'overlay', 'aufs', 'ramfs', 'iso9660'}
# Sin esto, las particiones snap siempre reportan 100% y disparan alertas de disco falsas.
# Without this, snap partitions always show 100% and trigger false disk alerts.
```

---

### `sysmon/sender.py` — Envío con cola / Queue-based sender

```python
class MetricSender:

    def send(payload):
        # 1. Intenta vaciar la cola pendiente (si hubo fallos previos)
        # 1. Tries to flush pending queue (from previous failures)
        self._flush_queue()

        # 2. Envía el payload actual
        # 2. Sends current payload
        ok = self._post(payload)

        # 3. Si falla, lo encola para el próximo ciclo
        # 3. If fails, queues it for next cycle
        if not ok:
            self._enqueue(payload)
```

Comportamiento ante errores HTTP / HTTP error behavior:
- `401` → Token inválido. No reintentar, log error. / *Invalid token. Do not retry, log error.*
- `422` → Payload inválido. No reintentar, log warning. / *Invalid payload. Do not retry, log warning.*
- `5xx` / timeout → Encolar para reintento. / *Queue for retry.*

La cola tiene un máximo de 50 entradas; si se llena, descarta los más antiguos.
*Queue has max 50 entries; when full, drops oldest payloads.*

---

### `sysmon/alerts.py` — Alertas locales / Local alerts

El agente evalúa umbrales **antes** de enviar, con cooldown para evitar spam.
*The agent evaluates thresholds **before** sending, with cooldown to prevent spam.*

```python
class AlertChecker:
    def check(payload) → list[dict]:
        # Evalúa cada regla contra el payload
        # Evaluates each rule against the payload
        # Respeta cooldown: una alerta igual no se repite antes de cooldown_s (300s)
        # Respects cooldown: same alert won't repeat before cooldown_s (300s)
```

Reglas por defecto (configurables en `agent.env`) / Default rules (configurable in `agent.env`):
- CPU warning ≥ 75%, critical ≥ 90%
- RAM warning ≥ 80%, critical ≥ 95%
- SWAP ≥ 60%
- Load 5m ≥ 4.0
- Temperatura / Temperature: dinámica según límites del sensor / *dynamic based on sensor limits*

---

### `sysmon/config.py` — Configuración / Configuration

Lee de `/etc/sysmon/agent.env`. Variables de entorno `SYSMON_*` tienen precedencia.
*Reads from `/etc/sysmon/agent.env`. `SYSMON_*` env vars take precedence.*

**Variables obligatorias / Required:**
```env
API_URL=http://192.168.1.x:8000
AGENT_TOKEN=<token-generado-en-el-panel / token-generated-in-panel>
```

**Variables opcionales / Optional:**
```env
INTERVAL_SECONDS=30          # Segundos entre capturas / Seconds between captures
LOG_LEVEL=INFO               # DEBUG, INFO, WARNING, ERROR
AGENT_NAME=web-server-01     # Nombre visible en el panel / Name shown in panel
CPU_WARN_THRESHOLD=75.0
CPU_CRITICAL_THRESHOLD=90.0
RAM_WARN_THRESHOLD=80.0
RAM_CRITICAL_THRESHOLD=95.0
TEMP_WARN_THRESHOLD=80.0
TEMP_CRITICAL_THRESHOLD=90.0
DISK_WARN_THRESHOLD=85.0
MAX_QUEUE=50                 # Máximo de capturas en cola / Max queued captures
REQUEST_TIMEOUT=10           # Timeout HTTP en segundos / HTTP timeout in seconds
SEND_PROCESSES=true          # Incluir top procesos en el payload / Include top processes
PROCESSES_LIMIT=10
```

---

## Panel Vue

### `src/stores/index.js` — Estado global / Global state (Pinia)

**`useDashboardStore`** — Estado del dashboard y agentes / Dashboard and agents state:
```javascript
fetch()           // GET /api/panel/dashboard → actualiza todo el estado / updates all state
startPolling(ms)  // Llama a fetch() cada ms (10000 = 10s) / Calls fetch() every ms
stopPolling()     // Cancela el intervalo / Cancels interval

// Computed:
agents            // Lista de agentes activos / Active agents list
openAlerts        // 10 alertas abiertas más recientes / 10 most recent open alerts
totals            // Contadores: online, warning, critical, offline, open_alerts / Counters
```

**`useAlertsStore`** — Estado de la página de alertas / Alerts page state:
```javascript
fetch(page)       // GET /api/panel/alerts con filtros y paginación / with filters and pagination
acknowledge(id)   // POST /api/panel/alerts/{id}/acknowledge
resolve(id, note) // POST /api/panel/alerts/{id}/resolve
archive(id)       // POST /api/panel/alerts/{id}/archive
archiveAllResolved(agentId?) // POST /api/panel/alerts/archive-resolved
```

---

### `src/services/api.js` — Cliente HTTP / HTTP client

Instancia Axios con baseURL desde variable de entorno `VITE_API_URL`.
*Axios instance with baseURL from `VITE_API_URL` env variable.*

```javascript
// En desarrollo (Docker), VITE_API_URL=http://localhost:8000
// En producción, cambiar a la URL real de la API
// In dev (Docker), VITE_API_URL=http://localhost:8000
// In production, change to real API URL
```

Todos los métodos están agrupados en el objeto `panelApi` / *All methods are grouped in the `panelApi` object*:
```javascript
panelApi.dashboard()
panelApi.agents()
panelApi.createAgent(data)     // { name, notes, notify_email_to }
panelApi.getToken(id)          // Obtiene token actual / Gets current token
panelApi.regenerateToken(id)   // Genera nuevo token / Generates new token
panelApi.metrics(id, hours)    // Datos para gráficas / Data for charts
// ... ver / see src/services/api.js para la lista completa / for full list
```

---

### `src/App.vue` — Shell principal / Main shell

- Sidebar fijo con navegación y badge de alertas abiertas. / *Fixed sidebar with navigation and open-alerts badge.*
- Header con título dinámico por ruta, indicador Live y reloj. / *Header with dynamic route title, Live indicator and clock.*
- Inicia polling global del store en `onMounted` → todos los datos se actualizan solos. / *Starts global store polling on `onMounted` → all data updates automatically.*
- Detiene polling en `onUnmounted` → no hay memory leaks. / *Stops polling on `onUnmounted` → no memory leaks.*

---

## Base de datos

### `agents`
| Campo / Field | Tipo / Type | Descripción / Description |
|---|---|---|
| `id` | int PK | — |
| `name` | string | Nombre visible en el panel / Name shown in panel |
| `hostname` | string | Hostname del servidor (auto, del agente) / Server hostname (auto, from agent) |
| `ip_address` | string | IP (auto, del agente) / IP (auto, from agent) |
| `token` | string unique | Bearer token de autenticación / Auth Bearer token |
| `status` | enum | `online`, `warning`, `critical`, `offline` |
| `last_seen_at` | timestamp | Último ping recibido / Last ping received |
| `offline_after_seconds` | int | Umbral para marcar offline (default: 120) / Threshold to mark offline |
| `notes` | string | Etiqueta libre (ej: "producción", "CASA") / Free label (e.g. "production", "home") |
| `notify_email` | bool | ¿Enviar emails de alerta? / Send alert emails? |
| `notify_email_to` | string | Email destino de alertas / Alert destination email |
| `is_active` | bool | False = "eliminado" (soft delete) / False = soft deleted |

### `metric_snapshots`
Cada fila = una captura del agente (~cada 30s). / *Each row = one agent capture (~every 30s).* Campos principales / Main fields:

| Campo / Field | Descripción / Description |
|---|---|
| `cpu_usage_percent` | Uso total de CPU / Total CPU usage |
| `cpu_load_1m/5m/15m` | Load average del sistema / System load average |
| `ram_usage_percent` | Uso de RAM / RAM usage |
| `disk_max_usage_percent` | Máximo uso de disco entre particiones / Max disk usage across partitions |
| `temp_max_celsius` | Temperatura máxima entre sensores / Max temperature across sensors |
| `net_sent/recv_mb` | Tráfico de red acumulado / Cumulative network traffic |
| `disks` | JSON completo de todas las particiones / Full JSON of all partitions |
| `temperatures` | JSON completo de todos los sensores / Full JSON of all sensors |
| `processes` | JSON top 10 procesos por CPU / JSON top 10 processes by CPU |

> Se limpian automáticamente después de 30 días con `sysmon:prune`.
> *Auto-cleaned after 30 days by `sysmon:prune`.*

### `alerts`
| Campo / Field | Descripción / Description |
|---|---|
| `severity` | `info`, `warning`, `critical` |
| `source` | `agent` (enviada por Python / sent by Python) o/or `server` (evaluada por Laravel / evaluated by Laravel) |
| `status` | `open`, `acknowledged`, `resolved` |
| `metric` | Nombre de la métrica (ej: `cpu.usage_percent`) / Metric name |
| `value` | Valor en el momento de dispararse / Value when fired |
| `threshold` | Umbral que se superó / Threshold that was exceeded |
| `archived_at` | NULL = visible, not-NULL = archivada / archived |

### `alert_rules`
| Campo / Field | Descripción / Description |
|---|---|
| `agent_id` | NULL = regla global (aplica a todos) / global rule (applies to all) |
| `rule_key` | Identificador único (ej: `cpu_critical`) / Unique identifier |
| `metric_path` | Notación punto para extraer del payload (ej: `cpu.usage_percent`) / Dot notation to extract from payload |
| `operator` | `gte`, `gt`, `lte`, `lt` |
| `threshold` | Valor numérico del umbral / Numeric threshold value |
| `message_template` | Texto con `{value}` y `{threshold}` como placeholders / Text with `{value}` and `{threshold}` placeholders |
| `cooldown_seconds` | Mínimo tiempo entre alertas iguales / Min time between identical alerts |

---

## Flujo de datos

```
CICLO DEL AGENTE (cada 30s) / AGENT CYCLE (every 30s):
────────────────────────────────────────────────────────
collector.py     → recolecta CPU, RAM, Disco, Red, Temp, Procesos
                   collects CPU, RAM, Disk, Network, Temp, Processes
alerts.py        → evalúa umbrales localmente (con cooldown)
                   evaluates thresholds locally (with cooldown)
sender.py        → POST /api/agent/metrics (Bearer token)
                   ↳ si falla → encola (max 50) y reintenta en el próximo ciclo
                     if fails → queues (max 50) and retries next cycle

PROCESAMIENTO BACKEND / BACKEND PROCESSING:
────────────────────────────────────────────────────────
AuthenticateAgent  → valida Bearer token → inyecta $agent en request
                     validates Bearer token → injects $agent into request
StoreMetricsRequest → valida estructura del payload / validates payload structure
MetricsService::process() [transacción / transaction] →
  ├─ updateAgentInfo()         → hostname, distro, cores, RAM
  ├─ MetricSnapshot::fromAgentPayload() → calcula disk_max, temp_max → guarda
                                          calculates disk_max, temp_max → saves
  ├─ AlertService::saveFromAgent()      → alertas del agente (cooldown Cache)
                                          agent alerts (Cache cooldown)
  ├─ AlertService::evaluateServerRules() → reglas del servidor / server rules
  └─ updateAgentStatus()       → critical > warning > online

TAREAS PROGRAMADAS / SCHEDULED TASKS:
────────────────────────────────────────────────────────
Cada minuto / Every minute → CheckOfflineAgents: marca offline si last_seen_at > 120s
                                                  marks offline if last_seen_at > 120s
Cada día / Daily           → PruneOldSnapshots: borra snapshots > 30 días
                                                 deletes snapshots > 30 days

POLLING DEL PANEL / PANEL POLLING (cada 10s / every 10s):
────────────────────────────────────────────────────────
GET /api/panel/dashboard  → agentes + métricas + alertas / agents + metrics + alerts
                          → actualiza Pinia store / updates Pinia store
                          → Vue re-renderiza automáticamente / re-renders automatically
```

---

## Variables de entorno

### Docker (`docker-compose.yml` → servicio `api`)

```env
DB_HOST=db
DB_DATABASE=sysmon
DB_USERNAME=sysmon
DB_PASSWORD=sysmon_pass
```

> La configuración SMTP ya no se gestiona mediante variables de entorno.
> Se configura directamente desde el panel: **Configuración → Email** (guardado en BD).
>
> *SMTP configuration is no longer managed via env vars.
> Configure it from the panel: **Settings → Email** (stored in DB).*

### Panel Vue (`sysmon-vue`)

```env
VITE_API_URL=http://localhost:8000
# En producción, apuntar a la URL pública de la API
# In production, point to public API URL
```

---

## Añadir un nuevo agente

### Desde el panel (recomendado) / From the panel (recommended):

1. Ir a **Agentes** → **+ Nuevo agente** / *Go to **Agents** → **+ New agent***
2. Rellenar nombre y etiqueta / *Fill in name and label*
3. Copiar el token que aparece (solo se muestra una vez) / *Copy the token shown (displayed only once)*
4. En el servidor Linux, editar `/etc/sysmon/agent.env` / *On the Linux server, edit `/etc/sysmon/agent.env`*:
   ```env
   API_URL=http://API-IP:8000
   AGENT_TOKEN=<token-copiado / copied-token>
   ```
5. `sudo systemctl restart sysmon-agent`

### Desde consola / From console:

```bash
docker compose exec api php artisan tinker

# En tinker / In tinker:
$agent = App\Models\Agent::create([
    'name'   => 'nuevo-servidor',
    'token'  => App\Models\Agent::generateToken(),
    'status' => 'offline',
]);
echo $agent->token;  // Copiar este token al agente / Copy this token to the agent
```

---

## Alertas

### Sistema de doble capa / Two-layer alert system:

```
Agente Python          API Laravel
─────────────          ───────────
Detecta localmente     Evalúa reglas configuradas
(incluso sin red)      en BD (mayor control)
Detects locally        Evaluates rules from DB
(even without network) (more control)
      │                      │
      └──────────────────────┤
                             ▼
                    Alerta en BD (status=open)
                    Alert in DB (status=open)
                             │
                    ┌────────┴────────┐
                    │  Email (si      │
                    │  notify=true)   │
                    │                 │
                    ▼                 ▼
             Panel Vue 3        Log Laravel
```

### Ciclo de vida de una alerta / Alert lifecycle:

```
open → acknowledged → resolved → archived
       (visto/seen)   (cerrado/closed)  (oculto/hidden)
```

### Cooldown — Prevención de spam / Spam prevention:

Cada regla tiene `cooldown_seconds`. Si la misma condición se mantiene, no se dispara otra alerta hasta que pase el cooldown. Implementado con `Cache::put("alert_cooldown:{agentId}:{ruleKey}", ...)`.

*Each rule has `cooldown_seconds`. Same condition won't fire again until cooldown expires. Implemented with Laravel Cache.*

---

## Producción

### Activar autenticación del panel / Enable panel authentication:

```php
// sysmon-laravel/routes/api.php, línea 36 / line 36:
// Cambiar / Change:
->middleware(['api'])
// Por / To:
->middleware(['api', 'auth:sanctum'])
```

### Cambiar contraseñas de BD / Change DB passwords:

En `docker-compose.yml`, cambiar `sysmon_pass` por una contraseña segura.
*In `docker-compose.yml`, replace `sysmon_pass` with a strong password.*

### Configurar SMTP real / Configure real SMTP:

La configuración SMTP se realiza desde el panel web, no en archivos de configuración.
*SMTP configuration is done from the web panel, not in config files.*

1. Ir a **http://localhost:5173** → **Configuración** → **Email** / *Go to panel → **Settings → Email***
2. Introducir host, puerto, usuario y contraseña del proveedor SMTP (Gmail, Sendgrid, Mailgun, etc.) / *Enter host, port, username and password of your SMTP provider*
3. Guardar — los cambios se aplican de inmediato sin reiniciar Docker. / *Save — changes apply immediately without restarting Docker.*

### Limpiar datos manualmente / Manual data cleanup:

```bash
# Limpiar snapshots viejos manualmente / Clean old snapshots manually
docker compose exec api php artisan sysmon:prune

# Ver estado de agentes / View agent status
docker compose exec api php artisan tinker
App\Models\Agent::all(['id','name','status','last_seen_at']);
```

---

## Comandos útiles / Useful commands

```bash
# Arrancar todo / Start everything
docker compose up -d

# Ver logs de la API / View API logs
docker compose logs -f api

# Ejecutar migrations / Run migrations
docker compose exec api php artisan migrate

# Ejecutar seeder (reglas de alerta por defecto) / Run seeder (default alert rules)
docker compose exec api php artisan db:seed --class=SysMonSeeder

# Ejecutar scheduler manualmente / Run scheduler manually
docker compose exec api php artisan schedule:run

# Shell MySQL
docker compose exec db mysql -u sysmon -psysmon_pass sysmon

# Estado del agente en el servidor Linux / Agent status on Linux server
sudo systemctl status sysmon-agent
sudo journalctl -u sysmon-agent -f --since "10 min ago"

# Reiniciar agente después de cambiar token / Restart agent after changing token
sudo systemctl restart sysmon-agent
```

---

## Apoya el proyecto / Support the project

Si este proyecto te resulta útil, puedes invitarme a un café:
*If you find this project useful, you can buy me a coffee:*

[![Donar con PayPal](https://www.paypalobjects.com/es_ES/ES/i/btn/btn_donate_LG.gif)](https://www.paypal.com/donate/?business=YUXRZATJCAHS2&no_recurring=0&currency_code=EUR)

---

*SysMon Central — Proyecto de monitorización open source / Open source monitoring project*

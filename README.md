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
5. [Backend Laravel — Guía de archivos clave](#backend-laravel)
6. [Agente Python — Guía de archivos clave](#agente-python)
7. [Panel Vue — Guía de archivos clave](#panel-vue)
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
- **Agente**: Corre en cada servidor Linux, recolecta métricas cada 30s y las envía a la API.
- **API**: Recibe métricas, evalúa reglas, dispara alertas, expone datos al panel.
- **Panel**: Dashboard web en tiempo real, polling cada 10s, sin necesidad de WebSockets.

---

## Requisitos

| Componente | Versión mínima |
|---|---|
| Docker Desktop | 24+ |
| Docker Compose | v2+ |
| Python (agente) | 3.9+ |
| Sistema del agente | Ubuntu 20.04+ / Debian 11+ |

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

*First run takes ~2-3 minutes: downloads images, runs `composer install`, migrations, seeders.*

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

El instalador:
1. Crea el usuario `sysmon` (sin login)
2. Instala Python + psutil + requests
3. Copia el agente a `/opt/sysmon/`
4. Crea el servicio systemd `sysmon-agent`
5. Crea `/etc/sysmon/agent.env` (configuración)

```bash
# Editar configuración / Edit configuration
sudo nano /etc/sysmon/agent.env
```

```env
API_URL=http://IP-DE-TU-PC:8000
AGENT_TOKEN=el-token-generado-en-el-panel
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
├── GUIA-INSTALACION.md         # Guía detallada de instalación
│
├── sysmon-agent/               # Agente Python para servidores Linux
│   ├── sysmon/
│   │   ├── agent.py            # Bucle principal / Main loop
│   │   ├── collector.py        # Recolección de métricas / Metrics collection
│   │   ├── config.py           # Gestión de configuración / Config management
│   │   ├── sender.py           # Cliente HTTP con cola / HTTP client with queue
│   │   └── alerts.py           # Evaluación local de alertas / Local alert evaluation
│   ├── scripts/
│   │   └── install.sh          # Instalador automático / Auto installer
│   ├── systemd/
│   │   └── sysmon-agent.service # Definición del servicio systemd
│   └── agent.env.example       # Plantilla de configuración / Config template
│
├── sysmon-laravel/             # API backend Laravel 11
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/Api/
│   │   │   │   ├── AgentMetricsController.php  # Endpoints del agente
│   │   │   │   └── PanelController.php          # Endpoints del panel Vue
│   │   │   ├── Middleware/
│   │   │   │   └── AuthenticateAgent.php        # Autenticación Bearer token
│   │   │   └── Requests/
│   │   │       └── StoreMetricsRequest.php      # Validación de payload
│   │   ├── Models/
│   │   │   ├── Agent.php           # Servidor monitorizado
│   │   │   ├── MetricSnapshot.php  # Datos de una captura (~30s)
│   │   │   ├── Alert.php           # Alerta disparada
│   │   │   └── AlertRule.php       # Regla de umbral
│   │   ├── Services/
│   │   │   ├── MetricsService.php  # Lógica principal de procesamiento
│   │   │   └── AlertService.php    # Evaluación y notificación de alertas
│   │   └── Console/Commands/
│   │       ├── CheckOfflineAgents.php  # Cron: marcar agentes offline
│   │       └── PruneOldSnapshots.php   # Cron: limpiar datos viejos
│   ├── routes/
│   │   ├── api.php             # Definición de rutas API
│   │   └── console.php         # Schedule de tareas programadas
│   └── database/
│       ├── migrations/         # Esquema de base de datos
│       └── seeders/
│           └── SysMonSeeder.php # Reglas de alerta globales por defecto
│
└── sysmon-vue/                 # Panel web Vue 3
    └── src/
        ├── App.vue             # Shell principal (sidebar + header)
        ├── router/index.js     # Rutas del panel
        ├── stores/index.js     # Estado global Pinia
        ├── services/api.js     # Cliente Axios para la API
        └── views/
            ├── DashboardView.vue    # Vista principal
            ├── AgentsView.vue       # Listado de agentes
            ├── AgentDetailView.vue  # Detalle + gráficas de un agente
            ├── AlertsView.vue       # Gestión de alertas
            └── RulesView.vue        # Configuración de umbrales
```

---

## Backend Laravel

### `routes/api.php` — Definición de rutas

Dos grupos de rutas / Two route groups:

```
/api/agent/*    → Autenticación: Bearer token del agente
/api/panel/*    → Autenticación: ninguna en dev (activar auth:sanctum en producción)
```

**Rutas del agente (Python → Laravel):**
```
POST /api/agent/metrics         → Enviar métricas de una captura
GET  /api/agent/ping            → Heartbeat de conectividad
GET  /api/agent/config          → Descargar configuración actualizada (reglas, umbrales)
```

**Rutas del panel (Vue → Laravel):**
```
GET  /api/panel/dashboard                    → Dashboard general con totales y alertas recientes
GET  /api/panel/agents                       → Listado de todos los agentes
POST /api/panel/agents                       → Crear nuevo agente (devuelve token)
GET  /api/panel/agents/{id}                  → Detalle de un agente
PUT  /api/panel/agents/{id}                  → Renombrar/actualizar agente
DEL  /api/panel/agents/{id}                  → Desactivar agente (soft delete)
GET  /api/panel/agents/{id}/token            → Ver token actual del agente
POST /api/panel/agents/{id}/regenerate-token → Generar nuevo token
GET  /api/panel/agents/{id}/metrics          → Serie temporal de métricas (para gráficas)
GET  /api/panel/agents/{id}/metrics/latest   → Última métrica del agente
GET  /api/panel/alerts                       → Alertas paginadas con filtros
POST /api/panel/alerts/{id}/acknowledge      → Marcar alerta como vista
POST /api/panel/alerts/{id}/resolve          → Resolver alerta con nota
POST /api/panel/alerts/{id}/archive          → Archivar alerta
POST /api/panel/alerts/archive-resolved      → Archivar todas las resueltas
GET  /api/panel/alert-rules                  → Listado de reglas de alerta
POST /api/panel/alert-rules                  → Crear nueva regla
PUT  /api/panel/alert-rules/{id}             → Modificar regla
DEL  /api/panel/alert-rules/{id}             → Eliminar regla
```

---

### `app/Services/MetricsService.php` — Procesamiento principal

**La función más importante del backend / The most important backend function.**

`process(Agent $agent, array $payload): void`

Ejecuta todo en una **transacción atómica** / Runs everything in an **atomic transaction**:

```
1. updateAgentInfo()        → Actualiza hostname, distro, cores, RAM desde el payload
2. MetricSnapshot::fromAgentPayload() → Guarda la captura en BD
3. AlertService::saveFromAgent() → Guarda alertas enviadas por el agente (cooldown 5 min)
4. AlertService::evaluateServerRules() → Evalúa reglas de backend contra el snapshot
5. updateAgentStatus()      → Cambia status del agente: critical > warning > online
```

`evaluateServerRules` vs alertas del agente:
- **Alertas del agente**: El propio agente Python detecta el problema y lo reporta.
- **Reglas del servidor**: El backend evalúa umbrales por su cuenta, como segunda capa de seguridad.

---

### `app/Services/AlertService.php` — Alertas y notificaciones

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
// For each active rule: extracts metric value, evaluates, fires if threshold exceeded.

notify(Agent $agent, Alert $alert)
// Envía email de notificación a agent.notify_email_to (o al configurado en .env).
// Sends email notification to agent.notify_email_to (or .env default).
```

`extractValue(array $payload, string $path)` — navega por notación punto, ej:
- `"cpu.usage_percent"` → `$payload['cpu']['usage_percent']`
- `"disk_max_usage_percent"` → campo calculado en `MetricSnapshot::fromAgentPayload()`

---

### `app/Models/MetricSnapshot.php` — Captura de métricas

`fromAgentPayload(Agent $agent, array $payload): self`

Transforma el payload raw del agente en una fila de base de datos.

**Calcula campos derivados / Calculates derived fields:**
```php
// disk_max_usage_percent → máximo uso de disco entre todas las particiones
//                          max disk usage across all partitions
$diskMax = collect($payload['disks'] ?? [])
    ->max('usage_percent') ?? 0.0;

// temp_max_celsius → temperatura máxima entre todos los sensores
//                    max temperature across all sensors
```

> **Importante**: Si no se calcula `disk_max_usage_percent` aquí, las reglas de disco del servidor nunca disparan porque no existe el campo en el payload raw.

---

### `app/Http/Middleware/AuthenticateAgent.php` — Autenticación del agente

Valida el Bearer token en cada petición del agente Python.

```php
// Extrae token del header: "Authorization: Bearer <token>"
// Busca en BD: Agent where token = $token AND is_active = true
// Si no existe → 401 Unauthorized
// Si existe → inyecta el agente en $request->_agent para usarlo en el controlador

// Extracts Bearer token from header, validates against DB, injects agent into request.
```

---

### `app/Http/Controllers/Api/AgentMetricsController.php` — Endpoints del agente

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

### `app/Http/Controllers/Api/PanelController.php` — Endpoints del panel

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
// Generates new token. Old one becomes invalid. Must update agent.env on server!
```

---

### `app/Console/Commands/` — Tareas programadas

**`CheckOfflineAgents.php`** — Se ejecuta cada minuto / Runs every minute:
```php
// Para cada agente activo y no-offline:
//   if agent.isOffline() → agent.status = 'offline'
// isOffline() compara: now() - last_seen_at > offline_after_seconds (120s por defecto)

// For each active non-offline agent:
//   if agent.isOffline() → marks status as 'offline'
```

**`PruneOldSnapshots.php`** — Se ejecuta a diario / Runs daily:
```php
// Elimina snapshots con collected_at < 30 días atrás.
// Sin esto la tabla metric_snapshots crecería indefinidamente.
// Deletes snapshots older than 30 days. Prevents unbounded table growth.
```

Estas tareas se registran en `routes/console.php`:
```php
Schedule::command('sysmon:check-offline')->everyMinute();
Schedule::command('sysmon:prune')->dailyAt('00:00');
```

---

### `database/seeders/SysMonSeeder.php` — Reglas de alerta por defecto

Crea las reglas globales (aplican a todos los agentes) / Creates global rules (apply to all agents):

| Regla | Métrica | Umbral | Severidad | Email |
|-------|---------|--------|-----------|-------|
| CPU — Aviso | `cpu.usage_percent` | ≥ 75% | warning | No |
| CPU — Crítico | `cpu.usage_percent` | ≥ 90% | critical | Sí |
| RAM — Aviso | `ram.usage_percent` | ≥ 80% | warning | No |
| RAM — Crítica | `ram.usage_percent` | ≥ 95% | critical | Sí |
| SWAP alta | `ram.swap_percent` | ≥ 60% | warning | No |
| Disco — Aviso | `disk_max_usage_percent` | ≥ 85% | warning | No |
| Disco — Crítico | `disk_max_usage_percent` | ≥ 95% | critical | Sí |

```bash
# Ejecutar seeder manualmente / Run seeder manually
docker compose exec api php artisan db:seed --class=SysMonSeeder
```

---

## Agente Python

### `sysmon/agent.py` — Bucle principal

```
main() → cada interval_seconds (30s por defecto / default 30s):
  1. collect_all()         → recolectar todas las métricas
  2. AlertChecker.check()  → evaluar alertas localmente
  3. MetricSender.send()   → enviar a la API (con cola si falla)
  4. sleep(restante)       → esperar hasta el próximo ciclo
```

Manejo de señales para apagado limpio / Signal handling for graceful shutdown:
```python
signal.signal(signal.SIGTERM, _handle_signal)  # systemctl stop
signal.signal(signal.SIGINT, _handle_signal)   # Ctrl+C
```

---

### `sysmon/collector.py` — Recolección de métricas

| Función | Qué recolecta | Librería |
|---------|--------------|---------|
| `get_cpu_metrics()` | Uso %, por-núcleo, frecuencia, carga 1/5/15m | psutil |
| `get_ram_metrics()` | RAM total/usada/libre, swap | psutil |
| `get_disk_metrics()` | Por partición: uso %, I/O (excluye snap/tmpfs) | psutil |
| `get_network_metrics()` | MB enviados/recibidos, conexiones activas | psutil |
| `get_temperatures()` | Temperatura por sensor (fallback a /sys/class/thermal) | psutil |
| `get_top_processes(limit=10)` | Top procesos por CPU: pid, usuario, % | psutil |
| `get_system_info()` | hostname, IP, distro, arch, uptime | socket, /etc/os-release |
| `collect_all()` | Combina todo en un único dict | — |

**Exclusión de particiones snap / Snap partition exclusion:**
```python
_EXCLUDE_FSTYPES = {'squashfs', 'tmpfs', 'devtmpfs', 'overlay', 'aufs', 'ramfs', 'iso9660'}
# Sin esto, las particiones snap siempre reportan 100% y disparan alertas de disco falsas.
# Without this, snap partitions always show 100% and trigger false disk alerts.
```

---

### `sysmon/sender.py` — Envío con cola

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
- `401` → Token inválido. No reintentar, log error.
- `422` → Payload inválido. No reintentar, log warning.
- `5xx` / timeout → Encolar para reintento.

La cola tiene un máximo de 50 entradas; si se llena, descarta los más antiguos.
*Queue has max 50 entries; when full, drops oldest payloads.*

---

### `sysmon/alerts.py` — Alertas locales

El agente evalúa umbrales **antes** de enviar, con cooldown para evitar spam:

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
- Temperatura: dinámica según límites del sensor

---

### `sysmon/config.py` — Configuración

Lee de `/etc/sysmon/agent.env`. Variables de entorno `SYSMON_*` tienen precedencia.
*Reads from `/etc/sysmon/agent.env`. `SYSMON_*` env vars take precedence.*

**Variables obligatorias / Required:**
```env
API_URL=http://192.168.1.x:8000
AGENT_TOKEN=<token-generado-en-el-panel>
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
SEND_PROCESSES=true          # Incluir top procesos en el payload
PROCESSES_LIMIT=10
```

---

## Panel Vue

### `src/stores/index.js` — Estado global (Pinia)

**`useDashboardStore`** — Estado del dashboard y agentes:
```javascript
fetch()           // GET /api/panel/dashboard → actualiza todo el estado
startPolling(ms)  // Llama a fetch() cada ms (10000 = 10s) / Calls fetch() every ms
stopPolling()     // Cancela el intervalo / Cancels interval

// Computed:
agents            // Lista de agentes activos / Active agents list
openAlerts        // 10 alertas abiertas más recientes / 10 most recent open alerts
totals            // Contadores: online, warning, critical, offline, open_alerts
```

**`useAlertsStore`** — Estado de la página de alertas:
```javascript
fetch(page)       // GET /api/panel/alerts con filtros y paginación
acknowledge(id)   // POST /api/panel/alerts/{id}/acknowledge
resolve(id, note) // POST /api/panel/alerts/{id}/resolve
archive(id)       // POST /api/panel/alerts/{id}/archive
archiveAllResolved(agentId?) // POST /api/panel/alerts/archive-resolved
```

---

### `src/services/api.js` — Cliente HTTP

Instancia Axios con baseURL desde variable de entorno `VITE_API_URL`.
*Axios instance with baseURL from `VITE_API_URL` env variable.*

```javascript
// En desarrollo (Docker), VITE_API_URL=http://localhost:8000
// En producción, cambiar a la URL real de la API
// In dev (Docker), VITE_API_URL=http://localhost:8000
// In production, change to real API URL
```

Todos los métodos están agrupados en el objeto `panelApi`:
```javascript
panelApi.dashboard()
panelApi.agents()
panelApi.createAgent(data)     // { name, notes, notify_email_to }
panelApi.getToken(id)          // Obtiene token actual / Gets current token
panelApi.regenerateToken(id)   // Genera nuevo token / Generates new token
panelApi.metrics(id, hours)    // Datos para gráficas / Data for charts
// ... ver src/services/api.js para la lista completa
```

---

### `src/App.vue` — Shell principal

- Sidebar fijo con navegación y badge de alertas abiertas.
- Header con título dinámico por ruta, indicador Live y reloj.
- Inicia polling global del store en `onMounted` → todos los datos se actualizan solos.
- Detiene polling en `onUnmounted` → no hay memory leaks.

*Fixed sidebar with navigation. Header with dynamic route title, Live indicator and clock. Starts global store polling on mount → all data updates automatically.*

---

## Base de datos

### `agents`
| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | int PK | — |
| `name` | string | Nombre visible en el panel |
| `hostname` | string | Hostname del servidor (auto, del agente) |
| `ip_address` | string | IP (auto, del agente) |
| `token` | string unique | Bearer token de autenticación |
| `status` | enum | `online`, `warning`, `critical`, `offline` |
| `last_seen_at` | timestamp | Último ping recibido |
| `offline_after_seconds` | int | Umbral para marcar offline (default: 120) |
| `notes` | string | Etiqueta libre (ej: "producción", "CASA") |
| `notify_email` | bool | ¿Enviar emails de alerta? |
| `notify_email_to` | string | Email destino de alertas |
| `is_active` | bool | False = "eliminado" (soft delete) |

### `metric_snapshots`
Cada fila = una captura del agente (~cada 30s). Campos principales:

| Campo | Descripción |
|-------|-------------|
| `cpu_usage_percent` | Uso total de CPU |
| `cpu_load_1m/5m/15m` | Load average del sistema |
| `ram_usage_percent` | Uso de RAM |
| `disk_max_usage_percent` | Máximo uso de disco entre particiones |
| `temp_max_celsius` | Temperatura máxima entre sensores |
| `net_sent/recv_mb` | Tráfico de red acumulado |
| `disks` | JSON completo de todas las particiones |
| `temperatures` | JSON completo de todos los sensores |
| `processes` | JSON top 10 procesos por CPU |

> Se limpian automáticamente después de 30 días con `sysmon:prune`.
> *Auto-cleaned after 30 days by `sysmon:prune`.*

### `alerts`
| Campo | Descripción |
|-------|-------------|
| `severity` | `info`, `warning`, `critical` |
| `source` | `agent` (enviada por Python) o `server` (evaluada por Laravel) |
| `status` | `open`, `acknowledged`, `resolved` |
| `metric` | Nombre de la métrica (ej: `cpu.usage_percent`) |
| `value` | Valor en el momento de dispararse |
| `threshold` | Umbral que se superó |
| `archived_at` | NULL = visible, not-NULL = archivada |

### `alert_rules`
| Campo | Descripción |
|-------|-------------|
| `agent_id` | NULL = regla global (aplica a todos) |
| `rule_key` | Identificador único (ej: `cpu_critical`) |
| `metric_path` | Notación punto para extraer del payload (ej: `cpu.usage_percent`) |
| `operator` | `gte`, `gt`, `lte`, `lt` |
| `threshold` | Valor numérico del umbral |
| `message_template` | Texto con `{value}` y `{threshold}` como placeholders |
| `cooldown_seconds` | Mínimo tiempo entre alertas iguales |

---

## Flujo de datos

```
CICLO DEL AGENTE (cada 30s) / AGENT CYCLE (every 30s):
────────────────────────────────────────────────────────
collector.py     → recolecta CPU, RAM, Disco, Red, Temp, Procesos
alerts.py        → evalúa umbrales localmente (con cooldown)
sender.py        → POST /api/agent/metrics (Bearer token)
                   ↳ si falla → encola (max 50) y reintenta en el próximo ciclo

PROCESAMIENTO BACKEND / BACKEND PROCESSING:
────────────────────────────────────────────────────────
AuthenticateAgent  → valida Bearer token → inyecta $agent en request
StoreMetricsRequest → valida estructura del payload
MetricsService::process() [transacción] →
  ├─ updateAgentInfo()         → hostname, distro, cores, RAM
  ├─ MetricSnapshot::fromAgentPayload() → calcula disk_max, temp_max → guarda
  ├─ AlertService::saveFromAgent()      → alertas del agente (cooldown Cache)
  ├─ AlertService::evaluateServerRules() → reglas del servidor
  └─ updateAgentStatus()       → critical > warning > online

TAREAS PROGRAMADAS / SCHEDULED TASKS:
────────────────────────────────────────────────────────
Cada minuto  → CheckOfflineAgents: marca offline si last_seen_at > 120s
Cada día     → PruneOldSnapshots: borra snapshots > 30 días

POLLING DEL PANEL / PANEL POLLING (cada 10s / every 10s):
────────────────────────────────────────────────────────
GET /api/panel/dashboard  → agentes + métricas + alertas
                          → actualiza Pinia store
                          → Vue re-renderiza automáticamente
```

---

## Variables de entorno

### Docker (`docker-compose.yml` → servicio `api`)

```env
DB_HOST=db
DB_DATABASE=sysmon
DB_USERNAME=sysmon
DB_PASSWORD=sysmon_pass
MAIL_HOST=sandbox.smtp.mailtrap.io   # Cambiar en producción / Change for production
MAIL_USERNAME=...
MAIL_PASSWORD=...
ALERT_FROM_EMAIL=noreply@sysmon.local
ALERT_FROM_NAME=SysMon Central
```

### Panel Vue (`sysmon-vue`)

```env
VITE_API_URL=http://localhost:8000
# En producción, apuntar a la URL pública de la API
# In production, point to public API URL
```

---

## Añadir un nuevo agente

### Desde el panel (recomendado) / From the panel (recommended):

1. Ir a **Agentes** → **+ Nuevo agente**
2. Rellenar nombre y etiqueta
3. Copiar el token que aparece (solo se muestra una vez)
4. En el servidor Linux, editar `/etc/sysmon/agent.env`:
   ```env
   API_URL=http://IP-DE-LA-API:8000
   AGENT_TOKEN=<token-copiado>
   ```
5. `sudo systemctl restart sysmon-agent`

### Desde consola / From console:

```bash
docker compose exec api php artisan tinker

# En tinker:
$agent = App\Models\Agent::create([
    'name'   => 'nuevo-servidor',
    'token'  => App\Models\Agent::generateToken(),
    'status' => 'offline',
]);
echo $agent->token;  // Copiar este token al agente
```

---

## Alertas

### Sistema de doble capa / Two-layer alert system:

```
Agente Python          API Laravel
─────────────          ───────────
Detecta localmente     Evalúa reglas configuradas
(incluso sin red)      en BD (mayor control)
      │                      │
      └──────────────────────┤
                             ▼
                    Alerta en BD (status=open)
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
         (visto)      (cerrado)  (oculto)
```

### Cooldown — Prevención de spam:

Cada regla tiene `cooldown_seconds`. Si la misma condición se mantiene, no se dispara otra alerta hasta que pase el cooldown. Implementado con `Cache::put("alert_cooldown:{agentId}:{ruleKey}", ...)`.

*Each rule has `cooldown_seconds`. Same condition won't fire again until cooldown expires. Implemented with Laravel Cache.*

---

## Producción

### Activar autenticación del panel / Enable panel authentication:

```php
// sysmon-laravel/routes/api.php, línea 36:
// Cambiar:
->middleware(['api'])
// Por:
->middleware(['api', 'auth:sanctum'])
```

### Cambiar contraseñas de BD / Change DB passwords:

En `docker-compose.yml`, cambiar `sysmon_pass` por una contraseña segura.

### Configurar SMTP real / Configure real SMTP:

```env
MAIL_HOST=smtp.tuproveedor.com
MAIL_PORT=587
MAIL_USERNAME=tu@email.com
MAIL_PASSWORD=contraseña
MAIL_ENCRYPTION=tls
```

### Limpiar datos manualmente / Manual data cleanup:

```bash
# Limpiar snapshots viejos manualmente
docker compose exec api php artisan sysmon:prune

# Ver estado de agentes
docker compose exec api php artisan tinker
App\Models\Agent::all(['id','name','status','last_seen_at']);
```

---

## Comandos útiles / Useful commands

```bash
# Arrancar todo / Start everything
docker compose up -d

# Ver logs de la API
docker compose logs -f api

# Ejecutar migrations
docker compose exec api php artisan migrate

# Ejecutar seeder (reglas de alerta por defecto)
docker compose exec api php artisan db:seed --class=SysMonSeeder

# Ejecutar scheduler manualmente
docker compose exec api php artisan schedule:run

# Shell MySQL
docker compose exec db mysql -u sysmon -psysmon_pass sysmon

# Estado del agente en el servidor Linux
sudo systemctl status sysmon-agent
sudo journalctl -u sysmon-agent -f --since "10 min ago"

# Reiniciar agente después de cambiar token
sudo systemctl restart sysmon-agent
```

---

*SysMon Central — Proyecto de monitorización open source / Open source monitoring project*

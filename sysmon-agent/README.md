# SysMon Agent

Agente ligero en Python para monitoreo de rendimiento en servidores Ubuntu.
Recoge métricas del sistema y las envía a tu backend Laravel vía HTTP.

---

## Qué recoge

| Métrica         | Detalle                                              |
|-----------------|------------------------------------------------------|
| **CPU**         | Uso global, por core, frecuencia, load average       |
| **RAM**         | Uso total/libre, SWAP                                |
| **Disco**       | Uso por partición, I/O read/write                    |
| **Red**         | Bytes enviados/recibidos por interfaz, conexiones    |
| **Temperaturas**| CPU cores, GPU, motherboard (vía lm-sensors / psutil)|
| **Procesos**    | Top N por CPU con PID, usuario, RAM, estado          |
| **Sistema**     | Hostname, IP, distro, uptime, versión OS             |

---

## Instalación rápida

```bash
# 1. Clonar / copiar el agente al servidor
git clone https://... /tmp/sysmon-agent
cd /tmp/sysmon-agent

# 2. Instalar (requiere root)
sudo bash scripts/install.sh

# 3. Editar la configuración
sudo nano /etc/sysmon/agent.env
#  → Configura API_URL y AGENT_TOKEN

# 4. Arrancar el servicio
sudo systemctl start sysmon-agent

# 5. Verificar
sudo systemctl status sysmon-agent
journalctl -u sysmon-agent -f
```

---

## Configuración (`/etc/sysmon/agent.env`)

```env
# Obligatorio
API_URL=https://tu-panel.com
AGENT_TOKEN=token-del-panel

# Identificación (opcional, si no se usa el hostname)
AGENT_NAME=web-server-01

# Intervalo de envío en segundos
INTERVAL_SECONDS=30

# Umbrales de alerta local
CPU_WARN_THRESHOLD=75
CPU_CRITICAL_THRESHOLD=90
RAM_WARN_THRESHOLD=80
RAM_CRITICAL_THRESHOLD=95
TEMP_WARN_THRESHOLD=80
DISK_WARN_THRESHOLD=85
```

---

## Formato del payload enviado al backend

```json
{
  "timestamp": "2025-03-05T14:32:01Z",
  "agent_name": "web-server-01",
  "system": {
    "hostname": "web-server-01",
    "ip": "192.168.1.10",
    "os": "Linux",
    "distro": "Ubuntu 22.04.3 LTS",
    "uptime_secs": 1234567
  },
  "cpu": {
    "usage_percent": 67.2,
    "per_core_percent": [72.1, 61.4, 68.0, 67.3],
    "load_1m": 2.4,
    "load_5m": 2.1,
    "load_15m": 1.8,
    "freq_current_mhz": 3200.0
  },
  "ram": {
    "total_gb": 32.0,
    "used_gb": 14.2,
    "usage_percent": 44.3,
    "swap_percent": 5.1
  },
  "disks": [
    {
      "device": "/dev/sda1",
      "mountpoint": "/",
      "total_gb": 500.0,
      "used_gb": 420.0,
      "usage_percent": 84.0,
      "read_mb": 12400.0,
      "write_mb": 3200.0
    }
  ],
  "network": {
    "total_sent_mb": 52400.0,
    "total_recv_mb": 128000.0,
    "interfaces": { "eth0": { "bytes_sent_mb": 52400.0, ... } },
    "connections_count": 142
  },
  "temperatures": {
    "coretemp": [
      { "label": "Core 0", "current": 71.0, "high": 80.0, "critical": 100.0 }
    ]
  },
  "processes": [
    { "pid": 1842, "name": "mysqld", "cpu_percent": 67.0, "ram_mb": 3400.0, "status": "running" }
  ],
  "alerts": [
    {
      "rule": "cpu_critical",
      "severity": "critical",
      "metric": "cpu.usage_percent",
      "value": 91.2,
      "threshold": 90.0,
      "message": "CPU CRÍTICA: 91.2% (umbral: 90.0%)"
    }
  ]
}
```

---

## Comandos útiles

```bash
# Ver logs en tiempo real
journalctl -u sysmon-agent -f

# Reiniciar
sudo systemctl restart sysmon-agent

# Parar
sudo systemctl stop sysmon-agent

# Desactivar arranque automático
sudo systemctl disable sysmon-agent

# Ejecutar manualmente (para debug)
/opt/sysmon/venv/bin/python /opt/sysmon/sysmon/agent.py --config /etc/sysmon/agent.env
```

---

## Endpoints del backend que el agente usa

| Método | Endpoint                  | Descripción                  |
|--------|---------------------------|------------------------------|
| POST   | `/api/agent/metrics`      | Envío de métricas (payload)  |

Headers requeridos:
```
Authorization: Bearer {AGENT_TOKEN}
Content-Type: application/json
```

El backend responde `200` o `201` si acepta el payload.

---

## Estructura del proyecto

```
sysmon-agent/
├── sysmon/
│   ├── __init__.py
│   ├── agent.py       ← bucle principal
│   ├── collector.py   ← recolección de métricas
│   ├── sender.py      ← envío HTTP con reintentos
│   ├── alerts.py      ← evaluación de umbrales local
│   └── config.py      ← lectura de configuración
├── scripts/
│   └── install.sh     ← instalación como servicio
├── systemd/
│   └── sysmon-agent.service
├── agent.env.example
└── README.md
```

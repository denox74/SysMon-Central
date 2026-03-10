"""
SysMon Agent — Collector
Recopila métricas del sistema: CPU, RAM, disco, red, procesos, temperaturas.
"""

import psutil
import platform
import socket
import time
import subprocess
from datetime import datetime
from typing import Optional


def get_cpu_metrics() -> dict:
    """CPU: uso global, por core, frecuencia, carga."""
    per_core = psutil.cpu_percent(interval=1, percpu=True)
    freq = psutil.cpu_freq()
    load = psutil.getloadavg()  # 1m, 5m, 15m

    return {
        "usage_percent":    psutil.cpu_percent(interval=None),
        "per_core_percent": per_core,
        "core_count":       psutil.cpu_count(logical=False),
        "thread_count":     psutil.cpu_count(logical=True),
        "freq_current_mhz": round(freq.current, 1) if freq else None,
        "freq_max_mhz":     round(freq.max, 1) if freq else None,
        "load_1m":          round(load[0], 2),
        "load_5m":          round(load[1], 2),
        "load_15m":         round(load[2], 2),
    }


def get_ram_metrics() -> dict:
    """RAM y SWAP."""
    vm   = psutil.virtual_memory()
    swap = psutil.swap_memory()

    return {
        "total_gb":        round(vm.total / 1e9, 2),
        "used_gb":         round(vm.used  / 1e9, 2),
        "available_gb":    round(vm.available / 1e9, 2),
        "usage_percent":   vm.percent,
        "swap_total_gb":   round(swap.total / 1e9, 2),
        "swap_used_gb":    round(swap.used  / 1e9, 2),
        "swap_percent":    swap.percent,
    }


# Sistemas de archivos excluidos del reporte de disco.
# Sin esto, las particiones snap (squashfs) siempre dan 100% y disparan falsas alertas.
# File systems excluded from disk report.
# Without this, snap partitions (squashfs) always show 100% causing false alerts.
_EXCLUDE_FSTYPES = {'squashfs', 'tmpfs', 'devtmpfs', 'overlay', 'aufs', 'ramfs', 'iso9660'}


def get_disk_metrics() -> list[dict]:
    """Todas las particiones montadas reales (excluye snap/tmpfs) + contadores I/O.
    All real mounted partitions (excludes snap/tmpfs) + I/O counters."""
    disks = []
    io_counters = psutil.disk_io_counters(perdisk=True)

    for part in psutil.disk_partitions(all=False):
        if part.fstype in _EXCLUDE_FSTYPES:
            continue
        try:
            usage = psutil.disk_usage(part.mountpoint)
        except PermissionError:
            continue

        # Intentar obtener I/O del dispositivo base (sda, nvme0n1…)
        dev_name = part.device.split("/")[-1]
        io = io_counters.get(dev_name)

        disks.append({
            "device":        part.device,
            "mountpoint":    part.mountpoint,
            "fstype":        part.fstype,
            "total_gb":      round(usage.total / 1e9, 2),
            "used_gb":       round(usage.used  / 1e9, 2),
            "free_gb":       round(usage.free  / 1e9, 2),
            "usage_percent": usage.percent,
            "read_mb":       round(io.read_bytes  / 1e6, 2) if io else None,
            "write_mb":      round(io.write_bytes / 1e6, 2) if io else None,
        })

    return disks


def get_network_metrics() -> dict:
    """Tráfico de red global y por interfaz."""
    net_io = psutil.net_io_counters()
    per_iface = {}

    for iface, stats in psutil.net_io_counters(pernic=True).items():
        if iface == "lo":
            continue
        per_iface[iface] = {
            "bytes_sent_mb":    round(stats.bytes_sent / 1e6, 2),
            "bytes_recv_mb":    round(stats.bytes_recv / 1e6, 2),
            "packets_sent":     stats.packets_sent,
            "packets_recv":     stats.packets_recv,
            "errors_in":        stats.errin,
            "errors_out":       stats.errout,
            "drop_in":          stats.dropin,
            "drop_out":         stats.dropout,
        }

    try:
        # net_connections() requiere permisos root en algunos sistemas.
        # net_connections() requires root permissions on some systems.
        connections_count = len(psutil.net_connections())
    except (psutil.AccessDenied, PermissionError):
        connections_count = None  # No disponible sin root / Not available without root

    return {
        "total_sent_mb":    round(net_io.bytes_sent / 1e6, 2),
        "total_recv_mb":    round(net_io.bytes_recv / 1e6, 2),
        "interfaces":       per_iface,
        "connections_count": connections_count,
    }


def get_temperatures() -> dict:
    """
    Temperaturas vía psutil (requiere lm-sensors instalado).
    Devuelve dict de sensor → lista de lecturas.
    """
    temps = {}

    try:
        raw = psutil.sensors_temperatures()
        for sensor_name, entries in raw.items():
            temps[sensor_name] = [
                {
                    "label":    entry.label or f"sensor_{i}",
                    "current":  entry.current,
                    "high":     entry.high,
                    "critical": entry.critical,
                }
                for i, entry in enumerate(entries)
            ]
    except AttributeError:
        # psutil no soporta sensors en este OS
        pass

    # Fallback para sistemas ARM o donde psutil no tiene soporte de sensores.
    # Fallback for ARM systems or where psutil has no sensor support.
    # Fallback: leer /sys/class/thermal (común en ARM / Ubuntu)
    if not temps:
        try:
            result = subprocess.run(
                ["find", "/sys/class/thermal", "-name", "temp"],
                capture_output=True, text=True, timeout=2
            )
            for path in result.stdout.strip().split("\n"):
                if not path:
                    continue
                try:
                    with open(path) as f:
                        val = int(f.read().strip()) / 1000
                    zone = path.split("/")[4]
                    temps.setdefault("thermal", []).append(
                        {"label": zone, "current": val, "high": None, "critical": None}
                    )
                except Exception:
                    pass
        except Exception:
            pass

    return temps


def get_top_processes(limit: int = 10) -> list[dict]:
    """Top N procesos por uso de CPU."""
    procs = []

    for proc in psutil.process_iter(
        ["pid", "name", "username", "cpu_percent", "memory_percent",
         "memory_info", "status", "create_time", "cmdline"]
    ):
        try:
            info = proc.info
            procs.append({
                "pid":            info["pid"],
                "name":           info["name"],
                "user":           info.get("username") or "?",
                "cpu_percent":    round(info["cpu_percent"] or 0, 2),
                "ram_percent":    round(info.get("memory_percent") or 0, 2),
                "ram_mb":         round((info["memory_info"].rss if info["memory_info"] else 0) / 1e6, 1),
                "status":         info["status"],
                "uptime_secs":    int(time.time() - (info["create_time"] or time.time())),
            })
        except (psutil.NoSuchProcess, psutil.AccessDenied):
            pass

    procs.sort(key=lambda x: x["cpu_percent"], reverse=True)
    return procs[:limit]


def get_system_info() -> dict:
    """Información estática del host (se envía con cada payload)."""
    boot_time = psutil.boot_time()
    return {
        "hostname":       socket.gethostname(),
        "ip":             socket.gethostbyname(socket.gethostname()),
        "os":             platform.system(),
        "os_version":     platform.version(),
        "os_release":     platform.release(),
        "distro":         _get_distro(),
        "arch":           platform.machine(),
        "python_version": platform.python_version(),
        "boot_time":      datetime.fromtimestamp(boot_time).isoformat(),
        "uptime_secs":    int(time.time() - boot_time),
    }


def _get_distro() -> str:
    try:
        with open("/etc/os-release") as f:
            for line in f:
                if line.startswith("PRETTY_NAME="):
                    return line.split("=", 1)[1].strip().strip('"')
    except Exception:
        pass
    return platform.system()


def collect_all(include_processes: bool = True) -> dict:
    """Recopila todas las métricas y devuelve un dict listo para enviar."""
    payload = {
        "timestamp":  datetime.utcnow().isoformat() + "Z",
        "system":     get_system_info(),
        "cpu":        get_cpu_metrics(),
        "ram":        get_ram_metrics(),
        "disks":      get_disk_metrics(),
        "network":    get_network_metrics(),
        "temperatures": get_temperatures(),
    }

    if include_processes:
        payload["processes"] = get_top_processes()

    return payload

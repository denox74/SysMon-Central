# SysMon — Guía de instalación completa
## Del cero a tener todo funcionando

---

## Resumen de lo que vas a tener

```
Tu PC (Windows/Mac/Linux)
├── Docker Desktop
│   ├── sysmon-db     → MySQL 8  (puerto 3306)
│   ├── sysmon-api    → Laravel  (puerto 8000)
│   └── sysmon-panel  → Vue      (puerto 5173)
                ▲
                │  HTTP por tu red local
                │
VM Ubuntu (VirtualBox / VMware)
└── sysmon-agent (Python, servicio systemd)
    → envía métricas cada 30s
```

---

## PASO 1 — Asegúrate de tener Docker Desktop instalado

- **Windows/Mac:** [https://www.docker.com/products/docker-desktop](https://www.docker.com/products/docker-desktop)
- **Linux:** instala `docker` y `docker compose` con tu gestor de paquetes

Verifica que funciona:
```bash
docker --version
docker compose version
```

---

## PASO 2 — Configurar email de alertas (opcional)

Crea un archivo `.env` en la carpeta raíz del proyecto (junto a `docker-compose.yml`):

```env
# .env (en la raíz del proyecto, junto a docker-compose.yml)

# Email para recibir alertas (usa Mailtrap para desarrollo)
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=TU_USUARIO_MAILTRAP
MAIL_PASSWORD=TU_PASSWORD_MAILTRAP

SYSMON_ALERT_EMAIL=tu@email.com
```

> Si omites este paso, el sistema funciona igualmente pero no enviará emails de alerta.
>
> **Mailtrap** es gratis y te permite ver los emails en el navegador sin que lleguen a ningún buzón real.
> Regístrate en https://mailtrap.io y usa las credenciales de tu "sandbox".

---

## PASO 3 — Arrancar Docker

```bash
# Situarse en la carpeta raíz del proyecto
cd ProyectoSymon/

# Arrancar todo
# La PRIMERA VEZ tarda ~5-10 minutos (descarga imágenes + crea proyecto Laravel)
docker compose up
```

Verás los logs de los tres contenedores. El contenedor `sysmon-api` mostrará:

```
=== Primera instalación: creando proyecto Laravel base... ===
=== Proyecto Laravel base creado OK ===
...
Server running on [http://0.0.0.0:8000]
```

Y el panel Vue:
```
sysmon-panel | VITE ready in XXXms
```

Ya puedes abrir el panel en: **http://localhost:5173**

> En arranques posteriores (segunda vez en adelante) tardará ~30 segundos porque
> el proyecto Laravel ya está creado.

### Comandos útiles Docker

```bash
# Ver estado de los contenedores
docker compose ps

# Ver logs de un servicio
docker compose logs api    # backend Laravel
docker compose logs panel  # Vue
docker compose logs db     # MySQL

# Seguir logs en tiempo real
docker compose logs -f api

# Parar todo
docker compose down

# Parar y borrar la base de datos (reset completo)
docker compose down -v
```

---

## PASO 4 — Verificar que la API funciona

Abre el navegador o usa curl:

```
http://localhost:8000/api/panel/dashboard
```

Deberías ver un JSON con `"agents": []` y `"totals": {...}`.

Si ves un error, revisa los logs:
```bash
docker compose logs api
```

---

## PASO 5 — Obtener el token del agente de prueba

El sistema ha creado automáticamente un agente de prueba llamado `vm-test-01`.
Para ver su token:

```bash
docker compose exec api php artisan tinker --execute="echo App\Models\Agent::first()->token;"
```

Copia ese token. Lo necesitarás en el PASO 7.

---

## PASO 6 — Conocer la IP de tu PC en la red local

El agente de la VM necesita la IP de tu PC para enviar datos.

**Windows:**
```
ipconfig
# Busca "Adaptador de red" → "Dirección IPv4"
# Suele ser algo como 192.168.1.X o 10.0.0.X
```

**Mac/Linux:**
```bash
ip addr show   # o
ifconfig
```

Anota esa IP, la usarás en el siguiente paso.

> Asegúrate de que la VM y tu PC están en la **misma red**.
> En VirtualBox usa "Red en modo puente" (Bridged) en la configuración de red de la VM.

---

## PASO 7 — Instalar el agente en la VM Ubuntu

Conectarte a la VM (por consola o SSH) y ejecutar:

```bash
# 1. Copiar la carpeta sysmon-agent desde tu PC a la VM
#    Opción A: scp (desde tu PC)
scp -r ProyectoSymon/sysmon-agent usuario@IP_DE_LA_VM:/tmp/

#    Opción B: si tienes acceso compartido o USB, copia la carpeta sysmon-agent

# 2. En la VM: instalar el agente
cd /tmp/sysmon-agent
sudo bash scripts/install.sh

# 3. Configurar el agente
sudo nano /etc/sysmon/agent.env
```

En el editor, configura estas líneas:

```env
# URL de tu backend Laravel (IP de tu PC + puerto 8000)
API_URL=http://192.168.1.X:8000

# Token del agente (el que obtuviste en el PASO 5)
AGENT_TOKEN=pega-el-token-aqui

# Nombre que verás en el panel
AGENT_NAME=vm-test-01
```

Guarda con `Ctrl+O`, `Enter`, `Ctrl+X`.

```bash
# 4. Arrancar el agente
sudo systemctl start sysmon-agent

# 5. Verificar que está funcionando
sudo systemctl status sysmon-agent
journalctl -u sysmon-agent -f
```

Deberías ver en los logs:
```
[INFO] sysmon — Ciclo #1 OK — CPU 12.4% | RAM 34.1% | Enviado en 0.23s
```

---

## PASO 8 — Ver los datos en el panel

1. Abre **http://localhost:5173**
2. Ve al **Dashboard** — deberías ver el agente `vm-test-01` con estado `online`
3. Haz clic en el agente para ver el detalle con gráficas

Si el agente no aparece online después de 1 minuto, revisa los logs del agente
y verifica que la IP y el token son correctos.

---

## Añadir más agentes (multi-agente)

Para monitorizar varias VMs, crea un agente por cada una desde el panel:

1. Abre **http://localhost:5173** → **Agentes** → **Nuevo agente**
2. Dale un nombre descriptivo (ej. `servidor-web`, `bd-principal`)
3. Copia el **token** que aparece al crearlo (solo se muestra una vez)
4. En la nueva VM: ejecuta `sudo bash scripts/install.sh` y configura `/etc/sysmon/agent.env` con ese token

Cada agente se identifica por su token único. El panel muestra todos con sus métricas independientes y permite configurar reglas de alerta globales o específicas por agente.

---

## Solución de problemas habituales

### El agente dice "No se pudo conectar"
- Verifica que Docker está corriendo: `docker compose ps`
- Verifica la IP de tu PC: puede haber cambiado. Actualiza `API_URL` en `agent.env`
- VirtualBox: asegúrate de que la red de la VM está en modo **Puente (Bridged)**
- Comprueba que el puerto 8000 no está bloqueado por el firewall de Windows

### La API devuelve 401 Unauthorized
- El token está mal copiado. Cópialo de nuevo: `docker compose exec api php artisan tinker --execute="echo App\Models\Agent::first()->token;"`
- Regenera el token desde el panel → Agentes → botón de regenerar token
- Reinicia el agente: `sudo systemctl restart sysmon-agent`

### El primer arranque de Docker falla o se cuelga
- Revisa los logs: `docker compose logs api`
- Asegúrate de tener conexión a internet (necesita descargar Laravel la primera vez)
- Prueba a reiniciar: `docker compose down && docker compose up`

### El panel Vue no carga
- Verifica: `docker compose logs panel`
- Espera un poco más, `npm install` puede tardar 1-2 minutos la primera vez

### No llegan los emails de alerta
- Verifica las credenciales de Mailtrap en el `.env` (junto a `docker-compose.yml`)
- Revisa: `docker compose logs api` para ver errores de email
- En producción cambiarás a un SMTP real (Gmail, Sendgrid, etc.)

 ### Cambiar el Token al agente
 - `sudo nano /etc/sysmon/agent.env` y cambiarlo en el archivo
 - `sudo systemctl restart sysmon-agent`


### Resetear todo y empezar de cero
```bash
docker compose down -v    # borra también los volúmenes (BD incluida)
docker compose up
```

---

## Para producción (cuando llegue el momento)

| Dev (ahora)            | Producción                              |
|------------------------|-----------------------------------------|
| `APP_DEBUG=true`       | `APP_DEBUG=false`                       |
| `php artisan serve`    | Nginx + PHP-FPM                         |
| `QUEUE_CONNECTION=sync`| Redis + Horizon (emails en background)  |
| Mailtrap               | SMTP real (Gmail, Sendgrid, Mailgun)    |
| Sin auth en el panel   | Laravel Sanctum + login Vue             |
| Token en texto plano   | Tokens hasheados en BD                  |
| HTTP                   | HTTPS con certificado                   |

---

## Resumen de URLs

| Servicio       | URL                                              |
|----------------|--------------------------------------------------|
| Panel Vue      | http://localhost:5173                            |
| Laravel API    | http://localhost:8000                            |
| API Dashboard  | http://localhost:8000/api/panel/dashboard        |
| MySQL          | localhost:3306 (usuario: sysmon / sysmon_pass)   |

# SysMon — Complete Installation Guide
## From zero to fully running

---

## What you will end up with

```
Your PC (Windows/Mac/Linux)
├── Docker Desktop
│   ├── sysmon-db     → MySQL 8  (port 3306)
│   ├── sysmon-api    → Laravel  (port 8000)
│   └── sysmon-panel  → Vue      (port 5173)
                ▲
                │  HTTP over your local network
                │
Ubuntu VM (VirtualBox / VMware)
└── sysmon-agent (Python, systemd service)
    → sends metrics every 30s
```

---

## STEP 1 — Make sure Docker Desktop is installed

- **Windows/Mac:** [https://www.docker.com/products/docker-desktop](https://www.docker.com/products/docker-desktop)
- **Linux:** install `docker` and `docker compose` with your package manager

Verify it works:
```bash
docker --version
docker compose version
```

---

## STEP 2 — Start Docker

```bash
# Go to the project root folder
cd ProyectoSymon/

# Start everything
# The FIRST TIME takes ~5-10 minutes (downloads images + creates the Laravel project)
docker compose up
```

You will see logs from the three containers. The `sysmon-api` container will show:

```
=== First install: creating base Laravel project... ===
=== Base Laravel project created OK ===
...
Server running on [http://0.0.0.0:8000]
```

And the Vue panel:
```
sysmon-panel | VITE ready in XXXms
```

You can now open the panel at: **http://localhost:5173**

> Subsequent starts (second time onwards) take ~30 seconds because
> the Laravel project is already created.

### Useful Docker commands

```bash
# Check container status
docker compose ps

# View logs for a service
docker compose logs api    # Laravel backend
docker compose logs panel  # Vue
docker compose logs db     # MySQL

# Follow logs in real time
docker compose logs -f api

# Stop everything
docker compose down

# Stop and delete the database (full reset)
docker compose down -v
```

---

## STEP 3 — Configure alert emails (optional)

Email is configured directly from the panel — no `.env` file required.

Once Docker is running, go to **http://localhost:5173** → **Settings** → **Email** and enter your SMTP credentials.

> **Mailtrap** is free and lets you view emails in the browser without them reaching any real inbox.
> Sign up at https://mailtrap.io and use your "sandbox" credentials.
>
> For production, simply enter your real SMTP provider's details (Gmail, Sendgrid, Mailgun, etc.) from the same panel.

---

## STEP 4 — Verify the API is working

Open a browser or use curl:

```
http://localhost:8000/api/panel/dashboard
```

You should see a JSON response with `"agents": []` and `"totals": {...}`.

If you see an error, check the logs:
```bash
docker compose logs api
```

---

## STEP 5 — Get the test agent token

The system automatically created a test agent called `vm-test-01`.
To see its token:

```bash
docker compose exec api php artisan tinker --execute="echo App\Models\Agent::first()->token;"
```

Copy that token. You will need it in STEP 7.

---

## STEP 6 — Find your PC's IP address on the local network

The agent on the VM needs your PC's IP to send data.

**Windows:**
```
ipconfig
# Look for "Network Adapter" → "IPv4 Address"
# Usually something like 192.168.1.X or 10.0.0.X
```

**Mac/Linux:**
```bash
ip addr show   # or
ifconfig
```

Note that IP — you will use it in the next step.

> Make sure the VM and your PC are on the **same network**.
> In VirtualBox, use "Bridged Adapter" in the VM's network settings.

---

## STEP 7 — Install the agent on the Ubuntu VM

Connect to the VM (via console or SSH) and run:

```bash
# 1. Copy the sysmon-agent folder from your PC to the VM
#    Option A: scp (from your PC)
scp -r ProyectoSymon/sysmon-agent user@VM_IP:/tmp/

#    Option B: if you have shared access or USB, copy the sysmon-agent folder

# 2. On the VM: install the agent
cd /tmp/sysmon-agent
sudo bash scripts/install.sh

# 3. Configure the agent
sudo nano /etc/sysmon/agent.env
```

In the editor, set these lines:

```env
# URL of your Laravel backend (your PC's IP + port 8000)
API_URL=http://192.168.1.X:8000

# Agent token (the one you got in STEP 5)
AGENT_TOKEN=paste-token-here

# Name you will see in the panel
AGENT_NAME=vm-test-01
```

Save with `Ctrl+O`, `Enter`, `Ctrl+X`.

```bash
# 4. Start the agent
sudo systemctl start sysmon-agent

# 5. Verify it is running
sudo systemctl status sysmon-agent
journalctl -u sysmon-agent -f
```

You should see in the logs:
```
[INFO] sysmon — Cycle #1 OK — CPU 12.4% | RAM 34.1% | Sent in 0.23s
```

---

## STEP 8 — View data in the panel

1. Open **http://localhost:5173**
2. Go to **Dashboard** — you should see the `vm-test-01` agent with status `online`
3. Click on the agent to see the detail view with charts

If the agent does not appear online after 1 minute, check the agent logs
and verify that the IP and token are correct.

---

## Adding more agents (multi-agent)

To monitor multiple VMs, create one agent per VM from the panel:

1. Open **http://localhost:5173** → **Agents** → **New agent**
2. Give it a descriptive name (e.g. `web-server`, `main-db`)
3. Copy the **token** shown at creation (only shown once)
4. On the new VM: run `sudo bash scripts/install.sh` and configure `/etc/sysmon/agent.env` with that token

Each agent is identified by its unique token. The panel shows all agents with their independent metrics and lets you configure alert rules globally or per agent.

---

## Common troubleshooting

### The agent says "Could not connect"
- Verify Docker is running: `docker compose ps`
- Your PC's IP may have changed. Update `API_URL` in `agent.env`
- VirtualBox: make sure the VM network is set to **Bridged Adapter**
- Check that port 8000 is not blocked by the Windows firewall

### The API returns 401 Unauthorized
- The token was copied incorrectly. Copy it again: `docker compose exec api php artisan tinker --execute="echo App\Models\Agent::first()->token;"`
- Regenerate the token from the panel → Agents → regenerate token button
- Restart the agent: `sudo systemctl restart sysmon-agent`

### First Docker start fails or hangs
- Check the logs: `docker compose logs api`
- Make sure you have internet access (needs to download Laravel the first time)
- Try restarting: `docker compose down && docker compose up`

### The Vue panel does not load
- Check: `docker compose logs panel`
- Wait a bit longer — `npm install` can take 1-2 minutes the first time

### Alert emails are not arriving
- Verify email is enabled in the panel: **Settings** → **Email**
- Check that the SMTP credentials are correct (username, password, host, port)
- Check: `docker compose logs api` for send errors
- Make sure the corresponding alert rule has the "Notify by email" option enabled

### Change the agent token
- `sudo nano /etc/sysmon/agent.env` and update the token in the file
- `sudo systemctl restart sysmon-agent`

### Reset everything and start fresh
```bash
docker compose down -v    # also deletes volumes (including DB)
docker compose up
```

---

## For production (when the time comes)

| Dev (now)                    | Production                                   |
|------------------------------|----------------------------------------------|
| `APP_DEBUG=true`             | `APP_DEBUG=false`                            |
| `php artisan serve`          | Nginx + PHP-FPM                              |
| `QUEUE_CONNECTION=sync`      | Redis + Horizon (emails in background)       |
| SMTP via panel (Mailtrap)    | Real SMTP via panel (Gmail, Sendgrid, Mailgun) |
| No panel authentication      | Laravel Sanctum + Vue login                  |
| Plain text tokens            | Hashed tokens in DB                          |
| HTTP                         | HTTPS with certificate                       |

---

## URL summary

| Service        | URL                                              |
|----------------|--------------------------------------------------|
| Vue Panel      | http://localhost:5173                            |
| Laravel API    | http://localhost:8000                            |
| API Dashboard  | http://localhost:8000/api/panel/dashboard        |
| MySQL          | localhost:3306 (user: sysmon / sysmon_pass)      |

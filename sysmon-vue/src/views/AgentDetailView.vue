<template>
  <div v-if="loading" class="loading">Cargando…</div>

  <div v-else-if="agent" class="detail">

    <!-- Header agent info -->
    <div class="detail-header card">
      <div class="card-body" style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
        <div>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <span :class="['status-dot', agent.status]"></span>
            <h2 style="font-size:20px">{{ agent.name }}</h2>
            <span :class="['badge', statusBadge]">{{ agent.status }}</span>
          </div>
          <div class="text-muted" style="font-size:11px">
            {{ agent.hostname }} · {{ agent.ip_address }} · {{ agent.distro }}
          </div>
          <div class="text-muted" style="font-size:11px;margin-top:2px">
            Último ping: {{ timeAgo(agent.last_seen_at) }} · Uptime: {{ uptimeStr }}
          </div>
        </div>
        <div style="margin-left:auto;display:flex;align-items:center;gap:8px">
          <span class="refresh-badge" title="Auto-refresh cada 10s">↻ {{ countdown }}s</span>
          <select class="input" style="width:120px" v-model="hours" @change="loadMetrics">
            <option value="1">Última 1h</option>
            <option value="6">Últimas 6h</option>
            <option value="24">Últimas 24h</option>
            <option value="72">Últimos 3d</option>
          </select>
          <button class="btn btn-ghost" @click="loadMetrics">↻</button>
        </div>
      </div>
    </div>

    <!-- Latest metric cards -->
    <div class="metric-cards" v-if="latest">
      <div class="m-card"><div class="m-label">CPU</div><div class="m-val cpu">{{ latest.cpu_usage_percent?.toFixed(1) }}<span>%</span></div><div class="m-sub">Load: {{ latest.cpu_load_5m?.toFixed(2) }}</div></div>
      <div class="m-card"><div class="m-label">RAM</div><div class="m-val ram">{{ latest.ram_usage_percent?.toFixed(1) }}<span>%</span></div><div class="m-sub">{{ latest.ram_used_gb?.toFixed(1) }} / {{ latest.ram_total_gb?.toFixed(0) }} GB</div></div>
      <div class="m-card"><div class="m-label">Disco</div><div class="m-val disk">{{ latest.disk_max_usage_percent?.toFixed(1) ?? '—' }}<span>%</span></div><div class="m-sub">máx partición</div></div>
      <div class="m-card"><div class="m-label">Temp</div><div class="m-val temp">{{ latest.temp_max_celsius?.toFixed(0) ?? '—' }}<span>°C</span></div><div class="m-sub">{{ latest.temp_max_sensor ?? '—' }}</div></div>
      <div class="m-card"><div class="m-label">Red RX</div><div class="m-val net">{{ latest.net_recv_mb?.toFixed(0) ?? '—' }}<span> MB</span></div><div class="m-sub">total acumulado</div></div>
    </div>

    <!-- Charts -->
    <div class="charts-grid" v-if="chartData">
      <div class="card">
        <div class="card-header"><div class="card-title">CPU %</div></div>
        <div class="card-body"><LineChart :data="chartData.cpu" :options="chartOpts('#00d4ff')" /></div>
      </div>
      <div class="card">
        <div class="card-header"><div class="card-title">RAM %</div></div>
        <div class="card-body"><LineChart :data="chartData.ram" :options="chartOpts('#a855f7')" /></div>
      </div>
      <div class="card">
        <div class="card-header"><div class="card-title">Temperatura °C</div></div>
        <div class="card-body"><LineChart :data="chartData.temp" :options="chartOpts('#ff6b35')" /></div>
      </div>
      <div class="card">
        <div class="card-header"><div class="card-title">Disco máx %</div></div>
        <div class="card-body"><LineChart :data="chartData.disk" :options="chartOpts('#00ff88')" /></div>
      </div>
    </div>

    <!-- Processes table -->
    <div class="card" v-if="processes.length">
      <div class="card-header"><div class="card-title">Procesos (top CPU)</div><span class="text-muted" style="margin-left:auto;font-size:10px">última lectura</span></div>
      <table class="table">
        <thead><tr><th>Proceso</th><th>PID</th><th>Usuario</th><th>CPU</th><th>RAM</th><th>Estado</th></tr></thead>
        <tbody>
          <tr v-for="p in processes" :key="p.pid">
            <td class="text-bright" style="font-weight:600">{{ p.name }}</td>
            <td class="text-muted">{{ p.pid }}</td>
            <td class="text-muted">{{ p.user }}</td>
            <td>
              <div style="display:flex;align-items:center;gap:6px">
                <div class="progress" style="width:80px"><div class="progress-fill" :style="{width:`${Math.min(100,p.cpu_percent)}%`,background:'var(--cpu)'}"></div></div>
                <span style="font-size:11px">{{ p.cpu_percent?.toFixed(1) }}%</span>
              </div>
            </td>
            <td style="font-size:11px">{{ p.ram_mb?.toFixed(0) }} MB</td>
            <td><span class="badge badge-success" style="font-size:9px">{{ p.status }}</span></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Alerts for this agent -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Alertas del agente</div>
        <select class="input" style="width:110px;margin-left:auto" v-model="alertFilter" @change="loadAlerts">
          <option value="">Todas</option>
          <option value="open">Abiertas</option>
          <option value="resolved">Resueltas</option>
        </select>
      </div>
      <div v-if="!alerts.length" class="empty-state" style="padding:20px;text-align:center;color:var(--text-muted);font-size:12px">Sin alertas</div>
      <table v-else class="table">
        <thead><tr><th>Sev.</th><th>Mensaje</th><th>Valor</th><th>Umbral</th><th>Hora</th><th>Estado</th><th></th></tr></thead>
        <tbody>
          <tr v-for="a in alerts" :key="a.id">
            <td><span :class="['badge', sevClass(a.severity)]">{{ a.severity }}</span></td>
            <td style="max-width:250px;font-size:11px">{{ a.message }}</td>
            <td class="text-bright" style="font-size:11px;font-weight:600">{{ a.value }}</td>
            <td class="text-muted" style="font-size:11px">{{ a.threshold }}</td>
            <td class="text-muted" style="font-size:11px">{{ timeAgo(a.fired_at) }}</td>
            <td><span :class="['badge', statusClass(a.status)]">{{ a.status }}</span></td>
            <td>
              <button v-if="a.status==='open'" class="btn btn-ghost btn-sm" @click="resolve(a.id)">Resolver</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute } from 'vue-router'
import { panelApi } from '@/services/api'
import { Line as LineChart } from 'vue-chartjs'
import { Chart, LineElement, PointElement, LinearScale, CategoryScale, Filler, Tooltip } from 'chart.js'

Chart.register(LineElement, PointElement, LinearScale, CategoryScale, Filler, Tooltip)

const route  = useRoute()
const id     = route.params.id

const agent    = ref(null)
const latest   = ref(null)
const loading  = ref(true)
const hours    = ref(24)
const chartData = ref(null)
const alerts   = ref([])
const alertFilter = ref('open')

// Auto-refresh state
const countdown  = ref(10)
let pollTimer    = null
let chartTimer   = null
let tickTimer    = null

const statusBadge = computed(() => ({
  online: 'badge-success', warning: 'badge-warn', critical: 'badge-danger', offline: 'badge-muted'
})[agent.value?.status] ?? 'badge-muted')

const processes = computed(() => latest.value?.processes ?? [])

const uptimeStr = computed(() => {
  const s = latest.value?.uptime_secs
  if (!s) return '—'
  const d = Math.floor(s/86400), h = Math.floor((s%86400)/3600)
  return `${d}d ${h}h`
})

function timeAgo(iso) {
  if (!iso) return '—'
  const diff = Math.floor((Date.now() - new Date(iso)) / 1000)
  if (diff < 60)   return `hace ${diff}s`
  if (diff < 3600) return `hace ${Math.floor(diff/60)}m`
  return `hace ${Math.floor(diff/3600)}h`
}

function sevClass(s)    { return {critical:'badge-danger',warning:'badge-warn',info:'badge-info'}[s]??'badge-muted' }
function statusClass(s) { return {open:'badge-danger',acknowledged:'badge-warn',resolved:'badge-success'}[s]??'badge-muted' }

function chartOpts(color) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
    scales: {
      x: { ticks: { color: '#4d6174', font: { family: 'JetBrains Mono', size: 9 }, maxTicksLimit: 8 }, grid: { color: '#1e2d3d' } },
      y: { ticks: { color: '#4d6174', font: { family: 'JetBrains Mono', size: 9 } }, grid: { color: '#1e2d3d' } }
    },
    elements: { point: { radius: 0 }, line: { tension: 0.3 } }
  }
}

function buildCharts(data) {
  const labels = data.map(d => new Date(d.collected_at).toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }))
  const dataset = (key, color, label) => ({
    labels,
    datasets: [{
      label,
      data: data.map(d => d[key]),
      borderColor: color,
      backgroundColor: color + '22',
      fill: true,
      borderWidth: 2,
    }]
  })
  return {
    cpu:  dataset('cpu_usage_percent', '#00d4ff', 'CPU %'),
    ram:  dataset('ram_usage_percent', '#a855f7', 'RAM %'),
    temp: dataset('temp_max_celsius',  '#ff6b35', 'Temp °C'),
    disk: dataset('disk_max_usage_percent', '#00ff88', 'Disco %'),
  }
}

async function loadMetrics() {
  const [mRes, lRes] = await Promise.all([
    panelApi.metrics(id, hours.value),
    panelApi.latestMetrics(id),
  ])
  chartData.value = buildCharts(mRes.data.data)
  latest.value    = lRes.data.data
}

async function loadAlerts() {
  const { data } = await panelApi.agentAlerts(id, { status: alertFilter.value })
  alerts.value = data.data
}

async function resolve(alertId) {
  await panelApi.resolveAlert(alertId)
  loadAlerts()
}

// Lightweight poll: only latest metrics + agent status (no chart reload)
async function refreshLatest() {
  try {
    const [aRes, lRes] = await Promise.all([
      panelApi.agent(id),
      panelApi.latestMetrics(id),
    ])
    agent.value  = aRes.data.agent
    latest.value = lRes.data.data
  } catch { /* silently ignore poll errors */ }
}

function startPolling() {
  countdown.value = 10

  // Countdown tick every second
  tickTimer = setInterval(() => {
    countdown.value = countdown.value <= 1 ? 10 : countdown.value - 1
  }, 1000)

  // Lightweight refresh every 10s
  pollTimer = setInterval(refreshLatest, 10_000)

  // Full chart refresh every 60s
  chartTimer = setInterval(loadMetrics, 60_000)
}

function stopPolling() {
  clearInterval(pollTimer)
  clearInterval(chartTimer)
  clearInterval(tickTimer)
}

onMounted(async () => {
  const { data } = await panelApi.agent(id)
  agent.value = data.agent
  loading.value = false
  await Promise.all([loadMetrics(), loadAlerts()])
  startPolling()
})

onUnmounted(stopPolling)
</script>

<style scoped>
.detail { display: flex; flex-direction: column; gap: 18px; }
.loading { color: var(--text-muted); padding: 40px; text-align: center; }

.metric-cards { display: grid; grid-template-columns: repeat(5,1fr); gap: 12px; }
.m-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 14px 16px; }
.m-label { font-size: 10px; color: var(--text-muted); letter-spacing: 1.5px; text-transform: uppercase; }
.m-val   { font-family: var(--font-display); font-size: 26px; font-weight: 800; margin: 4px 0 2px; line-height: 1; }
.m-val span { font-size: 14px; }
.m-val.cpu  { color: var(--cpu); }
.m-val.ram  { color: var(--ram); }
.m-val.disk { color: var(--disk); }
.m-val.temp { color: var(--temp); }
.m-val.net  { color: var(--net); }
.m-sub { font-size: 10px; color: var(--text-muted); }

.charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.charts-grid .card-body { height: 160px; }

.refresh-badge {
  font-size: 10px;
  font-family: var(--font-mono);
  color: var(--text-muted);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 3px 8px;
  white-space: nowrap;
}
</style>

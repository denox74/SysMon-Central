<template>
  <div class="dashboard">

    <!-- Startup phase: waiting for API to boot (up to 3 min) -->
    <template v-if="!store.data && !store.timedOut">
      <div class="connecting-wrap">
        <div class="connecting-label">{{ store.loading ? 'Conectando con la API…' : 'Esperando arranque de la API…' }}</div>
        <div class="connecting-bar"><div class="connecting-fill"></div></div>
        <div class="startup-progress-track">
          <div class="startup-progress-fill" :style="{ width: progressPct + '%' }"></div>
        </div>
        <div class="connecting-hint">{{ store.loading ? 'Estableciendo conexión con el servidor' : 'Reintentando conexión automáticamente…' }}</div>
        <div class="startup-sub">Puede tardar hasta 3 minutos al iniciar por primera vez · {{ elapsedLabel }}</div>
      </div>
    </template>

    <!-- Error after timeout -->
    <div v-else-if="store.error && !store.data" class="api-error">
      <span>⚠</span>
      <div>
        <strong>Sin conexión con la API</strong>
        <p>{{ store.error }}</p>
      </div>
      <button class="btn btn-ghost btn-sm" @click="store.fetch()">Reintentar</button>
    </div>

    <template v-else>
      <!-- Totals row -->
      <div class="totals-row">
        <div class="total-card" @click="$router.push('/agents')">
          <div class="total-label">Online</div>
          <div class="total-value accent2">{{ totals.agents_online ?? 0 }}</div>
        </div>
        <div class="total-card" @click="$router.push('/agents')">
          <div class="total-label">Warning</div>
          <div class="total-value warn">{{ totals.agents_warning ?? 0 }}</div>
        </div>
        <div class="total-card" @click="$router.push('/agents')">
          <div class="total-label">Crítico</div>
          <div class="total-value danger">{{ totals.agents_critical ?? 0 }}</div>
        </div>
        <div class="total-card" @click="$router.push('/agents')">
          <div class="total-label">Offline</div>
          <div class="total-value muted">{{ totals.agents_offline ?? 0 }}</div>
        </div>
        <div class="total-card alert-card" @click="$router.push('/alerts')">
          <div class="total-label">Alertas abiertas</div>
          <div class="total-value danger">{{ totals.open_alerts ?? 0 }}</div>
        </div>
      </div>

      <!-- Agents grid -->
      <div class="section-title">Agentes</div>
      <div class="agents-grid">
        <AgentCard
          v-for="agent in store.agents"
          :key="agent.id"
          :agent="agent"
          @click="$router.push(`/agents/${agent.id}`)"
        />
      </div>

      <!-- Recent alerts -->
      <div class="section-title" style="margin-top:28px">
        Alertas recientes
        <RouterLink to="/alerts" class="see-all">Ver todas →</RouterLink>
      </div>
      <div class="card">
        <div v-if="!store.openAlerts.length" class="empty-state">
          <span>✓</span> Sin alertas abiertas
        </div>
        <table v-else class="table">
          <thead>
            <tr>
              <th>Severidad</th>
              <th>Agente</th>
              <th>Mensaje</th>
              <th>Métrica</th>
              <th>Hora</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="alert in store.openAlerts" :key="alert.id">
              <td><span :class="['badge', severityClass(alert.severity)]">{{ alert.severity }}</span></td>
              <td class="text-bright">{{ alert.agent?.name ?? '—' }}</td>
              <td style="max-width:300px">{{ alert.message }}</td>
              <td class="text-accent text-mono" style="font-size:11px">{{ alert.metric }}</td>
              <td class="text-muted" style="font-size:11px">{{ timeAgo(alert.fired_at) }}</td>
              <td>
                <button class="btn btn-ghost btn-sm" @click.stop="resolveAlert(alert.id)">Resolver</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

    </template>

    <!-- Reconnecting toast (polling error but data exists) -->
    <div v-if="store.error && store.data" class="reconnecting-toast">
      <span class="reconnecting-dot"></span>
      Reconectando… <button class="btn btn-ghost btn-sm" style="margin-left:8px" @click="store.fetch()">Reintentar</button>
    </div>

    <!-- Last update -->
    <div v-if="store.lastUpdate && !store.error" class="last-update">
      Última actualización: {{ store.lastUpdate.toLocaleTimeString('es-ES') }}
    </div>
  </div>
</template>

<!--
  DashboardView.vue — Vista principal / Main dashboard view
  Tres estados posibles / Three possible states:
    1. loading && !data  → barra de "Conectando…" (primera carga)
                           "Connecting…" bar (first load)
    2. error && !data    → mensaje de error con botón Reintentar
                           error message with Retry button
    3. data presente     → tarjetas de totales + grid de agentes + alertas recientes
                           totals cards + agents grid + recent alerts
  El toast "Reconectando" aparece si el polling falla pero ya hay datos.
  The "Reconnecting" toast appears if polling fails but data already exists.
-->
<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useDashboardStore } from '@/stores'
import { panelApi } from '@/services/api'
import AgentCard from '@/components/dashboard/AgentCard.vue'

const store  = useDashboardStore()
const totals = computed(() => store.totals)

// Progreso de arranque (0–180 segundos → 0–100%)
const STARTUP_MAX_S = 180
const elapsed       = ref(0)
let   elapsedTimer  = null

const progressPct  = computed(() => Math.min(100, (elapsed.value / STARTUP_MAX_S) * 100))
const elapsedLabel = computed(() => {
  const s = elapsed.value
  return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${s % 60}s`
})

onMounted(() => {
  elapsedTimer = setInterval(() => {
    elapsed.value = Math.floor((Date.now() - store.startupStartedAt) / 1000)
  }, 1000)
})

onUnmounted(() => clearInterval(elapsedTimer))

/**
 * Devuelve la clase CSS de badge según la severidad.
 * Returns the badge CSS class based on severity.
 */
function severityClass(s) {
  return { critical: 'badge-danger', warning: 'badge-warn', info: 'badge-info' }[s] ?? 'badge-muted'
}

/**
 * Convierte una fecha ISO a texto relativo ("hace 5m").
 * Converts an ISO date to relative text ("hace 5m").
 */
function timeAgo(iso) {
  if (!iso) return '—'
  const diff = Math.floor((Date.now() - new Date(iso)) / 1000)
  if (diff < 60)  return `hace ${diff}s`
  if (diff < 3600) return `hace ${Math.floor(diff/60)}m`
  return `hace ${Math.floor(diff/3600)}h`
}

/**
 * Resuelve una alerta desde la tabla de alertas recientes y recarga el store.
 * Resolves an alert from the recent alerts table and reloads the store.
 */
async function resolveAlert(id) {
  await panelApi.resolveAlert(id)
  store.fetch()
}
</script>

<style scoped>
.dashboard { display: flex; flex-direction: column; gap: 18px; }

.api-error {
  display: flex;
  align-items: center;
  gap: 14px;
  background: rgba(255,61,90,0.08);
  border: 1px solid rgba(255,61,90,0.3);
  border-radius: var(--radius-lg);
  padding: 16px 20px;
  font-size: 12px;
}
.api-error > span { font-size: 20px; }
.api-error strong { color: var(--danger); }
.api-error p { color: var(--text-muted); margin-top: 2px; }
.api-error .btn { margin-left: auto; flex-shrink: 0; }

/* Connecting state */
.connecting-wrap {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 14px;
  padding: 60px 20px;
}
.connecting-label {
  font-family: var(--font-display);
  font-size: 15px;
  font-weight: 600;
  color: var(--text-bright);
  letter-spacing: 0.5px;
}
.connecting-bar {
  width: 320px;
  height: 4px;
  background: var(--surface2);
  border-radius: 2px;
  overflow: hidden;
}
.connecting-fill {
  height: 100%;
  width: 40%;
  background: linear-gradient(90deg, transparent, var(--accent), transparent);
  border-radius: 2px;
  animation: scan 1.4s ease-in-out infinite;
}
@keyframes scan {
  0%   { transform: translateX(-100%) scaleX(1); }
  50%  { transform: translateX(150%) scaleX(1.5); }
  100% { transform: translateX(400%) scaleX(1); }
}
.connecting-hint {
  font-size: 11px;
  color: var(--text-muted);
  letter-spacing: 0.5px;
}
.startup-progress-track {
  width: 320px;
  height: 3px;
  background: var(--surface2);
  border-radius: 2px;
  overflow: hidden;
  margin-top: -6px;
}
.startup-progress-fill {
  height: 100%;
  background: var(--accent2);
  border-radius: 2px;
  transition: width 1s linear;
}
.startup-sub {
  font-size: 10px;
  color: var(--text-muted);
  opacity: 0.6;
  letter-spacing: 0.3px;
}

/* Reconnecting toast */
.reconnecting-toast {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 11px;
  color: var(--text-muted);
  padding: 6px 12px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  width: fit-content;
}
.reconnecting-dot {
  width: 6px; height: 6px;
  border-radius: 50%;
  background: var(--warn);
  animation: pulse-dot 1.2s infinite;
}

.totals-row {
  display: flex;
  gap: 12px;
}
.total-card {
  flex: 1;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  padding: 14px 18px;
  cursor: pointer;
  transition: border-color var(--transition);
}
.total-card:hover { border-color: var(--border2); }
.total-label { font-size: 10px; color: var(--text-muted); letter-spacing: 1px; text-transform: uppercase; }
.total-value { font-family: var(--font-display); font-size: 30px; font-weight: 800; margin-top: 4px; line-height: 1; }
.total-value.accent2 { color: var(--accent2); }
.total-value.warn    { color: var(--warn); }
.total-value.danger  { color: var(--danger); }
.total-value.muted   { color: var(--text-muted); }
.alert-card { border-left: 2px solid var(--danger); }

.section-title {
  font-family: var(--font-display);
  font-size: 12px;
  font-weight: 600;
  color: var(--text-muted);
  letter-spacing: 1px;
  text-transform: uppercase;
  display: flex;
  align-items: center;
  gap: 10px;
}
.see-all { color: var(--accent); font-size: 11px; text-decoration: none; margin-left: auto; }
.see-all:hover { text-decoration: underline; }

.agents-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }

.empty-state { padding: 24px; text-align: center; color: var(--accent2); font-size: 12px; }

.last-update { font-size: 10px; color: var(--text-muted); text-align: right; margin-top: 4px; }
</style>

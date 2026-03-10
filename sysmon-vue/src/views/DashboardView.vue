<template>
  <div class="dashboard">

    <!-- Error state -->
    <div v-if="store.error" class="api-error">
      <span>⚠</span>
      <div>
        <strong>Sin conexión con la API</strong>
        <p>{{ store.error }}</p>
      </div>
      <button class="btn btn-ghost btn-sm" @click="store.fetch()">Reintentar</button>
    </div>

    <!-- Loading skeleton -->
    <template v-else-if="store.loading && !store.data">
      <div class="skeleton-row">
        <div v-for="i in 4" :key="i" class="skeleton-card"></div>
      </div>
    </template>

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

    <!-- Last update -->
    <div v-if="store.lastUpdate" class="last-update">
      Última actualización: {{ store.lastUpdate.toLocaleTimeString('es-ES') }}
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useDashboardStore } from '@/stores'
import { panelApi } from '@/services/api'
import AgentCard from '@/components/dashboard/AgentCard.vue'

const store  = useDashboardStore()
const totals = computed(() => store.totals)

function severityClass(s) {
  return { critical: 'badge-danger', warning: 'badge-warn', info: 'badge-info' }[s] ?? 'badge-muted'
}

function timeAgo(iso) {
  if (!iso) return '—'
  const diff = Math.floor((Date.now() - new Date(iso)) / 1000)
  if (diff < 60)  return `hace ${diff}s`
  if (diff < 3600) return `hace ${Math.floor(diff/60)}m`
  return `hace ${Math.floor(diff/3600)}h`
}

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

.skeleton-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; }
.skeleton-card {
  height: 120px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  animation: shimmer 1.5s infinite;
}
@keyframes shimmer { 0%,100%{opacity:0.5} 50%{opacity:1} }

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

<template>
  <div class="alerts-view">
    <div class="view-header">
      <h2>Alertas</h2>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <!-- Búsqueda por nombre de agente -->
        <input
          class="input"
          style="width:150px"
          v-model="searchAgent"
          placeholder="Buscar agente…"
          @input="reset()"
        />
        <select class="input" style="width:120px" v-model="store.filters.severity" @change="reset()">
          <option value="">Severidad</option>
          <option value="critical">Crítico</option>
          <option value="warning">Warning</option>
          <option value="info">Info</option>
        </select>
        <select class="input" style="width:110px" v-model="store.filters.status" @change="reset()">
          <option value="">Estado</option>
          <option value="open">Abierta</option>
          <option value="acknowledged">Vista</option>
          <option value="resolved">Resuelta</option>
        </select>
        <button
          :class="['btn', store.filters.archived ? 'btn-primary' : 'btn-ghost']"
          @click="toggleArchived()"
          title="Ver alertas archivadas"
        >🗄 Archivo</button>
        <button class="btn btn-ghost" @click="reset()">↻</button>
        <button
          v-if="!store.filters.archived && hasResolved"
          class="btn btn-ghost"
          style="color:var(--text-muted);font-size:11px"
          @click="archiveAll()"
        >Archivar resueltas</button>
        <!-- Eliminar archivadas permanentemente (solo en vista archivo) -->
        <button
          v-if="store.filters.archived"
          class="btn btn-danger btn-sm"
          style="font-size:11px"
          @click="deleteArchived()"
        >✕ Eliminar todas permanentemente</button>
      </div>
    </div>

    <div v-if="store.loading" style="padding:32px;text-align:center;color:var(--text-muted)">Cargando…</div>
    <div v-else-if="!grouped.length && !store.filters.agent_id" class="card" style="padding:32px;text-align:center;color:var(--text-muted)">
      {{ store.filters.archived ? 'No hay alertas archivadas' : 'Sin alertas con estos filtros' }}
    </div>
    <div v-else-if="store.filters.agent_id && !store.items.length" class="card" style="padding:32px;text-align:center;color:var(--text-muted)">
      Sin alertas con estos filtros
    </div>

    <!-- Vista agrupada por agente (sin filtro de agente específico) -->
    <template v-if="!store.filters.agent_id && !store.loading">
      <div v-for="group in grouped" :key="group.agentId" class="agent-group">
        <div class="group-header">
          <!-- Botón colapsar/expandir -->
          <button class="collapse-btn" @click="toggleCollapse(group.agentId)" :title="collapsedAgents[group.agentId] ? 'Expandir' : 'Colapsar'">
            {{ collapsedAgents[group.agentId] ? '▶' : '▼' }}
          </button>
          <span :class="['status-dot', group.agentStatus]"></span>
          <span class="group-name">{{ group.agentName }}</span>
          <span class="badge badge-danger" v-if="group.open > 0">{{ group.open }} abierta{{ group.open > 1 ? 's' : '' }}</span>
          <button
            v-if="!store.filters.archived && group.resolved > 0"
            class="btn btn-ghost btn-sm"
            style="font-size:10px;margin-left:auto"
            @click="archiveAll(group.agentId)"
          >Archivar resueltas ({{ group.resolved }})</button>
        </div>
        <div class="card" v-show="!collapsedAgents[group.agentId]">
          <table class="table">
            <thead>
              <tr><th></th><th>Sev.</th><th>Mensaje</th><th>Valor</th><th>Umbral</th><th>Hora</th><th>Estado</th><th>Acciones</th></tr>
            </thead>
            <tbody>
              <template v-for="a in group.alerts" :key="a.id">
                <tr>
                  <td style="width:28px;text-align:center">
                    <button
                      v-if="a.occurrences_count > 0"
                      class="btn btn-ghost btn-sm"
                      style="padding:2px 5px;font-size:10px"
                      @click="toggleExpand(a.id)"
                      :title="expandedAlerts[a.id] ? 'Ocultar ocurrencias' : 'Ver ocurrencias'"
                    >{{ expandedAlerts[a.id] ? '▼' : '▶' }}</button>
                  </td>
                  <td><span :class="['badge', sevClass(a.severity)]">{{ a.severity }}</span></td>
                  <td style="font-size:11px;max-width:260px">
                    {{ a.message }}
                    <span v-if="a.occurrences_count > 0" class="occurrences-chip">{{ a.occurrences_count }} ocurrencia{{ a.occurrences_count > 1 ? 's' : '' }}</span>
                  </td>
                  <td class="text-bright" style="font-size:12px;font-weight:600">{{ a.value }}</td>
                  <td class="text-muted" style="font-size:11px">{{ a.threshold }}</td>
                  <td class="text-muted" style="font-size:11px">{{ fmt(a.fired_at) }}</td>
                  <td><span :class="['badge', stClass(a.status)]">{{ a.status }}</span></td>
                  <td>
                    <div style="display:flex;gap:4px">
                      <button v-if="a.status==='open'" class="btn btn-ghost btn-sm" @click="store.acknowledge(a.id)">Visto</button>
                      <button v-if="a.status!=='resolved'" class="btn btn-ghost btn-sm" @click="store.resolve(a.id)">Resolver</button>
                      <button v-if="a.status==='resolved'" class="btn btn-ghost btn-sm" style="color:var(--text-muted)" @click="store.archive(a.id)" title="Archivar">🗄</button>
                    </div>
                  </td>
                </tr>
                <!-- Fila de ocurrencias expandibles -->
                <tr v-if="expandedAlerts[a.id] && a.occurrences_count > 0" class="occurrences-row">
                  <td colspan="8">
                    <div class="occurrences-inner">
                      <table class="occurrences-table">
                        <thead>
                          <tr><th>Hora</th><th>Valor</th><th>Mensaje</th></tr>
                        </thead>
                        <tbody>
                          <tr v-for="(occ, i) in a.occurrences" :key="i">
                            <td>{{ fmt(occ.fired_at) }}</td>
                            <td>{{ occ.value }}</td>
                            <td>{{ occ.message }}</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- Vista plana con agente filtrado -->
    <div v-else-if="store.filters.agent_id && !store.loading" class="card">
      <table class="table">
        <thead>
          <tr><th></th><th>Sev.</th><th>Mensaje</th><th>Valor</th><th>Umbral</th><th>Hora</th><th>Estado</th><th>Acciones</th></tr>
        </thead>
        <tbody>
          <template v-for="a in store.items" :key="a.id">
            <tr>
              <td style="width:28px;text-align:center">
                <button
                  v-if="a.occurrences_count > 0"
                  class="btn btn-ghost btn-sm"
                  style="padding:2px 5px;font-size:10px"
                  @click="toggleExpand(a.id)"
                >{{ expandedAlerts[a.id] ? '▼' : '▶' }}</button>
              </td>
              <td><span :class="['badge', sevClass(a.severity)]">{{ a.severity }}</span></td>
              <td style="font-size:11px;max-width:260px">
                {{ a.message }}
                <span v-if="a.occurrences_count > 0" class="occurrences-chip">{{ a.occurrences_count }} ocurrencia{{ a.occurrences_count > 1 ? 's' : '' }}</span>
              </td>
              <td class="text-bright" style="font-size:12px;font-weight:600">{{ a.value }}</td>
              <td class="text-muted" style="font-size:11px">{{ a.threshold }}</td>
              <td class="text-muted" style="font-size:11px">{{ fmt(a.fired_at) }}</td>
              <td><span :class="['badge', stClass(a.status)]">{{ a.status }}</span></td>
              <td>
                <div style="display:flex;gap:4px">
                  <button v-if="a.status==='open'" class="btn btn-ghost btn-sm" @click="store.acknowledge(a.id)">Visto</button>
                  <button v-if="a.status!=='resolved'" class="btn btn-ghost btn-sm" @click="store.resolve(a.id)">Resolver</button>
                  <button v-if="a.status==='resolved'" class="btn btn-ghost btn-sm" style="color:var(--text-muted)" @click="store.archive(a.id)" title="Archivar">🗄</button>
                </div>
              </td>
            </tr>
            <tr v-if="expandedAlerts[a.id] && a.occurrences_count > 0" class="occurrences-row">
              <td colspan="8">
                <div class="occurrences-inner">
                  <table class="occurrences-table">
                    <thead>
                      <tr><th>Hora</th><th>Valor</th><th>Mensaje</th></tr>
                    </thead>
                    <tbody>
                      <tr v-for="(occ, i) in a.occurrences" :key="i">
                        <td>{{ fmt(occ.fired_at) }}</td>
                        <td>{{ occ.value }}</td>
                        <td>{{ occ.message }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <div v-if="store.pagination && store.pagination.last_page > 1" class="pagination">
      <button class="btn btn-ghost btn-sm" :disabled="page===1" @click="changePage(page-1)">← Anterior</button>
      <span class="text-muted" style="font-size:11px">Página {{ page }} de {{ store.pagination.last_page }} · {{ store.pagination.total }} alertas</span>
      <button class="btn btn-ghost btn-sm" :disabled="page===store.pagination.last_page" @click="changePage(page+1)">Siguiente →</button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAlertsStore, useDashboardStore } from '@/stores'
import { panelApi } from '@/services/api'

const store     = useAlertsStore()
const dashStore = useDashboardStore()
const page      = ref(1)

// Búsqueda por nombre de agente
const searchAgent = ref('')

// Estado de grupos colapsados: { [agentId]: true/false }
const collapsedAgents = ref({})

// Estado de ocurrencias expandidas: { [alertId]: true/false }
const expandedAlerts = ref({})

const sevClass = s => ({ critical: 'badge-danger', warning: 'badge-warn', info: 'badge-info' })[s] ?? 'badge-muted'
const stClass  = s => ({ open: 'badge-danger', acknowledged: 'badge-warn', resolved: 'badge-success' })[s] ?? 'badge-muted'

function fmt(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('es-ES', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

const hasResolved = computed(() => store.items.some(a => a.status === 'resolved'))

const grouped = computed(() => {
  const map = {}
  const search = searchAgent.value.toLowerCase().trim()
  for (const alert of store.items) {
    const id   = alert.agent?.id ?? 0
    const name = alert.agent?.name ?? 'Sin agente'
    // Filtrar por nombre de agente si hay búsqueda
    if (search && !name.toLowerCase().includes(search)) continue
    if (!map[id]) {
      map[id] = {
        agentId:     id,
        agentName:   name,
        agentStatus: dashStore.agents.find(a => a.id === id)?.status ?? 'offline',
        alerts:      [],
        open:        0,
        resolved:    0,
      }
    }
    map[id].alerts.push(alert)
    if (alert.status === 'open' || alert.status === 'acknowledged') map[id].open++
    if (alert.status === 'resolved') map[id].resolved++
  }
  return Object.values(map).sort((a, b) => b.open - a.open)
})

function toggleCollapse(agentId) {
  collapsedAgents.value = { ...collapsedAgents.value, [agentId]: !collapsedAgents.value[agentId] }
}

function toggleExpand(alertId) {
  expandedAlerts.value = { ...expandedAlerts.value, [alertId]: !expandedAlerts.value[alertId] }
}

function reset() { page.value = 1; store.fetch(1) }
function changePage(p) { page.value = p; store.fetch(p) }

function toggleArchived() {
  store.filters.archived = store.filters.archived ? '' : '1'
  store.filters.status   = ''
  reset()
}

async function archiveAll(agentId = null) {
  await store.archiveAllResolved(agentId)
  store.fetch(page.value)
}

async function deleteArchived() {
  if (!confirm('¿Eliminar PERMANENTEMENTE todas las alertas archivadas? Esta acción no se puede deshacer.')) return
  await panelApi.deleteArchivedAlerts()
  store.fetch(page.value)
}

onMounted(() => store.fetch())
</script>

<style scoped>
.alerts-view { display: flex; flex-direction: column; gap: 18px; }
.view-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
.view-header h2 { font-size: 18px; }
.pagination { display: flex; align-items: center; justify-content: center; gap: 16px; }

.agent-group { display: flex; flex-direction: column; gap: 8px; }
.group-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 2px;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-bright);
}
.group-name { flex: 1; }

.collapse-btn {
  background: none;
  border: none;
  color: var(--text-muted);
  cursor: pointer;
  font-size: 11px;
  padding: 2px 4px;
  border-radius: 3px;
  line-height: 1;
  transition: color var(--transition);
}
.collapse-btn:hover { color: var(--text-bright); }

.occurrences-chip {
  display: inline-block;
  margin-left: 6px;
  padding: 1px 6px;
  border-radius: 10px;
  background: rgba(0,212,255,0.12);
  border: 1px solid rgba(0,212,255,0.2);
  color: var(--accent);
  font-size: 10px;
  font-weight: 600;
  vertical-align: middle;
}

.occurrences-row td { padding: 0 !important; background: rgba(0,0,0,0.15); }
.occurrences-inner {
  padding: 8px 16px 8px 44px;
  border-top: 1px solid rgba(0,212,255,0.08);
}

.occurrences-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 11px;
}
.occurrences-table th {
  color: var(--text-muted);
  text-align: left;
  padding: 4px 8px;
  font-weight: 600;
  border-bottom: 1px solid var(--border);
}
.occurrences-table td {
  padding: 4px 8px;
  color: var(--text);
  border-bottom: 1px solid rgba(255,255,255,0.04);
}
</style>

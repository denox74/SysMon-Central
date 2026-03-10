<template>
  <div class="alerts-view">
    <div class="view-header">
      <h2>Alertas</h2>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <select class="input" style="width:150px" v-model="store.filters.agent_id" @change="reset()">
          <option value="">Todos los agentes</option>
          <option v-for="a in dashStore.agents" :key="a.id" :value="a.id">{{ a.name }}</option>
        </select>
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
        >🗄 {{ store.filters.archived ? 'Archivo' : 'Archivo' }}</button>
        <button class="btn btn-ghost" @click="reset()">↻</button>
        <button
          v-if="!store.filters.archived && hasResolved"
          class="btn btn-ghost"
          style="color:var(--text-muted);font-size:11px"
          @click="archiveAll()"
        >Archivar resueltas</button>
      </div>
    </div>

    <div v-if="store.loading" style="padding:32px;text-align:center;color:var(--text-muted)">Cargando…</div>
    <div v-else-if="!store.items.length" class="card" style="padding:32px;text-align:center;color:var(--text-muted)">
      {{ store.filters.archived ? 'No hay alertas archivadas' : 'Sin alertas con estos filtros' }}
    </div>

    <!-- Vista agrupada por agente (sin filtro de agente específico) -->
    <template v-else-if="!store.filters.agent_id">
      <div v-for="group in grouped" :key="group.agentId" class="agent-group">
        <div class="group-header">
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
        <div class="card">
          <table class="table">
            <thead>
              <tr><th>Sev.</th><th>Mensaje</th><th>Valor</th><th>Umbral</th><th>Hora</th><th>Estado</th><th>Acciones</th></tr>
            </thead>
            <tbody>
              <tr v-for="a in group.alerts" :key="a.id">
                <td><span :class="['badge', sevClass(a.severity)]">{{ a.severity }}</span></td>
                <td style="font-size:11px;max-width:280px">{{ a.message }}</td>
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
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- Vista plana con agente filtrado -->
    <div v-else class="card">
      <table class="table">
        <thead>
          <tr><th>Sev.</th><th>Mensaje</th><th>Valor</th><th>Umbral</th><th>Hora</th><th>Estado</th><th>Acciones</th></tr>
        </thead>
        <tbody>
          <tr v-for="a in store.items" :key="a.id">
            <td><span :class="['badge', sevClass(a.severity)]">{{ a.severity }}</span></td>
            <td style="font-size:11px;max-width:280px">{{ a.message }}</td>
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

const store     = useAlertsStore()
const dashStore = useDashboardStore()
const page      = ref(1)

const sevClass = s => ({ critical: 'badge-danger', warning: 'badge-warn', info: 'badge-info' })[s] ?? 'badge-muted'
const stClass  = s => ({ open: 'badge-danger', acknowledged: 'badge-warn', resolved: 'badge-success' })[s] ?? 'badge-muted'

function fmt(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('es-ES', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

const hasResolved = computed(() => store.items.some(a => a.status === 'resolved'))

// Agrupa alertas por agente
const grouped = computed(() => {
  const map = {}
  for (const alert of store.items) {
    const id = alert.agent?.id ?? 0
    if (!map[id]) {
      map[id] = {
        agentId:     id,
        agentName:   alert.agent?.name ?? 'Sin agente',
        agentStatus: dashStore.agents.find(a => a.id === id)?.status ?? 'offline',
        alerts:      [],
        open:        0,
        resolved:    0,
      }
    }
    map[id].alerts.push(alert)
    if (alert.status === 'open') map[id].open++
    if (alert.status === 'resolved') map[id].resolved++
  }
  return Object.values(map).sort((a, b) => b.open - a.open)
})

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
</style>

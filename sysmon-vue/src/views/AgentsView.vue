<template>
  <div class="agents-view">

    <div class="view-header">
      <h2>Agentes</h2>
      <button class="btn btn-primary" @click="showCreate = true">+ Nuevo agente</button>
    </div>

    <div class="filters-bar">
      <input class="input" v-model="searchName" placeholder="Filtrar por nombre…"   style="width:200px" />
      <input class="input" v-model="searchTag"  placeholder="Filtrar por etiqueta…" style="width:200px" />
      <div class="filter-num">
        <span class="filter-label">Temp ≥</span>
        <input class="input input-sm" v-model.number="filterTemp" type="number" min="0" placeholder="°C" style="width:72px" />
      </div>
      <div class="filter-num">
        <span class="filter-label">Disco ≥</span>
        <input class="input input-sm" v-model.number="filterDisk" type="number" min="0" max="100" placeholder="%" style="width:72px" />
      </div>
      <div class="filter-num">
        <span class="filter-label">RAM ≥</span>
        <input class="input input-sm" v-model.number="filterRam" type="number" min="0" max="100" placeholder="%" style="width:72px" />
      </div>
      <button v-if="hasFilters" class="btn btn-ghost btn-sm" @click="clearFilters">✕ Limpiar</button>
    </div>

    <div class="card">
      <table class="table">
        <thead>
          <tr>
            <th>Estado</th><th>Nombre</th><th>Etiqueta</th><th>Host / IP</th>
            <th>CPU</th><th>RAM</th><th>Temp</th><th>Disco</th><th>Último ping</th><th>Alertas</th><th></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="a in filteredAgents"
            :key="a.id"
            class="agent-row"
            @click="$router.push(`/agents/${a.id}`)"
          >
            <td>
              <div class="status-cell">
                <span :class="['status-dot', a.status]"></span>
                <span :class="['status-text', a.status]">{{ a.status }}</span>
              </div>
            </td>
            <td>
              <span class="text-bright" style="font-weight:600">{{ a.name }}</span>
            </td>
            <td>
              <span v-if="a.notes" class="tag-label">{{ a.notes }}</span>
              <span v-else class="text-muted">—</span>
            </td>
            <td class="text-muted" style="font-size:11px">{{ a.hostname || '—' }}<br>{{ a.ip_address || '—' }}</td>
            <td>
              <span v-if="a.metrics" :style="{color: cpuColor(a.metrics.cpu_percent)}">
                {{ a.metrics.cpu_percent?.toFixed(1) }}%
              </span>
              <span v-else class="text-muted">—</span>
            </td>
            <td>
              <span v-if="a.metrics" :style="{color: ramColor(a.metrics.ram_percent)}">
                {{ a.metrics.ram_percent?.toFixed(1) }}%
              </span>
              <span v-else class="text-muted">—</span>
            </td>
            <td>
              <span v-if="a.metrics?.temp_max" :style="{color: tempColor(a.metrics.temp_max)}">
                {{ a.metrics.temp_max.toFixed(0) }}°C
              </span>
              <span v-else class="text-muted">—</span>
            </td>
            <td>
              <span v-if="a.metrics" :style="{color: diskColor(a.metrics.disk_max)}">
                {{ a.metrics.disk_max?.toFixed(1) }}%
              </span>
              <span v-else class="text-muted">—</span>
            </td>
            <td class="text-muted" style="font-size:11px">{{ timeAgo(a.last_seen_at) }}</td>
            <td>
              <span v-if="a.open_alerts > 0" class="badge badge-danger">{{ a.open_alerts }}</span>
              <span v-else class="badge badge-success">OK</span>
            </td>
            <td @click.stop>
              <div style="display:flex;gap:4px">
                <button class="btn btn-ghost btn-sm" @click="viewToken(a)" title="Ver token">🔑</button>
                <button class="btn btn-ghost btn-sm" @click="openRename(a)" title="Renombrar">✏</button>
                <button class="btn btn-danger btn-sm" @click="deleteAgent(a.id)" title="Eliminar">✕</button>
              </div>
            </td>
          </tr>
          <tr v-if="!agents.length">
            <td colspan="11" class="empty-state">Sin agentes. Crea uno para empezar.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create modal -->
    <Teleport to="body">
      <div v-if="showCreate" class="modal-backdrop" @click.self="showCreate = false">
        <div class="modal">
          <div class="modal-header">
            <h3>Nuevo agente</h3>
            <button class="btn btn-ghost btn-sm" @click="showCreate = false">✕</button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label class="form-label">Nombre del agente *</label>
              <input class="input" v-model="form.name" placeholder="ej: web-server-01" />
            </div>
            <div class="form-group">
              <label class="form-label">Email de alertas (opcional)</label>
              <input class="input" v-model="form.notify_email_to" placeholder="admin@empresa.com" />
            </div>
            <div class="form-group">
              <label class="form-label">Etiqueta</label>
              <input class="input" v-model="form.notes" placeholder="ej: producción, frontend…" />
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-ghost" @click="showCreate = false">Cancelar</button>
            <button class="btn btn-primary" @click="createAgent" :disabled="!form.name">Crear agente</button>
          </div>
        </div>
      </div>

      <!-- Token display modal -->
      <div v-if="tokenModal" class="modal-backdrop" @click.self="tokenModal = null">
        <div class="modal">
          <div class="modal-header">
            <h3>Token de {{ tokenModal.name }}</h3>
            <button class="btn btn-ghost btn-sm" @click="tokenModal = null">✕</button>
          </div>
          <div class="modal-body">
            <p class="text-muted" style="font-size:11px;margin-bottom:12px">
              Copia este token en <code style="color:var(--accent)">/etc/sysmon/agent.env</code> → <code style="color:var(--accent)">AGENT_TOKEN=</code>
            </p>
            <div class="token-box">{{ tokenModal.token ?? 'Cargando…' }}</div>
            <p class="text-muted" style="font-size:10px;margin-top:8px">⚠ Si regeneras el token tendrás que actualizar <code>/etc/sysmon/agent.env</code> en el agente.</p>
          </div>
          <div class="modal-footer">
            <button class="btn btn-ghost" @click="regenerateToken(tokenModal.id)">↻ Regenerar token</button>
            <button class="btn btn-primary" @click="copyToken(tokenModal.token)">Copiar</button>
          </div>
        </div>
      </div>
      <!-- Rename modal -->
      <div v-if="renameModal" class="modal-backdrop" @click.self="renameModal = null">
        <div class="modal">
          <div class="modal-header">
            <h3>Renombrar agente</h3>
            <button class="btn btn-ghost btn-sm" @click="renameModal = null">✕</button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label class="form-label">Nombre</label>
              <input class="input" v-model="renameForm.name" @keyup.enter="saveRename" />
            </div>
            <div class="form-group">
              <label class="form-label">Etiqueta</label>
              <input class="input" v-model="renameForm.notes" placeholder="ej: producción, frontend…" />
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-ghost" @click="renameModal = null">Cancelar</button>
            <button class="btn btn-primary" @click="saveRename" :disabled="!renameForm.name">Guardar</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { panelApi } from '@/services/api'
import { useDashboardStore } from '@/stores'

const store   = useDashboardStore()
const agents  = computed(() => store.agents)

const searchName = ref('')
const searchTag  = ref('')
const filterTemp = ref(null)
const filterDisk = ref(null)
const filterRam  = ref(null)

const hasFilters = computed(() =>
  searchName.value || searchTag.value ||
  filterTemp.value !== null && filterTemp.value !== '' ||
  filterDisk.value !== null && filterDisk.value !== '' ||
  filterRam.value  !== null && filterRam.value  !== ''
)

function clearFilters() {
  searchName.value = ''
  searchTag.value  = ''
  filterTemp.value = null
  filterDisk.value = null
  filterRam.value  = null
}

const filteredAgents = computed(() => {
  let list = agents.value
  const name = searchName.value.trim().toLowerCase()
  const tag  = searchTag.value.trim().toLowerCase()
  if (tag)  list = list.filter(a => a.notes?.toLowerCase().includes(tag))
  if (name) list = list.filter(a => a.name?.toLowerCase().includes(name))
  if (filterTemp.value !== null && filterTemp.value !== '')
    list = list.filter(a => (a.metrics?.temp_max ?? 0) >= filterTemp.value)
  if (filterDisk.value !== null && filterDisk.value !== '')
    list = list.filter(a => (a.metrics?.disk_max ?? 0) >= filterDisk.value)
  if (filterRam.value !== null && filterRam.value !== '')
    list = list.filter(a => (a.metrics?.ram_percent ?? 0) >= filterRam.value)
  return list
})

const showCreate = ref(false)
const tokenModal  = ref(null)
const tokenCache  = {}   // guarda el último token generado por agente (en memoria de sesión)
const renameModal = ref(null)
const renameForm  = ref({ name: '', notes: '' })
const form = ref({ name: '', notify_email_to: '', notes: '' })

function timeAgo(iso) {
  if (!iso) return '—'
  const diff = Math.floor((Date.now() - new Date(iso)) / 1000)
  if (diff < 60)   return `hace ${diff}s`
  if (diff < 3600) return `hace ${Math.floor(diff/60)}m`
  return `hace ${Math.floor(diff/3600)}h`
}

function cpuColor(v)  { if (!v) return ''; return v >= 90 ? 'var(--danger)' : v >= 75 ? 'var(--warn)' : 'var(--accent2)' }
function ramColor(v)  { if (!v) return ''; return v >= 90 ? 'var(--danger)' : v >= 80 ? 'var(--warn)' : 'var(--text)' }
function tempColor(v) { if (!v) return ''; return v >= 85 ? 'var(--danger)' : v >= 70 ? 'var(--warn)' : 'var(--text)' }
function diskColor(v) { if (!v) return ''; return v >= 95 ? 'var(--danger)' : v >= 85 ? 'var(--warn)' : 'var(--text)' }

async function createAgent() {
  const { data } = await panelApi.createAgent(form.value)
  tokenModal.value = { name: data.agent.name, id: data.agent.id, token: data.token }
  showCreate.value = false
  form.value = { name: '', notify_email_to: '', notes: '' }
  store.fetch()
}

async function deleteAgent(id) {
  if (!confirm('¿Desactivar este agente?')) return
  await panelApi.deleteAgent(id)
  store.fetch()
}

async function viewToken(agent) {
  tokenModal.value = { name: agent.name, id: agent.id, token: tokenCache[agent.id] ?? null }
  if (!tokenCache[agent.id]) {
    try {
      const { data } = await panelApi.getToken(agent.id)
      tokenCache[agent.id] = data.token
      if (tokenModal.value?.id === agent.id) tokenModal.value.token = data.token
    } catch { /* sin token disponible */ }
  }
}

async function regenerateToken(id) {
  const { data } = await panelApi.regenerateToken(id)
  tokenCache[id] = data.token
  tokenModal.value.token = data.token
}

function copyToken(token) {
  navigator.clipboard.writeText(token)
  alert('Token copiado al portapapeles')
}

function openRename(agent) {
  renameModal.value = agent
  renameForm.value  = { name: agent.name, notes: agent.notes ?? '' }
}

async function saveRename() {
  if (!renameForm.value.name) return
  await panelApi.updateAgent(renameModal.value.id, renameForm.value)
  renameModal.value = null
  store.fetch()
}
</script>

<style scoped>
.agents-view { display: flex; flex-direction: column; gap: 18px; }
.view-header { display: flex; align-items: center; justify-content: space-between; }
.view-header h2 { font-size: 18px; }

.filters-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.filter-num  { display: flex; align-items: center; gap: 5px; }
.filter-label { font-size: 11px; color: var(--text-muted); white-space: nowrap; }
.input-sm { padding: 5px 8px; font-size: 12px; }

.agent-row { cursor: pointer; }
.empty-state { text-align: center; padding: 32px; color: var(--text-muted); }

.status-cell { display: flex; align-items: center; gap: 6px; }
.status-text { font-size: 10px; font-family: var(--font-mono); text-transform: uppercase; letter-spacing: 0.5px; }
.status-text.online   { color: var(--accent2); }
.status-text.offline  { color: var(--text-muted); }
.status-text.warning  { color: var(--warn); }
.status-text.critical { color: var(--danger); }

.tag-label {
  display: inline-block;
  margin-left: 6px;
  font-size: 9px;
  padding: 2px 6px;
  border-radius: 4px;
  background: var(--surface2);
  border: 1px solid var(--border);
  color: var(--text-muted);
  font-family: var(--font-mono);
  vertical-align: middle;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.7);
  display: flex; align-items: center; justify-content: center;
  z-index: 100;
}
.modal {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  width: 440px;
  max-width: 95vw;
}
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 20px; border-bottom: 1px solid var(--border); }
.modal-header h3 { font-size: 15px; }
.modal-body { padding: 20px; }
.modal-footer { display: flex; gap: 8px; justify-content: flex-end; padding: 14px 20px; border-top: 1px solid var(--border); }

.token-box {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 12px;
  font-size: 11px;
  color: var(--accent);
  word-break: break-all;
  font-family: var(--font-mono);
}
</style>

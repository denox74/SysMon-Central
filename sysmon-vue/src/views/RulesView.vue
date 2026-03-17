<template>
  <div class="rules-view">
    <div class="view-header">
      <div>
        <h2>Umbrales de alerta</h2>
        <p class="text-muted" style="font-size:11px;margin-top:2px">Las reglas globales aplican a todos los agentes. Puedes añadir reglas específicas por agente.</p>
      </div>
      <button class="btn btn-primary" @click="openCreate()">+ Nueva regla</button>
    </div>

    <div class="card">
      <table class="table">
        <thead>
          <tr><th>Agente</th><th>Nombre</th><th>Métrica</th><th>Condición</th><th>Severidad</th><th>Email</th><th>Activa</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="r in rules" :key="r.id">
            <td>
              <span v-if="!r.agent_id" class="badge badge-info">Global</span>
              <span v-else class="text-muted" style="font-size:11px">{{ r.agent?.name ?? `#${r.agent_id}` }}</span>
            </td>
            <td class="text-bright" style="font-weight:600">{{ r.name }}</td>
            <td class="text-accent" style="font-size:11px;font-family:var(--font-mono)">{{ r.metric_path }}</td>
            <td style="font-size:12px">
              <template v-if="r.metric_path === 'agent_offline'">
                <span style="color:var(--text-muted);font-style:italic">Desconectado</span>
              </template>
              <template v-else>
                <code style="color:var(--warn)">{{ opLabel(r.operator) }} {{ r.threshold }}</code>
              </template>
            </td>
            <td><span :class="['badge', sevClass(r.severity)]">{{ r.severity }}</span></td>
            <td style="font-size:12px">{{ r.notify_email ? '✓' : '—' }}</td>
            <td>
              <div class="toggle" :class="{on: r.is_active}" @click="toggleRule(r)">
                <div class="toggle-knob"></div>
              </div>
            </td>
            <td>
              <div style="display:flex;gap:4px">
                <button class="btn btn-ghost btn-sm" @click="openEdit(r)">Editar</button>
                <button class="btn btn-danger btn-sm" @click="deleteRule(r.id)">✕</button>
              </div>
            </td>
          </tr>
          <tr v-if="!rules.length">
            <td colspan="8" style="text-align:center;padding:32px;color:var(--text-muted)">Sin reglas. Crea una para empezar.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create / Edit modal -->
    <Teleport to="body">
      <div v-if="modal" class="modal-backdrop" @click.self="modal = null">
        <div class="modal">
          <div class="modal-header">
            <h3>{{ editing ? 'Editar regla' : 'Nueva regla' }}</h3>
            <button class="btn btn-ghost btn-sm" @click="modal = null">✕</button>
          </div>
          <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group" style="grid-column:1/-1">
              <label class="form-label">Nombre *</label>
              <input class="input" v-model="modal.name" placeholder="ej: CPU crítica" />
            </div>
            <div class="form-group" style="grid-column:1/-1">
              <label class="form-label">Clave única (rule_key) *</label>
              <input class="input" v-model="modal.rule_key" placeholder="ej: cpu_critical" :disabled="!!editing" />
            </div>
            <div class="form-group" style="grid-column:1/-1">
              <label class="form-label">Métrica *</label>
              <select class="input" v-model="modal.metric_path">
                <option value="cpu.usage_percent">cpu.usage_percent</option>
                <option value="ram.usage_percent">ram.usage_percent</option>
                <option value="ram.swap_percent">ram.swap_percent</option>
                <option value="cpu.load_5m">cpu.load_5m</option>
                <option value="disk_max_usage_percent">disk_max_usage_percent</option>
                <option value="temp_max_celsius">temp_max_celsius</option>
                <option value="agent_offline">agent_offline — Agente desconectado</option>
              </select>
            </div>
            <template v-if="modal.metric_path === 'agent_offline'">
              <div class="form-group" style="grid-column:1/-1">
                <p class="field-hint" style="color:var(--text-muted);font-size:11px;padding:8px 12px;background:rgba(0,212,255,0.04);border:1px solid rgba(0,212,255,0.12);border-radius:var(--radius)">
                  ⓘ Esta regla dispara cuando el agente lleva sin enviar datos más tiempo del configurado en su perfil. No necesita umbral numérico.
                </p>
              </div>
            </template>
            <template v-else>
              <div class="form-group">
                <label class="form-label">Operador</label>
                <select class="input" v-model="modal.operator">
                  <option value="gte">≥ Mayor o igual</option>
                  <option value="gt">› Mayor que</option>
                  <option value="lte">≤ Menor o igual</option>
                  <option value="lt">‹ Menor que</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Umbral *</label>
                <input class="input" type="number" v-model="modal.threshold" placeholder="ej: 90" />
              </div>
            </template>
            <div class="form-group">
              <label class="form-label">Severidad</label>
              <select class="input" v-model="modal.severity">
                <option value="warning">Warning</option>
                <option value="critical">Critical</option>
                <option value="info">Info</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Cooldown (segundos)</label>
              <input class="input" type="number" v-model="modal.cooldown_seconds" />
            </div>
            <div class="form-group" style="grid-column:1/-1">
              <label class="form-label">Mensaje</label>
              <input class="input" v-model="modal.message_template" placeholder="ej: CPU al {value}% (umbral: {threshold}%)" />
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:8px">
              <input type="checkbox" v-model="modal.notify_email" id="notifyEmail" style="width:auto" />
              <label for="notifyEmail" class="form-label" style="margin:0">Notificar por email</label>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-ghost" @click="modal = null">Cancelar</button>
            <button class="btn btn-primary" @click="saveRule">{{ editing ? 'Guardar' : 'Crear' }}</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<!--
  RulesView.vue — Gestión de reglas de alerta (umbrales)
  Alert rule management (thresholds).

  Cada regla define: métrica, operador, umbral, severidad y cooldown.
  Each rule defines: metric, operator, threshold, severity and cooldown.

  Tipos de regla / Rule types:
    - Global (agent_id = NULL): aplica a todos los agentes
      Global (agent_id = NULL): applies to all agents
    - Específica (agent_id = X): aplica solo al agente indicado
      Specific (agent_id = X): applies only to the specified agent

  Las reglas se evalúan en Laravel (MetricsService::evaluateServerRules)
  al procesar cada snapshot. El campo cooldown_seconds evita spam de alertas.
  Rules are evaluated in Laravel (MetricsService::evaluateServerRules)
  when processing each snapshot. cooldown_seconds prevents alert spam.

  metric_path usa notación de puntos para campos del snapshot, ej:
    cpu.usage_percent → snapshot['cpu']['usage_percent']
    disk_max_usage_percent → snapshot['disk_max_usage_percent'] (campo derivado)
  metric_path uses dot notation for snapshot fields, e.g.:
    cpu.usage_percent → snapshot['cpu']['usage_percent']
    disk_max_usage_percent → snapshot['disk_max_usage_percent'] (derived field)
-->
<script setup>
import { ref, watch, onMounted } from 'vue'
import { panelApi } from '@/services/api'

const rules   = ref([])
const modal   = ref(null)   // datos del modal abierto (null = cerrado) / open modal data (null = closed)
const editing = ref(null)   // ID de la regla siendo editada (null = modo crear) / ID of rule being edited (null = create mode)

// Mapeos de badge y operador a símbolo legible / Badge and operator-to-symbol mappings
const sevClass = s => ({critical:'badge-danger',warning:'badge-warn',info:'badge-info'})[s]??'badge-muted'
const opLabel  = o => ({gte:'≥',gt:'>',lte:'≤',lt:'<'})[o]??o

/**
 * Abre el modal en modo "crear" con valores por defecto razonables.
 * Opens the modal in "create" mode with sensible default values.
 * El rule_key debe ser único en BD; se puede editar solo en creación.
 * The rule_key must be unique in DB; editable only during creation.
 */
watch(() => modal.value?.metric_path, (path) => {
  if (!modal.value || editing.value) return
  if (path === 'agent_offline') {
    modal.value.message_template = 'El agente lleva offline más del tiempo configurado'
    modal.value.rule_key = modal.value.rule_key || 'agent_offline'
    modal.value.operator = 'gte'
    modal.value.threshold = 1
  } else if (modal.value.message_template === 'El agente lleva offline más del tiempo configurado') {
    modal.value.message_template = 'Métrica al {value}% (umbral: {threshold}%)'
  }
})

function openCreate() {
  editing.value = null
  modal.value = { name:'', rule_key:'', metric_path:'cpu.usage_percent', operator:'gte', threshold: 80, severity:'warning', message_template:'Métrica al {value}% (umbral: {threshold}%)', cooldown_seconds: 300, notify_email: false }
}

/**
 * Abre el modal en modo "editar" precargando la regla seleccionada.
 * Opens the modal in "edit" mode pre-filling the selected rule.
 * El rule_key está deshabilitado en edición para preservar la clave.
 * rule_key is disabled in edit mode to preserve the key.
 */
function openEdit(rule) {
  editing.value = rule.id
  modal.value = { ...rule }
}

/**
 * Crea o actualiza la regla según si editing.value tiene valor.
 * Creates or updates the rule depending on whether editing.value has a value.
 */
async function saveRule() {
  if (editing.value) {
    await panelApi.updateAlertRule(editing.value, modal.value)
  } else {
    await panelApi.createAlertRule(modal.value)
  }
  modal.value = null
  loadRules()
}

/**
 * Elimina una regla tras confirmación del usuario.
 * Deletes a rule after user confirmation.
 */
async function deleteRule(id) {
  if (!confirm('¿Eliminar esta regla?')) return
  await panelApi.deleteAlertRule(id)
  loadRules()
}

/**
 * Activa/desactiva la regla sin abrir el modal.
 * Toggles the rule on/off without opening the modal.
 * Actualiza localmente para respuesta inmediata en UI.
 * Updates locally for immediate UI feedback.
 */
async function toggleRule(rule) {
  await panelApi.updateAlertRule(rule.id, { is_active: !rule.is_active })
  rule.is_active = !rule.is_active
}

/**
 * Carga todas las reglas de alerta desde la API.
 * Loads all alert rules from the API.
 * La API devuelve reglas globales y específicas mezcladas; la BD ordena por agent_id nulls first.
 * The API returns global and specific rules mixed; DB orders with agent_id nulls first.
 */
async function loadRules() {
  const { data } = await panelApi.alertRules()
  rules.value = data
}

onMounted(loadRules)
</script>

<style scoped>
.rules-view { display: flex; flex-direction: column; gap: 18px; }
.view-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
.view-header h2 { font-size: 18px; }

.toggle {
  width: 36px; height: 18px;
  background: var(--border);
  border-radius: 9px;
  position: relative;
  cursor: pointer;
  transition: background var(--transition);
}
.toggle.on { background: var(--accent2); }
.toggle-knob {
  position: absolute;
  width: 14px; height: 14px;
  background: #fff;
  border-radius: 50%;
  top: 2px; left: 2px;
  transition: left var(--transition);
}
.toggle.on .toggle-knob { left: 20px; }

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
  width: 520px; max-width: 95vw;
  max-height: 90vh; overflow-y: auto;
}
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 20px; border-bottom: 1px solid var(--border); }
.modal-header h3 { font-size: 15px; }
.modal-body { padding: 20px; }
.modal-footer { display: flex; gap: 8px; justify-content: flex-end; padding: 14px 20px; border-top: 1px solid var(--border); }
</style>

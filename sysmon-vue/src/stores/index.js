/**
 * Stores globales de SysMon Central (Pinia).
 * Global Pinia stores for SysMon Central.
 *
 * useDashboardStore → estado de agentes, alertas recientes y totales.
 *                     Hace polling a /api/panel/dashboard cada 10s.
 *                     State for agents, recent alerts and totals.
 *                     Polls /api/panel/dashboard every 10s.
 *
 * useAlertsStore    → estado de la página de alertas con filtros y paginación.
 *                     State for alerts page with filters and pagination.
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { panelApi } from '@/services/api'

// ── Dashboard Store ───────────────────────────────────────────────────────────
const STARTUP_TIMEOUT_MS = 3 * 60 * 1000   // 3 minutos para esperar que arranque la API
const _startedAt         = Date.now()       // momento en que se cargó el módulo

export const useDashboardStore = defineStore('dashboard', () => {
  // Estado principal devuelto por /api/panel/dashboard
  // Main state returned by /api/panel/dashboard
  const data       = ref(null)
  const loading    = ref(false)
  const error      = ref(null)
  const lastUpdate = ref(null)
  const timedOut   = ref(false)    // true cuando se agotó el tiempo de espera de arranque
  let   pollTimer  = null  // ID del intervalo activo / active interval ID

  // ── Getters ──────────────────────────────────────────────
  // Extraen sublistas del objeto data con fallback seguro
  // Extract sub-lists from data object with safe fallbacks
  const agents      = computed(() => data.value?.agents      ?? [])
  const openAlerts  = computed(() => data.value?.open_alerts ?? [])
  const totals      = computed(() => data.value?.totals      ?? {})

  // Subconjuntos derivados para tarjetas de resumen del Dashboard
  // Derived subsets used by Dashboard summary cards
  const criticalAgents = computed(() =>
    agents.value.filter(a => a.status === 'critical'))

  const offlineAgents = computed(() =>
    agents.value.filter(a => a.status === 'offline'))

  // ── Actions ───────────────────────────────────────────────

  /**
   * Carga los datos del dashboard desde la API.
   * Fetches dashboard data from the API.
   * Llamado al arrancar y cada intervalMs segundos.
   * Called on startup and every intervalMs seconds.
   */
  async function fetch() {
    loading.value = true
    error.value   = null
    try {
      const { data: res } = await panelApi.dashboard()
      data.value       = res
      lastUpdate.value = new Date()
    } catch (e) {
      // Comprobar si se agotó el tiempo de espera de arranque
      if (!timedOut.value && Date.now() - _startedAt >= STARTUP_TIMEOUT_MS) {
        timedOut.value = true
      }
      // Solo mostrar error si ya hay datos anteriores (polling normal) o si se agotó el tiempo
      // No limpiamos data.value para seguir mostrando datos anteriores
      if (data.value !== null || timedOut.value) {
        error.value = 'No se pudo conectar con la API. ¿Está Laravel corriendo?'
      }
    } finally {
      loading.value = false
    }
  }

  /**
   * Inicia el polling automático. Hace fetch inmediato y luego cada intervalMs.
   * Starts automatic polling. Fetches immediately then every intervalMs.
   */
  function startPolling(intervalMs = 10000) {
    fetch()
    pollTimer = setInterval(fetch, intervalMs)
  }

  /**
   * Detiene el polling (llamado al desmontar App.vue).
   * Stops polling (called when App.vue unmounts).
   */
  function stopPolling() {
    clearInterval(pollTimer)
  }

  return { data, loading, error, lastUpdate, timedOut, startupStartedAt: _startedAt, agents, openAlerts, totals, criticalAgents, offlineAgents, fetch, startPolling, stopPolling }
})

// ── Alerts Store ─────────────────────────────────────────────────────────────
export const useAlertsStore = defineStore('alerts', () => {
  // Lista paginada de alertas. Se actualiza al llamar fetch() o cambiar filtros.
  // Paginated alert list. Updated when fetch() is called or filters change.
  const items      = ref([])
  const loading    = ref(false)
  const pagination = ref(null)  // { current_page, last_page, total }

  // Filtros activos; los vacíos ('') se omiten en la petición HTTP
  // Active filters; empty values ('') are omitted from the HTTP request
  const filters    = ref({ status: '', severity: '', agent_id: '', archived: '' })

  /**
   * Carga alertas paginadas aplicando los filtros actuales.
   * Fetches paginated alerts applying current filters.
   * Los filtros vacíos se eliminan antes de enviar los params.
   * Empty filters are stripped before sending params.
   */
  async function fetch(page = 1) {
    loading.value = true
    try {
      const params = { page, ...Object.fromEntries(Object.entries(filters.value).filter(([,v]) => v)) }
      const { data } = await panelApi.alerts(params)
      items.value      = data.data
      pagination.value = { current_page: data.current_page, last_page: data.last_page, total: data.total }
    } finally {
      loading.value = false
    }
  }

  /**
   * Marca una alerta como "acknowledged" (vista pero no resuelta).
   * Marks an alert as "acknowledged" (seen but not resolved).
   * Actualiza el estado localmente para evitar un re-fetch.
   * Updates state locally to avoid a re-fetch.
   */
  async function acknowledge(id) {
    await panelApi.acknowledgeAlert(id)
    const a = items.value.find(x => x.id === id)
    if (a) a.status = 'acknowledged'
  }

  /**
   * Resuelve una alerta con nota opcional.
   * Resolves an alert with an optional note.
   */
  async function resolve(id, note = '') {
    await panelApi.resolveAlert(id, note)
    const a = items.value.find(x => x.id === id)
    if (a) { a.status = 'resolved'; a.resolved_at = new Date().toISOString() }
  }

  /**
   * Archiva una alerta individual (la elimina de la vista actual).
   * Archives a single alert (removes it from the current view).
   */
  async function archive(id) {
    await panelApi.archiveAlert(id)
    items.value = items.value.filter(x => x.id !== id)
  }

  /**
   * Archiva todas las alertas resueltas, opcionalmente de un agente concreto.
   * Archives all resolved alerts, optionally filtered by agent.
   * Si no se está viendo archivadas, las elimina de la lista local.
   * If not viewing archived, removes them from the local list.
   */
  async function archiveAllResolved(agentId = null) {
    const params = agentId ? { agent_id: agentId } : {}
    await panelApi.archiveResolved(params)
    if (!filters.value.archived) {
      items.value = items.value.filter(x => x.status !== 'resolved')
    }
  }

  return { items, loading, pagination, filters, fetch, acknowledge, resolve, archive, archiveAllResolved }
})

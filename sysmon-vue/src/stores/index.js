import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { panelApi } from '@/services/api'

export const useDashboardStore = defineStore('dashboard', () => {
  const data       = ref(null)
  const loading    = ref(false)
  const error      = ref(null)
  const lastUpdate = ref(null)
  let   pollTimer  = null

  // ── Getters ──────────────────────────────────────────────
  const agents      = computed(() => data.value?.agents      ?? [])
  const openAlerts  = computed(() => data.value?.open_alerts ?? [])
  const totals      = computed(() => data.value?.totals      ?? {})

  const criticalAgents = computed(() =>
    agents.value.filter(a => a.status === 'critical'))

  const offlineAgents = computed(() =>
    agents.value.filter(a => a.status === 'offline'))

  // ── Actions ───────────────────────────────────────────────
  async function fetch() {
    loading.value = true
    error.value   = null
    try {
      const { data: res } = await panelApi.dashboard()
      data.value       = res
      lastUpdate.value = new Date()
    } catch (e) {
      error.value = 'No se pudo conectar con la API. ¿Está Laravel corriendo?'
    } finally {
      loading.value = false
    }
  }

  function startPolling(intervalMs = 10000) {
    fetch()
    pollTimer = setInterval(fetch, intervalMs)
  }

  function stopPolling() {
    clearInterval(pollTimer)
  }

  return { data, loading, error, lastUpdate, agents, openAlerts, totals, criticalAgents, offlineAgents, fetch, startPolling, stopPolling }
})

export const useAlertsStore = defineStore('alerts', () => {
  const items      = ref([])
  const loading    = ref(false)
  const pagination = ref(null)
  const filters    = ref({ status: '', severity: '', agent_id: '', archived: '' })

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

  async function acknowledge(id) {
    await panelApi.acknowledgeAlert(id)
    const a = items.value.find(x => x.id === id)
    if (a) a.status = 'acknowledged'
  }

  async function resolve(id, note = '') {
    await panelApi.resolveAlert(id, note)
    const a = items.value.find(x => x.id === id)
    if (a) { a.status = 'resolved'; a.resolved_at = new Date().toISOString() }
  }

  async function archive(id) {
    await panelApi.archiveAlert(id)
    items.value = items.value.filter(x => x.id !== id)
  }

  async function archiveAllResolved(agentId = null) {
    const params = agentId ? { agent_id: agentId } : {}
    await panelApi.archiveResolved(params)
    if (!filters.value.archived) {
      items.value = items.value.filter(x => x.status !== 'resolved')
    }
  }

  return { items, loading, pagination, filters, fetch, acknowledge, resolve, archive, archiveAllResolved }
})

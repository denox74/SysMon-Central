/**
 * Cliente HTTP para la API de SysMon Central.
 * HTTP client for the SysMon Central API.
 *
 * La baseURL se configura con la variable de entorno VITE_API_URL.
 * En Docker dev: VITE_API_URL=http://localhost:8000
 * En producción: apuntar a la URL pública de la API.
 *
 * The baseURL is configured with the VITE_API_URL environment variable.
 * In Docker dev: VITE_API_URL=http://localhost:8000
 * In production: point to the public API URL.
 */
import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL
    ? `${import.meta.env.VITE_API_URL}/api`
    : '/api',
  timeout: 15000,
  headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }
})

// ── Interceptor: log de errores ─────────────────────────────
api.interceptors.response.use(
  res => res,
  err => {
    console.error('[API Error]', err.response?.status, err.config?.url, err.message)
    return Promise.reject(err)
  }
)

// ── Panel endpoints ─────────────────────────────────────────
export const panelApi = {

  // Dashboard
  dashboard:         ()           => api.get('/panel/dashboard'),

  // Agentes
  agents:            ()           => api.get('/panel/agents'),
  agent:             (id)         => api.get(`/panel/agents/${id}`),
  createAgent:       (data)       => api.post('/panel/agents', data),
  updateAgent:       (id, data)   => api.put(`/panel/agents/${id}`, data),
  deleteAgent:       (id)         => api.delete(`/panel/agents/${id}`),
  getToken:          (id)         => api.get(`/panel/agents/${id}/token`),
  regenerateToken:   (id)         => api.post(`/panel/agents/${id}/regenerate-token`),

  // Métricas
  metrics:           (id, hours=24) => api.get(`/panel/agents/${id}/metrics`, { params: { hours } }),
  latestMetrics:     (id)           => api.get(`/panel/agents/${id}/metrics/latest`),

  // Alertas
  alerts:            (params={})  => api.get('/panel/alerts', { params }),
  agentAlerts:       (id, params) => api.get(`/panel/agents/${id}/alerts`, { params }),
  acknowledgeAlert:  (id)         => api.post(`/panel/alerts/${id}/acknowledge`),
  resolveAlert:      (id, note)   => api.post(`/panel/alerts/${id}/resolve`, { note }),
  archiveAlert:      (id)         => api.post(`/panel/alerts/${id}/archive`),
  archiveResolved:   (params={})  => api.post('/panel/alerts/archive-resolved', {}, { params }),

  // Reglas
  alertRules:        (params={})  => api.get('/panel/alert-rules', { params }),
  createAlertRule:   (data)       => api.post('/panel/alert-rules', data),
  updateAlertRule:   (id, data)   => api.put(`/panel/alert-rules/${id}`, data),
  deleteAlertRule:   (id)         => api.delete(`/panel/alert-rules/${id}`),

  // Configuración de email
  getEmailSettings:    ()     => api.get('/panel/settings/email'),
  updateEmailSettings: (data) => api.put('/panel/settings/email', data),
  testEmailSettings:   ()     => api.post('/panel/settings/email/test'),
}

export default api

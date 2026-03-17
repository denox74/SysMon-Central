/**
 * Router de SysMon Central.
 * Usa hash history (#/) para funcionar sin configuración de servidor.
 * Uses hash history (#/) to work without server configuration.
 *
 * Todas las rutas usan lazy-loading (import dinámico) para que el panel
 * arranque rápido y solo cargue cada vista cuando se visita por primera vez.
 * All routes use lazy-loading (dynamic import) so the panel starts fast
 * and only loads each view when first visited.
 */
import { createRouter, createWebHashHistory } from 'vue-router'

const routes = [
  { path: '/',         name: 'dashboard', component: () => import('@/views/DashboardView.vue') },
  { path: '/agents',   name: 'agents',    component: () => import('@/views/AgentsView.vue') },
  { path: '/agents/:id', name: 'agent',   component: () => import('@/views/AgentDetailView.vue') },
  { path: '/alerts',   name: 'alerts',    component: () => import('@/views/AlertsView.vue') },
  { path: '/rules',    name: 'rules',     component: () => import('@/views/RulesView.vue') },
  { path: '/settings', name: 'settings', component: () => import('@/views/SettingsView.vue') },
]

export default createRouter({
  history: createWebHashHistory(),
  routes
})

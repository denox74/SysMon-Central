import { createRouter, createWebHashHistory } from 'vue-router'

const routes = [
  { path: '/',         name: 'dashboard', component: () => import('@/views/DashboardView.vue') },
  { path: '/agents',   name: 'agents',    component: () => import('@/views/AgentsView.vue') },
  { path: '/agents/:id', name: 'agent',   component: () => import('@/views/AgentDetailView.vue') },
  { path: '/alerts',   name: 'alerts',    component: () => import('@/views/AlertsView.vue') },
  { path: '/rules',    name: 'rules',     component: () => import('@/views/RulesView.vue') },
]

export default createRouter({
  history: createWebHashHistory(),
  routes
})

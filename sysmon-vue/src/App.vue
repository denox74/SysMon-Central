<template>
  <div class="app-shell grid-bg">

    <!-- Sidebar -->
    <aside class="sidebar">
      <div class="logo">
        <div class="logo-title">SysMon <span>Central</span></div>
        <div class="logo-sub">PERFORMANCE MONITOR</div>
      </div>

      <nav class="nav">
        <div class="nav-section">Monitoreo</div>
        <RouterLink to="/"        class="nav-item" active-class="active"><span class="nav-icon">⬡</span> Dashboard</RouterLink>
        <RouterLink to="/agents"  class="nav-item" active-class="active"><span class="nav-icon">⊕</span> Agentes</RouterLink>
        <RouterLink to="/alerts"  class="nav-item" active-class="active">
          <span class="nav-icon">◎</span> Alertas
          <span v-if="openCount > 0" class="nav-badge">{{ openCount }}</span>
        </RouterLink>

        <div class="nav-section">Configuración</div>
        <RouterLink to="/rules"   class="nav-item" active-class="active"><span class="nav-icon">◧</span> Umbrales</RouterLink>
      </nav>

    </aside>

    <!-- Main -->
    <div class="main-area">
      <!-- Header -->
      <header class="top-header">
        <div>
          <div class="header-title">{{ pageTitle }}</div>
        </div>
        <div class="header-right">
          <span class="live-indicator">
            <span class="live-dot"></span>
            Live · actualiza cada 10s
          </span>
          <span class="time-chip">{{ clock }}</span>
        </div>
      </header>

      <!-- Page content -->
      <main class="page-content">
        <RouterView v-slot="{ Component }">
          <Transition name="fade" mode="out-in">
            <component :is="Component" />
          </Transition>
        </RouterView>
      </main>
    </div>

  </div>
</template>

<!--
  App.vue — Shell principal de la aplicación / Main application shell
  Contiene sidebar, header y el <RouterView> que monta las vistas.
  Contains the sidebar, header and <RouterView> that mounts the views.

  - El polling del dashboard arranca en onMounted y se detiene en onUnmounted.
    Dashboard polling starts in onMounted and stops in onUnmounted.
  - openCount alimenta el badge de alertas en el sidebar.
    openCount feeds the alerts badge in the sidebar.
  - El reloj se actualiza cada segundo con setInterval.
    The clock updates every second via setInterval.
-->
<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute } from 'vue-router'
import { useDashboardStore } from '@/stores'

const route  = useRoute()
const store  = useDashboardStore()

// Número de alertas abiertas para el badge rojo del sidebar
// Number of open alerts for the red sidebar badge
const openCount = computed(() => store.totals?.open_alerts ?? 0)

// Reloj en tiempo real en el header / Real-time clock in the header
const clock = ref('')
let clockTimer = null

function updateClock() {
  clock.value = new Date().toLocaleTimeString('es-ES')
}

// Mapeo nombre-de-ruta → título que muestra el header
// Route name → title shown in the header
const titles = { dashboard: 'Dashboard', agents: 'Agentes', agent: 'Detalle de agente', alerts: 'Alertas', rules: 'Umbrales' }
const pageTitle = computed(() => titles[route.name] ?? 'SysMon Central')

onMounted(() => {
  // Inicia polling cada 10 s y el reloj cada 1 s
  // Starts polling every 10 s and the clock every 1 s
  store.startPolling(10000)
  updateClock()
  clockTimer = setInterval(updateClock, 1000)
})

onUnmounted(() => {
  // Limpia timers al salir para evitar memory leaks
  // Clean up timers on exit to avoid memory leaks
  store.stopPolling()
  clearInterval(clockTimer)
})
</script>

<style scoped>
.app-shell {
  display: grid;
  grid-template-columns: var(--sidebar-w) 1fr;
  min-height: 100vh;
  position: relative;
  z-index: 1;
}

/* Sidebar */
.sidebar {
  background: var(--surface);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  position: sticky;
  top: 0;
  height: 100vh;
  overflow-y: auto;
}

.logo {
  padding: 22px 22px 18px;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
.logo-title {
  font-family: var(--font-display);
  font-size: 20px;
  font-weight: 800;
  color: var(--text-bright);
}
.logo-title span { color: var(--accent); }
.logo-sub { font-size: 9px; color: var(--text-muted); letter-spacing: 2px; margin-top: 2px; }

.nav { padding: 16px 0; flex: 1; }
.nav-section {
  font-size: 9px;
  letter-spacing: 2px;
  color: var(--text-muted);
  padding: 12px 22px 5px;
  text-transform: uppercase;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 9px 22px;
  font-size: 12px;
  color: var(--text-muted);
  text-decoration: none;
  border-left: 2px solid transparent;
  transition: all var(--transition);
}
.nav-item:hover { color: var(--text); background: rgba(255,255,255,0.03); }
.nav-item.active { color: var(--accent); background: var(--accent-dim); border-left-color: var(--accent); }
.nav-icon { font-size: 13px; width: 16px; text-align: center; }
.nav-badge {
  margin-left: auto;
  background: var(--danger);
  color: #fff;
  font-size: 9px;
  padding: 1px 6px;
  border-radius: 10px;
  font-weight: 700;
  animation: pulse-dot 2s infinite;
}

/* Header */
.main-area { display: flex; flex-direction: column; min-height: 100vh; }
.top-header {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  padding: 0 28px;
  height: var(--header-h);
  display: flex;
  align-items: center;
  gap: 16px;
  flex-shrink: 0;
  position: sticky;
  top: 0;
  z-index: 10;
}
.header-title { font-family: var(--font-display); font-size: 15px; font-weight: 600; color: var(--text-bright); }
.header-right { margin-left: auto; display: flex; align-items: center; gap: 12px; }
.live-indicator { display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--accent2); }
.live-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--accent2); box-shadow: 0 0 0 0 rgba(0,255,136,0.4); animation: ring 2s infinite; }
@keyframes ring { 0%{box-shadow:0 0 0 0 rgba(0,255,136,0.4)} 70%{box-shadow:0 0 0 7px rgba(0,255,136,0)} 100%{box-shadow:0 0 0 0 rgba(0,255,136,0)} }
.time-chip { font-size: 11px; color: var(--accent); background: var(--accent-dim); border: 1px solid rgba(0,212,255,0.2); padding: 4px 10px; border-radius: 20px; }

.page-content { padding: 24px 28px; flex: 1; }
</style>

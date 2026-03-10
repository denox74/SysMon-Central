<template>
  <div :class="['agent-card', `status-${agent.status}`]">
    <div class="card-top">
      <span :class="['status-dot', agent.status]"></span>
      <div class="agent-name">{{ agent.name }}</div>
      <span :class="['badge', statusBadge]">{{ statusLabel }}</span>
    </div>

    <div class="agent-host">{{ agent.hostname || '—' }} · {{ agent.ip_address || '—' }}</div>
    <div class="agent-distro text-muted">{{ agent.distro || 'Ubuntu' }}</div>

    <template v-if="m">
      <div class="metrics">
        <MetricRow label="CPU"  :value="m.cpu_percent"  :max="100" color="var(--cpu)"  unit="%" />
        <MetricRow label="RAM"  :value="m.ram_percent"  :max="100" color="var(--ram)"  unit="%" />
        <MetricRow label="Disk" :value="m.disk_max"     :max="100" color="var(--disk)" unit="%" />
        <MetricRow label="Temp" :value="m.temp_max"     :max="100" color="var(--temp)" unit="°C" v-if="m.temp_max" />
      </div>
      <div class="card-footer">
        <span class="text-muted">Load: {{ m.load_5m?.toFixed(2) ?? '—' }}</span>
        <span class="text-muted">{{ timeAgo(m.collected_at) }}</span>
      </div>
    </template>
    <div v-else class="no-data">Sin datos recientes</div>

    <div v-if="agent.open_alerts > 0" class="alert-ribbon">
      ⚠ {{ agent.open_alerts }} alerta{{ agent.open_alerts > 1 ? 's' : '' }}
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import MetricRow from './MetricRow.vue'

const props = defineProps({ agent: Object })

// computed() hace que m, statusLabel y statusBadge se recalculen cada vez que
// el prop `agent` cambia (el store lo reemplaza cada 10 s con datos nuevos).
// computed() ensures m, statusLabel and statusBadge recalculate whenever the
// `agent` prop changes (the store replaces it every 10 s with fresh data).
const m           = computed(() => props.agent.metrics)
const statusLabel = computed(() => ({ online: 'Online', warning: 'Warning', critical: 'Crítico', offline: 'Offline' }[props.agent.status] ?? '—'))
const statusBadge = computed(() => ({ online: 'badge-success', warning: 'badge-warn', critical: 'badge-danger', offline: 'badge-muted' }[props.agent.status] ?? 'badge-muted'))

function timeAgo(iso) {
  if (!iso) return '—'
  const diff = Math.floor((Date.now() - new Date(iso)) / 1000)
  if (diff < 60)   return `hace ${diff}s`
  if (diff < 3600) return `hace ${Math.floor(diff/60)}m`
  return `hace ${Math.floor(diff/3600)}h`
}
</script>

<style scoped>
.agent-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  padding: 16px;
  cursor: pointer;
  transition: all var(--transition);
  position: relative;
  overflow: hidden;
}
.agent-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  background: var(--status-color, var(--border));
  transition: background var(--transition);
}
.agent-card.status-online   { --status-color: var(--accent2); }
.agent-card.status-warning  { --status-color: var(--warn); }
.agent-card.status-critical { --status-color: var(--danger); }
.agent-card.status-offline  { --status-color: var(--text-muted); opacity: 0.65; }
.agent-card:hover { border-color: var(--status-color); transform: translateY(-1px); }

.card-top { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
.agent-name { font-family: var(--font-display); font-size: 14px; font-weight: 600; color: var(--text-bright); flex: 1; }
.agent-host { font-size: 11px; color: var(--text-muted); margin-bottom: 2px; }
.agent-distro { font-size: 10px; margin-bottom: 12px; }

.metrics { display: flex; flex-direction: column; gap: 7px; }
.card-footer { display: flex; justify-content: space-between; margin-top: 10px; font-size: 10px; }

.no-data { font-size: 11px; color: var(--text-muted); padding: 12px 0; text-align: center; }

.alert-ribbon {
  position: absolute;
  top: 8px; right: -28px;
  background: var(--danger);
  color: #fff;
  font-size: 9px;
  font-weight: 700;
  padding: 2px 36px;
  transform: rotate(45deg);
  letter-spacing: 0.5px;
}
</style>

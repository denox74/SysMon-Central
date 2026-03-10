<template>
  <div class="metric-row">
    <span class="metric-label">{{ label }}</span>
    <div class="progress">
      <div class="progress-fill" :style="{ width: `${pct}%`, background: barColor }"></div>
    </div>
    <span class="metric-value" :style="{ color: valueColor }">
      {{ display }}{{ unit }}
    </span>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  label:  String,
  value:  Number,
  max:    { type: Number, default: 100 },
  color:  { type: String, default: 'var(--accent)' },
  unit:   { type: String, default: '%' },
  warnAt: { type: Number, default: 75 },
  critAt: { type: Number, default: 90 },
})

const pct     = computed(() => props.value != null ? Math.min(100, (props.value / props.max) * 100) : 0)
const display = computed(() => props.value != null ? Math.round(props.value * 10) / 10 : '—')

const valueColor = computed(() => {
  if (props.value == null) return 'var(--text-muted)'
  if (pct.value >= props.critAt) return 'var(--danger)'
  if (pct.value >= props.warnAt) return 'var(--warn)'
  return props.color
})

const barColor = computed(() => {
  if (pct.value >= props.critAt) return 'var(--danger)'
  if (pct.value >= props.warnAt) return 'var(--warn)'
  return props.color
})
</script>

<style scoped>
.metric-row { display: grid; grid-template-columns: 36px 1fr 52px; align-items: center; gap: 8px; }
.metric-label { font-size: 10px; color: var(--text-muted); }
.metric-value  { font-size: 11px; font-weight: 600; text-align: right; }
</style>

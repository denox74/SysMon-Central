<template>
  <div class="settings-view">
    <div class="view-header">
      <div>
        <h2>Configuración de email</h2>
        <p class="text-muted" style="font-size:11px;margin-top:2px">
          Configura el servidor SMTP, remitente, destinatarios y tipos de alerta que envían correo.
        </p>
      </div>
    </div>

    <div v-if="saved"  class="banner banner-ok">✓ Configuración guardada correctamente.</div>
    <div v-if="error"  class="banner banner-err">✗ {{ error }}</div>

    <div v-if="loading" style="color:var(--text-muted);font-size:13px;padding:32px 0">Cargando…</div>

    <template v-else>

      <!-- ── Servidor SMTP ─────────────────────────────────────────── -->
      <div class="card section">
        <div class="section-title"><span class="section-icon">⚙</span> Servidor SMTP</div>
        <p class="section-desc">
          Credenciales del servidor de correo saliente. Para desarrollo sin SMTP real puedes usar
          <a href="https://mailtrap.io" target="_blank" class="link">Mailtrap</a>
          — los emails aparecen en el navegador sin enviarse a nadie.
        </p>

        <div class="form-grid-3">
          <div class="form-group" style="grid-column:1/3">
            <label class="form-label">Host *</label>
            <input class="input" type="text" v-model="form.smtp_host" placeholder="smtp.gmail.com" />
          </div>
          <div class="form-group">
            <label class="form-label">Puerto *</label>
            <input class="input" type="number" v-model="form.smtp_port" placeholder="587" />
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Usuario</label>
            <input class="input" type="text" v-model="form.smtp_username" placeholder="tu@email.com" autocomplete="off" />
          </div>
          <div class="form-group">
            <label class="form-label">Contraseña</label>
            <div style="position:relative">
              <input class="input" :type="showPass ? 'text' : 'password'" v-model="form.smtp_password"
                     placeholder="••••••••" autocomplete="new-password" style="padding-right:36px" />
              <button class="show-pass-btn" type="button" @click="showPass = !showPass" :title="showPass ? 'Ocultar' : 'Mostrar'">
                {{ showPass ? '🙈' : '👁' }}
              </button>
            </div>
            <p class="field-hint">Déjalo vacío para no cambiar la contraseña guardada.</p>
          </div>
        </div>

        <div class="form-group" style="max-width:220px">
          <label class="form-label">Cifrado</label>
          <select class="input" v-model="form.smtp_encryption">
            <option value="tls">TLS (recomendado, puerto 587)</option>
            <option value="ssl">SSL (puerto 465)</option>
            <option value="none">Sin cifrado (puerto 25)</option>
          </select>
        </div>
      </div>

      <!-- ── Remitente ─────────────────────────────────────────────── -->
      <div class="card section">
        <div class="section-title"><span class="section-icon">✉</span> Remitente</div>
        <p class="section-desc">Email y nombre que aparecen en el campo "De:" de los correos de alerta.</p>
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Email del remitente *</label>
            <input class="input" type="email" v-model="form.from_address" placeholder="alertas@tudominio.com" />
          </div>
          <div class="form-group">
            <label class="form-label">Nombre visible *</label>
            <input class="input" type="text" v-model="form.from_name" placeholder="SysMon Alertas" />
          </div>
        </div>
      </div>

      <!-- ── Destinatarios ─────────────────────────────────────────── -->
      <div class="card section">
        <div class="section-title"><span class="section-icon">◎</span> Destinatarios</div>
        <p class="section-desc">
          Emails que reciben las alertas. Puedes añadir tantos como quieras.
          Si un agente tiene su propio email configurado, recibirá él solo (no la lista global).
        </p>

        <!-- Lista de destinatarios -->
        <div class="recipients-list">
          <div v-for="(email, idx) in form.recipients" :key="idx" class="recipient-row">
            <span class="recipient-email">{{ email }}</span>
            <button class="btn btn-danger btn-sm" @click="removeRecipient(idx)" title="Eliminar">✕</button>
          </div>
          <div v-if="!form.recipients.length" class="no-recipients">
            Sin destinatarios configurados — las alertas no se enviarán por email.
          </div>
        </div>

        <!-- Añadir destinatario -->
        <div class="add-recipient">
          <input class="input" type="email" v-model="newRecipient"
                 placeholder="nuevo@email.com"
                 @keydown.enter.prevent="addRecipient" />
          <button class="btn btn-primary" @click="addRecipient">+ Añadir</button>
        </div>
        <p v-if="recipientError" class="field-hint" style="color:var(--danger)">{{ recipientError }}</p>
      </div>

      <!-- ── Severidades ────────────────────────────────────────────── -->
      <div class="card section">
        <div class="section-title"><span class="section-icon">◧</span> Severidades que envían email</div>
        <p class="section-desc">
          Selecciona qué niveles disparan correo. Las reglas individuales también deben
          tener activado "Notificar por email" en la sección Umbrales.
        </p>
        <div class="sev-checks">
          <label class="sev-label" :class="{ active: form.notify_severities.includes('info') }">
            <input type="checkbox" value="info" v-model="form.notify_severities" />
            <span class="badge badge-info">info</span>
            <span class="sev-desc">Alertas informativas</span>
          </label>
          <label class="sev-label" :class="{ active: form.notify_severities.includes('warning') }">
            <input type="checkbox" value="warning" v-model="form.notify_severities" />
            <span class="badge badge-warn">warning</span>
            <span class="sev-desc">Avisos de uso elevado</span>
          </label>
          <label class="sev-label" :class="{ active: form.notify_severities.includes('critical') }">
            <input type="checkbox" value="critical" v-model="form.notify_severities" />
            <span class="badge badge-danger">critical</span>
            <span class="sev-desc">Umbrales críticos superados</span>
          </label>
        </div>
        <p v-if="form.notify_severities.length === 0" class="field-hint" style="color:var(--danger);margin-top:6px">
          ⚠ Sin severidades seleccionadas no se enviará ningún email.
        </p>
      </div>

      <!-- ── Botón guardar ─────────────────────────────────────────── -->
      <div style="display:flex;justify-content:flex-end">
        <button class="btn btn-primary" @click="save" :disabled="saving">
          {{ saving ? 'Guardando…' : 'Guardar configuración' }}
        </button>
      </div>

    </template>
  </div>
</template>

<!--
  SettingsView.vue — Configuración de email de alertas
  Gestiona toda la configuración de email desde el panel, sin tocar archivos del servidor:
  - Servidor SMTP completo (host, puerto, usuario, contraseña, cifrado)
  - Remitente (from_address, from_name)
  - Destinatarios (lista de emails, soporte multi-destinatario)
  - Severidades que disparan email (info / warning / critical)
-->
<script setup>
import { ref, watch, onMounted } from 'vue'
import { panelApi } from '@/services/api'

// Puerto estándar recomendado por tipo de cifrado
const DEFAULT_PORTS = { tls: 587, ssl: 465, none: 25 }

const loading      = ref(true)
const saving       = ref(false)
const saved        = ref(false)
const error        = ref('')
const showPass     = ref(false)
const newRecipient = ref('')
const recipientError = ref('')

const form = ref({
  smtp_host:         '',
  smtp_port:         587,
  smtp_username:     '',
  smtp_password:     '',
  smtp_encryption:   'tls',
  from_address:      '',
  from_name:         'SysMon',
  recipients:        [],
  notify_severities: ['warning', 'critical'],
})

// Al cambiar el cifrado, actualizar el puerto al valor estándar recomendado
watch(() => form.value.smtp_encryption, (enc) => {
  form.value.smtp_port = DEFAULT_PORTS[enc] ?? 587
})

function addRecipient() {
  recipientError.value = ''
  const email = newRecipient.value.trim()
  if (!email) return
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    recipientError.value = 'Email no válido.'
    return
  }
  if (form.value.recipients.includes(email)) {
    recipientError.value = 'Este email ya está en la lista.'
    return
  }
  form.value.recipients.push(email)
  newRecipient.value = ''
}

function removeRecipient(idx) {
  form.value.recipients.splice(idx, 1)
}

async function load() {
  loading.value = true
  try {
    const { data } = await panelApi.getEmailSettings()
    form.value.smtp_host         = data.smtp_host         ?? ''
    form.value.smtp_port         = data.smtp_port         ?? 587
    form.value.smtp_username     = data.smtp_username     ?? ''
    form.value.smtp_password     = ''   // no precargamos la contraseña por seguridad
    form.value.smtp_encryption   = data.smtp_encryption   ?? 'tls'
    form.value.from_address      = data.from_address      ?? ''
    form.value.from_name         = data.from_name         ?? 'SysMon'
    form.value.recipients        = data.recipients        ?? []
    form.value.notify_severities = data.notify_severities ?? ['warning', 'critical']
  } catch (e) {
    error.value = 'Error cargando la configuración.'
  } finally {
    loading.value = false
  }
}

async function save() {
  saved.value = false
  error.value = ''
  saving.value = true
  try {
    await panelApi.updateEmailSettings(form.value)
    saved.value = true
    form.value.smtp_password = ''   // limpiar campo tras guardar
    setTimeout(() => { saved.value = false }, 4000)
  } catch (e) {
    error.value = e?.response?.data?.message ?? 'Error al guardar. Comprueba los campos.'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.settings-view { display: flex; flex-direction: column; gap: 18px; }
.view-header h2 { font-size: 18px; }

.section { padding: 20px 24px; display: flex; flex-direction: column; gap: 14px; }
.section-title {
  font-size: 13px; font-weight: 700; color: var(--text-bright);
  display: flex; align-items: center; gap: 6px;
}
.section-icon { color: var(--accent); font-size: 14px; }
.section-desc { font-size: 11px; color: var(--text-muted); margin: 0; }
.link { color: var(--accent); text-decoration: none; }
.link:hover { text-decoration: underline; }

/* Form grids */
.form-grid   { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 120px; gap: 12px; }
.field-hint  { font-size: 10px; color: var(--text-muted); margin-top: 4px; }

/* Password toggle */
.show-pass-btn {
  position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer; font-size: 14px; padding: 0; line-height: 1;
}

/* Recipients */
.recipients-list { display: flex; flex-direction: column; gap: 6px; }
.recipient-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 8px 12px;
  background: rgba(0,212,255,0.04);
  border: 1px solid rgba(0,212,255,0.12);
  border-radius: var(--radius);
}
.recipient-email { font-size: 12px; color: var(--text-bright); font-family: var(--font-mono); }
.no-recipients { font-size: 11px; color: var(--text-muted); font-style: italic; padding: 8px 0; }
.add-recipient { display: flex; gap: 8px; align-items: center; }
.add-recipient .input { flex: 1; }

/* Severity checkboxes */
.sev-checks { display: flex; flex-direction: column; gap: 8px; }
.sev-label {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 14px;
  border: 1px solid var(--border); border-radius: var(--radius);
  cursor: pointer;
  transition: border-color var(--transition), background var(--transition);
}
.sev-label input[type="checkbox"] { width: auto; accent-color: var(--accent); cursor: pointer; }
.sev-label.active { border-color: rgba(0,212,255,0.3); background: rgba(0,212,255,0.04); }
.sev-desc { font-size: 11px; color: var(--text-muted); }

/* Banners */
.banner { padding: 10px 16px; border-radius: var(--radius); font-size: 12px; font-weight: 600; }
.banner-ok  { background: rgba(0,255,136,0.08); border: 1px solid rgba(0,255,136,0.2); color: var(--accent2); }
.banner-err { background: rgba(255,77,77,0.08);  border: 1px solid rgba(255,77,77,0.2);  color: var(--danger); }
</style>

<?php defined('ABSPATH') || exit;
$is_edit = !empty($editing);
$prefill = $prefill ?? [];
$action  = $is_edit ? 'lls_update' : 'lls_create';
$nonce   = $is_edit ? 'lls_update' : 'lls_create';
$plan    = $is_edit ? $editing['plan'] : ($prefill['plan'] ?? 'starter');
$field   = fn($k, $default = '') => $editing[$k] ?? $prefill[$k] ?? $default;
?>
<div class="wrap lls-wrap">
  <h1 class="lls-title">
    <span class="lls-logo">🔑</span>
    <?= $is_edit ? 'Editar Licencia' : 'Nueva Licencia' ?>
    <a href="<?= admin_url('admin.php?page=luna-licenses') ?>" class="lls-btn lls-btn-ghost" style="float:right">← Volver</a>
  </h1>

  <?php if ($is_edit): ?>
    <div class="lls-notice lls-notice-info">
      Clave: <strong><?= esc_html($editing['license_key']) ?></strong>
      &nbsp;
      <button onclick="navigator.clipboard.writeText('<?= esc_attr($editing['license_key']) ?>');this.textContent='✓ Copiada!';setTimeout(()=>this.textContent='Copiar clave',1500)" class="lls-btn lls-btn-xs lls-btn-secondary">Copiar clave</button>
    </div>
  <?php endif; ?>

  <div class="lls-form-wrap">
    <form method="post" action="<?= admin_url('admin-post.php') ?>" class="lls-form">
      <?php wp_nonce_field($nonce); ?>
      <input type="hidden" name="action" value="<?= esc_attr($action) ?>">
      <?php if ($is_edit): ?>
        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
      <?php elseif (!empty($prefill['request_id'])): ?>
        <input type="hidden" name="request_id" value="<?= (int)$prefill['request_id'] ?>">
      <?php endif; ?>

      <?php if (!empty($prefill['request_id'])): ?>
        <div class="lls-notice lls-notice-info">Completando desde una solicitud recibida — revisá los datos antes de crear la licencia.</div>
      <?php endif; ?>

      <div class="lls-form-grid">

        <div class="lls-field">
          <label class="lls-label">Nombre del cliente <span class="lls-req">*</span></label>
          <input type="text" name="customer_name" value="<?= esc_attr($field('customer_name')) ?>" class="lls-input" required placeholder="Ej: Juan García">
        </div>

        <div class="lls-field">
          <label class="lls-label">Email del cliente</label>
          <input type="email" name="customer_email" value="<?= esc_attr($field('customer_email')) ?>" class="lls-input" placeholder="cliente@email.com">
        </div>

        <div class="lls-field lls-field-full">
          <label class="lls-label">Dominio autorizado <span class="lls-req">*</span></label>
          <input type="text" name="domain" value="<?= esc_attr($field('domain')) ?>" class="lls-input" required
                 placeholder="ej: miempresa.com (sin https:// ni www)">
          <span class="lls-hint">El plugin verificará que se instale <strong>solo</strong> en este dominio. Ingresá solo el dominio raíz.</span>
        </div>

        <div class="lls-field">
          <label class="lls-label">Plan</label>
          <select name="plan" class="lls-input lls-sel" id="lls-plan-sel" onchange="llsUpdatePlan(this.value)">
            <?php foreach (LLS_License::PLANS as $pk => $pv): ?>
              <option value="<?= esc_attr($pk) ?>" <?= selected($plan, $pk, false) ?>>
                <?= esc_html($pv['label']) ?> — <?= $pv['max_workspaces'] == 999 ? 'Ilimitado' : $pv['max_workspaces'] ?> workspace<?= $pv['max_workspaces']!=1?'s':'' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($is_edit): ?>
        <div class="lls-field">
          <label class="lls-label">Estado</label>
          <select name="status" class="lls-input lls-sel">
            <option value="active"    <?= selected($editing['status'],'active',false) ?>>Activa</option>
            <option value="inactive"  <?= selected($editing['status'],'inactive',false) ?>>Inactiva</option>
            <option value="suspended" <?= selected($editing['status'],'suspended',false) ?>>Suspendida</option>
          </select>
        </div>
        <?php endif; ?>

        <div class="lls-field">
          <label class="lls-label">Fecha de vencimiento</label>
          <input type="date" name="expires_at" value="<?= esc_attr($field('expires_at')) ?>" class="lls-input">
          <span class="lls-hint">Dejá vacío para licencia sin vencimiento.</span>
        </div>

        <div class="lls-field lls-field-full">
          <label class="lls-label">Notas internas</label>
          <textarea name="notes" class="lls-input" rows="3" placeholder="Notas sobre esta licencia, pedido, condiciones, etc."><?= esc_textarea($field('notes')) ?></textarea>
        </div>

      </div>

      <!-- Plan summary card -->
      <div class="lls-plan-card" id="lls-plan-card">
        <?php $caps = LLS_License::PLANS[$plan]; ?>
        <div class="lls-plan-card-title" id="lls-pc-title"><?= esc_html(LLS_License::plan_label($plan)) ?></div>
        <div class="lls-plan-card-row"><span>Workspaces</span><strong id="lls-pc-ws"><?= $caps['max_workspaces'] == 999 ? 'Ilimitados' : $caps['max_workspaces'] ?></strong></div>
        <div class="lls-plan-card-row"><span>Sitios autorizados</span><strong id="lls-pc-sites"><?= $caps['max_sites'] == 999 ? 'Ilimitados' : $caps['max_sites'] ?></strong></div>
        <div class="lls-plan-card-row"><span>Usuarios</span><strong id="lls-pc-users"><?= $caps['max_users'] == 999 ? 'Ilimitados' : $caps['max_users'] ?></strong></div>
      </div>

      <div class="lls-form-footer">
        <button type="submit" class="lls-btn lls-btn-primary lls-btn-lg">
          <?= $is_edit ? '💾 Guardar cambios' : '🔑 Crear licencia' ?>
        </button>
        <a href="<?= admin_url('admin.php?page=luna-licenses') ?>" class="lls-btn lls-btn-ghost">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<script>
const llsPlans = <?= json_encode(LLS_License::PLANS) ?>;
function llsUpdatePlan(plan) {
  const p = llsPlans[plan] || llsPlans['starter'];
  document.getElementById('lls-pc-title').textContent = plan.charAt(0).toUpperCase() + plan.slice(1);
  document.getElementById('lls-pc-ws').textContent    = p.max_workspaces >= 999 ? 'Ilimitados' : p.max_workspaces;
  document.getElementById('lls-pc-sites').textContent = p.max_sites      >= 999 ? 'Ilimitados' : p.max_sites;
  document.getElementById('lls-pc-users').textContent = p.max_users      >= 999 ? 'Ilimitados' : p.max_users;
}
</script>

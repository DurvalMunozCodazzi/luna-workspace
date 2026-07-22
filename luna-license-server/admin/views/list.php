<?php defined('ABSPATH') || exit; ?>
<div class="wrap lls-wrap">
  <h1 class="lls-title">
    <span class="lls-logo">🔑</span> Luna Licenses
    <a href="<?= admin_url('admin.php?page=luna-licenses-new') ?>" class="lls-btn lls-btn-primary" style="float:right">+ Nueva Licencia</a>
  </h1>

  <?php if (isset($_GET['msg'])): ?>
    <?php $msgs = ['created'=>'Licencia creada correctamente.','updated'=>'Licencia actualizada.','deleted'=>'Licencia eliminada.','error'=>'Error al guardar la licencia.']; ?>
    <div class="lls-notice <?= $_GET['msg']==='error'?'lls-notice-error':'lls-notice-success' ?>">
      <?= esc_html($msgs[$_GET['msg']] ?? 'Operación realizada.') ?>
      <?php if ($_GET['msg']==='error' && !empty($_GET['dberr'])): ?>
        <br><small><strong>Detalle:</strong> <?= esc_html(urldecode($_GET['dberr'])) ?></small>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Stats -->
  <?php
    global $wpdb; $t = $wpdb->prefix.'lls_licenses';
    $stats = $wpdb->get_row("SELECT
      COUNT(*) total,
      SUM(status='active') active,
      SUM(status='inactive') inactive,
      SUM(status='suspended') suspended,
      SUM(expires_at IS NOT NULL AND expires_at < CURDATE()) expired
      FROM `{$t}`", ARRAY_A);
  ?>
  <div class="lls-stats">
    <div class="lls-stat"><span class="lls-stat-n"><?= (int)$stats['total'] ?></span><span class="lls-stat-l">Total</span></div>
    <div class="lls-stat lls-stat-ok"><span class="lls-stat-n"><?= (int)$stats['active'] ?></span><span class="lls-stat-l">Activas</span></div>
    <div class="lls-stat lls-stat-wa"><span class="lls-stat-n"><?= (int)$stats['inactive'] ?></span><span class="lls-stat-l">Inactivas</span></div>
    <div class="lls-stat lls-stat-er"><span class="lls-stat-n"><?= (int)$stats['suspended'] ?></span><span class="lls-stat-l">Suspendidas</span></div>
    <div class="lls-stat lls-stat-er"><span class="lls-stat-n"><?= (int)$stats['expired'] ?></span><span class="lls-stat-l">Expiradas</span></div>
  </div>

  <!-- Filters -->
  <form method="get" class="lls-filters">
    <input type="hidden" name="page" value="luna-licenses">
    <input type="text" name="s" value="<?= esc_attr($search) ?>" placeholder="Buscar por clave, nombre, email o dominio…" class="lls-input lls-search">
    <select name="status" class="lls-input lls-sel">
      <option value="">Todos los estados</option>
      <option value="active"    <?= selected($status,'active',false) ?>>Activas</option>
      <option value="inactive"  <?= selected($status,'inactive',false) ?>>Inactivas</option>
      <option value="suspended" <?= selected($status,'suspended',false) ?>>Suspendidas</option>
    </select>
    <button type="submit" class="lls-btn lls-btn-secondary">Filtrar</button>
    <?php if ($search || $status): ?>
      <a href="<?= admin_url('admin.php?page=luna-licenses') ?>" class="lls-btn lls-btn-ghost">✕ Limpiar</a>
    <?php endif; ?>
  </form>

  <!-- Table -->
  <div class="lls-table-wrap">
    <table class="lls-table">
      <thead>
        <tr>
          <th>Clave</th>
          <th>Cliente</th>
          <th>Dominio</th>
          <th>Plan</th>
          <th>Estado</th>
          <th>Vencimiento</th>
          <th>Creada</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($data['rows'])): ?>
          <tr><td colspan="8" class="lls-empty">No se encontraron licencias.</td></tr>
        <?php else: foreach ($data['rows'] as $lic):
          $exp = $lic['expires_at'];
          $expired = $exp && $exp < date('Y-m-d');
          $status_label = ['active'=>'Activa','inactive'=>'Inactiva','suspended'=>'Suspendida'][$lic['status']] ?? $lic['status'];
          $status_cls   = ['active'=>'ok','inactive'=>'wa','suspended'=>'er'][$lic['status']] ?? '';
        ?>
          <tr>
            <td>
              <span class="lls-key" onclick="navigator.clipboard.writeText('<?= esc_attr($lic['license_key']) ?>');this.textContent='✓ Copiada!';setTimeout(()=>this.textContent='<?= esc_attr($lic['license_key']) ?>',1500)" title="Clic para copiar">
                <?= esc_html($lic['license_key']) ?>
              </span>
            </td>
            <td>
              <strong><?= esc_html(($lic['customer_name'] ?? '') ?: '—') ?></strong>
              <?php if (!empty($lic['customer_email'])): ?>
                <br><span class="lls-meta"><?= esc_html($lic['customer_email']) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($lic['domain'])): ?>
                <span class="lls-domain"><?= esc_html($lic['domain']) ?></span>
              <?php else: ?>
                <span class="lls-meta">Sin dominio</span>
              <?php endif; ?>
            </td>
            <td><span class="lls-plan lls-plan-<?= esc_attr($lic['plan'] ?? '') ?>"><?= esc_html(LLS_License::plan_label($lic['plan'] ?? '')) ?></span></td>
            <td>
              <span class="lls-badge lls-badge-<?= $status_cls ?>">
                <?= esc_html($status_label) ?><?= ($expired && $lic['status']==='active') ? ' · Expirada' : '' ?>
              </span>
            </td>
            <td><?= $exp ? esc_html($exp) : '<span class="lls-meta">Sin límite</span>' ?></td>
            <td><span class="lls-meta"><?= esc_html(substr($lic['created_at'] ?? '',0,10)) ?></span></td>
            <td class="lls-actions">
              <a href="<?= admin_url('admin.php?page=luna-licenses-new&edit='.urlencode($lic['license_key'])) ?>" class="lls-btn lls-btn-xs lls-btn-secondary">Editar</a>
              <!-- Toggle active/inactive -->
              <form method="post" action="<?= admin_url('admin-post.php') ?>" style="display:inline">
                <?php wp_nonce_field('lls_toggle'); ?>
                <input type="hidden" name="action" value="lls_toggle">
                <input type="hidden" name="id"     value="<?= (int)$lic['id'] ?>">
                <input type="hidden" name="status"  value="<?= esc_attr($lic['status']) ?>">
                <button type="submit" class="lls-btn lls-btn-xs <?= $lic['status']==='active'?'lls-btn-warning':'lls-btn-ghost' ?>">
                  <?= $lic['status']==='active' ? 'Desactivar' : 'Activar' ?>
                </button>
              </form>
              <a href="<?= admin_url('admin.php?page=luna-licenses-log&key='.urlencode($lic['license_key'])) ?>" class="lls-btn lls-btn-xs lls-btn-ghost">Log</a>
              <!-- Delete -->
              <form method="post" action="<?= admin_url('admin-post.php') ?>" style="display:inline" onsubmit="return confirm('¿Eliminar esta licencia? Esta acción no se puede deshacer.')">
                <?php wp_nonce_field('lls_delete'); ?>
                <input type="hidden" name="action" value="lls_delete">
                <input type="hidden" name="id"     value="<?= (int)$lic['id'] ?>">
                <button type="submit" class="lls-btn lls-btn-xs lls-btn-danger">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($data['total'] > 25):
    $total_pages = ceil($data['total'] / 25);
    $base_url = admin_url('admin.php?page=luna-licenses' . ($search?"&s=".urlencode($search):'') . ($status?"&status=".urlencode($status):''));
  ?>
    <div class="lls-pagination">
      <?php for ($p = 1; $p <= $total_pages; $p++): ?>
        <a href="<?= $base_url ?>&paged=<?= $p ?>" class="lls-btn lls-btn-xs <?= $p===$page?'lls-btn-primary':'lls-btn-ghost' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <span class="lls-meta"><?= $data['total'] ?> licencias en total</span>
    </div>
  <?php endif; ?>
</div>

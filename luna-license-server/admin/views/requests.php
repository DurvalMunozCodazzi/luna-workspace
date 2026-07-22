<?php defined('ABSPATH') || exit; ?>
<div class="wrap lls-wrap">
  <h1 class="lls-title">
    <span class="lls-logo">📬</span> Solicitudes
  </h1>

  <?php if (isset($_GET['msg'])): ?>
    <?php $msgs = ['rejected' => 'Solicitud rechazada.', 'created' => 'Licencia creada — solicitud marcada como atendida.']; ?>
    <div class="lls-notice lls-notice-success"><?= esc_html($msgs[$_GET['msg']] ?? 'Operación realizada.') ?></div>
  <?php endif; ?>

  <!-- Filters -->
  <form method="get" class="lls-filters">
    <input type="hidden" name="page" value="luna-licenses-requests">
    <select name="status" class="lls-input lls-sel" onchange="this.form.submit()">
      <option value="pending"  <?= selected($status, 'pending', false) ?>>Pendientes</option>
      <option value="sent"     <?= selected($status, 'sent', false) ?>>Atendidas</option>
      <option value="rejected" <?= selected($status, 'rejected', false) ?>>Rechazadas</option>
      <option value=""         <?= selected($status, '', false) ?>>Todas</option>
    </select>
  </form>

  <!-- Table -->
  <div class="lls-table-wrap">
    <table class="lls-table">
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Email</th>
          <th>Teléfono</th>
          <th>Dominio</th>
          <th>Plan</th>
          <th>Recibida</th>
          <th>Estado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="8" class="lls-empty">No hay solicitudes<?= $status ? ' con este estado' : '' ?>.</td></tr>
        <?php else: foreach ($rows as $req):
          $status_label = ['pending' => 'Pendiente', 'sent' => 'Atendida', 'rejected' => 'Rechazada'][$req['status']] ?? $req['status'];
          $status_cls   = ['pending' => 'wa', 'sent' => 'ok', 'rejected' => 'er'][$req['status']] ?? '';
          // Solicitudes viejas (de antes de que el formulario tuviera plan) pueden
          // no tener esta columna cargada — no asumir que siempre está.
          $req_plan     = $req['plan'] ?? 'free';
        ?>
          <tr>
            <td><strong><?= esc_html($req['nombre']) ?></strong></td>
            <td><?= esc_html($req['email']) ?></td>
            <td><?= esc_html($req['telefono']) ?></td>
            <td><span class="lls-domain"><?= esc_html($req['dominio']) ?></span></td>
            <td>
              <span class="lls-plan lls-plan-<?= esc_attr($req_plan) ?>"><?= esc_html(LLS_License::plan_label($req_plan)) ?></span>
              <?php if ($req_plan !== 'free' && $req['status'] === 'pending'): ?>
                <br><small style="color:#c62828">⏳ Verificar comprobante antes de aprobar</small>
              <?php endif; ?>
            </td>
            <td><span class="lls-meta"><?= esc_html(substr($req['created_at'], 0, 16)) ?></span></td>
            <td><span class="lls-badge lls-badge-<?= $status_cls ?>"><?= esc_html($status_label) ?></span></td>
            <td class="lls-actions">
              <?php if ($req['status'] === 'pending'): ?>
                <a href="<?= admin_url('admin.php?page=luna-licenses-new&from_request=' . (int)$req['id']) ?>" class="lls-btn lls-btn-xs lls-btn-primary">✅ Crear licencia</a>
                <form method="post" action="<?= admin_url('admin-post.php') ?>" style="display:inline" onsubmit="return confirm('¿Rechazar esta solicitud?')">
                  <?php wp_nonce_field('lls_reject_request'); ?>
                  <input type="hidden" name="action" value="lls_reject_request">
                  <input type="hidden" name="id" value="<?= (int)$req['id'] ?>">
                  <button type="submit" class="lls-btn lls-btn-xs lls-btn-ghost">✕ Rechazar</button>
                </form>
              <?php else: ?>
                <span class="lls-meta">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php defined('ABSPATH') || exit; ?>
<div class="wrap lls-wrap">
  <h1 class="lls-title"><span class="lls-logo">⚙️</span> Configuración</h1>
  <div class="lls-form-wrap" style="max-width:600px">
    <form method="post" class="lls-form">
      <?php wp_nonce_field('lls_settings', 'lls_settings_nonce'); ?>
      <div class="lls-field">
        <label class="lls-label">Endpoint de verificación (solo lectura)</label>
        <input type="text" class="lls-input" value="<?= esc_url(rest_url('luna-licenses/v1/verify')) ?>" readonly onclick="this.select()">
        <span class="lls-hint">Este es el URL que debe configurarse en el plugin Luna Workspace del cliente.</span>
      </div>
      <div class="lls-field">
        <label class="lls-label">Clave Privada RSA <span style="color:#c62828">&#9888;&#65039; Solo en este servidor — nunca compartir</span></label>
        <textarea class="lls-input lls-mono" name="lls_private_key" rows="10"
                  style="font-size:11px;resize:vertical"
                  placeholder="-----BEGIN PRIVATE KEY-----"><?= esc_textarea(get_option('lls_private_key','')) ?></textarea>
        <span class="lls-hint">
          <?php if ($private_key_set): ?>
            <span style="color:#2e7d32">&#10003; Clave privada configurada.</span>
          <?php else: ?>
            <span style="color:#c62828">Sin clave privada — las licencias no firmadas con RSA.</span>
          <?php endif; ?>
          Pega aqui la clave privada RSA generada. La clave publica va en el plugin.
        </span>
      </div>
      <div class="lls-form-footer">
        <button type="submit" class="lls-btn lls-btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

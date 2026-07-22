<?php defined('ABSPATH') || exit;
$meta        = get_option('lls_banners_meta', []);
$upgrade_url = $meta['upgrade_url']  ?? 'https://websobreruedas.com/luna-planes';
$upgrade_txt = $meta['upgrade_text'] ?? '⚡ Quitar anuncios';
$json_path   = ABSPATH . 'luna-ads.json';
$json_url    = home_url('/luna-ads.json');
// Pad banners array to always show 5 slots
while (count($banners) < 5) {
    $banners[] = ['text'=>'','img'=>'','link'=>'https://websobreruedas.com/luna-planes','cta'=>'Ver →','active'=>false];
}
?>
<div class="wrap lls-wrap">
  <h1 class="lls-title"><span class="lls-logo">📢</span> Banners — Plan Gratuito</h1>

  <?php if ($msg === 'saved'): ?>
    <div class="notice notice-success is-dismissible"><p>✅ Banners guardados y <code>luna-ads.json</code> actualizado.</p></div>
  <?php endif; ?>

  <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">

    <!-- FORM -->
    <div class="lls-form-wrap" style="flex:1;min-width:520px">
      <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
        <?php wp_nonce_field('lls_save_banners'); ?>
        <input type="hidden" name="action" value="lls_save_banners">

        <!-- Banners -->
        <?php foreach ($banners as $i => $b): ?>
        <div class="lls-form-section" style="margin-bottom:20px;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden">
          <div style="background:#f8f8f8;padding:10px 16px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #e0e0e0">
            <label style="display:flex;align-items:center;gap:8px;font-weight:600;margin:0;cursor:pointer">
              <input type="checkbox" name="banner_active[<?= $i ?>]" value="1" <?= !empty($b['active']) ? 'checked' : '' ?>>
              Banner <?= $i + 1 ?>
            </label>
            <span style="font-size:11px;color:#999;margin-left:auto"><?= !empty($b['active']) ? '<span style="color:#2e7d32">● Activo</span>' : '<span style="color:#999">○ Inactivo</span>' ?></span>
          </div>
          <div style="padding:14px 16px;display:grid;gap:10px">
            <div class="lls-field" style="margin:0">
              <label class="lls-label">Texto del banner</label>
              <input type="text" class="lls-input" name="banner_text[<?= $i ?>]"
                     value="<?= esc_attr($b['text']) ?>"
                     placeholder="Ej: Actualizá al plan Básico — desde $19/mes"
                     maxlength="120">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
              <div class="lls-field" style="margin:0">
                <label class="lls-label">URL imagen <span style="font-weight:400;color:#999">(opcional)</span></label>
                <input type="url" class="lls-input" name="banner_img[<?= $i ?>]"
                       value="<?= esc_attr($b['img']) ?>"
                       placeholder="https://...imagen.jpg">
              </div>
              <div class="lls-field" style="margin:0">
                <label class="lls-label">Texto del botón</label>
                <input type="text" class="lls-input" name="banner_cta[<?= $i ?>]"
                       value="<?= esc_attr($b['cta'] ?: 'Ver más →') ?>"
                       placeholder="Ver planes →" maxlength="30">
              </div>
            </div>
            <div class="lls-field" style="margin:0">
              <label class="lls-label">Enlace del banner</label>
              <input type="url" class="lls-input" name="banner_link[<?= $i ?>]"
                     value="<?= esc_attr($b['link'] ?: 'https://websobreruedas.com/luna-planes') ?>"
                     placeholder="https://websobreruedas.com/luna-planes">
            </div>
          </div>
        </div>
        <?php endforeach; ?>

        <!-- CTA global -->
        <div class="lls-form-section" style="border:1px solid #5b6af0;border-radius:8px;padding:14px 16px;margin-bottom:20px;background:#f8f8ff">
          <div style="font-weight:700;margin-bottom:12px;color:#5b6af0">Botón "Quitar anuncios" (siempre visible)</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div class="lls-field" style="margin:0">
              <label class="lls-label">Texto del botón</label>
              <input type="text" class="lls-input" name="upgrade_text"
                     value="<?= esc_attr($upgrade_txt) ?>" maxlength="40">
            </div>
            <div class="lls-field" style="margin:0">
              <label class="lls-label">URL destino</label>
              <input type="url" class="lls-input" name="upgrade_url"
                     value="<?= esc_attr($upgrade_url) ?>">
            </div>
          </div>
        </div>

        <div class="lls-form-footer">
          <button type="submit" class="lls-btn lls-btn-primary">💾 Guardar y publicar</button>
        </div>
      </form>
    </div>

    <!-- SIDEBAR INFO -->
    <div style="width:260px;flex-shrink:0">
      <div style="background:#f8f8f8;border:1px solid #e0e0e0;border-radius:8px;padding:16px;font-size:13px">
        <div style="font-weight:700;margin-bottom:10px">📡 Archivo público</div>
        <code style="word-break:break-all;font-size:11px;display:block;background:#fff;padding:8px;border-radius:4px;border:1px solid #ddd;margin-bottom:8px"><?= esc_html($json_url) ?></code>
        <?php if (file_exists($json_path)): ?>
          <span style="color:#2e7d32;font-weight:600">✅ Archivo generado</span><br>
          <small style="color:#666">Modificado: <?= date('d/m/Y H:i', filemtime($json_path)) ?></small>
        <?php else: ?>
          <span style="color:#c62828">⚠️ Aún no generado — guardá para crearlo</span>
        <?php endif; ?>

        <hr style="margin:14px 0;border-color:#e0e0e0">
        <div style="font-weight:700;margin-bottom:8px">🔄 Rotación</div>
        <p style="color:#555;margin:0">Los banners activos rotan cada <strong>9 segundos</strong> en la app de cada cliente gratuito.</p>

        <hr style="margin:14px 0;border-color:#e0e0e0">
        <div style="font-weight:700;margin-bottom:8px">💡 Imágenes</div>
        <p style="color:#555;margin:0">Subí la imagen desde <a href="<?= admin_url('media-new.php') ?>">Medios → Añadir</a>, copiá la URL y pegala en el campo de imagen del banner.</p>

        <hr style="margin:14px 0;border-color:#e0e0e0">
        <div style="font-weight:700;margin-bottom:8px">⚡ Caché</div>
        <p style="color:#555;margin:0">Agregá esto a tu <code>.htaccess</code> para que los clientes cacheen el JSON 24h:</p>
        <pre style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:8px;font-size:10px;overflow-x:auto;margin-top:6px">&lt;Files "luna-ads.json"&gt;
  Header set Cache-Control
    "public, max-age=86400"
&lt;/Files&gt;</pre>
      </div>
    </div>

  </div>
</div>

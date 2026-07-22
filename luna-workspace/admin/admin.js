jQuery(function ($) {
    // Save license key
    $('#luna-save-license').on('click', function () {
        var key = $('#luna-license-input').val().trim();
        var $btn = $(this);
        $btn.text('Guardando...').prop('disabled', true);
        $.post(lunaAdmin.ajaxUrl, {
            action: 'luna_save_license',
            nonce: lunaAdmin.nonce,
            license_key: key
        }).done(function (res) {
            if (res.success) {
                verifyAndShow(key);
            } else {
                showResult('err', '❌ ' + (res.data.message || 'Error al guardar'));
            }
        }).fail(function () {
            showResult('err', '❌ Error de conexión');
        }).always(function () {
            $btn.text('Guardar y verificar').prop('disabled', false);
        });
    });

    // Just verify without saving
    $('#luna-check-license').on('click', function () {
        var key = $('#luna-license-input').val().trim();
        verifyAndShow(key);
    });

    function verifyAndShow(key) {
        var $result = $('#luna-license-result');
        $result.show().html('<span style="color:#888">Verificando...</span>');
        $.post(lunaAdmin.ajaxUrl, {
            action: 'luna_check_license_status',
            nonce: lunaAdmin.nonce,
            license_key: key
        }).done(function (data) {
            if (data.valid) {
                var plan  = data.plan  || '—';
                var seats = data.max_workspaces >= 999 ? 'Ilimitado' : data.max_workspaces;
                var exp   = data.expires_at || '—';
                var grace = data.grace ? '<br>⚠️ Vencida — quedan ' + data.grace_days + ' días de gracia.' : '';
                var cls   = data.grace ? 'luna-warn' : 'luna-ok';
                showResult(cls, '✅ Licencia válida — Plan: <strong>' + plan + '</strong> · Workspaces: ' + seats + ' · Vence: ' + exp + grace);
            } else {
                var msgs = {
                    'expired':        '❌ Licencia vencida. Renovála para continuar.',
                    'suspended':      '❌ Licencia suspendida. Contactá soporte.',
                    'site_limit':     '❌ Límite de sitios alcanzado para esta licencia.',
                    'domain_mismatch':'❌ Esta licencia no está autorizada para este dominio. Contactá al vendedor.',
                    'not_found':      '❌ Clave de licencia no encontrada. Verificá que la clave esté bien escrita.',
                    'no_key':         '⚠️ No ingresaste una clave de licencia.',
                    'bad_response':   '⚠️ No se pudo contactar el servidor de licencias. Verificá la URL configurada.',
                };
                showResult('err', msgs[data.reason] || '❌ ' + (data.message || 'Licencia inválida'));
            }
        }).fail(function () {
            showResult('warn', '⚠️ No se pudo conectar al servidor de licencias.');
        });
    }

    function showResult(type, html) {
        $('#luna-license-result').show().html('<div class="luna-' + type + '">' + html + '</div>');
    }
});

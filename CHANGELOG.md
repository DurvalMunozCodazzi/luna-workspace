# Changelog — Luna Workspace

Reconstruido el 2026-07-22 a partir de los .zip de cada versión (no había
historial de git previo). Formato [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/),
versionado [Semántico](https://semver.org/lang/es/).

## [11.1.94] - actual

Versión con la que se inicializó este repo. Acumula todo lo de abajo.

### Corregido (respecto a 11.1.93)
- Paneles laterales (detalle de tarjeta, tema, workspaces, notificaciones,
  calendario, SMTP, API/webhooks, etiquetas globales) quedaban tapados por
  el banner de "actualizá tu plan" en el plan Gratis — se ajustó su
  posición para no superponerse (`top: calc(var(--th) + var(--ads-h))`).

## [11.1.93]

### Corregido
- **Bug de borrado de usuario**: si se tocaba "Guardar" mientras el popup de
  confirmación de borrado seguía abierto, `editUserId` ya estaba en `null`
  (lo pisaba el cierre del modal) y el pedido de borrado mandaba `id=null`.
  Se fijó el id objetivo al momento del click, en frontend y backend
  (`users.php` ahora también valida que llegue un id).

## [11.1.91]

### Cambiado
- Selector de "Cliente" en la tarjeta de cobros: reemplaza el `<select>`
  simple por un popup con buscador (mejor UX para listas largas de
  clientes).

## [11.1.90]

### Seguridad — corregido (**no estaba en producción al momento de este changelog**)
- **`auth.php` exponía endpoints de diagnóstico sin ninguna autenticación**,
  agregados en algún momento para debuggear un bug de "olvidé mi
  contraseña" y nunca retirados:
  - `?action=diag_verify&login=&password=` devolvía si una contraseña era
    correcta para un usuario — oráculo de fuerza bruta público (mitigado
    solo por un rate-limit de 10 intentos/15min por IP).
  - `?action=diag_user&login=` confirmaba existencia de usuario/email, rol,
    y si tenía WhatsApp configurado.
  - `?action=diag_whatsapp&login=` enviaba un WhatsApp real a nombre del
    usuario.
  - `?action=diag_roundtrip`, `fix_password_column`, `diag_last_reset`
    escribían en la base de datos sin autenticación.
  - Se eliminaron todos, y el endpoint `diag` que quedó ahora requiere
    `requireAdmin()`.
- **IDOR en `metrics.php`**: cualquier usuario autenticado podía ver las
  métricas de cualquier workspace cambiando `?ws=`, sin verificar
  membresía. Se agregó el chequeo.
- **IDOR en `upload.php`**: se podía adjuntar un archivo a una tarjeta de un
  workspace ajeno; solo se verificaba que la tarjeta existiera, no la
  pertenencia. Se agregó el chequeo de membresía.
- **Control de acceso roto en `kanban.php`**: el rol `visitor` (pensado como
  solo lectura) podía crear/editar/borrar columnas y tarjetas — antes solo
  se bloqueaban las acciones sobre etiquetas. Ahora se bloquea cualquier
  escritura para ese rol.
- **`notifications.php`**: cualquier usuario autenticado (no solo admin)
  podía disparar el envío de WhatsApp a otros usuarios, consumiendo la
  cuota paga de la cuenta de CallMeBot. Ahora requiere rol admin.
- **`users.php`**: los campos de WhatsApp/Telegram (función de planes
  pagos) solo se validaban en el frontend; un usuario del plan Gratis podía
  setearlos llamando la API directamente. Se agregó la validación también
  en el servidor.

## [11.1.87]

### Cambiado
- Límite de usuarios por plan (`max_users`) agregado a la definición de
  cada plan (Gratis=1, Emprendedor=5, Pyme=10, Profesional=20,
  Corporativo=999) y aplicado al crear usuarios nuevos.

## [11.1.86]

Versión que estaba corriendo en producción (websobreruedas.ar) al momento
de armar este repo. Sin registro de changelog anterior a esta fecha.

# Luna Workspace — instrucciones del proyecto

Dos plugins de WordPress que trabajan juntos: `luna-workspace/` (el
producto) y `luna-license-server/` (validación de licencias). Producto de
Web Sobre Ruedas (websobreruedas.com / .ar).

## Versionado

- SemVer. La versión se mantiene sincronizada entre el header `Version:` y
  la constante `LUNA_VERSION` en `luna-workspace/luna-workspace.php` (y
  `LLS_VERSION` en `luna-license-server/luna-license-server.php` para ese
  plugin). El campo `Description:` de `luna-workspace.php` también trae un
  número de versión hardcodeado en el texto — actualizarlo junto con el
  header para que no quede desincronizado (se detectó desincronizado al
  armar este repo).
- Cada cambio funcional agrega una entrada nueva en `CHANGELOG.md` (raíz
  del repo) y bumpea la versión: PATCH para fixes, MINOR para
  funcionalidad nueva compatible, MAJOR si rompe algo.
- **Al entregar un plugin como .zip, el nombre del archivo debe incluir el
  número de versión** (ej. `luna-workspace-11.1.95.zip`), para no mezclar
  versiones viejas al ir iterando.

## Notas del código

- `luna-workspace/app/` es una app PHP standalone (no usa WordPress
  directamente para su lógica de negocio) montada dentro del plugin;
  `luna-workspace/includes/` es la capa que la integra a WordPress
  (activación, licencia, registro).
- Los endpoints en `app/api/*.php` son la API que consume el frontend
  (`app/index.html`, una SPA de un solo archivo). Cualquier endpoint nuevo
  ahí necesita su propio chequeo de autenticación/autorización explícito
  — no hay un guard global en `auth.php` ni en ningún otro punto de
  entrada. Ver `CHANGELOG.md` v11.1.90 para el historial de bugs de
  autorización ya encontrados y corregidos (endpoints de diagnóstico sin
  auth, IDOR por falta de chequeo de membresía al workspace, rol
  `visitor` que no era realmente de solo lectura) — repasar ese mismo tipo
  de problema en cualquier endpoint nuevo antes de darlo por terminado.
- No dejar endpoints de diagnóstico/debug (`diag_*`, `fix_*`, etc.) sin
  `requireAdmin()` ni de forma permanente en el código — si hace falta uno
  para investigar un bug puntual, sacarlo (o protegerlo) antes de cerrar
  esa tarea.
- Hay dos esquemas de firma de licencia sin conciliar entre servidor y
  cliente: `luna-license-server` firma con RSA (campo `sig`), y eso es lo
  que `luna-workspace/includes/class-luna-license.php` verifica
  correctamente. Pero `luna-workspace/app/config.php::getLicenseInfo()`
  tiene un chequeo HMAC separado y más viejo que espera un campo `hmac`
  que el servidor nunca envía — está inactivo (requiere
  `LUNA_HMAC_SECRET`, nunca definida), pero si alguien la define pensando
  que suma seguridad, rompe la verificación de licencia del lado de la
  app. Antes de tocar cualquiera de los dos, decidir si el HMAC se
  termina de implementar en el servidor o se borra del cliente — no dejar
  que seguir divergiendo.

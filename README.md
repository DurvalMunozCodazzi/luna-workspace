# Luna Workspace

Plugin de WordPress: pizarra colaborativa estilo Kanban, gestión de tareas,
equipos y proyectos. Producto de **Web Sobre Ruedas** (websobreruedas.com /
websobreruedas.ar).

Dos plugins en este repo, que trabajan juntos:

- **`luna-workspace/`** — el producto en sí (tablero Kanban, usuarios,
  workspaces, notificaciones por WhatsApp/Telegram, cobros, calendario,
  etc.). Versión actual: **11.1.94**.
- **`luna-license-server/`** — servidor de licencias que usa `luna-workspace`
  para validar claves, dominios y planes (Gratis / Emprendedor / Pyme /
  Profesional / Corporativo). Versión actual: **2.3.0**.

## ⚠️ Estado de producción vs. este repo

Al armar este repo (2026-07-22) el sitio **websobreruedas.ar** tenía
instalada la **v11.1.86**, varias versiones más vieja que la que se subió acá
(**v11.1.94**). La diferencia no es cosmética: entre la 87 y la 90 se
corrigieron varios problemas de seguridad reales que siguen activos en
producción. Ver el detalle en [`CHANGELOG.md`](CHANGELOG.md).

**Acción recomendada: actualizar el sitio a la v11.1.94 cuanto antes.**

## Instalación / actualización

Cada plugin se instala igual que cualquier plugin de WordPress: `Plugins →
Añadir nuevo → Subir plugin`, seleccionando el .zip correspondiente. `luna-workspace`
depende de `luna-license-server` (o de un servidor de licencias compatible)
para validar el plan activo.

## Versionado

Ver [`CLAUDE.md`](CLAUDE.md) para la convención de versionado de este repo.

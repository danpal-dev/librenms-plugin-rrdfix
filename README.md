# 🔧 LibreNMS Plugin · **RrdFix**

> Plugin autocontenido para LibreNMS que repara gráficas de RRD corrompidas,
> valores NaN y disponibilidad mal reportada. Diseñado para no perderse ni
> romperse con `git pull` / actualizaciones de LibreNMS.

---

## 📚 Documentación

| Archivo | Tema |
|---|---|
| [INSTALACIÓN.md](./INSTALACIÓN.md) | Instalación, actualización y **persistencia post-upgrade LibreNMS** |
| [GUÍA.md](./GUÍA.md) | **Manual del usuario final**: cómo usar la interfaz web, cada campo del formulario, casos de uso |
| [FAQ.md](./FAQ.md) | Preguntas frecuentes, errores comunes y cómo recuperarse de ellos |
| [ARQUITECTURA.md](./ARQUITECTURA.md) | Detalle técnico: hooks de LibreNMS, clases, endpoints, seguridad |

---

## 📁 Estructura (100% autocontenido — NADA fuera de este directorio)

```
app/Plugins/RrdFix/
├── Settings.php             # Hook de configuración (SettingsHook) + registro DE RUTAS del plugin
├── Menu.php                 # Hook de menú (MenuEntryHook)
├── Page.php                 # Hook de página (SinglePageHook)
├── DeviceOverview.php       # Hook de resumen por dispositivo (DeviceOverviewHook, opcional)
├── Http/
│   └── RunController.php    # Controlador: submit formulario + endpoint JSON /rrdfix/status
├── Support/
│   └── PendingRun.php       # Valida y parsea los settings pendientes desde la BD
├── resources/
│   └── views/
│       ├── menu.blade.php
│       ├── settings.blade.php
│       └── page.blade.php   # UI del panel: formulario 2 pasos + progreso + log + docs
├── rrd-fix.py               # Wrapper → runpy del script oficial
├── tests/                   # Tests autónomos del plugin
│   └── run.php
├── INSTALACIÓN.md           # Cómo instalar / actualizar / recuperar tras un upgrade LibreNMS
├── GUÍA.md                  # Manual del usuario
├── FAQ.md                   # Problemas frecuentes
├── ARQUITECTURA.md          # Documentación técnica
├── .gitignore
└── README.md                # (este archivo)
```

---

## ✅ Garantía de persistencia post actualización LibreNMS

Por regla del repo padre **`/opt/librenms`** actualiza con `git pull` y **borra o sobreescribe TODO lo que no trackee**.
Para NO perder este plugin:

1. **Este directorio (`app/Plugins/RrdFix/`) es su propio repositorio Git**.
   - LibreNMS nunca hace `git pull --recurse-submodules` por defecto, ni hace `force` en subdirs.
2. Todo el código del plugin vive DENTRO de `app/Plugins/RrdFix/`.
   - No hay cambios en `routes/web.php`, `config/*`, `app/Http/Controllers/*`, ni migraciones.
3. Las rutas, controladores y vistas se registran desde las clases hook.
4. **Si por algo se borra este directorio**, clona tu respaldo:
   ```bash
   cd /opt/librenms/app/Plugins
   git clone git@tu-repo:tu-org/RrdFix.git RrdFix
   cd RrdFix && chown -R librenms:librenms .
   ```

El paso-a-paso está en [INSTALACIÓN.md](./INSTALACIÓN.md).

# 🔧 LibreNMS Plugin · **RrdFix**

> Plugin autocontenido para LibreNMS que repara gráficas de RRD corrompidas,
> valores NaN y disponibilidad mal reportada. Diseñado para **no perderse ni
> romperse** con `git pull` / actualizaciones de LibreNMS.

---

## ⚡ QUICKSTART · Instalación en 5 minutos (desde `git clone`)

Esta es la guía rápida. La guía detallada y persistencia post-upgrade
está en **[INSTALACIÓN.md](./INSTALACIÓN.md)**.

---

### 1️⃣ Clona el repositorio DENTRO de LibreNMS

```bash
cd /opt/librenms/app/Plugins
sudo -u librenms git clone https://github.com/danpal-dev/librenms-plugin-rrdfix.git RrdFix
```

> 🔑 **Importante:** el directorio **se tiene que llamar exactamente `RrdFix`**.
> LibreNMS usa el nombre de la carpeta como nombre del plugin (`plugin_name='RrdFix'`).

---

### 2️⃣ Requisitos del sistema (comprueba antes de entrar al panel)

```bash
# PHP 8.2+, Python 3.9+, rrdtool CLI, usuario librenms existe
php --version | head -1
python3 --version
rrdtool --version | head -1
id librenms
# Todos deben devolver una línea válida
```

---

### 3️⃣ Permisos de ejecución del script Python

```bash
cd /opt/librenms/app/Plugins/RrdFix
sudo chmod +x rrd-fix.py
sudo chown -R librenms:librenms .
```

---

### 4️⃣ Sudoers (solo si PHP-FPM no corre como `librenms`)

RrdFix intenta detectar automáticamente si ya eres usuario `librenms`.
Si no lo eres (por ejemplo `www-data`), usa `sudo -n -u librenms` para
ejecutar el script — por tanto necesitas una regla en sudoers:

```bash
# Crea el archivo de sudoers (no edites /etc/sudoers a mano)
echo 'www-data ALL=(librenms) NOPASSWD: /bin/sh -lc *' | sudo tee /etc/sudoers.d/librenms-plugin
sudo chmod 0440 /etc/sudoers.d/librenms-plugin

# Verifica que NO pida contraseña:
sudo -u www-data sudo -n -u librenms -- sh -lc 'whoami'
# -> debe imprimir "librenms" sin preguntar password
```

> Omite este paso si tu `php-fpm.d` ya configura `user = librenms`
> (es el caso más habitual en instalaciones estándar de LibreNMS).

---

### 5️⃣ Habilita el plugin en la base de datos

Entra por **UI** si puedes:  
🔗 `https://TU-LIBRENMS/plugin/settings` → **RrdFix** → ✅ Activo → Guardar.

Si no ves el plugin en la lista (carga en progreso) fuerza la activación por CLI:

```bash
cd /opt/librenms
sudo -u librenms php artisan tinker --execute="
    \App\Models\Plugin::updateOrCreate(
        ['plugin_name' => 'RrdFix', 'version' => 2],
        ['plugin_active' => 1]
    );
    echo 'OK - RrdFix activado' . PHP_EOL;
"
```

---

### 6️⃣ 🧹 Limpia TOTALMENTE las cachés de Laravel (IMPORTANTE)

> Olvidar este paso causa **404 / vista no disponible** aunque el plugin
> esté bien instalado — el PluginManager no registra el namespace de
> vistas `RrdFix::resources.views.*` hasta invalidar OPcache.

```bash
cd /opt/librenms
sudo -u librenms php artisan route:clear
sudo -u librenms php artisan view:clear
sudo -u librenms php artisan cache:clear
sudo -u librenms php artisan config:clear
# o de forma resumida:
sudo -u librenms php artisan optimize:clear

# Recarga OPcache (ajusta la versión de PHP):
sudo systemctl reload php8.2-fpm || sudo systemctl reload php8.3-fpm || sudo systemctl reload php8.4-fpm
```

---

### 7️⃣ Verifica que funciona

```bash
# 1. Comprueba que el plugin aparece y está activo
cd /opt/librenms
sudo -u librenms php artisan tinker --execute="
    \$p = \App\Models\Plugin::where('plugin_name','RrdFix')->first();
    echo 'plugin_active: ' . (\$p?->plugin_active ?? 'NO ENCONTRADO') . PHP_EOL;
"

# 2. Tests rápidos del plugin (incluidos en tests/run.php)
sudo -u librenms php /opt/librenms/app/Plugins/RrdFix/tests/run.php
```

Abre en el navegador:  
🔗 **`https://TU-LIBRENMS/plugin/RrdFix`**

Deberías ver:
- Título **"Corrección de RRD"**
- Formulario con 2 pasos (Rango → Escaneo NaN opcional)
- Panel "Estado del proceso" y visor de logs
- Pestañas de documentación (Guía / FAQ / Instalación / Arquitectura)

Endpoint de estado (solo con sesión admin):  
🔗 `https://TU-LIBRENMS/rrdfix/status`  
devuelve JSON con `running`, `log_content` y `process`.

---

## 🆘 Troubleshooting inmediato

### 🔴 "404 Not Found" / "vista no disponible" al abrir `/plugin/RrdFix`

**99% de los casos es uno de estos 4 puntos:**

| # | Causa | Comprobación | Fix |
|---|---|---|---|
| 1 | Plugin desactivado por TypeError en hooks | `plugin_active = 0` en BD | Ver paso 5 (reactivar) + paso 6 (limpiar caché) |
| 2 | OPcache viejo todavía sirve hooks anteriores | | Paso 6 completo + `systemctl reload phpX.X-fpm` |
| 3 | El usuario admin no tiene gate `plugin.admin` ni `admin` | `$user->can('admin')` devuelve false | Promociona tu usuario: `UPDATE users SET role='admin' WHERE user_id=...` |
| 4 | Carpeta mal nombrada (no es `RrdFix`) | `ls /opt/librenms/app/Plugins/RrdFix/` existe? | Renombra. LibreNMS requiere el nombre exacto. |

**Diagnóstico rápido desde el servidor:**
```bash
cd /opt/librenms
# Mira el log de LibreNMS para ver el error exacto
sudo tail -n 50 storage/logs/librenms.log | grep -iE 'RrdFix|plugin|error'
```

---

### 🔴 Submit del formulario → "No se pudo iniciar rrd-fix. Revisa el log."

Revisa `/opt/librenms/storage/logs/rrd-fix.log` y el sudoers del paso 4.  
Solución casi siempre: **PHP-FPM ya corre como `librenms`** y no necesita
sudo, pero la regla de sudoers no existe o es errónea. El plugin ya detecta
esto automáticamente (vía `posix_geteuid()`), asegúrate de estar en
versión ≥ 1.1.0 (`git pull origin master`).

---

### 🔴 Log muestra "(sin salida todavía)" aunque el proceso corre

Es el **buffering de stdout de Python**. Arreglo incluido desde v1.0.1:
se ejecuta `python3 -u` (unbuffered). Si tienes versión anterior:
```bash
cd /opt/librenms/app/Plugins/RrdFix && sudo -u librenms git pull origin master
```

---

## 📚 Documentación detallada

| Archivo | Tema |
|---|---|
| **[INSTALACIÓN.md](./INSTALACIÓN.md)** | Persistencia post-upgrade LibreNMS, recuperación si se borró, actualizar solo el plugin, desinstalación |
| **[GUÍA.md](./GUÍA.md)** | Manual del usuario final: cómo usar el formulario, cada campo, casos de uso |
| **[FAQ.md](./FAQ.md)** | Problemas frecuentes con su diagnóstico y fix paso a paso |
| **[ARQUITECTURA.md](./ARQUITECTURA.md)** | Detalle técnico: hooks de LibreNMS, clases, endpoints, seguridad |

---

## 🗺 Paths canónicos (NO confundir)

> Este plugin es la **única versión mantenida** de RrdFix.
> No hay "scripts externos" fuera de este directorio — antes del 2026-09-15 existió
> un skill de TRAE en `.github/skills/rrd-fix/` con una versión standalone
> vieja del script Python; hoy **el skill fue ELIMINADO completamente** y cualquier
> referencia a paths como `.github/skills/rrd-fix/scripts/rrd-fix.py` es obsoleta.

| Propósito | Path canónico (SIEMPRE usar este) |
|---|---|
| Script CLI de corrección de RRD (Python) | `/opt/librenms/app/Plugins/RrdFix/rrd-fix.py` |
| Plugin web LibreNMS (formulario) | `/plugin/RrdFix` |
| Implementación de los hooks | `app/Plugins/RrdFix/Settings.php`, `Menu.php`, `Page.php` |
| Tests autónomos | `/opt/librenms/app/Plugins/RrdFix/tests/run.php` |
| Instalador de release de GitHub | `/opt/librenms/app/Plugins/RrdFix/scripts/install-release.sh` |
| Auditoría / diagnose plugin vs Reports | `/opt/librenms/app/Plugins/RrdFix/scripts/diagnose-plugin.php` |

**Uso CLI rápido (sin abrir la web):**
```bash
python3 /opt/librenms/app/Plugins/RrdFix/rrd-fix.py \
  --ip 10.21.21.1 \
  --start "2026-09-10 08:00:00" \
  --end   "2026-09-10 09:30:00" \
  --avail-fill 100
```

---

## 📁 Estructura (100% autocontenido — NADA fuera de este directorio)

```
app/Plugins/RrdFix/
├── Settings.php              # Hook SettingsHook → constructor registra ruta /rrdfix/status
├── Menu.php                  # Hook MenuEntryHook → entrada menú izquierdo
├── Page.php                  # Hook SinglePageHook → panel principal
├── Http/
│   └── RunController.php     # GET /rrdfix/status (JSON: PID, CPU, RAM, log)
├── Support/
│   └── PendingRun.php        # Parsea settings del formulario 2 pasos
├── resources/views/
│   ├── page.blade.php        # UI: formulario + progreso + log + docs embebidas
│   ├── markdown-lines.blade.php  # Render Markdown minimalista
│   ├── menu.blade.php
│   └── settings.blade.php
├── rrd-fix.py                # Script Python: corrige rangos + escanea NaN
├── tests/run.php             # Tests autónomos (php tests/run.php)
├── README.md                 # Este archivo (Quickstart)
├── INSTALACIÓN.md            # Persistencia post-upgrade
├── GUÍA.md                   # Manual usuario
├── FAQ.md                    # Troubleshooting
├── ARQUITECTURA.md           # Doc técnica
└── .gitignore
```

---

## ✅ Garantía de persistencia post actualización LibreNMS

Por diseño:
1. **Este directorio es su propio repositorio Git independiente.**
   LibreNMS hace `git pull` en el repo padre, pero **nunca** entra en
   subdirectorios que tienen su propio `.git/`.
2. Todo el código del plugin vive DENTRO de `app/Plugins/RrdFix/`.
   No hay modificaciones a `routes/web.php`, `config/`,
   `app/Http/Controllers/`, ni migraciones.
3. Las rutas y namespaces de vistas se registran desde las clases hook
   (`Settings::__construct()` y `PluginProvider::boot()`).

**Si por algo se borra este directorio:**
```bash
cd /opt/librenms/app/Plugins
sudo -u librenms git clone https://github.com/danpal-dev/librenms-plugin-rrdfix.git RrdFix
sudo chown -R librenms:librenms RrdFix
```

Luego repite los pasos **5, 6, 7** de esta guía.

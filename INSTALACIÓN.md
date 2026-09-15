# 📦 Instalación · Actualización · Persistencia post-upgrade LibreNMS

Este documento explica cómo instalar RrdFix, cómo actualizarlo y, lo más importante:
**cómo asegurar que NO se pierda el plugin cuando actualices LibreNMS con `git pull`.**

---

## 1. Instalación (0-touch en el core de LibreNMS)

### Requisitos
- LibreNMS 24.x+ (con sistema de plugins habilitado)
- PHP 8.3+
- Python 3.9+ con módulos `subprocess`, `datetime`, `re`, `pathlib`, `xml.etree` (estándar)
- rrdtool (cli) instalado: `rrdtool dump/restore`
- rrdcached corriendo (socket `/var/run/rrdcached.sock`)
- Usuario `librenms` dueño del directorio `/opt/librenms/`

### Paso 1 — colocar el plugin

Copia/clona el directorio `RrdFix` en:

```bash
/opt/librenms/app/Plugins/RrdFix/
```

Si lo instalas por primera vez desde un zip:

```bash
cd /opt/librenms/app/Plugins
unzip /ruta/a/RrdFix.zip
chown -R librenms:librenms RrdFix/
```

### Paso 2 — hacer de este plugin un repositorio Git INDEPENDIENTE

**Esto es lo que evita que se pierda con `git pull` de LibreNMS:**

```bash
cd /opt/librenms/app/Plugins/RrdFix
git init
git add -A
git commit -m "feat(install): RrdFix plugin inicial"
git branch -M main
# Añade tu repo remoto (GitHub, GitLab, interno) — ej:
git remote add origin git@git.tu-empresa.pe:monitoreo/librenms-rrdfix.git
git push -u origin main
```

> ⚠️ **Importante:** LibreNMS actualiza `/opt/librenms` con `git pull`.
> `git pull` en el repo padre NO borra subdirectorios con su propio `.git` interno.
> El directorio `app/Plugins/` está designado para plugins y NUNCA se
> sobreescribe por actualización oficial (cada plugin tiene su namespace).

### Paso 3 — habilitar el plugin

Ve a LibreNMS → menú **Administración de plugins** (`/plugin/settings`) →
activa **RrdFix** → guardar.

Comprueba (como usuario `librenms`):

```bash
cd /opt/librenms
sudo -u librenms php artisan tinker --execute="
    \$p = \App\Models\Plugin::where('plugin_name','RrdFix')->first();
    echo \$p?->plugin_active ? 'ACTIVADO (plugin_active='.\$p->plugin_active.')' : 'DESACTIVADO';
"
```

### Paso 4 — limpiar cachés

```bash
cd /opt/librenms
sudo -u librenms php artisan optimize:clear
sudo systemctl reload php8.4-fpm        # adapta versión / init (openrc/brexx)
```

### Paso 5 — abrir el panel

- Ruta web: `https://tu-librenms/plugin/RrdFix/`
- Ruta del menú (izquierda LibreNMS): **Plugins > Corrección de RRD**
- Endpoint JSON estado (autenticado, `plugin.admin`): `GET /rrdfix/status`

---

## 2. ¿Cómo NO perderse con `git pull` del core LibreNMS?

### ✅ Método seguro (recomendado)

El plugin ya es autocontenido y tiene su propio `.git`. La actualización
normal de LibreNMS es así:

```bash
# 1. Actualizar core LibreNMS
cd /opt/librenms
sudo -u librenms git pull
sudo -u librenms ./scripts/composer_wrapper.php install --no-dev -n
sudo -u librenms php artisan migrate --force -n

# 2. Actualizar SOLO el plugin RrdFix
cd /opt/librenms/app/Plugins/RrdFix
sudo -u librenms git pull origin main
sudo -u librenms php artisan optimize:clear
sudo systemctl reload php8.4-fpm

# 3. Verificar
sudo -u librenms php /opt/librenms/app/Plugins/RrdFix/tests/run.php
# -> PASS: ruta oficial, precedencia sobre 404, formulario y consumo seguro de parámetros.
```

### ❌ Lo que NUNCA hagas (causa pérdida del plugin)

1. **No** hagas commit de los archivos del plugin en el repo PADRE de LibreNMS
   (tu próximo `git pull --rebase` te los borrará con conflicto o se perderán).
2. **No** coloques controladores en `app/Http/Controllers/` ni edites
   `routes/web.php` — esos archivos SÍ se sobreescriben.
3. **No** dependas de archivos en `public/` o `resources/views/vendor/` sin
   respaldo. Todo lo del plugin va en `app/Plugins/RrdFix/`.

### 🛟 Si se borró accidentalmente (recuperación en 2 minutos)

```bash
cd /opt/librenms/app/Plugins
sudo -u librenms git clone git@git.tu-empresa.pe:monitoreo/librenms-rrdfix.git RrdFix
cd RrdFix
sudo chown -R librenms:librenms .
sudo -u librenms php artisan optimize:clear
sudo systemctl reload php8.4-fpm
# Ir a Administración de plugins y activar si estaba desactivado.
```

---

## 3. Actualizar SOLO el plugin RrdFix (sin tocar LibreNMS)

```bash
cd /opt/librenms/app/Plugins/RrdFix
sudo -u librenms git status
sudo -u librenms git pull origin main
sudo -u librenms php artisan optimize:clear
sudo systemctl reload php8.4-fpm
sudo -u librenms php tests/run.php
```

---

## 4. Desinstalación limpia

```bash
# 1. Desactivar en UI: Administración de plugins → RrdFix → Desactivar
# 2. Quitar el directorio
rm -rf /opt/librenms/app/Plugins/RrdFix
# 3. (opcional) Quitar la fila de la BD
cd /opt/librenms
sudo -u librenms php artisan tinker --execute="
    \App\Models\Plugin::where('plugin_name','RrdFix')->delete();
"
sudo -u librenms php artisan optimize:clear
```

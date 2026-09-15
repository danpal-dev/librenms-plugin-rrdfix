# ❓ FAQ · Problemas frecuentes

---

## 1. 🔴 Al entrar al plugin me sale **404 Not Found**

### Causa 1.1 — plugin desactivado automáticamente por error PHP

Síntoma: `PluginPageController` dice "missing" o `abort(404)`.

Diagnóstico: entra al tinker:
```bash
cd /opt/librenms
sudo -u librenms php artisan tinker --execute="
    echo \App\Models\Plugin::where('plugin_name','RrdFix')->value('plugin_active');
"
```
Si devuelve `0` o `null` → fue desactivado.

Solución:
```bash
sudo -u librenms php artisan tinker --execute="
    \App\Models\Plugin::updateOrCreate(
      ['plugin_name'=>'RrdFix'],
      ['plugin_active'=>1,'version'=>'1.0']
    );
"
sudo -u librenms php artisan optimize:clear
sudo systemctl reload php8.4-fpm
```

### Causa 1.2 — error de sintaxis / TypeError en hooks

El `PluginManager` captura excepciones al registrar hooks y desactiva el plugin.

- Comprueba `authorize()` no tenga tipado no-nullable:
  ✅ `public function authorize(?Authenticatable $user): bool`
  ❌ `public function authorize(Authenticatable $user): bool`
- Limpia OPcache: `systemctl reload php8.4-fpm`

---

## 2. 🔴 Al hacer submit aparece **"No se pudo iniciar rrd-fix. Revisa el log."**

Muy común en instalaciones donde PHP-FPM **ya corre como usuario `librenms`**
y el script intentaba `sudo -n -u librenms` innecesariamente, pero el
usuario `librenms` no está en sudoers → exit code 1.

Diagnóstico (ejecuta en el server):
```bash
ps aux | grep 'php-fpm.*pool' | head -3
```
Si la columna USER es `librenms` → no necesitas sudo.

Arreglo incluido desde v1.0.1: `Page.php` usa `posix_geteuid()` para
detectar esto y lanza `sh -lc` directamente sin sudo cuando ya eres
`librenms`. **Actualiza el plugin y recarga FPM**.

---

## 3. ⚪ El panel Log muestra "(sin salida todavía)" y dura 0 bytes

### Causa 3.1 — buffering stdout de Python (más común)

Python bufferiza la salida por bloques de ~8KB cuando rediriges a archivo.
Arreglo incluido: ejecutamos `python3 -u` (unbuffered).

### Causa 3.2 — el script no arrancó

Mira si el proceso existe:
```bash
ps auxf | grep 'python3.*rrd-fix.py' | grep -v grep
```
Si no hay línea: abre el log `/opt/librenms/storage/logs/rrd-fix.log` o
`librenms.log` y busca la línea de error exacta.

### Causa 3.3 — permisos

```bash
ls -la /opt/librenms/storage/logs/
# todos los archivos deben ser librenms:librenms
sudo chown -R librenms:librenms /opt/librenms/storage/logs
```

---

## 4. 🔴 `rrdfix/status` devuelve `401 Unauthenticated`

Es **correcto y esperado**. El endpoint requiere:
- Middleware `web` (sesión cookie `laravel_session`)
- Middleware `auth` (usuario logueado)
- Middleware `can:plugin.admin` (permiso plugin.admin)

Para consumirlo desde curl necesitas pasar la cookie de sesión como
usuario administrador. Desde la UI del panel el navegador ya lo hace.

---

## 5. 🟡 Botón "Corrección en curso" se queda pillado infinitamente

Significa que `isRunning()` sigue detectando proceso Python. Si sabes que
terminó (miraste `ps` y no hay PID):

### Motivo 5.1 — proceso zombie o `grep` match del propio grep

Arreglo incluido: regex anclada `^[^ ]*[[:space:]]+[0-9]+...` con `grep -v grep`.

### Motivo 5.2 — 2 ejecuciones simultáneas (cuidado)

Si alguien lanzó otra tarea a mano por CLI quedan 2 PIDs. Matar manual:
```bash
pkill -f 'python3.*rrd-fix.py'
```

---

## 6. 🟡 Los backups `.bak_*` ocupan mucho espacio

Política normal: **por defecto NO los borramos** (seguridad). Cuando
confirmas que la corrección salió bien, puedes borrar backups antiguos:

```bash
find /opt/librenms/rrd -type f -name '*.bak_20260[78]*' -delete
```

---

## 7. ⚠ "Warning: el RRD destino es 1 fila más corto que el patrón"

Informativo, no bloqueante. Ocurre cuando el backup tiene 1 celda menos
por redondeo de step RRD (300s). Lo ajusta automáticamente con `rrdtool resize` + padding NaN.

---

## 8. 🆘 "Acabo de hacer `git pull` del core LibreNMS y el plugin desapareció"

Ver [INSTALACIÓN.md: Recuperación](INSTALACIÓN.md). La razón: tu `RrdFix/`
**no tenía su propio `.git/` interno** y se perdió al reescribir el
directorio padre. Recupéralo con:
```bash
cd /opt/librenms/app/Plugins
git clone git@tu-repo:RrdFix.git RrdFix
chown -R librenms:librenms RrdFix
```

---

## 9. 🎨 El diseño no se parece a Flowbite (botones antiguos)

Necesitas tener el plugin **Flowbite Theme** instalado y activado en
LibreNMS (`FlowbiteTheme`). Al activarlo cambia las variables CSS
`--fb-primary / --fb-font-family` y todas las tarjetas del panel RrdFix
cambian automáticamente al estilo (tarjetas con sombra, esquinas
redondeadas, acentos en azul `#2563eb`, badges pill).

Si no lo tienes instalado, el diseño funciona igual con el estilo
por defecto de LibreNMS (bootstrap 3 clásico).

---

## 10. ❗ Error `rrdcached socket /var/run/rrdcached.sock not writable`

Permisos del socket:
```bash
ls -la /var/run/rrdcached.sock
srw-rw---- 1 librenms librenms ...
```
Si no, edita `/etc/default/rrdcached` o el servicio systemd y reasigna
el grupo, luego `systemctl restart rrdcached`.

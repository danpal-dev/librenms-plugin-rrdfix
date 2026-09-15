# 🏗 ARQUITECTURA TÉCNICA · RrdFix

> Documento para desarrolladores: cómo está construido internamente el
> plugin, qué hooks usa, endpoints registra, seguridad y cómo extenderlo
> sin romper la persistencia post-upgrade.

---

## 1. Hooks de LibreNMS que registra (archivos en el root del plugin)

LibreNMS carga hooks haciendo `glob(app/Plugins/*/*.php)`. Cada archivo
en el nivel **root** del plugin debe implementar una interfaz de
`LibreNMS\Interfaces\Plugins\Hooks\*`.

| Archivo | Hook | Propósito |
|---|---|---|
| `Settings.php` | `SettingsHook` | 1) Hook de configuración (devuelve vacío, no hay panel admin extra). 2) Punto de registro de RUTAS del plugin: `Route::group(web+auth+can:plugin.admin)` registra `GET rrdfix/status`. |
| `Menu.php` | `MenuEntryHook` | Añade el enlace "Corrección de RRD" al menú de LibreNMS. Usa `route('plugin.page',['plugin'=>'RrdFix'])` — NUNCA `url()`. |
| `Page.php` | `SinglePageHook` | UI principal del plugin. Expone 4 métodos: `authorize`, `data`, `run`, `response`. Además públicos `isRunning()` y `readLog()` que consume RunController. |
| `DeviceOverview.php` | `DeviceOverviewHook` (opcional) | (futuro) añadir mini panel "Últimas correcciones RRD" en la vista individual de dispositivo. |

**Clases auxiliares NO en root:** van en subdirectorios: `Http/`, `Support/`,
`resources/views/`. Así el PluginProvider no los intenta cargar como hooks.

### 1.1 🔑 FIRMAS DE HOOKS — REGLAS OBLIGATORIAS (no romperlas)

> Regla extraída a posteriori del bug v1.1.0/1.1.1: **"Vista faltante."**
> aunque el plugin esté activo.
>
> **Causa raíz:** `PluginManager::fillArgs()` inyecta SIEMPRE `settings`,
> `pluginName` y `user` a `app()->call([$hookInst,'authorize'], $args)`
> y `app()->call([$hookInst,'data'], $args)`. Si los métodos no declaran
> los parámetros que se pasan por nombre, `BoundMethod::call()` puede
> lanzar excepción según versión Laravel → **desactivación automática**
> del plugin → NO se registra el namespace de vistas `loadViewsFrom()`
> → `PluginPageController` hace fallback a `plugins.missing`.

#### Tabla de firmas OBLIGATORIAS (copiar literalmente):

| Hook             | `authorize()` — firma obligatoria                          | `data()` — firma obligatoria             |
|---               |---                                                         |---                                       |
| `Settings.php`   | `authorize(?Authenticatable $user, array $settings = [])`  | `data(array $settings): array`           |
| `Menu.php`       | `authorize(?Authenticatable $user, array $settings = [])`  | `data(array $settings = []): array`      |
| `Page.php`       | `authorize(?Authenticatable $user, array $settings = [])`  | `data(array $settings = []): array`      |
| `DeviceOverview` | `authorize(?Authenticatable $user, Device $device)`        | `data(Device $device): array`            |

- Import: `use Illuminate\Contracts\Auth\Authenticatable;` (nunca
  `\Illuminate\Foundation\Auth\User` — no es intercambiable).
- El param `array $settings = []` **debe tener valor por defecto** — así
  el método sigue siendo válido incluso cuando Container no lo inyecta.
- `DeviceOverview` NO usa `$settings` como 2º param porque el Hook base
  (`DeviceOverviewHook`) pasa un `Device $device` — respeta la firma.

#### Buenas prácticas anti-fallo (RrdFix las usa todas):

1. **`authorize()` con try/catch por cada `$user->can(...)`.** El gate
   `plugin.admin` no está definido en todas las instalaciones.
2. **`data()` con try/catch general** que devuelva un array mínimo
   conteniendo SIEMPRE `'content_view' => 'RrdFix::...'` (evita fallback
   a plugins.missing).
3. **`Settings::__construct()` que registra rutas — envolver en
   try/catch(\Throwable)** + comprobar `app()->routesAreCached()`.
4. **Nunca `route('plugin.route.name')` directo en Blade.** Calcula una
   variable con `Router::has()` + fallback a `url('/ruta/bruta')`.

---

## 2. Flujo completo: Usuario → Submit → Ejecución → UI en vivo

```
  (Usuario)
      │ submit formulario POST /plugin/settings/RrdFix
      ▼
  PluginSettingsController::update()  [Laravel core, no modificamos]
      │ valida can:plugin.admin
      │ guarda en plugins.settings {rrd_fix_action:"run", device:"10.21.21.1", ...}
      │
      ├──▶ redirect 302 → GET /plugin/RrdFix
      │
  PluginPageController::handle()  [core]
      │ llama PageHook::data()
      │   ▼
      │ PendingRun::fromSettings($settings)
      │   → detecta rrd_fix_action==="run"
      │   → limpia settings (action=null) para NO repetir ejecución
      │   ▼
      │ Page::run([...]) ← llama al método run() del hook
      │       ├── normaliza fechas Lima → 'Y-m-d H:i:s'
      │       ├── arma $pythonArgs (python3 -u rrd-fix.py --ip ...)
      │       ├── decide si necesita sudo:
      │       │     posix_geteuid() === posix_getpwnam('librenms')['uid']
      │       │     ? sh -lc : sudo -n -u librenms sh -lc
      │       ├── background: $command >> LOG 2>&1 &
      │       └── devuelve flash+type
      ▼
  Render page.blade.php
      │ (PHP) inyecta $running, $log_content, $log_path, devices, form
      ▼
  Navegador
      │
      ├─ <script> arranca startMonitoring(running)
      ├─ setTimeout(poll, 4000) cada 4s
      └─ XHR GET /rrdfix/status (autenticado)
              │
              ▼
         RunController::status()
           ├── Page::isRunning()    → booleano
           ├── Page::readLog()      → últimas 80 líneas
           ├── shell_exec ps -eo pid,etime,pcpu,pmem,args | grep rrd-fix
           └── response JSON {running, process:{pid,etime,cpu,mem,args}, log_content}
       ▼
  setRunning(true/false) actualiza panel Progreso + Log + botones
```

---

## 3. Endpoints expuestos

| Método | URL | Middlewares | Controlador | Devuelve |
|---|---|---|---|---|
| GET | `/plugin/RrdFix` | web, auth, can:plugin.admin | (core SinglePageHook) | HTML, página principal |
| POST | `/plugin/settings/RrdFix` | web, auth, can:plugin.admin | (core PluginSettingsController) | Redirect 302 tras guardar settings (triggers run) |
| GET | `/rrdfix/status` | web, auth, can:plugin.admin | RrdFix\Http\RunController::status | JSON estado |

**Nota:** la ruta `/rrdfix/status` NO empieza por `/plugin/*` por practicidad;
se registra en Settings.php y pasa por los mismos middlewares.

---

## 4. Seguridad

- **Todos los endpoints** (tanto los core como los propios) requieren
  `can:plugin.admin`. No hay forma de ejecutar el script sin login admin.
- **Argumentos del comando shell** pasan por `array_map('escapeshellarg', $pythonArgs)`.
- **Campos del formulario** se validan en `PendingRun::fromSettings()` y `Page::run()`:
  - IP regex IPv4 válida o hostname alfanumérico + guiones + puntos.
  - Fechas pasan por `DateTimeImmutable::createFromFormat('!Y-m-d\TH:i')` (estricto).
  - Booleans solo `true/false` via `$request->boolean(...)`.
  - Availability solo `up` / `down`.
- **Log path y RRD dir** son `self::LOG_PATH` y `self::RRD_DIR` constantes
  de clase, NO concatenados con entrada usuario.
- **Logs volcados al panel** se renderizan en `<pre>` → HTML especial chars por defecto de Blade (`{{ }}` auto-escapa).

---

## 5. Persistencia post-upgrade (cómo/por qué sobrevive a `git pull`)

1. **Autocontenido 100% en `app/Plugins/RrdFix/`.**
   - NUNCA tocamos archivos fuera de este directorio.
   - NUNCA modificamos `routes/web.php`, `bootstrap/app.php`, `config/*.php`,
     `app/Http/Controllers/`, `database/migrations/`.
   - Todos los settings se guardan en `plugins.settings` (JSON global) o
     `devices_attribs` (clave-valor por dispositivo) — no hay migraciones.
2. **`.git` interno en el directorio.** El repo padre `/opt/librenms` al
   hacer `git pull` NUNCA hace `rm -rf` de subdirectorios; y si lo hiciera,
   tenemos un `origin` remoto desde el que clonar de vuelta en 60s.
3. **Registro de rutas dinámico desde Settings.php.** Laravel carga el
   provider → carga Settings hook → ejecuta el `Route::group` del
   archivo. Si actualizamos FPM, vuelve a cargar todo.

---

## 6. Extender el plugin (sin romper compatibilidad)

### Añadir un nuevo flag CLI al Python

1. En `Page.php::run()` añade `if ($form['nuevo_flag']) $pythonArgs[]='--nuevo-flag';`
2. Añade el checkbox en `page.blade.php` dentro del panel Paso 1 o 2.
3. Actualiza tests en `tests/run.php`.

### Añadir otro endpoint

1. Define la ruta en `Settings.php` (dentro del grupo web+auth+can).
2. Añade método público en `RunController.php` o crea un `DocsController.php` nuevo.
3. Añade JS en page.blade.php si lo consume el panel.

### Añadir panel por dispositivo (DeviceOverviewHook)

Crea `DeviceOverview.php` en el root que implemente
`LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook`:
```php
// DeviceOverview.php
public function authorize(?Authenticatable $user, Device $device): bool { ... }
public function data(Device $device): array { return [...]; }
```
Y la vista `resources/views/device-overview.blade.php` con los últimos 10
logs ejecutados sobre ese dispositivo.

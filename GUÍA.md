# 📘 GUÍA DE USUARIO · RrdFix

> Cómo usar la interfaz web para reparar archivos RRD, huecos de NaN y
> disponibilidad mal reportada en LibreNMS.

---

## 🧭 Acceso

- URL: `https://tu-librenms/plugin/RrdFix/`
- Menú lateral izquierdo → **Plugins → Corrección de RRD** 🔧
- Requiere permiso: **`plugin.admin`** (usuarios administradores)

---

## 📋 Vista general

La pantalla tiene **4 paneles verticales**, en orden:

| Panel | Título | Para qué |
|---|---|---|
| 1 | **Paso 1 · Corrección del rango (obligatorio)** | El trabajo principal: define dispositivo + fechas + estado real del dispositivo. |
| 2 | **Paso 2 · Buscar y rellenar NaN en otro rango (opcional)** | Actívalo SOLO si además hay huecos intermitentes en las gráficas, fuera del periodo del Paso 1. |
| 3 | **Progreso** | Siempre visible. Barra animada + tiempo + PID + CPU + RAM + estado. |
| 4 | **Log** | Salida en vivo del script Python (se actualiza cada 4 segundos, no hace falta recargar). |
| 5 | **Documentación / Ayuda** | Acordeón con GUÍA, FAQ, Instalación, Arquitectura. |

---

## 🛠 Paso 1 — Corrección del rango (obligatorio)

Se usa para **reparar un periodo concreto** en el que sabes (certeza) qué
pasó realmente con el dispositivo. Ejemplos:

- 🟢 "El router NUNCA estuvo caído entre el 20 y el 24 de agosto, pero la
  gráfica muestra huecos porque el poller LibreNMS tuvo un problema"
  → usa **No estuvo caído (→ 100% up)**.
- 🔴 "El enlace sí estuvo caído esas fechas (lo confirmamos con RFO,
  tickets, etc.) y queremos reflejarlo correctamente → disponibilidad 0%"
  → usa **Estuvo caído (→ 0% up)**.

### Campo a campo

| Campo | Descripción | Valor ejemplo |
|---|---|---|
| **Dispositivo** | Hostname del equipo objetivo | `10.21.21.1 - ros core santa anita new` |
| **Inicio (Lima)** | Fecha y hora ZONA HORARIA LIMA de comienzo del periodo | `2026-08-20 00:00` |
| **Fin (Lima)** | Fecha y hora LIMA final (incluida hasta esa hora) | `2026-08-24 23:59` |
| **Estado durante el rango** | ⬆ **No estuvo caído** → fuerza disponibilidad 100%, dBm normal, rellena NaN con patron días anteriores.<br>⬇ **Estuvo caído** → fuerza 0% availability, dBm = -40, ping perdido. | `No estuvo caído` |
| **☑ Omitir dBm SFP** | Desactiva la corrección de valores de potencia óptica. Úsalo solo cuando el problema no es de enlaces fibra/SFP (ej: problema interno del poller, no de enlace de datos). | ❌ desmarcado por defecto |

### ¿Qué hace el script exactamente con el Paso 1?

1. **Backup** previo de cada RRD que toca (archivos `*.bak_YYYYMMDD_HHMMSS` al lado del original).
2. **Availability RRDs** (`availability-*.rrd`) → reescribe el periodo entero al valor 0 o 100 según el estado.
3. **Pares de puertos** (`port-id-*.rrd`) → INOCTETS / OUTOCTETS / errores / velocidad según el estado.
4. **dBm SFP** → si no está marcado "Omitir", reescribe dBmTx/Rx normales (↑) o `-40 dBm` (↓).
5. **ICMP perf** → patrón de días anteriores.
6. **Memoria / CPU / Sensores** → copia el patrón de días equivalentes de semanas pasadas.

---

## 🔎 Paso 2 — Buscar y rellenar NaN (opcional, toggle)

> Desactivado por defecto. **Actívalo solo si te hacen falta.**

Busca celdas con valor `NaN` (huecos vacíos = no se recolectó muestra)
en **un rango más amplio** que el Paso 1, y las rellena copiando el valor
del mismo día de la semana anterior (o 2 días atrás si el patrón no existe).

**Cuándo SÍ activarlo:**
- Hubo fallo masivo del poller LibreNMS durante todo el mes (no solo 5 días).
- Hay fallos intermitentes repetidos (picos de 5 minutos de NaN).
- La gráfica de un sensor se ve como una "línea cortada" y no coincide
  con una caída real del dispositivo.

**Cuándo NO activarlo:**
- Solo fue una caída conocida y acotada de 5 días.
- El periodo del Paso 1 ya es todo el rango con problemas.

### Campo a campo

| Campo | Descripción |
|---|---|
| **☑ Buscar y rellenar NaN en otro rango** | Checkbox que muestra/oculta sus fechas. Desmarcado por defecto. |
| **Inicio escaneo NaN** | Fecha desde la que buscar huecos (Lima, hora local). |
| **Fin escaneo NaN** | Fecha hasta la que buscar huecos. |
| *(nota)* | Si dejas **ambas vacías**, el escaneo NaN usará **todo el mes** de las fechas del Paso 1 (si las hay). |

---

## ▶ Ejecutar corrección

1. Pulsa el botón rojo **▶ Ejecutar corrección** (btn-lg).
2. El formulario entero se deshabilita (no se puede volver a enviar hasta que termine).
3. El botón cambia a **▶ Corrección en curso…** (deshabilitado).
4. El panel **Progreso** se pone azul con barra rayada animada:
   - ⏱ **Tiempo transcurrido:** `00:00 → 05:32 → 12:05…`
   - 🖥 **PID:** 3397367
   - 📈 **CPU:** 88.2%
   - 🧠 **RAM:** 2.1%
5. El panel **Log** se **actualiza solo cada 4 segundos** con lo que va
   escribiendo el script Python, scrolleando automáticamente al fondo.

### ¿Cuánto tarda?

- Router con 30 RRDs → **~45 segundos**
- Router Core con **100+ RRDs, 20+ puertos, sensores dBm** → **2–6 minutos**
- Si además activas escaneo NaN de un mes entero → añade +50% al tiempo.

---

## 🧪 3 casos de uso típicos

### Caso A — "El router no cayó, el poller sí (falsa caída)"

> 20/08 al 24/08, dispositivo `10.21.21.1`. Ticket del ISP confirma que el
> enlace estuvo activo. LibreNMS marcó 42% availability.

| Campo | Valor |
|---|---|
| Dispositivo | 10.21.21.1 |
| Inicio | 20/08/2026 00:00 |
| Fin | 24/08/2026 23:59 |
| Estado | **No estuvo caído → 100% up** |
| Omitir dBm SFP | ❌ (desmarcado) |
| Buscar NaN | ❌ |

Resultado: availability RRDs pasados del 42% al **100%**, dBm restaurados,
puertos con throughput correcto.

---

### Caso B — "El enlace sí cayó (RFO del proveedor), registrar caída real"

| Campo | Valor |
|---|---|
| Dispositivo | 45.177.21.97 coar wan 01 |
| Inicio | 12/09/2026 10:15 |
| Fin | 12/09/2026 14:40 |
| Estado | **Estuvo caído → 0% up** |
| Omitir dBm SFP | ❌ |

---

### Caso C — "Fallos intermitentes, NaN dispersos en todo Agosto"

> El equipo estuvo 100% todo el mes, pero hay NaN de 5-10min por todas las
> gráficas (poller saturado, rrdcached bloqueado, etc.).

1. **Paso 1** → 1-2 agosto al 31 agosto + No estuvo caído.
2. **Paso 2** → ☑ activar + Inicio: 1/ago, Fin: 31/ago (mismo rango)
3. Ejecutar → repara los bloques completos (Paso 1) + rellena los huecos
   de NaN de 5min uno por uno (Paso 2).

---

## 🗄 Dónde vive cada cosa

- Log de ejecución: `/opt/librenms/storage/logs/rrd-fix.log` (contenido visible en el panel Log)
- Backups: al lado de cada `.rrd`, archivo `nombre.rrd.bak_YYYYMMDD_HHMMSS`
- Script Python: `/opt/librenms/app/Plugins/RrdFix/rrd-fix.py` → wrapper del script oficial

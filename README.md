# Monitor de Servidor con Laravel

Trabajo práctico de Programación IV — UTN FRRe, cohorte 2026.

App Laravel que lee datos del sistema operativo (nombre del equipo, sistema operativo,
versión de PHP, disco, memoria, CPU, tiempo encendido y temperatura) y los muestra en
una vista web que se actualiza sola (polling) y en una API JSON.

## Sistema operativo usado

- **Sistema operativo:** Linux (CachyOS, kernel 7.3), con el desafío opcional de Linux
- **PHP:** 8.4.1
- **Laravel:** 13

El service también funciona en Windows 10/11 con el script de PowerShell del enunciado.

## Cómo ejecutarlo

```powershell
composer install
copy .env.example .env
php artisan key:generate
php artisan serve
```

- Vista web: http://127.0.0.1:8000/servidor
- API JSON: http://127.0.0.1:8000/api/metrics

## Estructura

| Parte | Archivo | Responsabilidad |
|---|---|---|
| Service | `app/Services/ServerMetrics.php` | Lee los datos del sistema operativo |
| Controlador | `app/Http/Controllers/ServerController.php` | Devuelve la vista o el JSON |
| Vista | `resources/views/servidor.blade.php` | Muestra las tarjetas y hace polling cada 5 s |
| Rutas | `routes/web.php`, `routes/api.php` | `/servidor` y `/api/metrics` (con `throttle:60,1`) |

En Windows, `ServerMetrics` ejecuta un script fijo de PowerShell (codificado con
`-EncodedCommand`) que consulta WMI con `Get-CimInstance` y devuelve JSON. Lo que no se
puede leer queda en `null` y la vista lo muestra como "No disponible".

**Desafío opcional (hecho):** el service también funciona en Linux. Según
`PHP_OS_FAMILY`, lee `/proc/meminfo`, `/proc/uptime`, `/proc/stat` (CPU, con dos
lecturas separadas 200 ms) y la temperatura de `/sys/class/thermal/thermal_zone0/temp`.
Si esa zona no existe (pasa en muchas PC de escritorio), la busca en los sensores
`hwmon` del procesador: `k10temp` o `zenpower` en AMD y `coretemp` en Intel.

## Respuestas

**1. ¿Por qué la temperatura suele aparecer como "No disponible" en Windows?**

La clase `MSAcpi_ThermalZoneTemperature` está en el namespace `root/wmi`, que solo se
puede consultar con permisos de administrador; desde una terminal común la consulta
falla y el `catch` deja la temperatura en `null`. Además, aunque se ejecute como
administrador, depende de que la placa madre informe la zona térmica por ACPI: muchas
no lo hacen, y otras devuelven un valor fijo que no es la temperatura real de la CPU.
En Linux pasa algo distinto: los sensores de `/sys/class/hwmon` se pueden leer sin
permisos de root. En este equipo (AMD), `thermal_zone0` no existe, pero el sensor
`k10temp` informa la temperatura del procesador (unos 40 °C en reposo).

**2. ¿Por qué el script de PowerShell nunca debe incluir datos que vengan del usuario?**

Porque `shell_exec()` ejecuta el comando en el servidor con los permisos de PHP. Si el
script se armara con datos de un formulario o de la URL, un atacante podría inyectar
comandos propios (por ejemplo, cerrar la cadena y agregar `; Remove-Item ...`) y
ejecutar cualquier cosa en el servidor. Esto se llama inyección de comandos. Por eso
el script es fijo y no recibe ningún parámetro externo.

**3. ¿Qué riesgo tiene dejar `/api/metrics` sin autenticación en un servidor real?**

- **Filtración de información:** cualquiera ve el nombre del equipo, el sistema
  operativo y la versión exacta de PHP, datos útiles para buscar vulnerabilidades
  conocidas de esas versiones.
- **Denegación de servicio:** cada pedido abre un proceso de PowerShell, que es costoso.
  Muchos pedidos a la vez pueden saturar la CPU y la memoria del servidor. El
  `throttle:60,1` limita por IP, pero no frena un ataque desde muchas IPs.

En producción se protege con autenticación, por ejemplo `->middleware('auth:sanctum')`.

**4. Con polling cada 5 segundos, ¿cuántos pedidos por minuto recibe el servidor si hay 50 personas mirando?**

Cada persona hace 60 / 5 = 12 pedidos por minuto, así que 50 × 12 = **600 pedidos por
minuto** (10 por segundo), aunque los datos no hayan cambiado. Y cada uno abre un
proceso de PowerShell.

**5. Si una app solo necesita la temperatura, ¿qué datos recibe de más al llamar a `/api/metrics`?**

Recibe todo lo demás: `hostname`, `os`, `php`, `disk` (total, libre y porcentaje),
`memory` (total, disponible y porcentaje), `cpu_pct`, `uptime_s` y `at`. Solo le servía
`cpu_temp_c`. Esto se llama *over-fetching*, y es el problema que resuelve GraphQL:
el cliente pide únicamente los campos que necesita.

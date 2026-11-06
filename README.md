# Monitor de Servidor en Tiempo Real con Laravel

Trabajo práctico 2 de Programación IV, UTN FRRe, cohorte 2026.

La app lee métricas del sistema operativo del servidor y las envía a todos los navegadores por WebSocket. Las métricas son nombre del equipo, sistema operativo, disco, memoria, CPU, temperatura y tiempo encendido. Usa Laravel Reverb, Echo, eventos, el scheduler y gráficos con Chart.js. También tiene botones que abren programas en el servidor, y la actividad se ve en vivo en todas las pestañas.

El TP 1 (la misma app con polling) está en el tag [`v1.0.0`](../../tree/v1.0.0).

## Sistema operativo usado

- **Sistema operativo:** Linux (CachyOS, kernel 7.3, KDE Plasma)
- **PHP:** 8.4.1
- **Laravel:** 13

El enunciado está pensado para Windows. El proyecto funciona en los dos sistemas:

| | Windows 10/11 | Linux |
|---|---|---|
| Métricas | Script fijo de PowerShell que consulta WMI | `/proc/meminfo`, `/proc/stat`, `/proc/uptime` |
| Temperatura | `MSAcpi_ThermalZoneTemperature`, que pide administrador | `thermal_zone0` o, si no existe, los sensores `hwmon` del procesador (`k10temp`, `zenpower`, `coretemp`) |
| Botón "Abrir calculadora" | `calc.exe` | El primero instalado de: `kcalc`, `gnome-calculator`, `galculator` |
| Botón "Abrir Bloc de notas" | `notepad.exe` | El primero instalado de: `kwrite`, `kate`, `gnome-text-editor`, `gedit`, `mousepad` |

## Cómo encaja todo

```
 MÉTRICAS · CADA 5 SEGUNDOS
 schedule:work ─> metrics:broadcast ─> ServerMetrics ─> MetricsUpdated ──┐
                                                       canal server.metrics │
                                                                           v
                                                          Reverb (puerto 8080) ─> Echo en todos los navegadores
                                                                           ^       (/servidor y /graficos)
 BOTONES · CUANDO ALGUIEN HACE CLIC                                        │
 botón ─> POST /demo/abrir/{app} ─> DemoController (lista blanca) ─> AppOpened
                                                                   canal server.demo
```

| Archivo | Responsabilidad |
|---|---|
| `app/Services/ServerMetrics.php` | Lee las métricas del sistema operativo (TP 1) |
| `app/Events/MetricsUpdated.php` | Evento con las métricas, canal `server.metrics` |
| `app/Console/Commands/BroadcastMetrics.php` | Comando `metrics:broadcast`: lee las métricas y dispara el evento |
| `routes/console.php` | Programa el comando cada 5 segundos |
| `app/Http/Controllers/DemoController.php` | Abre programas desde una lista blanca y avisa con `AppOpened` |
| `app/Events/AppOpened.php` | Evento de actividad, canal `server.demo` |
| `resources/views/servidor.blade.php` | Monitor en tiempo real, botones y actividad |
| `resources/views/components/grafico-metrica.blade.php` | Componente de Blade de un gráfico |
| `resources/views/graficos.blade.php` | Vista con tres gráficos que usan el componente |
| `resources/js/app.js` | Carga Echo y Chart.js |

## Instalación

Requisitos: PHP 8.3 o superior, Composer, Node.js y npm.

```bash
git clone <url-del-repo> monitor-servidor
cd monitor-servidor

composer install
npm install

cp .env.example .env            # Windows: copy .env.example .env
php artisan key:generate
php artisan migrate
```

En el `.env`, completar las claves de Reverb con cualquier texto y activar los botones:

```dotenv
REVERB_APP_KEY=mi-clave-reverb
REVERB_APP_SECRET=mi-secreto-reverb
DEMO_APPS=true
```

## Ejecución

Hacen falta cuatro terminales en la carpeta del proyecto:

| Terminal | Comando | Qué hace | Si se detiene |
|---|---|---|---|
| 1 | `php artisan reverb:start --debug` | Mantiene abiertos los WebSockets | La vista muestra "Sin conexión" |
| 2 | `php artisan schedule:work` | Ejecuta `metrics:broadcast` cada 5 s | Sigue "Conectado", pero los valores no cambian |
| 3 | `npm run dev` | Sirve el JavaScript de Echo y Chart.js | Error "Vite manifest not found" |
| 4 | `php artisan serve` | Sirve las páginas | El navegador no abre nada |

- Monitor: http://127.0.0.1:8000/servidor
- Gráficos: http://127.0.0.1:8000/graficos
- API del TP 1: http://127.0.0.1:8000/api/metrics

## Seguridad de los botones

- **Desactivados por defecto.** Solo funcionan con `DEMO_APPS=true`. Si no, responden 403.
- **Lista blanca.** El navegador manda una clave (`calculadora` o `bloc`), nunca un comando. Cualquier otra clave da 404, tanto en la ruta (`whereIn`) como en el controlador. Lo probé con `rm`, `calc.exe` y `../etc`, y las tres dieron 404.
- **Sin shell en Linux.** El programa se lanza con `proc_open(['setsid', '-f', $exe])`. Al pasar un array no interviene ninguna shell, así que no hay forma de inyectar comandos.
- **Límite de uso.** `throttle:2,1` deja abrir 2 programas por minuto desde cada PC; el siguiente pedido da 429.
- **CSRF.** Los botones mandan el token CSRF. Un POST desde otro sitio da 419.

## Respuestas

**1. ¿Qué diferencia ven en la pestaña Red entre el TP 1 (polling) y este TP?**

En el TP 1 aparecía un pedido HTTP nuevo a `/api/metrics` cada 5 segundos, cada uno con sus cabeceras, su conexión y su respuesta JSON completa, aunque los datos no hubieran cambiado. En este TP, con el filtro WS, hay **una sola conexión** abierta con Reverb en el puerto 8080. Esa conexión no se cierra, y en la sección *Mensajes* aparece un mensaje nuevo cada 5 segundos. No hay pedidos nuevos: el navegador no pregunta, el servidor avisa.

**2. ¿Qué pasa con la vista si detienen `reverb:start`? ¿Y si detienen `schedule:work`?**

- **Sin `reverb:start`:** se corta el WebSocket y el indicador pasa a **"Sin conexión"** (lo probé). Los valores quedan congelados en la última lectura. Al volver a levantar Reverb, Echo (pusher-js) se reconecta solo, sin recargar la página; en mi prueba tardó unos 20 segundos, porque reintenta con esperas cada vez más largas.
- **Sin `schedule:work`:** el WebSocket sigue abierto, así que el indicador sigue en **"Conectado"**, pero nadie dispara `MetricsUpdated` y los valores dejan de cambiar. En la prueba las métricas siguieron llegando unos segundos más, hasta terminar el minuto en curso. Pasa porque `schedule:work` lanza cada minuto un proceso `schedule:run`, y ese proceso hijo es el que hace las ejecuciones de cada 5 segundos dentro del minuto.

**3. ¿Por qué PowerShell se ejecuta la misma cantidad de veces con 1 o con 30 personas mirando?**

Porque las métricas ya no se leen cuando alguien las pide, sino cuando lo decide el scheduler: `metrics:broadcast` corre cada 5 segundos, o sea 12 veces por minuto, y ejecuta `ServerMetrics` (PowerShell en Windows) una sola vez. Después Reverb **reparte el mismo mensaje** a todas las conexiones abiertas. La cantidad de lecturas depende del reloj, no de la cantidad de personas. Con polling, 30 personas eran 30 × 12 = 360 pedidos y 360 ejecuciones de PowerShell por minuto.

**4. ¿Por qué `DemoController` usa una lista blanca en lugar de recibir el nombre del programa desde el navegador?**

Porque todo lo que llega del navegador lo puede modificar cualquiera: con F12, Postman o `curl` se puede mandar lo que uno quiera. Si el controlador ejecutara `popen($request->input('programa'), 'r')`, cualquier persona de la red podría correr **cualquier comando** en el servidor con los permisos de PHP: borrar archivos, descargar malware, leer el `.env` con las claves. Eso es **ejecución remota de comandos**, una de las vulnerabilidades más graves. Con la lista blanca el navegador solo elige una clave, y es el servidor el que la traduce a un programa fijo. Lo que no está en la lista no se ejecuta nunca.

**5. ¿Qué diferencia hay entre `ShouldBroadcast` y `ShouldBroadcastNow`?**

- **`ShouldBroadcast`** no envía el evento en el momento: lo pone en la **cola**, y hace falta otro proceso (`php artisan queue:work`) que lo tome y lo mande a Reverb. Sirve para que el pedido HTTP no espere, y permite reintentos si Reverb falla.
- **`ShouldBroadcastNow`** lo envía **en el mismo momento**, dentro del proceso que lo dispara, sin cola.

Acá se usa `ShouldBroadcastNow` porque las métricas son instantáneas: una lectura que llega tarde ya no sirve, y así no hace falta un quinto proceso corriendo.

**6. ¿Por qué la vista de gráficos no necesitó ningún cambio en el servidor? ¿Qué ventaja tiene haber usado un componente?**

Porque Reverb ya transmite `MetricsUpdated` por el canal público `server.metrics` a **todos** los que estén escuchando. La vista `/graficos` es simplemente un oyente más del mismo canal: recibe los mismos datos que `/servidor` y los dibuja en lugar de escribirlos. El servidor no sabe ni le importa cuántas pantallas hay ni qué hacen con los datos.

El componente `<x-grafico-metrica>` evita repetir el mismo HTML tres veces: la tarjeta, el título, el valor y el `<canvas>` están escritos una sola vez, y cada uso cambia solo los parámetros (`titulo`, `campo`, `color`, `tipo`). Para agregar un gráfico nuevo, por ejemplo la temperatura, alcanza con una línea en la vista. Un cambio de diseño se hace en un solo archivo y se aplica a todos.

## TP 1: respuestas

<details>
<summary>Preguntas del TP 1 (monitor con polling)</summary>

**1. ¿Por qué la temperatura suele aparecer como "No disponible" en Windows?**
La clase `MSAcpi_ThermalZoneTemperature` está en el namespace `root/wmi`, que solo se puede consultar con permisos de administrador. Desde una terminal común la consulta falla y el `catch` deja la temperatura en `null`. Además, aunque se ejecute como administrador, depende de que la placa madre informe la zona térmica por ACPI: muchas no lo hacen, y otras devuelven un valor fijo que no es la temperatura real. En Linux, en cambio, los sensores de `/sys/class/hwmon` se leen sin root. En este equipo (AMD), `thermal_zone0` no existe, pero el sensor `k10temp` informa la temperatura del procesador.

**2. ¿Por qué el script de PowerShell nunca debe incluir datos que vengan del usuario?**
Porque `shell_exec()` ejecuta el comando en el servidor con los permisos de PHP. Si el script se armara con datos de un formulario o de la URL, un atacante podría inyectar comandos propios. Esto se llama inyección de comandos. Por eso el script es fijo y no recibe ningún parámetro externo.

**3. ¿Qué riesgo tiene dejar `/api/metrics` sin autenticación en un servidor real?**
Filtración de información: el nombre del equipo, el sistema operativo y la versión de PHP sirven para buscar vulnerabilidades conocidas. Y denegación de servicio: cada pedido abre un proceso de PowerShell, y muchos pedidos a la vez saturan el servidor. El `throttle:60,1` limita por IP, pero no frena un ataque desde muchas IPs. En producción se protege con autenticación, por ejemplo `auth:sanctum`.

**4. Con polling cada 5 segundos, ¿cuántos pedidos por minuto recibe el servidor si hay 50 personas mirando?**
60 / 5 = 12 pedidos por persona por minuto, así que 50 × 12 = **600 pedidos por minuto** (10 por segundo).

**5. Si una app solo necesita la temperatura, ¿qué datos recibe de más al llamar a `/api/metrics`?**
Todo lo demás: `hostname`, `os`, `php`, `disk`, `memory`, `cpu_pct`, `uptime_s` y `at`. Esto se llama *over-fetching*, y es el problema que resuelve GraphQL: el cliente pide solo los campos que necesita.

</details>

## Problemas comunes

| Síntoma | Solución |
|---|---|
| El indicador dice "Sin conexión" | Levantar `php artisan reverb:start` |
| "Conectado", pero los valores no cambian | Revisar que `schedule:work` esté corriendo |
| `metrics:broadcast` falla con "Connection refused" | Levantar Reverb antes del scheduler |
| Error "Vite manifest not found" | `npm run dev` o `npm run build` |
| No aparecen los botones | Poner `DEMO_APPS=true` y correr `php artisan config:clear` |
| Error 429 al tocar un botón | Se superó el límite de 2 por minuto: esperar |
| Error 503 al tocar un botón (Linux) | No hay ninguna calculadora o editor de la lista instalados |
| El programa no se abre en Linux | `php artisan serve` tiene que correr desde una terminal de la sesión gráfica, para heredar `DISPLAY` / `WAYLAND_DISPLAY` |

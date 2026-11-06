<?php

namespace App\Http\Controllers;

use App\Events\AppOpened;
use Illuminate\Http\Request;

class DemoController extends Controller
{
    // Lista blanca: el navegador elige una CLAVE, nunca el comando.
    // En Windows se usan los programas del enunciado. En Linux se prueba cada
    // candidato en orden y se usa el primero que esté instalado (KDE, GNOME, XFCE).
    private const APPS = [
        'Windows' => [
            'calculadora' => ['calc.exe'],
            'bloc' => ['notepad.exe'],
        ],
        'Linux' => [
            'calculadora' => ['kcalc', 'gnome-calculator', 'galculator'],
            'bloc' => ['kwrite', 'kate', 'gnome-text-editor', 'gedit', 'mousepad'],
        ],
    ];

    public function abrir(Request $request, string $app)
    {
        // Solo funciona si la demo está activada y el sistema tiene programas definidos
        $apps = self::APPS[PHP_OS_FAMILY] ?? null;

        if (! config('app.demo_apps') || $apps === null) {
            abort(403, 'La demo está desactivada.');
        }

        $candidatos = $apps[$app] ?? abort(404);

        PHP_OS_FAMILY === 'Windows'
            ? $this->abrirEnWindows($candidatos[0])
            : $this->abrirEnLinux($candidatos);

        // Avisa a todos los navegadores conectados
        AppOpened::dispatch($app, $request->ip(), now()->format('H:i:s'));

        return response()->json(['ok' => true]);
    }

    // Abre el programa en el servidor sin esperar a que se cierre
    private function abrirEnWindows(string $exe): void
    {
        pclose(popen('start "" ' . $exe, 'r'));
    }

    private function abrirEnLinux(array $candidatos): void
    {
        $exe = collect($candidatos)->first(fn (string $programa) => $this->instalado($programa))
            ?? abort(503, 'No hay ningún programa instalado para esta acción.');

        // Se pasa un array a proc_open: no interviene ninguna shell, así que no hay
        // forma de inyectar comandos. "setsid -f" lo separa de PHP y vuelve enseguida.
        $proceso = proc_open(
            ['setsid', '-f', $exe],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        if (is_resource($proceso)) {
            proc_close($proceso);
        }
    }

    // Busca el ejecutable en las carpetas del PATH
    private function instalado(string $programa): bool
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $carpeta) {
            if (is_executable($carpeta . DIRECTORY_SEPARATOR . $programa)) {
                return true;
            }
        }

        return false;
    }
}

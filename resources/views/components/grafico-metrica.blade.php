@props([
    'titulo',
    'campo',
    'color' => '#1d5fa8',
    'tipo' => 'linea',
])

<div class="card">
    <div class="grafico-head">
        <span class="label">{{ $titulo }}</span>
        <span class="value" id="valor-{{ $campo }}">--</span>
    </div>
    <canvas data-grafico="{{ $campo }}" data-tipo="{{ $tipo }}" data-color="{{ $color }}"></canvas>
</div>

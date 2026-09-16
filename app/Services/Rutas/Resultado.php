<?php

namespace App\Services\Rutas;

use App\Models\Sides\SidesRuta;

/** Resultado de agregar clientes a una ruta: los que ya estaban en otra ruta se omiten. */
final class Resultado
{
    public function __construct(
        public readonly SidesRuta $ruta,
        public readonly int $agregados,
        public readonly int $recibidos,
    ) {
    }

    public function omitidos(): int
    {
        return max(0, $this->recibidos - $this->agregados);
    }

    public function mensaje(string $inicio): string
    {
        $mensaje = $inicio.' '.$this->agregados.' '.($this->agregados === 1 ? 'cliente agregado' : 'clientes agregados').'.';

        if ($this->omitidos() > 0) {
            $mensaje .= ' Omitidos: '.$this->omitidos().' (ya tenían ruta, estaban repetidos o no existen en SEPED).';
        }

        return $mensaje;
    }
}

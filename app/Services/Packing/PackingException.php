<?php

namespace App\Services\Packing;

use RuntimeException;

/** Regla de negocio de packing no cumplida; el mensaje se muestra tal cual al empacador. */
class PackingException extends RuntimeException
{
}

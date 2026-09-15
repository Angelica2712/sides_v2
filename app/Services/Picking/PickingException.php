<?php

namespace App\Services\Picking;

use RuntimeException;

/** Regla de negocio de picking no cumplida; el mensaje se muestra tal cual al operario. */
class PickingException extends RuntimeException
{
}

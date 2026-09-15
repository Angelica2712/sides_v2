<?php

namespace App\Services\BatchPicking;

use RuntimeException;

/** Regla de negocio de Batch Picking no cumplida; el mensaje se muestra tal cual. */
class BatchPickingException extends RuntimeException
{
}

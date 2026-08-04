<?php

// app/Exceptions/StaleModelException.php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException as parentAlias;

class StaleModelException extends parentAlias
{
    public function __construct(public Model $model)
    {
        parent::__construct(sprintf(
            'The [%s] model was changed by another process since it was retrieved.',
            class_basename($model)
        ));
    }
}

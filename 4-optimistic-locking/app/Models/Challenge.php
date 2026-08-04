<?php

namespace App\Models;

use App\Concerns\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Challenge extends Model
{
    use HasFactory;
    use HasOptimisticLocking;
}

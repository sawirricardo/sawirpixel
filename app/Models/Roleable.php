<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RoleableFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

#[Table(name: 'roleables')]
final class Roleable extends MorphPivot
{
    /** @use HasFactory<RoleableFactory> */
    use HasFactory;

    public $incrementing = true;
}

<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PermissionableFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

#[Table(name: 'permissionables')]
final class Permissionable extends MorphPivot
{
    /** @use HasFactory<PermissionableFactory> */
    use HasFactory;

    public $incrementing = true;
}

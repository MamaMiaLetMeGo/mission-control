<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class MissionSetting extends Model
{
    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}

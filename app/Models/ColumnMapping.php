<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ColumnMapping extends Model
{
    protected $fillable = [
        'source_signature',
        'source_column_name',
        'mapped_field',
        'created_by',
    ];
}

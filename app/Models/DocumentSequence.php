<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One counter per document type per UTC year. Only DocumentNumber touches it.
 */
class DocumentSequence extends Model
{
    protected $fillable = ['type', 'year', 'last_value'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_value' => 'integer',
        ];
    }
}

<?php

namespace Modules\Import\Entities;

use Illuminate\Database\Eloquent\Model;

class Attribute extends Model
{
    protected $table = 'attribute';

    protected $fillable = [
        'siteid',
        'type',
        'domain',
        'code',
        'label',
        'pos',
        'status',
        'editor'
    ];

    /**
     * Get types of the attribute
     */
    function type()
    {
        return $this->belongsTo(AttributeType::class, 'type', 'code');
    }
}

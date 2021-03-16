<?php

namespace Modules\Import\Entities;

use Illuminate\Database\Eloquent\Model;

class AttributeType extends Model
{
    protected $table = 'attribute_type';

    protected $fillable = [
        'siteid',
        'domain',
        'code',
        'label',
        'pos',
        'editor'
    ];

    /**
     * Get attributes of type
     */
    function attributes()
    {
        return $this->hasMany(Attribute::class, 'type', 'code');
    }
}

<?php

namespace Modules\Import\Entities;

use Illuminate\Database\Eloquent\Model;

class PriceList extends Model
{
    protected $table = 'price_list';

    protected $fillable = [
        'parentid',
        'siteid',
        'type',
        'domain',
        'refid',
        'pos',
        'status',
        'editor',
    ];
}

<?php

namespace Modules\Import\Entities;

use Illuminate\Database\Eloquent\Model;

class SupplierList extends Model
{
    protected $table = 'supplier_list';

    protected $fillable = [
        'parentid',
        'siteid',
        'type',
        'domain',
        'refid',
        'status',
        'editor'
    ];
}

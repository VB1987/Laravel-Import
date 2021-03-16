<?php

namespace Modules\Import\Entities;

use Illuminate\Database\Eloquent\Model;

class CatalogList extends Model
{
    protected $table = 'catalog_list';

    protected $fillable = [
        'parentid',
        'siteid',
        'type',
        'domain',
        'refid',
        'pos',
        'status',
        'editor'
    ];
}

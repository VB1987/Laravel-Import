<?php

namespace Modules\Import\Entities;

use Illuminate\Database\Eloquent\Model;

class ProductList extends Model
{
    protected $table = 'product_list';

    protected $fillable = [
        'parentid',
        'siteid',
        'type',
        'domain',
        'refid',
        'start',
        'end',
        'config',
        'pos',
        'status',
        'mtime',
        'ctime',
        'editor'
    ];

    protected $casts = [
        'config' => 'array',
    ];

    /**
     * Get linked product
     */
    function parent(){
        return $this->belongsTo(Product::class, 'parentid');
    }

    function text(){
        return $this->belongsTo(Text::class, 'refid');
    }
}

<?php

namespace Modules\Import\Entities;

use Illuminate\Database\Eloquent\Model;

class Text extends Model
{
    protected $table = 'text';

    protected $fillable = [
        'siteid',
        'type',
        'domain',
        'langid',
        'label',
        'content',
        'status',
        'mtime',
        'ctime',
        'editor'
    ];

    function productlist(){
        return $this->hasMany(ProductList::class, 'refid', 'id')->where('mshop_product_list.domain', 'text');
    }
}

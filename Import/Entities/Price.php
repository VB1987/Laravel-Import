<?php

namespace Modules\Import\Entities;

use Illuminate\Database\Eloquent\Model;

class Price extends Model
{
    protected $table = 'price';

    protected $fillable = [
        'siteid',
        'type',
        'domain',
        'label',
        'currencyid',
        'quantity',
        'type',
        'value',
        'costs',
        'rebate',
        'taxrate',
        'discount_percentage',
        'status',
        'editor',
    ];

    /**
     * Get price links
     */
    function priceList()
    {
        return $this->hasOne(PriceList::class, 'parentid', 'id');
    }

    /**
     * Get product link
     */
    function product()
    {
        return $this->belongsToMany(
            Product::class,
            'mshop_product_list',
            'refid',
            'parentid'
        )->where('mshop_product_list.domain', 'price')
            ->where('mshop_product_list.type', 'default');
    }

    function productList()
    {
        return $this->hasMany(ProductList::class, 'refid', 'id');
    }
}

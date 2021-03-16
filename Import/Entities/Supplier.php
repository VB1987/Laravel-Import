<?php

namespace Modules\Import\Entities;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $table = 'supplier';

    protected $fillable = [
        'siteid',
        'code',
        'label',
        'status',
        'editor'
    ];

    protected $appends = [
        'single_address'
    ];

    public function addresses() {
        return $this->hasMany(Addresses::class, 'parentid', 'id');
    }

    public function getSingleAddressAttribute() {
        return $this->addresses->first();
    }
}

<?php

namespace Modules\Import\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Addresses extends Model
{
    use SoftDeletes;

    public $incrementing = true;

    protected $fillable = [
        'addressable_type',
        'addressable_id',
        'primary_send',
        'primary_invoice',
        'primary_private',
        'company',
        'name',
        'street',
        'number',
        'numberExt',
        'postalcode',
        'city',
        'country',
    ];

    protected $hidden = [];

    protected $table = 'addresses';

    public $timestamps = true;

    public function addressable()
    {
        return $this->morphTo();
    }
}

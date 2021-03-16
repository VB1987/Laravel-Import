<?php

namespace Modules\Import\Entities;

use Illuminate\Database\Eloquent\Model;

class Catalog extends Model
{
    protected $table = 'catalog';

    protected $fillable = [
        'parentid',
        'siteid',
        'level',
        'code',
        'label',
        'config',
        'nleft',
        'nright',
        'target',
        'editor'
    ];

    /**
     * Get parent of catalog
     */
    public function parent() {
        return $this->belongsTo($this, 'parentid', 'id');
    }

    /**
     * Get children of the catalog
     */
    public function children() {
        return $this->hasMany($this, 'parentid', 'id');
    }

    /**
     * Add childrene to catalog
     */
    public function addChildren($children) {
        foreach($children as $child)
        {
            if(count($child->children) >= 1)
            {
                $this->addChildren($child->children);
            }
        }
    }
}

<?php

namespace Modules\Import\Entities;

use App\Http\Traits\HasTags;
use App\User;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    use HasTags;

    protected $table = 'media';

    protected $fillable = [];

    public function uploadedBy(){
        return $this->belongsTo(User::class, 'created_by');
    }
}

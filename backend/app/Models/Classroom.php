<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Classroom extends Model
{
    use HasFactory;

    protected $table = 'classes';

    protected $fillable = ['code', 'name'];

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'class_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Submission extends Model
{
    use HasFactory;

    protected $fillable = [
        'nim',
        'student_name',
        'class_id',
        'course_id',
        'meeting',
        'original_filename',
        'stored_path',
        'mime_type',
        'file_size',
    ];

    protected function casts(): array
    {
        return [
            'meeting' => 'integer',
            'file_size' => 'integer',
        ];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'class_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolClass extends Model
{
    protected $fillable = [
        'grade',
        'letter',
        'academic_year',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'academic_year' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /** «5-А» */
    protected function title(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->grade}-{$this->letter}");
    }
}

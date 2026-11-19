<?php

namespace Hmzisb\ReferenceData\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A language and its metadata.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property \stdClass $details
 */
class Language extends Model
{
    protected $table = 'languages';

    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = [
        'details' => 'object',
    ];

    /**
     * Filter by a lowercase two- or three-letter language code.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCode(Builder $query, string $code): Builder
    {
        return $query->where($this->qualifyColumn('code'), $code);
    }
}

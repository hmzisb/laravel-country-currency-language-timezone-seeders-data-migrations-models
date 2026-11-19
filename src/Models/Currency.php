<?php

namespace Hmzisb\ReferenceData\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A currency and its metadata.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int|null $minor_unit
 * @property \stdClass $details
 */
class Currency extends Model
{
    protected $table = 'currencies';

    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = [
        'minor_unit' => 'integer',
        'details' => 'object',
    ];

    /**
     * Filter by an uppercase three-letter currency code.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCode(Builder $query, string $code): Builder
    {
        return $query->where($this->qualifyColumn('code'), $code);
    }
}

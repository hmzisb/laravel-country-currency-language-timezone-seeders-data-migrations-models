<?php

namespace Hmzisb\ReferenceData\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An IANA time zone identifier supported by the catalog.
 *
 * @property int $id
 * @property string $code
 */
class Timezone extends Model
{
    protected $table = 'timezones';

    public $timestamps = false;

    /**
     * Filter by the stored IANA identifier, preserving its case.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCode(Builder $query, string $code): Builder
    {
        return $query->where($this->qualifyColumn('code'), $code);
    }
}

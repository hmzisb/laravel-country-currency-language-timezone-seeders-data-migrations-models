<?php

namespace Hmzisb\ReferenceData\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A country and its associated catalog codes.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $default_locale
 * @property string|null $region
 * @property string|null $flag
 * @property \stdClass $details
 * @property list<string> $currency_codes
 * @property list<string> $language_codes
 * @property list<string> $timezone_codes
 */
class Country extends Model
{
    protected $table = 'countries';

    public $timestamps = false;

    /**
     * Preserve the distinction between metadata objects and lists of codes.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'details' => 'object',
        'currency_codes' => 'array',
        'language_codes' => 'array',
        'timezone_codes' => 'array',
    ];

    /**
     * Filter by an uppercase two-letter country code.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCode(Builder $query, string $code): Builder
    {
        return $query->where($this->qualifyColumn('code'), $code);
    }

    /**
     * Filter to countries whose currency list contains the given code.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithCurrencyCode(Builder $query, string $code): Builder
    {
        return $query->whereJsonContains($this->qualifyColumn('currency_codes'), [$code]);
    }

    /**
     * Filter to countries whose language list contains the given code.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithLanguageCode(Builder $query, string $code): Builder
    {
        return $query->whereJsonContains($this->qualifyColumn('language_codes'), [$code]);
    }

    /**
     * Filter to countries whose time zone list contains the given identifier.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithTimezoneCode(Builder $query, string $code): Builder
    {
        return $query->whereJsonContains($this->qualifyColumn('timezone_codes'), [$code]);
    }

    /**
     * Build a currency lookup on the country model's connection.
     *
     * @return Builder<Currency>
     */
    public function currencyOptions(): Builder
    {
        return Currency::on($this->getConnectionName())->whereIn('code', $this->currency_codes ?? []);
    }

    /**
     * Build a language lookup on the country model's connection.
     *
     * @return Builder<Language>
     */
    public function languageOptions(): Builder
    {
        return Language::on($this->getConnectionName())->whereIn('code', $this->language_codes ?? []);
    }

    /**
     * Build a time zone lookup on the country model's connection.
     *
     * @return Builder<Timezone>
     */
    public function timezoneOptions(): Builder
    {
        return Timezone::on($this->getConnectionName())->whereIn('code', $this->timezone_codes ?? []);
    }
}

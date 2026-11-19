<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create the four catalog tables and enforce their top-level JSONB shapes.
     */
    public function up(): void
    {
        $connection = $this->postgresConnection();
        $schema = $connection->getSchemaBuilder();

        $schema->create('languages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('name', 255);
            $table->jsonb('details')->default('{}');
        });

        $schema->create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('name', 255);
            $table->smallInteger('minor_unit')->nullable();
            $table->jsonb('details')->default('{}');
        });

        $schema->create('timezones', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 255)->unique();
        });

        $schema->create('countries', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 2)->unique();
            $table->string('name', 255);
            $table->string('default_locale', 255)->nullable();
            $table->string('region', 255)->nullable();
            $table->string('flag', 16)->nullable();
            $table->jsonb('details')->default('{}');
            $table->jsonb('currency_codes')->default('[]');
            $table->jsonb('language_codes')->default('[]');
            $table->jsonb('timezone_codes')->default('[]');
        });

        $grammar = $connection->getSchemaGrammar();
        $languages = $grammar->wrapTable('languages');
        $currencies = $grammar->wrapTable('currencies');
        $countries = $grammar->wrapTable('countries');

        $connection->statement(<<<SQL
            ALTER TABLE {$languages}
                ADD CONSTRAINT languages_details_object CHECK (jsonb_typeof(details) = 'object')
            SQL);

        $connection->statement(<<<SQL
            ALTER TABLE {$currencies}
                ADD CONSTRAINT currencies_details_object CHECK (jsonb_typeof(details) = 'object'),
                ADD CONSTRAINT currencies_minor_unit_range CHECK (minor_unit IS NULL OR minor_unit BETWEEN 0 AND 4)
            SQL);

        $connection->statement(<<<SQL
            ALTER TABLE {$countries}
                ADD CONSTRAINT countries_details_object CHECK (jsonb_typeof(details) = 'object'),
                ADD CONSTRAINT countries_currency_codes_array CHECK (jsonb_typeof(currency_codes) = 'array'),
                ADD CONSTRAINT countries_language_codes_array CHECK (jsonb_typeof(language_codes) = 'array'),
                ADD CONSTRAINT countries_timezone_codes_array CHECK (jsonb_typeof(timezone_codes) = 'array')
            SQL);
    }

    /**
     * Drop the tables created by this migration.
     */
    public function down(): void
    {
        $schema = $this->postgresConnection()->getSchemaBuilder();

        $schema->dropIfExists('countries');
        $schema->dropIfExists('timezones');
        $schema->dropIfExists('currencies');
        $schema->dropIfExists('languages');
    }

    /**
     * Resolve the migration connection before running PostgreSQL-specific DDL.
     */
    private function postgresConnection(): Connection
    {
        $connection = DB::connection($this->getConnection());

        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('The reference data migration requires PostgreSQL.');
        }

        return $connection;
    }
};

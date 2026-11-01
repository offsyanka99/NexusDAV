<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sabre stores Unix timestamps in these columns. PostgreSQL integer is 32-bit,
 * so an event after 2038-01-19 failed with "integer out of range". SQLite
 * integers are already 64-bit and are left alone.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private array $columns = [
        'calendarobjects' => ['lastmodified', 'firstoccurence', 'lastoccurence'],
        'calendarsubscriptions' => ['lastmodified'],
        'schedulingobjects' => ['lastmodified'],
        'cards' => ['lastmodified'],
        'locks' => ['created'],
    ];

    public function up(): void
    {
        $this->resize(true);
    }

    public function down(): void
    {
        $this->resize(false);
    }

    private function resize(bool $wide): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->columns as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns, $wide): void {
                foreach ($columns as $column) {
                    $definition = $wide ? $table->bigInteger($column) : $table->integer($column);

                    if (! ($tableName === 'calendarobjects' && $column === 'lastmodified')) {
                        $definition->nullable();
                    }

                    $definition->change();
                }
            });
        }
    }
};

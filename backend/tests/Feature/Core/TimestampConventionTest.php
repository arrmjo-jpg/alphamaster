<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // PostgreSQL is the authoritative engine (ADR 0027) and the only one of the
    // two with a timezone-aware timestamp type: on SQLite `timestamps()` and
    // `timestampsTz()` both produce `datetime`, so there is no distinction to
    // assert and nothing this convention could catch there.
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Timestamp zone-awareness is a PostgreSQL distinction.');
    }
});

/**
 * Every `created_at` / `updated_at` in the schema, with its column type.
 *
 * @return array<string, string>
 */
function timestampColumnTypes(): array
{
    $rows = DB::select(
        "select table_name, column_name, data_type
           from information_schema.columns
          where table_schema = 'public'
            and column_name in ('created_at', 'updated_at')
          order by table_name, column_name"
    );

    $types = [];

    foreach ($rows as $row) {
        $types[$row->table_name.'.'.$row->column_name] = $row->data_type;
    }

    return $types;
}

test('every timestamp column in the schema is timezone aware', function (): void {
    // A deployment whose server zone changes must not reinterpret rows it has
    // already written, so the platform stores `timestamptz` wherever it stores a
    // moment at all (ADR 0029 item 7).
    $offenders = [];

    foreach (timestampColumnTypes() as $column => $type) {
        // The queue tables store unix seconds in an integer column. They are not
        // moments in the SQL sense and have no zone to be aware of.
        if (! str_starts_with($type, 'timestamp')) {
            continue;
        }

        if ($type !== 'timestamp with time zone') {
            $offenders[$column] = $type;
        }
    }

    expect($offenders)->toBe([]);
});

test('the tables that were converted are timezone aware', function (): void {
    // Named explicitly, because these three were the exception that the rule
    // above would otherwise only describe in the abstract: `permissions` and
    // `roles` arrived with Spatie's published migration, and `languages` was
    // written alongside them.
    $types = timestampColumnTypes();

    foreach (['permissions', 'roles', 'languages'] as $table) {
        foreach (['created_at', 'updated_at'] as $column) {
            expect($types)->toHaveKey($table.'.'.$column)
                ->and($types[$table.'.'.$column])->toBe('timestamp with time zone', $table.'.'.$column);
        }
    }
});

test('a converted table still reads back the moment it was given', function (): void {
    // The conversion reinterpreted existing values as UTC. Round-tripping a known
    // moment shows that reinterpretation matches what Eloquent writes, rather
    // than shifting rows by the server's offset.
    $moment = now()->setTimezone('UTC')->setDate(2024, 3, 1)->setTime(12, 30, 0);

    DB::table('languages')->insert([
        'id' => (string) Str::ulid(),
        'code' => 'zz',
        'name' => 'Timestamp Probe',
        'native_name' => 'Timestamp Probe',
        'direction' => 'ltr',
        'is_active' => false,
        'is_default' => false,
        'sort_order' => 99,
        'created_at' => $moment,
        'updated_at' => $moment,
    ]);

    $stored = DB::table('languages')->where('code', 'zz')->value('created_at');

    expect(Carbon\Carbon::parse((string) $stored)->utc()->format('Y-m-d H:i:s'))
        ->toBe($moment->format('Y-m-d H:i:s'));
});

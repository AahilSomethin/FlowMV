<?php

namespace App\Services\Analytics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Duration math stays in SQL. Median is the middle value, or the mean of the two middle values.
 */
final class DurationSql
{
    public static function seconds(string $startColumn, string $endColumn): string
    {
        self::guardColumn($startColumn);
        self::guardColumn($endColumn);

        if (DB::connection()->getDriverName() === 'pgsql') {
            return "extract(epoch from ({$endColumn} - {$startColumn}))";
        }

        return "((julianday({$endColumn}) - julianday({$startColumn})) * 86400.0)";
    }

    public static function utcDate(string $column): string
    {
        self::guardColumn($column);

        if (DB::connection()->getDriverName() === 'pgsql') {
            return "({$column})::date";
        }

        return "date({$column})";
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return array{sample_size: int, average_seconds: int|null, median_seconds: int|null}
     */
    public static function summarize(Builder $query, string $secondsSql): array
    {
        $inner = (clone $query)->reorder()->select(DB::raw($secondsSql.' as seconds'));
        $row = DB::selectOne(self::summarySql($inner->toSql()), $inner->getBindings());

        return self::numbers($row);
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  list<string>  $groupColumns
     * @return list<array<string, int|string|null>>
     */
    public static function grouped(Builder $query, string $secondsSql, array $groupColumns): array
    {
        $aliases = [];
        $selects = [];

        foreach ($groupColumns as $column) {
            self::guardColumn($column);
            $alias = substr($column, (int) strrpos($column, '.') + 1);
            $aliases[] = $alias;
            $selects[] = DB::raw($column.' as '.$alias);
        }

        $selects[] = DB::raw($secondsSql.' as seconds');
        $inner = (clone $query)->reorder()->select($selects);
        $partition = implode(', ', $aliases);
        $grouped = $partition;
        $sql = <<<SQL
            with durations as ({$inner->toSql()}),
            ranked as (
                select {$grouped}, seconds,
                       row_number() over (partition by {$partition} order by seconds) as rn,
                       count(*) over (partition by {$partition}) as cnt
                from durations
            )
            select {$grouped},
                   avg(seconds) as median_seconds,
                   max(cnt) as sample_size
            from ranked
            where rn in (cast((cnt + 1) / 2 as integer), cast((cnt + 2) / 2 as integer))
            group by {$grouped}
        SQL;

        return array_map(function (object $row) use ($aliases): array {
            $item = ['sample_size' => (int) $row->sample_size, 'median_seconds' => self::round($row->median_seconds)];

            foreach ($aliases as $alias) {
                $item[$alias] = $row->{$alias};
            }

            return $item;
        }, DB::select($sql, $inner->getBindings()));
    }

    private static function summarySql(string $inner): string
    {
        return <<<SQL
            with durations as ({$inner}),
            ranked as (
                select seconds,
                       row_number() over (order by seconds) as rn,
                       count(*) over () as cnt
                from durations
            )
            select
                (select count(*) from durations) as sample_size,
                (select avg(seconds) from durations) as average_seconds,
                (
                    select avg(seconds)
                    from ranked
                    where rn in (cast((cnt + 1) / 2 as integer), cast((cnt + 2) / 2 as integer))
                ) as median_seconds
        SQL;
    }

    /**
     * @return array{sample_size: int, average_seconds: int|null, median_seconds: int|null}
     */
    private static function numbers(?object $row): array
    {
        $sample = (int) ($row->sample_size ?? 0);

        return [
            'sample_size' => $sample,
            'average_seconds' => $sample === 0 ? null : self::round($row->average_seconds ?? null),
            'median_seconds' => $sample === 0 ? null : self::round($row->median_seconds ?? null),
        ];
    }

    private static function round(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) round((float) $value);
    }

    private static function guardColumn(string $column): void
    {
        if (preg_match('/^[a-z_]+\.[a-z_]+$/', $column) !== 1) {
            throw new \InvalidArgumentException('Duration column is not a qualified identifier.');
        }
    }
}

<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\LazyCollection;

final class ExportRows
{
    /** Traverse an existing timestamp DESC, id DESC query without offset shifts. */
    public static function descending(Builder|Relation $query, string $timestamp): LazyCollection
    {
        return new LazyCollection(function () use ($query, $timestamp) {
            $model = $query->getModel();
            $timeColumn = $model->qualifyColumn($timestamp);
            $keyColumn = $model->getQualifiedKeyName();
            $cursor = null;

            do {
                $batch = clone $query;
                if ($cursor !== null) {
                    [$time, $id] = $cursor;
                    $batch->where(function ($query) use ($timeColumn, $keyColumn, $time, $id) {
                        $query->where($timeColumn, '<', $time)
                            ->orWhere(fn ($query) => $query->where($timeColumn, $time)->where($keyColumn, '<', $id));
                    });
                }

                $records = $batch->limit(200)->get();
                if ($records->isEmpty()) {
                    return;
                }

                $last = $records->last();
                $cursor = [$last->getRawOriginal($timestamp), $last->getKey()];
                foreach ($records as $record) {
                    yield $record;
                }
            } while ($records->count() === 200);
        });
    }
}

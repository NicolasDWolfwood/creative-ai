<?php

namespace App\Services;

use App\Enums\PostMediaType;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Playlist;
use App\Models\Track;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoryOpportunityService
{
    public const PER_PAGE = 20;

    public const SEARCH_LIMIT = 100;

    /** @return array<string, int> */
    public function counts(): array
    {
        return collect(PostMediaType::cases())
            ->mapWithKeys(fn (PostMediaType $type): array => [
                $type->value => $this->modelQuery($type)->count(),
            ])
            ->all();
    }

    public function count(): int
    {
        return array_sum($this->counts());
    }

    /**
     * @return LengthAwarePaginator<int, Model>
     */
    public function paginate(
        ?PostMediaType $type = null,
        ?string $search = null,
        int $perPage = self::PER_PAGE,
        int $page = 1,
        string $pageName = 'opportunities',
    ): LengthAwarePaginator {
        $search = Str::of((string) $search)
            ->squish()
            ->limit(self::SEARCH_LIMIT, '')
            ->toString();
        $types = $type ? [$type] : PostMediaType::cases();
        $queries = collect($types)
            ->map(fn (PostMediaType $type): Builder => $this->unionSourceQuery($type, $search))
            ->values();

        /** @var Builder $union */
        $union = $queries->shift();

        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        $paginator = DB::query()
            ->fromSub($union, 'story_opportunities')
            ->orderByDesc('updated_at')
            ->orderBy('opportunity_type')
            ->orderByDesc('id')
            ->paginate(
                perPage: max(1, min($perPage, 100)),
                pageName: $pageName,
                page: max(1, $page),
            );

        $rows = $paginator->getCollection();
        $models = collect($types)->flatMap(function (PostMediaType $type) use ($rows) {
            $ids = $rows
                ->where('opportunity_type', $type->value)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id);

            if ($ids->isEmpty()) {
                return [];
            }

            return $this->modelQuery($type)
                ->whereKey($ids)
                ->get()
                ->mapWithKeys(fn (Model $model): array => [$type->value.':'.$model->getKey() => $model]);
        });

        $paginator->setCollection(
            $rows
                ->map(fn (object $row): ?Model => $models->get($row->opportunity_type.':'.$row->id))
                ->filter()
                ->values(),
        );

        return $paginator;
    }

    public function find(PostMediaType $type, int $id): ?Model
    {
        return $this->modelQuery($type)->find($id);
    }

    protected function modelQuery(PostMediaType $type): EloquentBuilder
    {
        /** @var EloquentBuilder $query */
        $query = match ($type) {
            PostMediaType::Track => Track::query()->publiclyAvailable(),
            PostMediaType::Artwork => Artwork::query()->published(),
            PostMediaType::Collection => Collection::query()->published(),
            PostMediaType::Album => Album::query()->published(),
            PostMediaType::Playlist => Playlist::query()->published(),
        };

        return $query->whereDoesntHave('journalMediaItems');
    }

    protected function unionSourceQuery(PostMediaType $type, ?string $search): Builder
    {
        $query = $this->modelQuery($type);

        if ($search !== '') {
            $query->where(function (EloquentBuilder $query) use ($type, $search): void {
                $query->whereLike('title', "%{$search}%");

                foreach ($this->searchableColumns($type) as $column) {
                    $query->orWhereLike($column, "%{$search}%");
                }
            });
        }

        return $query
            ->select(['id', 'title', 'updated_at'])
            ->selectRaw('? as opportunity_type', [$type->value])
            ->toBase();
    }

    /** @return list<string> */
    protected function searchableColumns(PostMediaType $type): array
    {
        return match ($type) {
            PostMediaType::Album => ['artist', 'album_artist', 'description'],
            PostMediaType::Track => ['artist', 'description'],
            PostMediaType::Artwork,
            PostMediaType::Collection,
            PostMediaType::Playlist => ['description'],
        };
    }
}

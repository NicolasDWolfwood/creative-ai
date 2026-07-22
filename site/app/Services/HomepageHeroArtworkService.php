<?php

namespace App\Services;

use App\Models\Artwork;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class HomepageHeroArtworkService
{
    protected const CACHE_KEY_VERSION = 'showcase.homepage-hero.v1';

    protected const SECONDS_PER_DAY = 86_400;

    public function select(?CarbonInterface $forDay = null): ?Artwork
    {
        $day = CarbonImmutable::instance($forDay ?: CarbonImmutable::now())
            ->utc()
            ->startOfDay();
        $cacheKey = self::CACHE_KEY_VERSION.':'.$day->toDateString();
        $cachedId = Cache::get($cacheKey);

        if (is_int($cachedId) || (is_string($cachedId) && ctype_digit($cachedId))) {
            $cached = $this->eligibleQuery()->find((int) $cachedId);

            if ($cached?->hasAvailableDisplayImage()) {
                return $cached;
            }

            Cache::forget($cacheKey);
        }

        $candidate = $this->chooseForDay($day);

        if ($candidate) {
            // The date is part of the key, so the next UTC day rotates without
            // explicit invalidation. Retain yesterday's key only long enough
            // for in-flight requests to settle.
            Cache::put($cacheKey, (int) $candidate->getKey(), $day->addDays(2));
        }

        return $candidate;
    }

    protected function chooseForDay(CarbonImmutable $day): ?Artwork
    {
        $candidates = $this->eligibleQuery()
            ->orderBy('id')
            ->get()
            ->filter(fn (Artwork $artwork): bool => $artwork->hasAvailableDisplayImage())
            ->sortBy(
                fn (Artwork $artwork): string => hash('sha256', 'homepage-hero:'.$artwork->getKey()),
                SORT_STRING,
            )
            ->values();

        if ($candidates->isEmpty()) {
            // Do not cache an empty pool: the first newly Featured or published
            // artwork should become visible immediately.
            return null;
        }

        $dayNumber = intdiv($day->getTimestamp(), self::SECONDS_PER_DAY);

        return $candidates->get($dayNumber % $candidates->count());
    }

    /** @return Builder<Artwork> */
    protected function eligibleQuery(): Builder
    {
        return Artwork::query()->homepageHeroEligible();
    }
}

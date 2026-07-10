<?php

namespace App\Observers;

use App\Models\Collection;
use App\Services\SmartCollectionService;

class CollectionObserver
{
    public function saved(Collection $collection): void
    {
        if ($collection->is_smart && $collection->auto_sync) {
            app(SmartCollectionService::class)->sync($collection);
        }
    }
}

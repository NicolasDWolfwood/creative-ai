<?php

namespace App\Models;

use App\Models\Concerns\BuildsSlugs;
use App\Models\Concerns\HasPublicationSchedule;
use App\Services\PrivateMediaService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Artwork extends Model
{
    use BuildsSlugs;
    use HasFactory;
    use HasPublicationSchedule;

    public const AI_STATUS_IDLE = 'idle';

    public const AI_STATUS_QUEUED = 'queued';

    public const AI_STATUS_PROCESSING = 'processing';

    public const AI_STATUS_READY = 'ready';

    public const AI_STATUS_FAILED = 'failed';

    public const AI_STATUS_APPLIED = 'applied';

    public const VARIANT_STATUS_PENDING = 'pending';

    public const VARIANT_STATUS_QUEUED = 'queued';

    public const VARIANT_STATUS_PROCESSING = 'processing';

    public const VARIANT_STATUS_READY = 'ready';

    public const VARIANT_STATUS_FAILED = 'failed';

    public const SIGNATURE_MODE_AUTOMATIC = 'automatic';

    public const SIGNATURE_MODE_BLACK = 'black';

    public const SIGNATURE_MODE_WHITE = 'white';

    public const SIGNATURE_MODE_EMBEDDED = 'embedded';

    public const SIGNATURE_MODE_NONE = 'none';

    /** Upload services can set this before save so the observer captures the intent. */
    public bool $analyzeAfterVariantGeneration = false;

    /**
     * Programmatic legacy imports that only provide image_path remain treated
     * as already signed. User-facing upload paths explicitly select Automatic.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'signature_mode' => self::SIGNATURE_MODE_EMBEDDED,
    ];

    protected $fillable = [
        'collection_id',
        'title',
        'slug',
        'description',
        'alt_text',
        'prompt',
        'process_notes',
        'image_path',
        'master_path',
        'display_path',
        'thumb_path',
        'signature_mode',
        'signature_position',
        'signature_active_treatment',
        'signature_resolved_tone',
        'signature_resolved_position',
        'signature_review_recommended',
        'signature_recipe_fingerprint',
        'signature_settings_revision',
        'original_filename',
        'width',
        'height',
        'public_width',
        'public_height',
        'variant_status',
        'variant_generation_token',
        'variant_error',
        'variant_queued_at',
        'variant_started_at',
        'variants_generated_at',
        'sort_order',
        'featured',
        'published',
        'generated_at',
        'published_at',
        'metadata',
        'ai_status',
        'ai_queue_token',
        'ai_apply_after_analysis',
        'ai_suggestion',
        'ai_model',
        'ai_error',
        'ai_queued_at',
        'ai_started_at',
        'ai_analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'published' => 'boolean',
            'generated_at' => 'datetime',
            'published_at' => 'datetime',
            'variant_queued_at' => 'datetime',
            'variant_started_at' => 'datetime',
            'variants_generated_at' => 'datetime',
            'signature_review_recommended' => 'boolean',
            'metadata' => 'array',
            'ai_suggestion' => 'array',
            'ai_apply_after_analysis' => 'boolean',
            'ai_queued_at' => 'datetime',
            'ai_started_at' => 'datetime',
            'ai_analyzed_at' => 'datetime',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class)
            ->withTimestamps()
            ->orderBy('sort_order');
    }

    /**
     * Standalone publication and collection-controlled availability are kept
     * separate so a collection can expose a member without listing it in the
     * global artwork archive.
     */
    #[Scope]
    protected function publiclyAvailable(Builder $query): void
    {
        $query
            ->where(function (Builder $query): void {
                $query
                    ->published()
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where(function (Builder $query): void {
                                $query
                                    ->whereNull('published_at')
                                    ->orWhere('published_at', '<=', now());
                            })
                            ->whereHas('collections', fn (Builder $query) => $query
                                ->published()
                                ->memberPublicationGrants());
                    });
            })
            ->withPublicRenditions();
    }

    /**
     * Homepage hero visibility is deliberately narrower than general public
     * availability. Featured drafts may supply the homepage display image, but
     * they do not gain artwork-detail, archive, sitemap, or generic media access.
     */
    #[Scope]
    protected function homepageHeroEligible(Builder $query): void
    {
        $query
            ->where(function (Builder $query): void {
                $query
                    ->where('featured', true)
                    ->orWhere('published', true);
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->withPublicRenditions();
    }

    public function isHomepageHeroEligible(): bool
    {
        return $this->hasPublicRenditions()
            && ((bool) $this->featured || (bool) $this->published)
            && (! $this->published_at || ! $this->published_at->isFuture());
    }

    public function isPubliclyAvailable(): bool
    {
        if (! $this->hasPublicRenditions()) {
            return false;
        }

        if ($this->isPubliclyPublished()) {
            return true;
        }

        if ($this->published_at?->isFuture()) {
            return false;
        }

        return $this->collections()
            ->published()
            ->memberPublicationGrants()
            ->exists();
    }

    public function effectivePublishedAt(): ?CarbonInterface
    {
        $dates = collect();

        if ($this->isPubliclyPublished() && $this->published_at) {
            $dates->push($this->published_at);
        }

        $this->collections()
            ->published()
            ->memberPublicationGrants()
            ->pluck('collections.published_at')
            ->each(function (mixed $publishedAt) use ($dates): void {
                $collectionPublishedAt = match (true) {
                    $publishedAt instanceof CarbonInterface => $publishedAt,
                    filled($publishedAt) => CarbonImmutable::parse((string) $publishedAt),
                    default => null,
                };
                $effectiveGrantDate = $collectionPublishedAt;

                if ($this->published_at
                    && (! $effectiveGrantDate || $this->published_at->gt($effectiveGrantDate))) {
                    $effectiveGrantDate = $this->published_at;
                }

                if ($effectiveGrantDate) {
                    $dates->push($effectiveGrantDate);
                }
            });

        return $dates
            ->sortBy(fn (CarbonInterface $publishedAt): int => $publishedAt->getTimestamp())
            ->first();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->withPivot('category')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function journalMediaItems(): HasMany
    {
        return $this->hasMany(PostMedia::class);
    }

    public function getImageUrlAttribute(): string
    {
        return route('media.artworks.show', [$this, 'variant' => 'original', 'v' => $this->mediaVersion($this->image_path)]);
    }

    public function getMasterUrlAttribute(): string
    {
        return route('media.artworks.show', [
            $this,
            'variant' => 'master',
            'v' => $this->mediaVersion($this->masterPath()),
        ]);
    }

    public function getPublicImageUrlAttribute(): string
    {
        return route('artworks.image', ['artwork' => $this, 'v' => $this->mediaVersion($this->image_path)]);
    }

    public function getDisplayUrlAttribute(): string
    {
        return route('media.artworks.show', [$this, 'variant' => 'display', 'v' => $this->mediaVersion($this->availableDisplayPath())]);
    }

    public function getHomepageDisplayUrlAttribute(): string
    {
        return route('media.artworks.homepage-display', [
            'artwork' => $this,
            'v' => $this->mediaVersion($this->availableDisplayPath()),
        ]);
    }

    public function getThumbUrlAttribute(): string
    {
        return route('media.artworks.show', [$this, 'variant' => 'thumb', 'v' => $this->mediaVersion($this->availableThumbPath())]);
    }

    public function availableDisplayPath(): string
    {
        return $this->firstExistingPath([
            $this->display_path,
            $this->image_path,
        ]);
    }

    public function availableThumbPath(): string
    {
        return $this->firstExistingPath([
            $this->thumb_path,
            $this->display_path,
            $this->image_path,
        ]);
    }

    public function imageVariantsExist(): bool
    {
        if (blank($this->image_path)
            || blank($this->display_path)
            || blank($this->thumb_path)) {
            return false;
        }

        $disk = app(PrivateMediaService::class);

        return $disk->sourceDisk($this->image_path)->exists($this->image_path)
            && $disk->sourceDisk($this->display_path)->exists($this->display_path)
            && $disk->sourceDisk($this->thumb_path)->exists($this->thumb_path);
    }

    #[Scope]
    protected function withPublicRenditions(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query
                ->where(function (Builder $query): void {
                    $query
                        ->where('signature_active_treatment', 'embedded')
                        ->whereNotNull('image_path');
                })
                ->orWhere(function (Builder $query): void {
                    $query->where(function (Builder $query): void {
                        $query
                            ->where('signature_active_treatment', 'signed')
                            ->orWhere(function (Builder $query): void {
                                $query
                                    ->where('signature_active_treatment', 'unsigned')
                                    ->where('signature_mode', self::SIGNATURE_MODE_NONE);
                            });
                    })
                        ->whereNotNull('image_path')
                        ->whereNotNull('display_path')
                        ->whereNotNull('thumb_path');
                });
        });
    }

    public function masterPath(): string
    {
        return (string) ($this->master_path ?: (
            $this->signature_mode === self::SIGNATURE_MODE_EMBEDDED ? $this->image_path : null
        ));
    }

    public function hasPublicRenditions(): bool
    {
        $activeTreatment = $this->signature_active_treatment;

        // Database defaults protect queried legacy rows. This fallback also
        // keeps freshly-created in-memory legacy records compatible before an
        // explicit refresh has loaded that default.
        if (blank($activeTreatment)
            && $this->signature_mode === self::SIGNATURE_MODE_EMBEDDED
            && filled($this->image_path)) {
            $activeTreatment = 'embedded';
        }

        $signedTreatmentRequired = in_array($this->signature_mode, [
            self::SIGNATURE_MODE_AUTOMATIC,
            self::SIGNATURE_MODE_BLACK,
            self::SIGNATURE_MODE_WHITE,
            self::SIGNATURE_MODE_EMBEDDED,
        ], true);

        if ($signedTreatmentRequired
            && ! in_array($activeTreatment, ['signed', 'embedded'], true)) {
            return false;
        }

        if ($activeTreatment === 'embedded') {
            return filled($this->image_path);
        }

        return filled($this->image_path)
            && filled($this->display_path)
            && filled($this->thumb_path);
    }

    public function requiresSignatureAsset(): bool
    {
        return in_array($this->signature_mode, [
            self::SIGNATURE_MODE_AUTOMATIC,
            self::SIGNATURE_MODE_BLACK,
            self::SIGNATURE_MODE_WHITE,
        ], true);
    }

    public function signatureStatus(?string $currentSettingsRevision = null): string
    {
        if ($this->variant_status === self::VARIANT_STATUS_FAILED) {
            return self::VARIANT_STATUS_FAILED;
        }

        if (in_array($this->variant_status, [
            self::VARIANT_STATUS_PENDING,
            self::VARIANT_STATUS_QUEUED,
            self::VARIANT_STATUS_PROCESSING,
        ], true)) {
            return $this->hasPublicRenditions() ? 'updating' : (string) $this->variant_status;
        }

        if ($currentSettingsRevision
            && $this->signature_mode !== self::SIGNATURE_MODE_EMBEDDED
            && ! hash_equals((string) $this->signature_settings_revision, $currentSettingsRevision)) {
            return 'stale';
        }

        return $this->hasPublicRenditions() ? self::VARIANT_STATUS_READY : self::VARIANT_STATUS_PENDING;
    }

    public function hasAvailableImage(): bool
    {
        foreach ([$this->thumb_path, $this->display_path, $this->image_path] as $path) {
            if (blank($path)) {
                continue;
            }

            $disk = app(PrivateMediaService::class)->sourceDisk((string) $path);

            if ($disk->exists($path)) {
                return true;
            }
        }

        return false;
    }

    public function hasAvailableDisplayImage(): bool
    {
        foreach ([$this->display_path, $this->image_path] as $path) {
            if (blank($path)) {
                continue;
            }

            $disk = app(PrivateMediaService::class)->sourceDisk((string) $path);

            if ($disk->exists($path)) {
                return true;
            }
        }

        return false;
    }

    public function getImageAltAttribute(): string
    {
        return $this->alt_text ?: $this->title;
    }

    /** @param array<int, string|null> $paths */
    protected function firstExistingPath(array $paths): string
    {
        foreach ($paths as $path) {
            if (blank($path)) {
                continue;
            }

            $disk = app(PrivateMediaService::class)->sourceDisk((string) $path);

            if ($disk->exists($path)) {
                return $path;
            }
        }

        return (string) ($this->image_path ?: collect($paths)->first(fn (?string $path): bool => filled($path)));
    }

    protected function mediaVersion(?string $path): string
    {
        return substr(hash('sha256', (string) $path), 0, 12);
    }
}

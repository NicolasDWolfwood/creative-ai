<?php

namespace App\Services;

use App\Enums\ArtworkSignatureMode;
use App\Enums\ArtworkSignaturePosition;
use App\Models\Artwork;
use App\Models\SiteSetting;
use App\Rules\ValidArtworkSignatureMask;
use BackedEnum;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class ArtworkSignatureSettings
{
    public const SETTING_KEY = 'artwork_signature_configuration';

    public const RENDERER_VERSION = ArtworkSignatureRenderer::RENDERER_VERSION;

    /** @var array<string, mixed>|null */
    protected ?array $resolved = null;

    /** @return array<string, mixed> */
    public function current(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $stored = SiteSetting::query()
            ->where('key', self::SETTING_KEY)
            ->first()?->value;

        return $this->resolved = $this->resolve(is_array($stored) ? $stored : []);
    }

    /** @return array<string, mixed> */
    public function formValues(): array
    {
        return $this->current();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function save(array $values): array
    {
        $candidate = array_replace($this->formValues(), $values);
        $validated = validator($candidate, [
            'asset_path' => ['required', 'string', 'max:255'],
            'default_mode' => ['required', Rule::enum(ArtworkSignatureMode::class)],
            'default_position' => ['required', Rule::enum(ArtworkSignaturePosition::class)],
            'scale_bp' => ['required', 'integer', 'min:100', 'max:5000'],
            'inset_bp' => ['required', 'integer', 'min:0', 'max:2500'],
            'opacity_bp' => ['required', 'integer', 'min:100', 'max:10000'],
        ])->validate();

        try {
            $asset = ValidArtworkSignatureMask::inspect($validated['asset_path']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'asset_path' => $exception->getMessage(),
            ]);
        }

        $configuration = $this->resolve([
            'asset_path' => $validated['asset_path'],
            'asset_sha256' => $asset['sha256'],
            'asset_width' => $asset['width'],
            'asset_height' => $asset['height'],
            'default_mode' => $this->mode($validated['default_mode'])->value,
            'default_position' => $this->position($validated['default_position'])->value,
            'scale_bp' => (int) $validated['scale_bp'],
            'inset_bp' => (int) $validated['inset_bp'],
            'opacity_bp' => (int) $validated['opacity_bp'],
        ]);

        SiteSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $configuration],
        );

        return $this->resolved = $configuration;
    }

    public function refresh(): self
    {
        $this->resolved = null;

        return $this;
    }

    public function hasAsset(): bool
    {
        $settings = $this->current();
        $path = $settings['asset_path'] ?? null;

        return is_string($path)
            && filled($settings['asset_sha256'] ?? null)
            && (int) ($settings['asset_width'] ?? 0) > 0
            && (int) ($settings['asset_height'] ?? 0) > 0
            && Storage::disk('local')->exists($path);
    }

    public function revision(): string
    {
        return (string) $this->current()['revision'];
    }

    public function defaultMode(): ArtworkSignatureMode
    {
        return $this->mode($this->current()['default_mode'] ?? null);
    }

    public function defaultPosition(): ArtworkSignaturePosition
    {
        return $this->position($this->current()['default_position'] ?? null);
    }

    /**
     * Return a content-addressed, serialization-safe recipe. Storage-relative
     * paths stay relative here so queue payloads do not capture host paths.
     *
     * @return array<string, mixed>
     */
    public function recipeFor(Artwork $artwork): array
    {
        $settings = $this->current();
        $mode = $this->mode($artwork->getAttribute('signature_mode') ?? $settings['default_mode']);
        $position = $this->position(
            $artwork->getAttribute('signature_position') ?? $settings['default_position'],
        );

        return [
            'renderer' => ArtworkSignatureRenderer::RENDERER_VERSION,
            'settings_revision' => (string) $settings['revision'],
            'signature_path' => is_string($settings['asset_path']) ? $settings['asset_path'] : null,
            'signature_sha256' => is_string($settings['asset_sha256']) ? $settings['asset_sha256'] : null,
            'treatment' => $mode->value,
            'corner' => $position->value,
            'scale_bp' => (int) $settings['scale_bp'],
            'inset_bp' => (int) $settings['inset_bp'],
            'opacity_bp' => (int) $settings['opacity_bp'],
            'matte_rgb' => $settings['matte_rgb'],
            'jpeg_quality' => (int) $settings['jpeg_quality'],
            'outputs' => [
                'large' => (int) $settings['large_max_width'],
                'display' => (int) $settings['display_max_width'],
                'thumb' => (int) $settings['thumb_max_width'],
            ],
        ];
    }

    /** @param array<string, mixed> $stored */
    protected function resolve(array $stored): array
    {
        $assetPath = $this->managedAssetPath($stored['asset_path'] ?? null);
        $assetSha256 = is_string($stored['asset_sha256'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/D', (string) $stored['asset_sha256'])
                ? strtolower((string) $stored['asset_sha256'])
                : null;
        $assetWidth = $this->storedInteger($stored, 'asset_width', null, 1, ValidArtworkSignatureMask::MAX_PIXELS);
        $assetHeight = $this->storedInteger($stored, 'asset_height', null, 1, ValidArtworkSignatureMask::MAX_PIXELS);

        if ($assetPath === null
            || $assetSha256 === null
            || $assetWidth === null
            || $assetHeight === null
            || $assetWidth > intdiv(ValidArtworkSignatureMask::MAX_PIXELS, $assetHeight)) {
            $assetPath = null;
            $assetSha256 = null;
            $assetWidth = null;
            $assetHeight = null;
        }

        $configuration = [
            'asset_path' => $assetPath,
            'asset_sha256' => $assetSha256,
            'asset_width' => $assetWidth,
            'asset_height' => $assetHeight,
            'default_mode' => $this->mode($stored['default_mode'] ?? null)->value,
            'default_position' => $this->position($stored['default_position'] ?? null)->value,
            'scale_bp' => $this->storedInteger($stored, 'scale_bp', 1200, 100, 5000),
            'inset_bp' => $this->storedInteger($stored, 'inset_bp', 300, 0, 2500),
            'opacity_bp' => $this->storedInteger($stored, 'opacity_bp', 10000, 100, 10000),
            'matte_rgb' => $this->matteRgb(),
            'jpeg_quality' => max(1, min(100, (int) config('creative_ai.image_variants.jpeg_quality', 86))),
            'large_max_width' => max(1, (int) config('creative_ai.image_variants.large', 2560)),
            'display_max_width' => max(1, (int) config('creative_ai.image_variants.display', 1600)),
            'thumb_max_width' => max(1, (int) config('creative_ai.image_variants.thumb', 720)),
        ];

        $revisionPayload = $configuration;
        unset($revisionPayload['asset_path']);

        return [
            ...$configuration,
            'revision' => $this->fingerprint([
                'settings_schema' => 1,
                'renderer' => self::RENDERER_VERSION,
                ...$revisionPayload,
            ]),
        ];
    }

    protected function mode(mixed $value): ArtworkSignatureMode
    {
        if ($value instanceof ArtworkSignatureMode) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return is_string($value)
            ? ArtworkSignatureMode::tryFrom($value) ?? ArtworkSignatureMode::Automatic
            : ArtworkSignatureMode::Automatic;
    }

    protected function position(mixed $value): ArtworkSignaturePosition
    {
        if ($value instanceof ArtworkSignaturePosition) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return is_string($value)
            ? ArtworkSignaturePosition::tryFrom($value) ?? ArtworkSignaturePosition::BottomRight
            : ArtworkSignaturePosition::BottomRight;
    }

    protected function managedAssetPath(mixed $path): ?string
    {
        if (! is_string($path)
            || ! preg_match(
                '/\Aartwork-signatures\/assets\/[A-Za-z0-9][A-Za-z0-9._-]*\.png\z/Di',
                $path,
            )) {
            return null;
        }

        return $path;
    }

    /** @return array{0:int,1:int,2:int} */
    protected function matteRgb(): array
    {
        $matte = config('creative_ai.image_variants.matte_rgb', [12, 13, 16]);

        if (! is_array($matte) || count($matte) !== 3) {
            return [12, 13, 16];
        }

        $channels = array_values($matte);

        foreach ($channels as $channel) {
            if (filter_var($channel, FILTER_VALIDATE_INT) === false
                || (int) $channel < 0
                || (int) $channel > 255) {
                return [12, 13, 16];
            }
        }

        return [
            (int) $channels[0],
            (int) $channels[1],
            (int) $channels[2],
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    protected function storedInteger(
        array $stored,
        string $key,
        ?int $default,
        int $minimum,
        int $maximum,
    ): ?int {
        $value = filter_var($stored[$key] ?? null, FILTER_VALIDATE_INT);

        if ($value === false || $value < $minimum || $value > $maximum) {
            return $default;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function fingerprint(array $values): string
    {
        try {
            return hash('sha256', json_encode(
                $values,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            ));
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to fingerprint artwork signature settings.', previous: $exception);
        }
    }
}

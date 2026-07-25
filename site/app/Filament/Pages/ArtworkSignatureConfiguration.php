<?php

namespace App\Filament\Pages;

use App\Enums\ArtworkSignatureMode;
use App\Enums\ArtworkSignaturePosition;
use App\Jobs\GenerateArtworkVariants;
use App\Models\Artwork;
use App\Models\User;
use App\Rules\ValidArtworkSignatureMask;
use App\Services\ArtworkSignatureSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class ArtworkSignatureConfiguration extends Page
{
    protected string $view = 'filament.pages.artwork-signature-configuration';

    protected static ?string $title = 'Artwork signatures';

    protected static ?string $navigationLabel = 'Artwork signatures';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-pencil';

    protected static string|\UnitEnum|null $navigationGroup = 'Publishing';

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'artwork-signatures';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public ?string $previewUrl = null;

    public ?string $assetSummary = null;

    public ?string $revisionSummary = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_admin;
    }

    public function mount(): void
    {
        $this->fillFromSettings(app(ArtworkSignatureSettings::class));
    }

    public function getTitle(): string|Htmlable
    {
        return 'Artwork signatures';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Keep private masters untouched while generated public renditions receive a consistent, contrast-aware signature.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Signature alpha mask')
                    ->description('Upload one monochrome PNG with a transparent background. The renderer derives both black and white signatures from its alpha channel.')
                    ->schema([
                        FileUpload::make('asset_path')
                            ->label('Private signature PNG')
                            ->disk('local')
                            ->directory('artwork-signatures/assets')
                            ->visibility('private')
                            ->acceptedFileTypes(['image/png'])
                            ->maxSize(5120)
                            ->rules([new ValidArtworkSignatureMask])
                            ->getUploadedFileNameForStorageUsing(
                                fn (TemporaryUploadedFile $file): string => Str::ulid().'.png',
                            )
                            ->image()
                            ->imagePreviewHeight('16rem')
                            ->openable()
                            ->downloadable()
                            ->required()
                            ->helperText('PNG only, up to 5 megapixels and 5 MiB. Visible pixels must not touch the canvas edge.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Default treatment')
                    ->description('These defaults apply to new artwork. Every artwork can override its treatment and corner without changing publication state.')
                    ->columns(2)
                    ->schema([
                        Select::make('default_mode')
                            ->label('New artwork treatment')
                            ->options(ArtworkSignatureMode::options())
                            ->native(false)
                            ->required(),
                        Select::make('default_position')
                            ->label('Default corner')
                            ->options(ArtworkSignaturePosition::options())
                            ->native(false)
                            ->required(),
                    ]),
                Section::make('Placement')
                    ->description('Percentages are relative to each generated rendition. The renderer uses the visible alpha bounds, not transparent canvas padding.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('scale_percent')
                            ->label('Signature size')
                            ->suffix('% of shorter side')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(50)
                            ->step(0.1)
                            ->required(),
                        TextInput::make('inset_percent')
                            ->label('Edge inset')
                            ->suffix('%')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(25)
                            ->step(0.1)
                            ->required(),
                        TextInput::make('opacity_percent')
                            ->label('Opacity')
                            ->suffix('%')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->step(0.1)
                            ->required(),
                    ]),
                Section::make('Generated outputs')
                    ->description('Output dimensions and JPEG quality come from the tracked application configuration and are included in the immutable recipe revision.')
                    ->columns(4)
                    ->schema([
                        TextInput::make('large_max_width')
                            ->label('Large')
                            ->suffix('px')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('display_max_width')
                            ->label('Display')
                            ->suffix('px')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('thumb_max_width')
                            ->label('Thumbnail')
                            ->suffix('px')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('jpeg_quality')
                            ->label('JPEG quality')
                            ->suffix('%')
                            ->disabled()
                            ->dehydrated(false),
                    ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $settings = app(ArtworkSignatureSettings::class);
        $settings->save([
            'asset_path' => $state['asset_path'] ?? null,
            'default_mode' => $state['default_mode'] ?? null,
            'default_position' => $state['default_position'] ?? null,
            'scale_bp' => $this->basisPoints($state['scale_percent'] ?? null),
            'inset_bp' => $this->basisPoints($state['inset_percent'] ?? null),
            'opacity_bp' => $this->basisPoints($state['opacity_percent'] ?? null),
        ]);
        $this->fillFromSettings($settings);

        Notification::make()
            ->success()
            ->title('Artwork signature settings saved')
            ->body('The private alpha mask and immutable rendering recipe are ready. Existing renditions are unchanged until regeneration is queued.')
            ->send();
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('queueStaleRenditions')
                ->label('Queue stale renditions')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Regenerate affected public renditions?')
                ->modalDescription(fn (): string => $this->staleRenditionCount().' non-embedded artwork record'
                    .($this->staleRenditionCount() === 1 ? ' uses' : 's use')
                    .' an older settings revision. Existing complete renditions stay available until each replacement succeeds.')
                ->modalSubmitActionLabel('Queue regeneration')
                ->disabled(fn (): bool => ! app(ArtworkSignatureSettings::class)->hasAsset()
                    || $this->staleRenditionCount() === 0)
                ->action(function (): void {
                    $selected = 0;
                    $queued = 0;
                    $settings = app(ArtworkSignatureSettings::class);

                    $this->staleRenditionQuery($settings)
                        ->orderBy('id')
                        ->chunkById(100, function ($artworks) use (&$selected, &$queued): void {
                            foreach ($artworks as $artwork) {
                                $selected++;

                                if (GenerateArtworkVariants::dispatchFor($artwork) !== null) {
                                    $queued++;
                                }
                            }
                        });

                    $notification = Notification::make()
                        ->title($queued.' public rendition'.($queued === 1 ? '' : 's').' queued')
                        ->body(($selected - $queued).' selected record'.(($selected - $queued) === 1 ? ' was' : 's were')
                            .' skipped because its private master was unavailable.');

                    ($queued > 0 ? $notification->success() : $notification->warning())->send();
                }),
        ];
    }

    protected function staleRenditionCount(): int
    {
        return $this->staleRenditionQuery(app(ArtworkSignatureSettings::class))->count();
    }

    /** @return array<string, int> */
    public function signatureCounts(): array
    {
        return [
            'ready' => Artwork::query()->where('variant_status', Artwork::VARIANT_STATUS_READY)->count(),
            'queued' => Artwork::query()->where('variant_status', Artwork::VARIANT_STATUS_QUEUED)->count(),
            'processing' => Artwork::query()->where('variant_status', Artwork::VARIANT_STATUS_PROCESSING)->count(),
            'stale' => $this->staleRenditionCount(),
            'failed' => Artwork::query()->where('variant_status', Artwork::VARIANT_STATUS_FAILED)->count(),
            'review' => Artwork::query()->where('signature_review_recommended', true)->count(),
        ];
    }

    /** @return Builder<Artwork> */
    protected function staleRenditionQuery(ArtworkSignatureSettings $settings): Builder
    {
        return Artwork::query()
            ->where('signature_mode', '!=', Artwork::SIGNATURE_MODE_EMBEDDED)
            ->whereNotIn('variant_status', [
                Artwork::VARIANT_STATUS_QUEUED,
                Artwork::VARIANT_STATUS_PROCESSING,
            ])
            ->where(function (Builder $query) use ($settings): void {
                $query
                    ->whereNull('signature_settings_revision')
                    ->orWhere('signature_settings_revision', '!=', $settings->revision());
            });
    }

    protected function fillFromSettings(ArtworkSignatureSettings $settings): void
    {
        $values = $settings->formValues();
        $this->form->fill([
            'asset_path' => $values['asset_path'],
            'default_mode' => $values['default_mode'],
            'default_position' => $values['default_position'],
            'scale_percent' => ((int) $values['scale_bp']) / 100,
            'inset_percent' => ((int) $values['inset_bp']) / 100,
            'opacity_percent' => ((int) $values['opacity_bp']) / 100,
            'large_max_width' => $values['large_max_width'],
            'display_max_width' => $values['display_max_width'],
            'thumb_max_width' => $values['thumb_max_width'],
            'jpeg_quality' => $values['jpeg_quality'],
        ]);

        $this->previewUrl = null;
        $this->assetSummary = null;
        $this->revisionSummary = substr((string) $values['revision'], 0, 16);

        if (! $settings->hasAsset()) {
            return;
        }

        $this->assetSummary = number_format((int) $values['asset_width'])
            .' × '.number_format((int) $values['asset_height'])
            .' px · SHA-256 '.substr((string) $values['asset_sha256'], 0, 16).'…';

        try {
            $this->previewUrl = Storage::disk('local')->temporaryUrl(
                (string) $values['asset_path'],
                now()->addMinutes(30),
            );
        } catch (Throwable) {
            $this->previewUrl = null;
        }
    }

    protected function basisPoints(mixed $percentage): int
    {
        return (int) round(((float) $percentage) * 100);
    }
}

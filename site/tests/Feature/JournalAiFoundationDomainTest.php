<?php

namespace Tests\Feature;

use App\Enums\PostAiOperation;
use App\Enums\PostAiRunStatus;
use App\Models\Artwork;
use App\Models\Post;
use App\Models\PostAiRun;
use App\Models\PostMedia;
use App\Models\Tag;
use App\Models\User;
use App\Services\JournalAiContextBuilder;
use App\Services\JournalAiContractRegistry;
use App\Services\JournalAiResultNormalizer;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalAiFoundationDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_run_schema_casts_relationships_privacy_and_permanent_delete_cascade(): void
    {
        $this->assertTrue(Schema::hasColumns('post_ai_runs', [
            'post_id', 'requester_id', 'retry_of_id', 'source_revision_id',
            'operation', 'status', 'queue_token', 'request_hash', 'source_hash', 'context_hash',
            'context_manifest', 'external_processing', 'acknowledged_by_user_id', 'acknowledged_at',
            'provider', 'model', 'normalized_endpoint', 'provider_profile_hash', 'credential_hmac',
            'generation_options', 'prompt_version', 'prompt_hash', 'schema_version', 'schema_hash',
            'structured_result', 'error_category', 'error_message', 'stale_reason',
            'queued_at', 'started_at', 'lease_expires_at', 'completed_at', 'cancelled_at',
            'dismissed_at', 'applied_at', 'applied_by_user_id', 'applied_revision_id',
        ]));

        $post = $this->makePost();
        $user = User::factory()->create();
        $revision = $post->revisions()->firstOrFail();
        $run = PostAiRun::query()->create($this->runAttributes($post, [
            'requester_id' => $user->id,
            'source_revision_id' => $revision->id,
            'model' => str_repeat('m', 255),
        ]));

        $this->assertSame(PostAiOperation::Outline, $run->operation);
        $this->assertSame(PostAiRunStatus::Queued, $run->status);
        $this->assertSame(255, strlen($run->model));
        $this->assertTrue($run->post->is($post));
        $this->assertTrue($run->requester->is($user));
        $this->assertTrue($run->sourceRevision->is($revision));
        $this->assertTrue($post->aiRuns()->firstOrFail()->is($run));
        $this->assertIsArray($run->context_manifest);
        $this->assertFalse($run->external_processing);

        $serialized = $run->toArray();

        foreach ([
            'queue_token', 'context_manifest', 'structured_result', 'source_hash', 'context_hash', 'request_hash',
            'provider_profile_hash', 'credential_hmac', 'generation_options', 'normalized_endpoint',
        ] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $serialized);
        }

        $retry = PostAiRun::query()->create($this->runAttributes($post, [
            'retry_of_id' => $run->id,
        ]));
        $this->assertTrue($retry->retryOf->is($run));
        $this->assertTrue($run->retries()->firstOrFail()->is($retry));

        $run->forceFill([
            'status' => PostAiRunStatus::Processing,
            'queue_token' => (string) Str::uuid(),
            'queue_priority' => 10,
            'started_at' => now(),
        ])->saveOrFail();
        $this->assertSame(PostAiRunStatus::Processing, $run->refresh()->status);

        try {
            $run->forceFill(['context_hash' => str_repeat('f', 64)])->saveOrFail();
            $this->fail('AI request provenance must not be mutable.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        try {
            $run->delete();
            $this->fail('AI attempts must not be individually deleted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('retained', $exception->getMessage());
        }

        $post->forceDelete();
        $this->assertDatabaseMissing('post_ai_runs', ['id' => $run->id]);
        $this->assertDatabaseMissing('post_ai_runs', ['id' => $retry->id]);
    }

    public function test_runs_reject_trashed_posts_and_cross_post_retry_or_revision_provenance(): void
    {
        $post = $this->makePost();
        $other = $this->makePost(['slug' => 'other-ai-post', 'title' => 'Other AI post']);
        $first = PostAiRun::query()->create($this->runAttributes($post));

        foreach ([
            ['retry_of_id' => $first->id, 'post_id' => $other->id],
            ['source_revision_id' => $post->revisions()->firstOrFail()->id, 'post_id' => $other->id],
        ] as $override) {
            try {
                PostAiRun::query()->create($this->runAttributes($other, $override));
                $this->fail('Cross-post AI provenance must be rejected.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('same post', $exception->getMessage());
            }
        }

        $post->delete();

        try {
            PostAiRun::query()->create($this->runAttributes($post));
            $this->fail('A trashed post must not create an AI run.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('trashed post', $exception->getMessage());
        }
    }

    public function test_context_is_deterministic_explicit_and_recursively_excludes_forbidden_data(): void
    {
        $post = $this->makePost([
            'slug' => 'never-send-this-post-slug',
            'title' => "A title\r\nwith a second line",
            'body' => 'Public body for context.',
            'editorial_brief' => 'PRIVATE-BRIEF-8e7fd',
            'editorial_notes' => 'PRIVATE-NOTES-628ab',
            'cover_image_path' => 'private/post-cover-secret.jpg',
        ]);
        $tag = Tag::query()->create(['name' => 'shared topic', 'slug' => 'private-tag-slug']);
        $post->tags()->attach($tag);
        $public = $this->artwork('Public media title', 'public-media', true, [
            'description' => 'Public media description',
            'prompt' => 'PRIVATE-PROMPT-271c',
            'process_notes' => 'PRIVATE-PROCESS-983b',
            'original_filename' => 'PRIVATE-FILENAME.png',
            'metadata' => ['private_technical' => 'PRIVATE-METADATA-381a'],
            'image_path' => 'private/original-path.png',
        ]);
        $draft = $this->artwork('PRIVATE-DRAFT-TITLE-f013', 'private-draft-slug', false, [
            'description' => 'PRIVATE-DRAFT-DESCRIPTION-a82c',
        ]);
        PostMedia::query()->create(['post_id' => $post->id, 'position' => 1, 'artwork_id' => $public->id]);
        PostMedia::query()->create(['post_id' => $post->id, 'position' => 2, 'artwork_id' => $draft->id]);
        $builder = app(JournalAiContextBuilder::class);
        $selectionA = [
            'fields' => ['body', 'title'],
            'include_connected_media' => true,
            'include_tags' => true,
        ];
        $selectionB = [
            'include_tags' => true,
            'fields' => ['title', 'body', 'body'],
            'include_connected_media' => true,
            'include_editorial_notes' => false,
            'include_editorial_brief' => false,
        ];
        $contextA = $builder->build($post, PostAiOperation::Directions, $selectionA);
        $contextB = $builder->build($post, PostAiOperation::Directions, $selectionB);

        $this->assertSame($contextA->manifest, $contextB->manifest);
        $this->assertSame($contextA->contextHash, $contextB->contextHash);
        $this->assertSame($contextA->sourceHash, $contextB->sourceHash);
        $this->assertSame("A title\nwith a second line", $contextA->outbound()['journal']['title']);
        $this->assertSame(['shared topic'], $contextA->outbound()['shared_tags']);
        $this->assertSame([[
            'type' => 'artwork',
            'title' => 'Public media title',
            'description' => 'Public media description',
        ]], $contextA->outbound()['connected_media']);
        $this->assertSame('not_effectively_public', $contextA->manifest['omitted_fields']['connected_media.non_public_records']);

        $encoded = json_encode($contextA->manifest, JSON_THROW_ON_ERROR);

        foreach ([
            'never-send-this-post-slug', 'PRIVATE-BRIEF-8e7fd', 'PRIVATE-NOTES-628ab',
            'private/post-cover-secret.jpg', 'private-tag-slug', 'PRIVATE-PROMPT-271c',
            'PRIVATE-PROCESS-983b', 'PRIVATE-FILENAME.png', 'PRIVATE-METADATA-381a',
            'private/original-path.png', 'PRIVATE-DRAFT-TITLE-f013',
            'PRIVATE-DRAFT-DESCRIPTION-a82c', 'private-draft-slug',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }

        $this->assertSame(1, $post->revisions()->count());
        $this->assertFalse($post->published);
    }

    public function test_private_context_requires_each_explicit_opt_in(): void
    {
        $post = $this->makePost([
            'editorial_brief' => 'Private brief selected deliberately.',
            'editorial_notes' => 'Private notes remain excluded.',
        ]);

        $context = app(JournalAiContextBuilder::class)->build($post, PostAiOperation::Outline, [
            'fields' => ['body'],
            'include_editorial_brief' => true,
        ]);

        $this->assertSame(
            'Private brief selected deliberately.',
            $context->outbound()['journal']['editorial_brief'],
        );
        $this->assertArrayNotHasKey('editorial_notes', $context->outbound()['journal']);
        $this->assertSame(
            'explicit_opt_in_required',
            $context->manifest['omitted_fields']['journal.editorial_notes'],
        );
    }

    public function test_connected_artwork_prompts_and_process_notes_have_independent_explicit_opt_ins(): void
    {
        $post = $this->makePost();
        $artwork = $this->artwork('Public process artwork', 'public-process-artwork', true, [
            'prompt' => 'A deliberately shared generation prompt.',
            'process_notes' => 'Deliberately shared process notes.',
        ]);
        PostMedia::query()->create([
            'post_id' => $post->id,
            'position' => 1,
            'artwork_id' => $artwork->id,
        ]);
        $draft = $this->artwork('Draft process artwork', 'draft-process-artwork', false, [
            'prompt' => 'NEVER-SHARE-DRAFT-PROMPT-4b2e',
            'process_notes' => 'NEVER-SHARE-DRAFT-PROCESS-f907',
        ]);
        PostMedia::query()->create([
            'post_id' => $post->id,
            'position' => 2,
            'artwork_id' => $draft->id,
        ]);
        $builder = app(JournalAiContextBuilder::class);
        $promptOnly = $builder->build($post, PostAiOperation::Directions, [
            'fields' => ['title'],
            'include_connected_media' => true,
            'include_connected_media_prompts' => true,
        ]);
        $processOnly = $builder->build($post, PostAiOperation::Directions, [
            'fields' => ['title'],
            'include_connected_media' => true,
            'include_connected_media_process_notes' => true,
        ]);

        $this->assertSame(
            'A deliberately shared generation prompt.',
            $promptOnly->outbound()['connected_media'][0]['prompt'],
        );
        $this->assertArrayNotHasKey('process_notes', $promptOnly->outbound()['connected_media'][0]);
        $this->assertSame(
            'Deliberately shared process notes.',
            $processOnly->outbound()['connected_media'][0]['process_notes'],
        );
        $this->assertArrayNotHasKey('prompt', $processOnly->outbound()['connected_media'][0]);
        $this->assertStringNotContainsString(
            'NEVER-SHARE-DRAFT-PROMPT-4b2e',
            json_encode($promptOnly->manifest, JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString(
            'NEVER-SHARE-DRAFT-PROCESS-f907',
            json_encode($processOnly->manifest, JSON_THROW_ON_ERROR),
        );

        try {
            $builder->build($post, PostAiOperation::Directions, [
                'fields' => ['title'],
                'include_connected_media_prompts' => true,
            ]);
            $this->fail('Prompt sharing must require connected media sharing too.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('require connected media', $exception->getMessage());
        }
    }

    public function test_source_hash_ignores_unselected_private_and_workflow_changes_but_tracks_selected_and_protected_targets(): void
    {
        $post = $this->makePost([
            'body' => 'Alpha selected body.',
            'excerpt' => 'Protected metadata excerpt.',
            'editorial_notes' => 'First private note.',
        ]);
        $builder = app(JournalAiContextBuilder::class);
        $selection = ['fields' => ['body']];
        $first = $builder->build($post, PostAiOperation::Directions, $selection);

        $post->forceFill([
            'editorial_notes' => 'Changed private note.',
            'featured' => true,
            'updated_at' => now()->addMinute(),
        ])->saveOrFail();
        $unrelated = $builder->build($post->refresh(), PostAiOperation::Directions, $selection);
        $this->assertSame($first->sourceHash, $unrelated->sourceHash);

        $post->update(['body' => 'Changed selected body.']);
        $selected = $builder->build($post->refresh(), PostAiOperation::Directions, $selection);
        $this->assertNotSame($first->sourceHash, $selected->sourceHash);

        $metadataA = $builder->build($post, PostAiOperation::Metadata, ['fields' => ['title']]);
        $post->update(['excerpt' => 'Changed protected metadata target.']);
        $metadataB = $builder->build($post->refresh(), PostAiOperation::Metadata, ['fields' => ['title']]);
        $this->assertSame($metadataA->contextHash, $metadataB->contextHash);
        $this->assertNotSame($metadataA->sourceHash, $metadataB->sourceHash);
    }

    public function test_improve_passage_uses_utf8_offsets_and_protects_the_whole_target(): void
    {
        $post = $this->makePost(['body' => 'Start café finish']);
        $selection = [
            'fields' => ['title'],
            'passage' => ['end' => 10, 'field' => 'body', 'start' => 6],
        ];
        $first = app(JournalAiContextBuilder::class)->build($post, PostAiOperation::ImprovePassage, $selection);

        $this->assertSame('café', $first->outbound()['selected_passage']['content']);
        $this->assertSame(6, $first->manifest['protected_targets']['body']['start']);
        $post->update(['body' => 'Start café changed finish']);
        $changed = app(JournalAiContextBuilder::class)->build($post->refresh(), PostAiOperation::ImprovePassage, $selection);
        $this->assertNotSame($first->sourceHash, $changed->sourceHash);
    }

    public function test_context_budgets_reject_without_truncating_or_mutating_the_post(): void
    {
        $post = $this->makePost([
            'body' => str_repeat('b', 40001),
            'editorial_brief' => str_repeat('r', 20000),
            'editorial_notes' => str_repeat('n', 20000),
        ]);
        $revisionCount = $post->revisions()->count();

        try {
            app(JournalAiContextBuilder::class)->build($post, PostAiOperation::Outline, ['fields' => ['body']]);
            $this->fail('Oversized context must be rejected rather than truncated.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('budget', $exception->getMessage());
        }

        $post->update(['body' => str_repeat('b', 30000)]);
        $revisionCount = $post->revisions()->count();

        try {
            app(JournalAiContextBuilder::class)->build($post, PostAiOperation::Outline, [
                'fields' => ['body'],
                'include_editorial_brief' => true,
                'include_editorial_notes' => true,
            ]);
            $this->fail('Oversized total context must be rejected rather than truncated.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('total byte budget', $exception->getMessage());
        }

        $this->assertSame(str_repeat('b', 30000), $post->refresh()->body);
        $this->assertSame($revisionCount, $post->revisions()->count());
    }

    public function test_all_operation_contracts_are_strict_dormant_and_safety_framed(): void
    {
        $registry = app(JournalAiContractRegistry::class);

        foreach (PostAiOperation::cases() as $operation) {
            $contract = $registry->for($operation);
            $this->assertSame($operation, $contract->operation);
            $this->assertSame([], $contract->tools());
            $this->assertFalse($contract->schema['additionalProperties']);
            $this->assertSame(array_keys($contract->schema['properties']), $contract->schema['required']);
            $this->assertNotSame($contract->schema, $contract->portableSchema());
            $this->assertStringContainsString('untrusted data', $contract->prompt);
            $this->assertStringContainsString('not as an edit or publication action', $contract->prompt);
            $this->assertStringContainsString('Do not claim to have validated facts', $contract->prompt);
            $this->assertArrayHasKey('claims_requiring_verification', $contract->schema['properties']);
            $this->assertGreaterThanOrEqual(64, $contract->maxOutputTokens);
            $this->assertSame(64, strlen($contract->promptHash()));
            $this->assertSame(64, strlen($contract->schemaHash()));
        }

        $metadata = json_encode($registry->for(PostAiOperation::Metadata)->schema, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('slug', $metadata);
        $this->assertStringNotContainsString('published', $metadata);
        $this->assertStringNotContainsString('scheduled', $metadata);
        $this->assertStringNotContainsString('status', $metadata);
    }

    public function test_normalizer_accepts_all_five_contracts_and_preserves_markdown_with_normalized_line_endings(): void
    {
        $normalizer = app(JournalAiResultNormalizer::class);
        $fixtures = $this->validResults();

        foreach ($fixtures as $operationValue => $result) {
            $operation = PostAiOperation::from($operationValue);
            $this->assertSame($result, $normalizer->normalize($operation, $result));
        }

        $improve = $fixtures[PostAiOperation::ImprovePassage->value];
        $improve['replacement_markdown'] = "## Heading\r\n\r\n- **Bold** [safe](https://example.com)\rFinal";
        $normalized = $normalizer->normalize(PostAiOperation::ImprovePassage, $improve);
        $this->assertSame(
            "## Heading\n\n- **Bold** [safe](https://example.com)\nFinal",
            $normalized['replacement_markdown'],
        );
    }

    #[DataProvider('maliciousResultProvider')]
    public function test_normalizer_atomically_rejects_unknown_wrong_oversized_control_and_unsafe_results(
        PostAiOperation $operation,
        callable $mutate,
    ): void {
        $post = $this->makePost()->refresh();
        $original = $post->getAttributes();
        $revisionCount = $post->revisions()->count();
        $result = $this->validResults()[$operation->value];

        try {
            app(JournalAiResultNormalizer::class)->normalize($operation, $mutate($result));
            $this->fail('Invalid provider output must be rejected before storage.');
        } catch (DomainException) {
            $this->assertSame($original, $post->refresh()->getAttributes());
            $this->assertSame($revisionCount, $post->revisions()->count());
            $this->assertDatabaseCount('post_ai_runs', 0);
        }
    }

    /** @return iterable<string, array{PostAiOperation, callable(array<string,mixed>): mixed}> */
    public static function maliciousResultProvider(): iterable
    {
        yield 'unknown root key' => [PostAiOperation::Directions, function (array $result): array {
            $result['unknown'] = 'surprise';

            return $result;
        }];
        yield 'unknown nested key' => [PostAiOperation::EditorialReview, function (array $result): array {
            $result['issues'][0]['unknown'] = true;

            return $result;
        }];
        yield 'missing required key' => [PostAiOperation::Outline, function (array $result): array {
            unset($result['thesis']);

            return $result;
        }];
        yield 'wrong type' => [PostAiOperation::ImprovePassage, function (array $result): array {
            $result['preserved_meaning'] = 'yes';

            return $result;
        }];
        yield 'oversized string' => [PostAiOperation::Metadata, function (array $result): array {
            $result['seo_description'] = str_repeat('x', 321);

            return $result;
        }];
        yield 'oversized list' => [PostAiOperation::Directions, function (array $result): array {
            $result['directions'] = array_fill(0, 13, $result['directions'][0]);

            return $result;
        }];
        yield 'control character' => [PostAiOperation::ImprovePassage, function (array $result): array {
            $result['replacement_markdown'] = "safe\x00unsafe";

            return $result;
        }];
        yield 'markdown javascript link' => [PostAiOperation::ImprovePassage, function (array $result): array {
            $result['replacement_markdown'] = '[click](javascript:alert(1))';

            return $result;
        }];
        yield 'markdown javascript image' => [PostAiOperation::ImprovePassage, function (array $result): array {
            $result['replacement_markdown'] = '![image](data:text/html,boom)';

            return $result;
        }];
        yield 'reference javascript destination' => [PostAiOperation::ImprovePassage, function (array $result): array {
            $result['replacement_markdown'] = "Read [this][bad].\n\n[bad]: javascript:alert(1)";

            return $result;
        }];
        yield 'html javascript source' => [PostAiOperation::ImprovePassage, function (array $result): array {
            $result['replacement_markdown'] = '<img src="javascript:alert(1)">';

            return $result;
        }];
        yield 'protocol relative link' => [PostAiOperation::ImprovePassage, function (array $result): array {
            $result['replacement_markdown'] = '[click](//host.example/path)';

            return $result;
        }];
    }

    public function test_normalize_json_rejects_invalid_excessively_deep_and_oversized_json(): void
    {
        $normalizer = app(JournalAiResultNormalizer::class);

        foreach ([
            '{not json',
            str_repeat('[', 20).str_repeat(']', 20),
            str_repeat(' ', JournalAiResultNormalizer::MAX_JSON_BYTES + 1),
        ] as $json) {
            try {
                $normalizer->normalizeJson(PostAiOperation::Directions, $json);
                $this->fail('Invalid JSON input must be rejected.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function validResults(): array
    {
        $claims = [['claim' => 'A factual detail may need checking.', 'reason' => 'No source was provided.']];

        return [
            PostAiOperation::Directions->value => [
                'summary' => 'Several optional directions.',
                'directions' => [[
                    'title' => 'Focus the theme',
                    'rationale' => 'A clear theme can connect the sections.',
                    'suggested_angle' => 'Follow the creative decision from start to finish.',
                    'questions' => ['What changed during the process?'],
                ]],
                'claims_requiring_verification' => $claims,
            ],
            PostAiOperation::Outline->value => [
                'working_title' => 'A possible working title',
                'thesis' => 'The process shaped the final work.',
                'sections' => [[
                    'heading' => 'Starting point',
                    'purpose' => 'Introduce the creative question.',
                    'key_points' => ['Describe the initial intention.'],
                ]],
                'claims_requiring_verification' => $claims,
            ],
            PostAiOperation::EditorialReview->value => [
                'summary' => 'The draft has a clear voice.',
                'strengths' => ['The opening is direct.'],
                'issues' => [[
                    'severity' => 'warning',
                    'category' => 'clarity',
                    'feedback' => 'Explain what this pronoun refers to.',
                    'passage' => 'It changed everything.',
                ]],
                'claims_requiring_verification' => $claims,
            ],
            PostAiOperation::ImprovePassage->value => [
                'replacement_markdown' => "A **suggested** passage.\n\nSecond paragraph.",
                'rationale' => 'This keeps the meaning while tightening the wording.',
                'preserved_meaning' => true,
                'claims_requiring_verification' => $claims,
            ],
            PostAiOperation::Metadata->value => [
                'excerpt' => 'A concise optional excerpt.',
                'cover_alt_text' => 'Abstract blue forms on a dark background.',
                'seo_title' => 'A possible SEO title',
                'seo_description' => 'An optional description of this Journal story.',
                'rationale' => ['The wording reflects the supplied text.'],
                'claims_requiring_verification' => $claims,
            ],
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function makePost(array $attributes = []): Post
    {
        return Post::query()->create(array_replace([
            'title' => 'Journal AI foundation post',
            'slug' => 'journal-ai-'.Str::uuid(),
            'excerpt' => 'An excerpt for AI foundation tests.',
            'body' => 'A complete body for AI foundation tests.',
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function artwork(string $title, string $slug, bool $published, array $attributes = []): Artwork
    {
        return Artwork::query()->create(array_replace([
            'title' => $title,
            'slug' => $slug,
            'image_path' => 'artworks/'.$slug.'.jpg',
            'published' => $published,
        ], $attributes));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function runAttributes(Post $post, array $overrides = []): array
    {
        return array_replace([
            'post_id' => $post->id,
            'operation' => PostAiOperation::Outline,
            'status' => PostAiRunStatus::Queued,
            'queue_token' => (string) Str::uuid(),
            'queue_name' => 'ai',
            'queue_priority' => 0,
            'source_hash' => str_repeat('a', 64),
            'context_hash' => str_repeat('b', 64),
            'request_hash' => str_repeat('c', 64),
            'context_manifest' => ['outbound' => ['journal' => ['body' => 'Test body']]],
            'external_processing' => false,
            'provider' => 'ollama',
            'model' => 'test-model',
            'normalized_endpoint' => 'http://ollama:11434',
            'provider_profile_hash' => str_repeat('d', 64),
            'credential_hmac' => null,
            'generation_options' => ['profile_version' => 1, 'timeout_seconds' => 90],
            'prompt_version' => 'prompt-v1',
            'prompt_hash' => str_repeat('e', 64),
            'schema_version' => 'schema-v1',
            'schema_hash' => str_repeat('f', 64),
            'queued_at' => now(),
        ], $overrides);
    }
}

<?php

namespace App\Services;

use App\Enums\PostAiOperation;
use App\Models\PostAiRun;
use DomainException;

final class JournalAiPresentation
{
    public function __construct(
        private readonly JournalAiContractRegistry $contracts,
        private readonly JournalAiResultNormalizer $normalizer,
        private readonly JournalAiContextBuilder $contexts,
    ) {}

    /** @return array<string, mixed> */
    public function result(PostAiRun $run): array
    {
        $contract = $this->contracts->for($run->operation);

        if ($run->prompt_version !== $contract->promptVersion
            || ! hash_equals((string) $run->prompt_hash, $contract->promptHash())
            || $run->schema_version !== $contract->schemaVersion
            || ! hash_equals((string) $run->schema_hash, $contract->schemaHash())
            || ! is_array($run->structured_result)) {
            throw new DomainException('This Journal AI result uses an unsupported contract.');
        }

        return $this->normalizer->normalize($run->operation, $run->structured_result);
    }

    public function isFresh(PostAiRun $run): bool
    {
        $post = $run->post;
        $selection = $run->context_manifest['selection'] ?? null;

        if ($post === null || $post->trashed() || ! is_array($selection)) {
            return false;
        }

        try {
            $context = $this->contexts->build($post, $run->operation, $selection);
        } catch (DomainException) {
            return false;
        }

        return hash_equals((string) $run->context_hash, $context->contextHash)
            && hash_equals((string) $run->source_hash, $context->sourceHash);
    }

    /** @param array<string, mixed> $result */
    public function copyText(PostAiOperation $operation, array $result): string
    {
        return match ($operation) {
            PostAiOperation::Directions => $this->directionsText($result),
            PostAiOperation::Outline => $this->outlineMarkdown($result),
            PostAiOperation::EditorialReview => $this->reviewText($result),
            PostAiOperation::ImprovePassage => (string) $result['replacement_markdown'],
            PostAiOperation::Metadata => $this->metadataText($result),
        };
    }

    /** @param array<string, mixed> $result */
    public function outlineMarkdown(array $result): string
    {
        $lines = [
            '# '.trim((string) $result['working_title']),
            '',
            trim((string) $result['thesis']),
        ];

        foreach ($result['sections'] as $section) {
            $lines[] = '';
            $lines[] = '## '.trim((string) $section['heading']);
            $lines[] = '';
            $lines[] = trim((string) $section['purpose']);

            foreach ($section['key_points'] as $point) {
                $lines[] = '- '.trim((string) $point);
            }
        }

        return trim(implode("\n", $lines));
    }

    /** @param array<string, mixed> $result */
    private function directionsText(array $result): string
    {
        $lines = [trim((string) $result['summary'])];

        foreach ($result['directions'] as $direction) {
            $lines[] = '';
            $lines[] = '## '.trim((string) $direction['title']);
            $lines[] = trim((string) $direction['rationale']);
            $lines[] = 'Suggested angle: '.trim((string) $direction['suggested_angle']);

            foreach ($direction['questions'] as $question) {
                $lines[] = '- '.trim((string) $question);
            }
        }

        return trim(implode("\n", $lines));
    }

    /** @param array<string, mixed> $result */
    private function reviewText(array $result): string
    {
        $lines = [trim((string) $result['summary']), '', 'Strengths:'];

        foreach ($result['strengths'] as $strength) {
            $lines[] = '- '.trim((string) $strength);
        }

        $lines[] = '';
        $lines[] = 'Editorial issues:';

        foreach ($result['issues'] as $issue) {
            $lines[] = sprintf(
                '- [%s / %s] %s%s',
                strtoupper((string) $issue['severity']),
                str_replace('_', ' ', (string) $issue['category']),
                trim((string) $issue['feedback']),
                filled($issue['passage'] ?? null) ? ' — Passage: '.trim((string) $issue['passage']) : '',
            );
        }

        return trim(implode("\n", $lines));
    }

    /** @param array<string, mixed> $result */
    private function metadataText(array $result): string
    {
        $labels = [
            'excerpt' => 'Excerpt',
            'cover_alt_text' => 'Cover alternative text',
            'seo_title' => 'SEO title',
            'seo_description' => 'SEO description',
        ];
        $lines = [];

        foreach ($labels as $field => $label) {
            if (is_string($result[$field] ?? null) && trim($result[$field]) !== '') {
                $lines[] = $label.': '.trim($result[$field]);
            }
        }

        return implode("\n\n", $lines);
    }
}

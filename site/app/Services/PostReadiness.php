<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Str;

class PostReadiness
{
    public function evaluate(Post $post): PostReadinessReport
    {
        $blockers = [];
        $warnings = [];

        if (blank($post->title)) {
            $blockers['title'] = 'Add a title.';
        }

        if (blank($post->slug)) {
            $blockers['slug'] = 'Add a permanent URL slug.';
        }

        if ($this->visibleMarkdownText((string) $post->body) === '') {
            $blockers['body'] = 'Add visible journal content.';
        }

        if (blank($post->excerpt)) {
            $warnings['excerpt'] = 'Add a short excerpt for journal listings and feeds.';
        }

        if (filled($post->cover_image_path) && blank($post->cover_alt_text)) {
            $warnings['cover_alt_text'] = 'Describe the cover image for readers who cannot see it.';
        }

        if (blank($post->seo_title)) {
            $warnings['seo_title'] = 'Add a page-specific SEO title.';
        }

        if (blank($post->seo_description)) {
            $warnings['seo_description'] = 'Add a page-specific SEO description.';
        }

        return new PostReadinessReport($blockers, $warnings);
    }

    private function visibleMarkdownText(string $markdown): string
    {
        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return Str::of(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            ->replace("\u{00A0}", ' ')
            ->squish()
            ->toString();
    }
}

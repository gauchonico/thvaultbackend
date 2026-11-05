<?php

namespace App\Search\Sources;

use App\Search\SourceDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Posts from a WordPress site's public REST API (NTV, KFM). */
class WordPressSource implements ContentSource
{
    private const PER_PAGE = 20;

    // Both NTV's and KFM's WordPress sites 406-block requests with no (or a
    // non-browser) User-Agent — this is required, not cosmetic.
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    // Brief pause between page fetches so a ~10k-22k post crawl doesn't hammer
    // their server hard enough to trip additional WAF/rate-limit blocking.
    private const REQUEST_DELAY_MICROSECONDS = 250_000;

    private string $url;

    public function __construct(private readonly string $outlet, string $url)
    {
        $this->url = rtrim($url, '/');
    }

    public function documents(?int $max = null, ?string $since = null): iterable
    {
        $prefix = Str::slug($this->outlet);
        $seen = 0;
        $page = 1;

        do {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(30)
                ->retry(3, 2000, throw: false)
                ->get("{$this->url}/wp-json/wp/v2/posts", array_filter([
                    '_embed'   => 1,
                    'per_page' => self::PER_PAGE,
                    'page'     => $page,
                    'after'    => $since ? date('c', strtotime($since)) : null,
                ]));

            if ($response->failed()) {
                throw new RuntimeException("Failed to fetch page {$page} from {$this->url}: HTTP {$response->status()}");
            }

            $totalPages = (int) $response->header('X-WP-TotalPages') ?: 1;

            foreach ($response->json() ?? [] as $post) {
                if ($max !== null && $seen >= $max) {
                    return;
                }
                $seen++;

                yield new SourceDocument(
                    id: "{$prefix}:{$post['id']}",
                    outlet: $this->outlet,
                    title: $this->clean($post['title']['rendered'] ?? ''),
                    text: $this->clean($post['content']['rendered'] ?? ''),
                    url: $post['link'] ?? null,
                    publishedAt: $post['date_gmt'] ?? $post['date'] ?? null,
                    metadata: [
                        'author'         => $post['_embedded']['author'][0]['name'] ?? null,
                        'categories'     => collect($post['_embedded']['wp:term'][0] ?? [])->pluck('name')->values()->all(),
                        'featured_image' => $post['_embedded']['wp:featuredmedia'][0]['source_url'] ?? null,
                    ],
                );
            }

            $page++;
            if ($page <= $totalPages) {
                usleep(self::REQUEST_DELAY_MICROSECONDS);
            }
        } while ($page <= $totalPages);
    }

    private function clean(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}

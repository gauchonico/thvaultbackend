<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Embeddings + chat completion client, provider-switchable via EMBEDDINGS_PROVIDER
 * ('openai', 'gemini' or 'ollama'). Gemini (Google AI Studio) has a free tier that
 * needs no card, which makes it the hosted option for demos. Ollama runs locally (no API key, no per-call cost) — the
 * default for Phase 1 while OpenAI billing isn't set up. Swap back to OpenAI later
 * by changing one env var; nothing else in the app needs to change, since chunk
 * embeddings are ingested fresh per-provider (dimensions differ: 1536 vs 768).
 */
class AiClient
{
    private string $provider;

    // OpenAI
    private string $openAiKey;
    private string $openAiEmbeddingModel;
    private string $openAiChatModel;

    // Gemini
    private string $geminiKey;
    private string $geminiEmbeddingModel;
    private string $geminiChatModel;
    private int $geminiDimensions;

    // Ollama
    private string $ollamaBaseUrl;
    private string $ollamaEmbeddingModel;
    private string $ollamaChatModel;

    public function __construct()
    {
        $this->provider = (string) config('services.embeddings.provider', 'openai');

        $this->openAiKey = (string) config('services.openai.key');
        $this->openAiEmbeddingModel = (string) config('services.openai.embedding_model', 'text-embedding-3-small');
        $this->openAiChatModel = (string) config('services.openai.chat_model', 'gpt-4o-mini');

        $this->geminiKey = (string) config('services.gemini.key');
        $this->geminiEmbeddingModel = (string) config('services.gemini.embedding_model', 'gemini-embedding-001');
        $this->geminiChatModel = (string) config('services.gemini.chat_model', 'gemini-flash-lite-latest');
        $this->geminiDimensions = (int) config('services.gemini.dimensions', 768);

        $this->ollamaBaseUrl = rtrim((string) config('services.ollama.base_url', 'http://localhost:11434'), '/');
        $this->ollamaEmbeddingModel = (string) config('services.ollama.embedding_model', 'nomic-embed-text');
        $this->ollamaChatModel = (string) config('services.ollama.chat_model', 'llama3.2:3b');
    }

    /**
     * Whether this client is usable as configured — for OpenAI that means an API
     * key is set; for Ollama it's always "configured" (no key needed), though the
     * server still might not be running (that surfaces as a request failure).
     */
    public function hasApiKey(): bool
    {
        return match ($this->provider) {
            'ollama' => true,
            'gemini' => $this->geminiKey !== '',
            default  => $this->openAiKey !== '',
        };
    }

    public function providerName(): string
    {
        return $this->provider;
    }

    /**
     * @param string[] $texts
     * @return array<int, float[]>
     */
    public function embedBatch(array $texts, bool $isQuery = false): array
    {
        if (empty($texts)) {
            return [];
        }

        return match ($this->provider) {
            'ollama' => $this->embedBatchOllama($texts),
            'gemini' => $this->embedBatchGemini($texts, $isQuery),
            default  => $this->embedBatchOpenAi($texts),
        };
    }

    /** Embeds a search query (Gemini tunes query and document vectors differently). */
    public function embed(string $text): array
    {
        return $this->embedBatch([$text], true)[0] ?? [];
    }

    /**
     * Embeddings rank passages by shared words and topic, not by what the question
     * means, so near-misses score almost as high as real answers. This asks the chat
     * model which passages genuinely answer the query and summarises only those, in
     * one call. Returns null (never throws) on failure so search can fall back to
     * similarity alone.
     *
     * @param array<int, array{title: string, brand: string, date: ?string, text: string}> $passages
     * @return array{relevant: int[], summary: ?string}|null
     */
    public function rankAndSummarize(string $query, array $passages): ?array
    {
        if (empty($passages)) {
            return ['relevant' => [], 'summary' => null];
        }

        $context = collect($passages)
            ->map(fn ($p, $i) => "[{$i}] {$p['title']} ({$p['brand']}, {$p['date']})\n{$p['text']}")
            ->implode("\n\n");

        $systemPrompt = 'You are a research assistant for a Ugandan news archive. '
            . 'Decide which numbered passages genuinely help answer the user\'s question. Judge by meaning: '
            . 'a passage that only shares some words or a broad topic with the question is NOT relevant. '
            . 'Then write exactly two neutral, factual sentences answering the question, using only the '
            . 'relevant passages and never adding details they do not contain. '
            . 'Respond with JSON only, in the form {"relevant": [passage numbers, most useful first], "summary": "..."}. '
            . 'If no passage is relevant, respond {"relevant": [], "summary": null}.';
        $userPrompt = "Question: \"{$query}\"\n\nPassages:\n{$context}";

        try {
            $raw = match ($this->provider) {
                'ollama' => $this->chatOllama($systemPrompt, $userPrompt, true),
                'gemini' => $this->chatGemini($systemPrompt, $userPrompt, true),
                default  => $this->chatOpenAi($systemPrompt, $userPrompt, true),
            };
        } catch (\Throwable $e) {
            report($e);
            return null;
        }

        // Some models wrap JSON in a ```json fence despite being asked not to.
        $data = json_decode(preg_replace('/^\s*```(?:json)?|```\s*$/', '', (string) $raw), true);
        if (!is_array($data) || !is_array($data['relevant'] ?? null)) {
            report(new RuntimeException('Research ranking returned unusable output: ' . Str::limit((string) $raw, 300)));
            return null;
        }

        $relevant = collect($data['relevant'])
            ->filter(fn ($i) => is_numeric($i) && isset($passages[(int) $i]))
            ->map(fn ($i) => (int) $i)
            ->unique()
            ->values()
            ->all();
        $summary = is_string($data['summary'] ?? null) ? trim($data['summary']) : '';

        return ['relevant' => $relevant, 'summary' => $relevant && $summary !== '' ? $summary : null];
    }

    // ─── OpenAI ─────────────────────────────────────────────────────────────

    private function embedBatchOpenAi(array $texts): array
    {
        if ($this->openAiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $response = Http::withToken($this->openAiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => $this->openAiEmbeddingModel,
                'input' => $texts,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI embeddings request failed: ' . $response->body());
        }

        $data = $response->json('data', []);
        usort($data, fn ($a, $b) => $a['index'] <=> $b['index']);

        return array_map(fn ($item) => $item['embedding'], $data);
    }

    private function chatOpenAi(string $systemPrompt, string $userPrompt, bool $json = false): ?string
    {
        $response = Http::withToken($this->openAiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->openAiChatModel,
                'temperature' => 0.2,
                ...($json ? ['response_format' => ['type' => 'json_object']] : []),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if ($response->failed()) {
            report(new RuntimeException('OpenAI chat completion failed: ' . $response->body()));
            return null;
        }

        return $response->json('choices.0.message.content');
    }

    // ─── Gemini ─────────────────────────────────────────────────────────────

    private function embedBatchGemini(array $texts, bool $isQuery): array
    {
        if ($this->geminiKey === '') {
            throw new RuntimeException('GOOGLE_API_KEY is not configured.');
        }

        $model = "models/{$this->geminiEmbeddingModel}";
        $vectors = [];

        // batchEmbedContents accepts at most 100 texts per call.
        foreach (array_chunk($texts, 100) as $batch) {
            // The free tier allows 100 texts a minute. On a 429, wait the delay Google
            // asks for and retry, so a long ingest crawls through instead of failing.
            // Searches are single texts and rarely hit this; a few retries cap the wait.
            for ($attempt = 1; ; $attempt++) {
                $response = Http::withHeaders(['x-goog-api-key' => $this->geminiKey])
                    ->connectTimeout(5)
                    ->timeout(60)
                    ->post("https://generativelanguage.googleapis.com/v1beta/{$model}:batchEmbedContents", [
                        'requests' => array_map(fn ($text) => [
                            'model' => $model,
                            'content' => ['parts' => [['text' => $text]]],
                            'taskType' => $isQuery ? 'RETRIEVAL_QUERY' : 'RETRIEVAL_DOCUMENT',
                            'outputDimensionality' => $this->geminiDimensions,
                        ], $batch),
                    ]);

                if ($response->status() !== 429 || $attempt >= ($isQuery ? 1 : 5)) {
                    break;
                }

                sleep($this->geminiRetryDelay($response));
            }

            if ($response->failed()) {
                throw new RuntimeException('Gemini embeddings request failed: ' . $response->body());
            }

            foreach ($response->json('embeddings', []) as $embedding) {
                $vectors[] = $this->normalize($embedding['values'] ?? []);
            }
        }

        return $vectors;
    }

    private function chatGemini(string $systemPrompt, string $userPrompt, bool $json = false): ?string
    {
        // Same fail-fast reasoning as Ollama below: a missing summary is fine, a
        // proxy-dropped search is not. Free-tier Flash answers in ~10s.
        $response = Http::withHeaders(['x-goog-api-key' => $this->geminiKey])
            ->connectTimeout(5)
            ->timeout(25)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$this->geminiChatModel}:generateContent", [
                'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => $json ? 800 : 400,
                    ...($json ? ['responseMimeType' => 'application/json'] : []),
                ],
            ]);

        if ($response->failed()) {
            report(new RuntimeException('Gemini chat request failed: ' . $response->body()));
            return null;
        }

        return $response->json('candidates.0.content.parts.0.text');
    }

    /** Seconds to wait before retrying a 429, from Google's RetryInfo ("21.8s"), plus a margin. */
    private function geminiRetryDelay(\Illuminate\Http\Client\Response $response): int
    {
        $delay = collect($response->json('error.details', []))
            ->firstWhere('@type', 'type.googleapis.com/google.rpc.RetryInfo')['retryDelay'] ?? '30s';

        return min(65, (int) ceil((float) $delay) + 2);
    }

    /**
     * Gemini only unit-normalizes full-size vectors; reduced ones (768) must be
     * normalized here so SearchableChunk::similarityTo's dot product stays cosine.
     */
    private function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $vector)));

        return $norm > 0 ? array_map(fn ($v) => $v / $norm, $vector) : $vector;
    }

    // ─── Ollama ─────────────────────────────────────────────────────────────

    private function embedBatchOllama(array $texts): array
    {
        // keep_alive keeps the model resident in memory between requests — without it,
        // Ollama unloads an idle model after ~5 min and the next call pays a slow reload.
        // Short connectTimeout so an offline Ollama host fails fast instead of hanging
        // for the full request timeout — matters when that host is a laptop that may
        // simply not be turned on / tunnelled right now.
        $response = Http::connectTimeout(5)->timeout(30)->post("{$this->ollamaBaseUrl}/api/embed", [
            'model' => $this->ollamaEmbeddingModel,
            'input' => $texts,
            'keep_alive' => '30m',
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Ollama embeddings request failed (is `ollama serve` running, and have you pulled "
                . "\"{$this->ollamaEmbeddingModel}\"?): " . $response->body()
            );
        }

        $embeddings = $response->json('embeddings');
        if (!is_array($embeddings)) {
            throw new RuntimeException('Ollama embeddings response was missing the "embeddings" field.');
        }

        return $embeddings;
    }

    private function chatOllama(string $systemPrompt, string $userPrompt, bool $json = false): ?string
    {
        // Fail fast rather than risk an upstream proxy (nginx/Herd) timing out the
        // connection first — a missing AI summary degrades gracefully; a dropped
        // connection makes the whole search look broken even though it would have
        // succeeded a few seconds later.
        $response = Http::connectTimeout(5)->timeout(25)->post("{$this->ollamaBaseUrl}/api/chat", [
            'model' => $this->ollamaChatModel,
            'stream' => false,
            'keep_alive' => '30m',
            'options' => ['temperature' => 0.2, 'num_predict' => $json ? 500 : 120],
            ...($json ? ['format' => 'json'] : []),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ]);

        if ($response->failed()) {
            report(new RuntimeException('Ollama chat request failed: ' . $response->body()));
            return null;
        }

        return $response->json('message.content');
    }
}

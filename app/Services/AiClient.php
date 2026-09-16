<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Embeddings + chat completion client, provider-switchable via EMBEDDINGS_PROVIDER
 * ('openai' or 'ollama'). Ollama runs locally (no API key, no per-call cost) — the
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
        return $this->provider === 'ollama' || $this->openAiKey !== '';
    }

    public function providerName(): string
    {
        return $this->provider;
    }

    /**
     * @param string[] $texts
     * @return array<int, float[]>
     */
    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        return $this->provider === 'ollama'
            ? $this->embedBatchOllama($texts)
            : $this->embedBatchOpenAi($texts);
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0] ?? [];
    }

    /**
     * Ask the chat model for a short synthesis of the given passages.
     * Returns null (never throws) on failure — a summary is a nice-to-have,
     * not something that should break the whole search request.
     */
    public function synthesize(string $query, array $passages): ?string
    {
        if (empty($passages)) {
            return null;
        }

        $context = collect($passages)
            ->map(fn ($p, $i) => "[{$i}] ({$p['brand']}, {$p['date']}) {$p['text']}")
            ->implode("\n\n");

        $systemPrompt = 'You are a research assistant summarizing news archive search results. '
            . 'Write exactly two sentences synthesizing the passages provided, in a neutral, '
            . 'factual tone. Do not fabricate details not present in the passages.';
        $userPrompt = "Query: \"{$query}\"\n\nPassages:\n{$context}";

        try {
            return $this->provider === 'ollama'
                ? $this->chatOllama($systemPrompt, $userPrompt)
                : $this->chatOpenAi($systemPrompt, $userPrompt);
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
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

    private function chatOpenAi(string $systemPrompt, string $userPrompt): ?string
    {
        $response = Http::withToken($this->openAiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->openAiChatModel,
                'temperature' => 0.2,
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

    private function chatOllama(string $systemPrompt, string $userPrompt): ?string
    {
        // Fail fast rather than risk an upstream proxy (nginx/Herd) timing out the
        // connection first — a missing AI summary degrades gracefully; a dropped
        // connection makes the whole search look broken even though it would have
        // succeeded a few seconds later.
        $response = Http::connectTimeout(5)->timeout(25)->post("{$this->ollamaBaseUrl}/api/chat", [
            'model' => $this->ollamaChatModel,
            'stream' => false,
            'keep_alive' => '30m',
            'options' => ['temperature' => 0.2, 'num_predict' => 120],
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

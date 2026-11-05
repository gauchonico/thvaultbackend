<?php

namespace App\Search;

use App\Search\Sources\ContentSource;
use App\Search\Sources\WordPressSource;
use InvalidArgumentException;

/** Builds a ContentSource for each outlet listed in config/researcher.php. */
class SourceRegistry
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return config('researcher.sources', []);
    }

    /** @return string[] */
    public function enabled(): array
    {
        return array_keys(array_filter($this->all(), fn ($source) => $source['enabled'] ?? false));
    }

    public function make(string $key): ContentSource
    {
        $config = $this->all()[$key]
            ?? throw new InvalidArgumentException("Unknown source \"{$key}\". Known: " . implode(', ', array_keys($this->all())));

        return match ($config['driver']) {
            'wordpress' => new WordPressSource($config['outlet'], $config['url']),
            default => throw new InvalidArgumentException(
                "Source \"{$key}\" uses the \"{$config['driver']}\" driver, which hasn't been built yet."
            ),
        };
    }
}

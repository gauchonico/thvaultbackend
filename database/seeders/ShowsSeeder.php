<?php

namespace Database\Seeders;

use App\Models\Episode;
use App\Models\Genre;
use App\Models\Show;
use App\Models\Tag;
use Illuminate\Database\Seeder;

class ShowsSeeder extends Seeder
{
    public function run(): void
    {
        $shows = [
            [
                'title'       => 'The Last Frontier',
                'description' => 'In a world ravaged by climate collapse, a team of explorers ventures beyond the last habitable zone to discover what lies in the unknown territories.',
                'genre'       => ['Sci-Fi', 'Drama', 'Adventure'],
                'year'        => 2025,
                'rating'      => 'TV-MA',
                'seasons'     => 3,
                'is_new'      => false,
                'is_live'     => false,
                'episodes'    => 10,
            ],
            [
                'title'       => 'Midnight Protocol',
                'description' => 'A cybersecurity analyst stumbles upon a global conspiracy that threatens to dismantle the internet as we know it.',
                'genre'       => ['Thriller', 'Tech'],
                'year'        => 2024,
                'rating'      => 'TV-14',
                'seasons'     => 2,
                'is_new'      => false,
                'is_live'     => false,
                'episodes'    => 8,
            ],
            [
                'title'       => 'Crown of Embers',
                'description' => 'Medieval kingdoms clash in a war of fire and politics where the true enemy lurks in the shadows.',
                'genre'       => ['Fantasy', 'Drama'],
                'year'        => 2024,
                'rating'      => 'TV-MA',
                'seasons'     => 4,
                'is_new'      => true,
                'is_live'     => false,
                'episodes'    => 12,
            ],
            [
                'title'       => 'Neon District',
                'description' => 'In a rain-soaked megacity, a detective with a dark past hunts a serial killer who leaves digital breadcrumbs.',
                'genre'       => ['Cyberpunk', 'Crime'],
                'year'        => 2025,
                'rating'      => 'TV-MA',
                'seasons'     => 1,
                'is_new'      => false,
                'is_live'     => true,
                'episodes'    => 6,
            ],
            [
                'title'       => 'Parallel Lives',
                'description' => 'A physicist discovers she can communicate with alternate versions of herself across parallel universes.',
                'genre'       => ['Sci-Fi', 'Mystery'],
                'year'        => 2024,
                'rating'      => 'TV-14',
                'seasons'     => 2,
                'is_new'      => false,
                'is_live'     => false,
                'episodes'    => 10,
            ],
            [
                'title'       => 'The Heist',
                'description' => 'A master thief assembles an unlikely crew to pull off the most ambitious art heist in history.',
                'genre'       => ['Crime', 'Action'],
                'year'        => 2025,
                'rating'      => 'TV-MA',
                'seasons'     => 1,
                'is_new'      => true,
                'is_live'     => false,
                'episodes'    => 8,
            ],
            [
                'title'       => 'Echoes of Tomorrow',
                'description' => 'Time travelers from the future arrive with a warning that could change the course of human history.',
                'genre'       => ['Sci-Fi', 'Thriller'],
                'year'        => 2023,
                'rating'      => 'TV-14',
                'seasons'     => 3,
                'is_new'      => false,
                'is_live'     => false,
                'episodes'    => 10,
            ],
            [
                'title'       => 'Savage Coast',
                'description' => 'Survivors of a shipwreck must navigate a mysterious island filled with ancient secrets and deadly creatures.',
                'genre'       => ['Adventure', 'Horror'],
                'year'        => 2024,
                'rating'      => 'TV-MA',
                'seasons'     => 2,
                'is_new'      => false,
                'is_live'     => false,
                'episodes'    => 8,
            ],
            [
                'title'       => 'Studio 54',
                'description' => 'The rise and fall of the most legendary nightclub in New York City during the disco era.',
                'genre'       => ['Drama', 'Music'],
                'year'        => 2025,
                'rating'      => 'TV-MA',
                'seasons'     => 1,
                'is_new'      => false,
                'is_live'     => false,
                'episodes'    => 6,
            ],
            [
                'title'       => 'Deep Blue',
                'description' => 'An ocean research team discovers an underwater civilization that has existed for millennia beneath the waves.',
                'genre'       => ['Sci-Fi', 'Adventure'],
                'year'        => 2024,
                'rating'      => 'TV-14',
                'seasons'     => 2,
                'is_new'      => false,
                'is_live'     => false,
                'episodes'    => 10,
            ],
            [
                'title'       => 'Iron Valley',
                'description' => 'A small-town sheriff uncovers a web of corruption that reaches the highest levels of government.',
                'genre'       => ['Drama', 'Crime'],
                'year'        => 2023,
                'rating'      => 'TV-MA',
                'seasons'     => 3,
                'is_new'      => false,
                'is_live'     => false,
                'episodes'    => 10,
            ],
            [
                'title'       => 'Quantum Shift',
                'description' => 'After a failed experiment, a group of scientists find themselves shifting between dimensions with no way home.',
                'genre'       => ['Sci-Fi', 'Action'],
                'year'        => 2025,
                'rating'      => 'TV-14',
                'seasons'     => 1,
                'is_new'      => true,
                'is_live'     => false,
                'episodes'    => 8,
            ],
        ];

        foreach ($shows as $index => $data) {
            $num          = $index + 1;
            $episodeCount = $data['episodes'];
            $genreNames   = $data['genre'];
            $isNew        = $data['is_new'];
            $isLive       = $data['is_live'];

            unset($data['episodes'], $data['genre'], $data['is_new'], $data['is_live']);

            $show = Show::create([
                ...$data,
                'poster'   => "https://picsum.photos/seed/show{$num}/400/600",
                'backdrop' => "https://picsum.photos/seed/bg{$num}/1920/1080",
            ]);

            $genreIds = collect($genreNames)->map(
                fn (string $name) => Genre::firstOrCreate(['name' => $name])->id
            );
            $show->genres()->attach($genreIds);

            $tagNames = collect([
                $isNew ? 'New' : null,
                $isLive ? 'Live' : null,
            ])->filter();

            if ($tagNames->isNotEmpty()) {
                $tagIds = $tagNames->map(
                    fn (string $name) => Tag::firstOrCreate(['name' => $name])->id
                );
                $show->tags()->attach($tagIds);
            }

            for ($e = 1; $e <= $episodeCount; $e++) {
                Episode::create([
                    'show_id'        => $show->id,
                    'title'          => "Episode {$e}",
                    'season'         => 1,
                    'episode_number' => $e,
                    'duration'       => (42 + ($e % 15)) . 'm',
                    'thumbnail'      => "https://picsum.photos/seed/ep{$num}{$e}/640/360",
                    'description'    => 'An exciting episode that pushes the story forward with unexpected twists and revelations.',
                ]);
            }
        }

        $this->command->info('✅ Seeded 12 shows with episodes successfully.');
    }
}
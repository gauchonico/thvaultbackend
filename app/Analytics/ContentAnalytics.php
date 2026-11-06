<?php

namespace App\Analytics;

use App\Models\Channel;
use App\Models\EpaperEdition;
use App\Models\Episode;
use App\Models\Partner;
use App\Models\Play;
use App\Models\Show;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Views, viewers and watch time from the play log. A view is a session that
 * played at least analytics.view_seconds; every session adds to watch time.
 * Viewers are people: an account when signed in, otherwise a browser.
 */
class ContentAnalytics
{
    private const VIEWER = 'CASE WHEN user_id IS NULL THEN device_id ELSE user_id END';

    private int $threshold;

    public function __construct()
    {
        $this->threshold = config('analytics.view_seconds');
    }

    public function report(CarbonInterface $from, CarbonInterface $to): array
    {
        $length = $from->diffInSeconds($to);
        $previousFrom = $from->copy()->subSeconds($length);

        return [
            'from'         => $from->toIso8601String(),
            'to'           => $to->toIso8601String(),
            'view_seconds' => $this->threshold,
            'totals'       => $this->totals($from, $to),
            'previous'     => $this->totals($previousFrom, $from),
            'daily'        => $this->daily($from, $to),
            'top_shows'    => $this->topShows($from, $to),
            'top_episodes' => $this->topEpisodes($from, $to),
            'channels'     => $this->channels($from, $to),
            'epaper'       => $this->epaper($from, $to),
            'partners'     => $this->partners($from, $to),
        ];
    }

    private function plays(CarbonInterface $from, CarbonInterface $to): Builder
    {
        return Play::query()->where('started_at', '>=', $from)->where('started_at', '<', $to);
    }

    /** views, viewers, hours and sessions as select expressions */
    private function metrics(): string
    {
        $t = $this->threshold;

        return "SUM(CASE WHEN seconds >= {$t} THEN 1 ELSE 0 END) AS views, "
            . "COUNT(DISTINCT CASE WHEN seconds >= {$t} THEN " . self::VIEWER . ' END) AS viewers, '
            . 'COALESCE(SUM(seconds), 0) AS seconds';
    }

    private function totals(CarbonInterface $from, CarbonInterface $to): array
    {
        $t = $this->threshold;
        $row = $this->plays($from, $to)->selectRaw($this->metrics()
            . ", SUM(CASE WHEN seconds >= {$t} AND user_id IS NULL THEN 1 ELSE 0 END) AS guest_views"
            . ', COALESCE(SUM(CASE WHEN is_subscriber THEN seconds ELSE 0 END), 0) AS subscriber_seconds')->first();

        $byType = $this->plays($from, $to)->selectRaw('content_type, ' . $this->metrics())
            ->groupBy('content_type')->get()->keyBy('content_type');

        return [
            'views'              => (int) $row->views,
            'viewers'            => (int) $row->viewers,
            'hours'              => $this->hours($row->seconds),
            'guest_views'        => (int) $row->guest_views,
            'subscriber_hours'   => $this->hours($row->subscriber_seconds),
            'by_type'            => collect(['episode', 'channel', 'epaper'])->mapWithKeys(fn ($type) => [$type => [
                'views'   => (int) ($byType[$type]->views ?? 0),
                'viewers' => (int) ($byType[$type]->viewers ?? 0),
                'hours'   => $this->hours($byType[$type]->seconds ?? 0),
            ]])->all(),
        ];
    }

    private function daily(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->plays($from, $to)
            ->selectRaw('DATE(started_at) AS day, content_type, ' . $this->metrics())
            ->groupBy('day', 'content_type')->get();

        $days = [];
        for ($day = $from->copy()->startOfDay(); $day < $to; $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'episode' => 0, 'channel' => 0, 'epaper' => 0, 'hours' => 0.0];
        }
        foreach ($rows as $row) {
            if (!isset($days[$row->day])) continue;
            $days[$row->day][$row->content_type] = (int) $row->views;
            $days[$row->day]['hours'] = round($days[$row->day]['hours'] + $row->seconds / 3600, 1);
        }

        return array_values($days);
    }

    private function topShows(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->ranked($this->plays($from, $to)->where('content_type', 'episode')->whereNotNull('show_id'), 'show_id');
        $shows = Show::with('partner:id,name')->whereIn('id', $rows->pluck('show_id'))->get(['id', 'title', 'poster', 'partner_id'])->keyBy('id');

        return $rows->map(fn ($row) => $this->row($row) + [
            'id'      => (int) $row->show_id,
            'title'   => $shows[$row->show_id]->title ?? 'Deleted show',
            'poster'  => $shows[$row->show_id]->poster ?? null,
            'partner' => $shows[$row->show_id]->partner->name ?? null,
        ])->all();
    }

    private function topEpisodes(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->ranked($this->plays($from, $to)->where('content_type', 'episode'), 'content_id');
        $episodes = Episode::with('show:id,title')->whereIn('id', $rows->pluck('content_id'))
            ->get(['id', 'show_id', 'title', 'season', 'episode_number'])->keyBy('id');

        return $rows->map(function ($row) use ($episodes) {
            $episode = $episodes[$row->content_id] ?? null;
            return $this->row($row) + [
                'id'    => (int) $row->content_id,
                'title' => $episode->title ?? 'Deleted episode',
                'show'  => $episode?->show?->title,
                'label' => $episode ? "S{$episode->season}:E{$episode->episode_number}" : null,
            ];
        })->all();
    }

    private function channels(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->ranked($this->plays($from, $to)->where('content_type', 'channel'), 'content_id');
        $channels = Channel::whereIn('id', $rows->pluck('content_id'))->get(['id', 'name', 'type'])->keyBy('id');

        return $rows->map(fn ($row) => $this->row($row) + [
            'id'   => (int) $row->content_id,
            'name' => $channels[$row->content_id]->name ?? 'Deleted channel',
            'type' => $channels[$row->content_id]->type ?? null,
        ])->all();
    }

    private function epaper(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->ranked($this->plays($from, $to)->where('content_type', 'epaper'), 'content_id');
        $editions = EpaperEdition::with('publication:id,name')->whereIn('id', $rows->pluck('content_id'))
            ->get(['id', 'publication_id', 'issue_date'])->keyBy('id');

        return $rows->map(fn ($row) => $this->row($row) + [
            'id'          => (int) $row->content_id,
            'publication' => $editions[$row->content_id]->publication->name ?? 'Deleted edition',
            'issue_date'  => $editions->get($row->content_id)?->issue_date?->toDateString(),
        ])->all();
    }

    private function partners(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->plays($from, $to)->whereNotNull('partner_id')
            ->selectRaw('partner_id, ' . $this->metrics())->groupBy('partner_id')->get()->keyBy('partner_id');

        $newFollowers = DB::table('partner_follows')->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw('partner_id, COUNT(*) AS total')->groupBy('partner_id')->pluck('total', 'partner_id');

        return Partner::withCount('followers')->orderBy('sort_order')->get(['id', 'name', 'type', 'logo'])
            ->map(fn (Partner $partner) => [
                'id'            => $partner->id,
                'name'          => $partner->name,
                'type'          => $partner->type,
                'logo'          => $partner->logo,
                'views'         => (int) ($rows[$partner->id]->views ?? 0),
                'viewers'       => (int) ($rows[$partner->id]->viewers ?? 0),
                'hours'         => $this->hours($rows[$partner->id]->seconds ?? 0),
                'followers'     => $partner->followers_count,
                'new_followers' => (int) ($newFollowers[$partner->id] ?? 0),
            ])
            ->sortByDesc('views')->values()->all();
    }

    private function ranked(Builder $query, string $key, int $limit = 10)
    {
        return $query->selectRaw("{$key}, " . $this->metrics())->groupBy($key)
            ->orderByDesc('views')->orderByDesc('seconds')->limit($limit)->get();
    }

    private function row($row): array
    {
        $views = (int) $row->views;

        return [
            'views'            => $views,
            'viewers'          => (int) $row->viewers,
            'hours'            => $this->hours($row->seconds),
            // Average minutes per view: how long people stay once they've started.
            'minutes_per_view' => $views ? round($row->seconds / 60 / $views, 1) : 0,
        ];
    }

    private function hours($seconds): float
    {
        return round(((int) $seconds) / 3600, 1);
    }
}

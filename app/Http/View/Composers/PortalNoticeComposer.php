<?php

declare(strict_types=1);

namespace App\Http\View\Composers;

use App\Domain\Master\Models\PortalNotice;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The notices the public landing page shows (suggestion 6).
 *
 * A composer for the same reason {@see PortalStatisticsComposer} is one: `/` is a
 * closure returning a view, and what the notice board holds is presentation.
 *
 * **Guarded exactly as the statistics are.** A missing table on a fresh checkout,
 * an unavailable database — the landing page still renders, with no notice board
 * rather than a stack trace. It is the first thing anyone sees.
 *
 * **And it falls back to the catalogue.** An installation that has published no
 * notice yet shows the four the language file has always carried, so the section
 * is never an empty box. Once the Administrator publishes one, the master wins
 * outright — a mixture of the two would be impossible to reason about.
 */
class PortalNoticeComposer
{
    public function compose(View $view): void
    {
        $view->with('portalNotices', $this->notices());
    }

    /**
     * @return Collection<int, array{day: string, month: string, title: string, text: string}>
     */
    private function notices(): Collection
    {
        try {
            $published = PortalNotice::query()->live()->get();
        } catch (Throwable) {
            $published = new Collection;
        }

        if ($published->isEmpty()) {
            return $this->fromCatalogue();
        }

        $rows = [];

        foreach ($published as $notice) {
            $rows[] = [
                'day' => $notice->published_from->format('d'),
                'month' => $this->monthLabel($notice->published_from),
                'title' => $notice->title(),
                'text' => $notice->body(),
            ];
        }

        return new Collection($rows);
    }

    /**
     * The month, abbreviated in the reader's language.
     *
     * Falls back to the English abbreviation if the key ever resolves to anything
     * but a string. That would be a catalogue fault — `BilingualCoverageTest`
     * refuses one — but this composer's standing rule is that nothing it does may
     * break the landing page.
     */
    private function monthLabel(Carbon $date): string
    {
        $label = __('landing.months.'.strtolower($date->format('M')));

        return is_string($label) ? $label : $date->format('M');
    }

    /**
     * The four notices the language file has always carried.
     *
     * Read defensively: a catalogue entry missing a field is skipped rather than
     * rendered as a half-empty notice.
     *
     * @return Collection<int, array{day: string, month: string, title: string, text: string}>
     */
    private function fromCatalogue(): Collection
    {
        $items = __('landing.notices.items');

        if (! is_array($items)) {
            return new Collection;
        }

        $rows = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            // Narrowed one field at a time rather than in a loop: a loop with a
            // `continue 2` is the same check to a reader and an opaque one to the
            // analyser, and the shape is the whole contract of this method.
            $day = $item['day'] ?? null;
            $month = $item['month'] ?? null;
            $title = $item['title'] ?? null;
            $text = $item['text'] ?? null;

            if (! is_string($day) || ! is_string($month) || ! is_string($title) || ! is_string($text)) {
                continue;
            }

            $rows[] = ['day' => $day, 'month' => $month, 'title' => $title, 'text' => $text];
        }

        return new Collection($rows);
    }
}

<?php

namespace App\Http\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Date-range filtering that agrees with what the browser put on screen.
 *
 * Timestamps are stored in UTC but rendered in the viewer's timezone, so
 * comparing stored dates against the calendar days someone picked lands every
 * late-evening row on the neighbouring day. East of UTC that shows up as a
 * range quietly reaching a day too far at one end and a day short at the
 * other. Converting the chosen days to the instants that bracket them is the
 * only comparison that matches the list.
 */
trait FiltersByLocalDateRange
{
    /**
     * The timezone the caller's date bounds are calendar days in.
     *
     * Falls back to the app timezone, which is what callers that send no
     * timezone always got, rather than rejecting the request: the parameter is
     * an optional refinement of an otherwise working filter.
     */
    protected function filterTimezone(Request $request): string
    {
        $timezone = (string) $request->get('timezone', '');

        return in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : (string) config('app.timezone', 'UTC');
    }

    /**
     * Constrain a timestamp to the calendar days between two bounds.
     *
     * `$expression` is interpolated into raw SQL so that a column, or
     * something like COALESCE over two of them, can be filtered the same way.
     * It must always be a literal the application controls - never anything
     * taken from the request.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @return \Illuminate\Database\Eloquent\Builder<*>
     */
    protected function whereLocalDateBetween(
        $query,
        Request $request,
        string $expression,
        ?string $from,
        ?string $to,
    ) {
        $timezone = $this->filterTimezone($request);

        if (filled($from)) {
            $query->whereRaw("{$expression} >= ?", [
                CarbonImmutable::parse($from, $timezone)
                    ->startOfDay()
                    ->utc()
                    ->toDateTimeString(),
            ]);
        }

        if (filled($to)) {
            // Half-open on purpose: "before midnight at the start of the next
            // day" covers the whole end day without depending on the column's
            // sub-second precision.
            $query->whereRaw("{$expression} < ?", [
                CarbonImmutable::parse($to, $timezone)
                    ->addDay()
                    ->startOfDay()
                    ->utc()
                    ->toDateTimeString(),
            ]);
        }

        return $query;
    }
}

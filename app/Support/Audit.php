<?php

namespace App\Support;

use App\Http\Middleware\RequestContext;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    /**
     * Record a named business event on a subject (D-021).
     *
     * Channel, request_id and ip are not added here: stamp() does that for every
     * activity row at save time, so this helper and the LogsActivity trait share
     * one code path. Call it inside the same DB::transaction as the change it
     * describes, so the trail can never disagree with the data.
     *
     * @param  string  $event  dotted name, for example `purchase_order.sent`
     * @param  array<string, mixed>  $properties
     * @param  string|null  $description  human sentence for the manager (E29); defaults to the event name
     */
    public static function record(string $event, Model $subject, array $properties = [], ?string $description = null): void
    {
        activity()
            ->performedOn($subject)
            ->event($event)
            ->withProperties($properties)
            ->log($description ?? $event);
    }

    /**
     * Add channel, request_id and ip to an activity row about to be saved (D-021).
     *
     * Registered once in AppServiceProvider through spatie's beforeLogging hook,
     * so entries written by the LogsActivity trait carry them too.
     */
    public static function stamp(Model $activity): void
    {
        $activity->properties = $activity->properties->merge([
            'channel' => app()->bound(RequestContext::CHANNEL_BINDING) ? app(RequestContext::CHANNEL_BINDING) : 'api',
            'request_id' => app()->bound(RequestContext::REQUEST_ID_BINDING) ? app(RequestContext::REQUEST_ID_BINDING) : null,
            'ip' => request()->ip(),
        ]);
    }
}

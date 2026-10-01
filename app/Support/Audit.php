<?php

namespace App\Support;

use App\Http\Middleware\RequestContext;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    /**
     * Record a named business event on a subject (D-021).
     *
     * Wraps activity() so every entry carries channel, request_id and ip without
     * services repeating them. Call it inside the same DB::transaction as the
     * change it describes, so the trail can never disagree with the data.
     *
     * @param  string  $event  dotted name, for example `purchase_order.sent`
     * @param  array<string, mixed>  $properties
     */
    public static function record(string $event, Model $subject, array $properties = []): void
    {
        activity()
            ->performedOn($subject)
            ->event($event)
            ->withProperties([
                ...$properties,
                'channel' => app()->bound(RequestContext::CHANNEL_BINDING) ? app(RequestContext::CHANNEL_BINDING) : 'api',
                'request_id' => app()->bound(RequestContext::REQUEST_ID_BINDING) ? app(RequestContext::REQUEST_ID_BINDING) : null,
                'ip' => request()->ip(),
            ])
            ->log($event);
    }
}

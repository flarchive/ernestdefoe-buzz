<?php

namespace Ernestdefoe\Buzz\Integration;

use Ernestdefoe\Buzz\Recorder;
use Ernestdefoe\Buzz\Settings;

/**
 * ernestdefoe/client-area: a first paid purchase.
 *
 * 🚨 A purchase is private by default: "Someone bought Parley Calls", with no
 * name, avatar or profile link, unless the admin switches "show the buyer's
 * name" on. Renewals and comped plans are never broadcast.
 */
class ClientArea
{
    public function __construct(private Recorder $recorder, private Settings $settings)
    {
    }

    public function __invoke(\ErnestDefoe\ClientArea\Event\ServicePaid $event): void
    {
        $subscription = $event->subscription;
        $product = $subscription->product;

        if (! $event->first || $subscription->provider === 'grant' || (int) $subscription->price_cents <= 0 || ! $product || ! $product->is_active || ! $subscription->user) {
            return;
        }

        $url = $product->extension_url;

        $this->recorder->record('purchase', $subscription->user, ['product' => $product->name], [
            'url' => is_string($url) && str_starts_with($url, '/') ? $url : null,
            'subject_id' => $product->id,
            'anonymous' => ! $this->settings->bool('purchase_show_buyer'),
        ]);
    }
}

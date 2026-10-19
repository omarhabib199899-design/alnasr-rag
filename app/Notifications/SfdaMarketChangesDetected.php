<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * One digest notification per daily run (not one per changed item — a
 * market-wide sweep of ~9,165 items can surface dozens/hundreds of changes
 * in a day, which would flood the bell). The full itemized list lives on
 * the SFDA Market Changes report page; this just summarizes counts and
 * links there, same "digest with a link" shape as any daily report email
 * would have, but delivered in-app (database channel only — no mail
 * driver configured for this app, see .env MAIL_MAILER=log).
 */
class SfdaMarketChangesDetected extends Notification
{
    public function __construct(
        public readonly string $detectedDate,
        public readonly int $priceIncreases,
        public readonly int $priceDecreases,
        public readonly int $agentChanges,
        public readonly int $otherFieldChanges,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $total = $this->priceIncreases + $this->priceDecreases + $this->agentChanges + $this->otherFieldChanges;

        return [
            'title' => 'SFDA Market Changes',
            'detected_date' => $this->detectedDate,
            'price_increases' => $this->priceIncreases,
            'price_decreases' => $this->priceDecreases,
            'agent_changes' => $this->agentChanges,
            'other_field_changes' => $this->otherFieldChanges,
            'total_changes' => $total,
            'body' => "{$total} change(s) detected across SFDA's full drug database on {$this->detectedDate}: {$this->priceIncreases} price increase(s), {$this->priceDecreases} price decrease(s), {$this->agentChanges} agent change(s), {$this->otherFieldChanges} other field change(s).",
            'url' => '/reports/sfda-market-changes',
        ];
    }
}

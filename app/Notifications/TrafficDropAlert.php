<?php

namespace App\Notifications;

use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrafficDropAlert extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $detection
     */
    public function __construct(public Website $website, public array $detection = [])
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Serfix Alert: Traffic anomaly on '.$this->website->domain)
            ->line("An unusual drop was detected for {$this->website->domain} on {$this->detection['date']}.");

        foreach (($this->detection['metrics'] ?? []) as $key => $m) {
            if (empty($m['triggered'])) {
                continue;
            }
            $label = match ($key) {
                'clicks' => 'Search clicks',
                'sessions' => 'Sessions',
                'avg_rank_position' => 'Avg tracked-keyword position',
                default => ucfirst(str_replace('_', ' ', $key)),
            };
            $pct = isset($m['change_percent']) ? round((float) $m['change_percent'], 1).'%' : 'n/a';
            $z = isset($m['z_score']) ? round((float) $m['z_score'], 2) : 'n/a';
            $mail->line(sprintf(
                '%s: %s vs baseline %s (%s, z=%s)',
                $label,
                number_format((float) $m['current'], 1),
                number_format((float) $m['baseline_mean'], 1),
                $pct,
                $z,
            ));
        }

        return $mail
            ->action('Open Serfix', route('dashboard'))
            ->line('Review traffic sources and keyword changes to investigate.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'website_id' => $this->website->id,
            'domain' => $this->website->domain,
            'detection' => $this->detection,
        ];
    }
}

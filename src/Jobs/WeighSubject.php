<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Phoenix1331\Clorian\Clorian;
use Phoenix1331\Clorian\Exceptions\DriverException;
use Phoenix1331\Clorian\Support\Weighable;
use Throwable;

/**
 * Weighs a model in the background. Retryable driver failures (rate limits,
 * overload, timeouts) are released with backoff, honouring retry-after;
 * after the last attempt, or on a permanent failure, the weighing is stored
 * as failed and the policy's defaults apply. Carries only the model's key.
 */
final class WeighSubject implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Seconds before the second and third attempts. */
    public const array BACKOFF = [10, 60];

    /** A model deleted before the job runs has nothing left to weigh. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Model $subject) {}

    public function handle(Clorian $clorian): void
    {
        try {
            $clorian->weigh(Weighable::policy($this->subject), Weighable::input($this->subject), $this->subject);
        } catch (DriverException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($e->retryAfter ?? $this->backoff()[min($this->attempts(), count(self::BACKOFF)) - 1]);

                return;
            }

            $this->fail($e);
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return self::BACKOFF;
    }

    /**
     * The last attempt failed: store a failed weighing and apply the
     * defaults. Messages from other exceptions may quote content, so only
     * their class is kept.
     */
    public function failed(?Throwable $e): void
    {
        $error = match (true) {
            $e instanceof DriverException => $e->getMessage(),
            $e === null => 'The weighing job failed.',
            default => sprintf('The weighing job failed with %s.', $e::class),
        };

        app(Clorian::class)->failed(Weighable::policy($this->subject), Weighable::input($this->subject), $this->subject, $error);
    }
}

<?php

namespace App\Jobs;

use App\Models\Commio\CommioInboundSMS;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Queue\SerializesModels;
use App\Models\Commio\CommioOutboundSMS;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use App\Notifications\SendSlackNotification;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;

class ProcessCommioSMSToEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 10;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * @var int
     */
    public $maxExceptions = 5;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * Indicate if the job should be marked as failed on timeout.
     *
     * @var bool
     */
    public $failOnTimeout = true;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 30;

    /**
     * Delete the job if its models no longer exist.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    private $org_id;

    private $message_uuid;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($data)
    {
        $this->org_id = $data['org_id'];
        $this->message_uuid = $data['message_uuid'];
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array
     */
    public function middleware()
    {
        return [(new RateLimitedWithRedis('emails'))];
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Allow only 2 tasks every 1 second
        Redis::throttle('emails')->allow(2)->every(1)->then(function () {

            $sms = new CommioInboundSMS();
            $sms->org_id = $this->org_id;
            $sms->message_uuid = $this->message_uuid;
            $sms->smsToEmail();

        }, function () {
            // Could not obtain lock; this job will be re-queued
            return $this->release(5);
        });
    }
}

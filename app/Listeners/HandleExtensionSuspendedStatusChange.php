<?php

namespace App\Listeners;

use App\Models\Activity;
use App\Jobs\SuspendAppUser;
use Illuminate\Bus\Queueable;
use App\Jobs\UpdateAppSettings;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Spatie\Activitylog\Facades\CauserResolver;
use App\Events\ExtensionSuspendedStatusChanged;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;

class HandleExtensionSuspendedStatusChange implements ShouldQueue
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
    public $timeout = 600;

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
    public $backoff = 15;

    /**
     * Delete the job if its models no longer exist.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    /**
     * Get the middleware the job should pass through.
     *
     * @return array
     */
    public function middleware()
    {
        return [(new RateLimitedWithRedis('default'))];
    }


    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(ExtensionSuspendedStatusChanged $event): void
    {

        CauserResolver::setCauser($event->user);

        // Set the runtime domain_uuid for the ActivityLog
        Activity::setRuntimeDomainUuid($event->model->domain_uuid);

        if ($event->model) {
            if ($event->model->suspended) {
                $event->model->do_not_disturb = 'true';
                $event->model->directory_visible = 'false';
                $event->model->directory_exten_visible = 'false';
            } else {
                $event->model->do_not_disturb = 'false';
                $event->model->directory_visible = 'true';
                $event->model->directory_exten_visible = 'true';
            }
            // logger($event->model);
            $event->model->save();


            // Suspend App user if exists
            // if ($event->model->mobile_app) {
            //     // Prepare payload for API/job 
            //     $mobileAppPayload = [
            //         'user_id'   => $event->model->mobile_app->user_id,
            //         'org_id'    => $event->model->mobile_app->org_id,
            //         'conn_id'   => $event->model->mobile_app->conn_id,
            //     ];

            //     if ($event->model->mobile_app->status == -1) {
            //         logger('STATUS: ' . $event->model->mobile_app->status);
            //         $mobileAppPayload['status'] = 1;

            //         logger("UPDATING USER");

            //         logger($mobileAppPayload);
            //         // Dispatch job
            //         UpdateAppSettings::dispatch($mobileAppPayload)->onQueue('default');


            //     }

            //     if ($event->model->mobile_app->status == 1) {
            //         logger('STATUS: ' . $event->model->mobile_app->status);
            //         logger("SUSPENDING USER");
            //         // Dispatch job
            //         SuspendAppUser::dispatch($mobileAppPayload)->onQueue('default');
            //     }
            // }
        }

        // Clear the runtime domain_uuid to avoid conflicts
        Activity::clearRuntimeDomainUuid();

        // Disable Vocemail if exists
        if ($event->model->voicemail) {
            if ($event->model->suspended) {
                $event->model->voicemail->voicemail_enabled = 'false';
            } else {
                $event->model->voicemail->voicemail_enabled = 'true';
            }
            $event->model->voicemail->save();
        }
    }
}

<?php

namespace App\Models;

use Carbon\Carbon;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\PhoneNumberFormat;
use Illuminate\Database\Eloquent\Model;
use libphonenumber\NumberParseException;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CDR extends Model
{
    use HasFactory, \App\Models\Traits\TraitUuid;

    protected $table = "v_xml_cdr";

    public $timestamps = false;

    protected $primaryKey = 'xml_cdr_uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'record_name',
    ];

    /**
     * The booted method of the model
     *
     * Define all attributes here like normal code

     */
    protected static function booted()
    {
        static::saving(function ($model) {
            // Remove attributes before saving to database
            unset($model->created_at_formatted);
            unset($model->caller_id_number_formatted);
            unset($model->caller_destination_formatted);
            unset($model->destination_number_formatted);
            unset($model->start_date);
            unset($model->start_time);
            unset($model->duration_formatted);
            unset($model->billsec_formatted);
            unset($model->waitsec_formatted);
            unset($model->call_disposition);
        });

        static::retrieved(function ($model) {
            // if ($model->created_at && $model->domain_uuid) {
            $time_zone = get_local_time_zone($model->domain_uuid);

            $model->created_at_formatted = Carbon::parse($model->created_at)->setTimezone($time_zone)->format('g:i:s A M d, Y');


            if ($model->caller_id_number) {
                $model->caller_id_number_formatted = $model->formatPhoneNumber($model->caller_id_number);
            }

            if ($model->caller_destination) {
                $model->caller_destination_formatted = $model->formatPhoneNumber($model->caller_destination);
            }

            if ($model->destination_number) {
                $model->destination_number_formatted = $model->formatPhoneNumber($model->destination_number);
            }

            if ($model->start_epoch) {
                $model->start_date = Carbon::createFromTimestamp($model->start_epoch, 'UTC')->setTimezone($time_zone)->format('M d, Y');
                $model->start_time = Carbon::createFromTimestamp($model->start_epoch, 'UTC')->setTimezone($time_zone)->format('g:i:s A');
            }

            if ($model->duration) {
                $model->duration_formatted = $model->getFormattedDuration($model->duration);
            }

            if ($model->billsec) {
                $model->billsec_formatted = $model->getFormattedDuration($model->billsec);
            }

            if ($model->start_epoch && $model->answer_epoch) {
                $model->waitsec_formatted = $model->getFormattedDuration($model->answer_epoch - $model->start_epoch);
            }

            if ($model->sip_hangup_disposition && $model->direction) {
                $dispositions = [
                    'send_bye' => 'The recipient hung up.',
                    'recv_bye' => 'The caller hung up.',
                    'send_refuse' => 'The call was refused by the recipient (e.g., busy or unavailable).',
                    'recv_refuse' => 'The call was refused by the recipient (e.g., busy or unavailable).',
                    'send_cancel' => 'The call was canceled before it was answered.',
                    'recv_cancel' => 'The call was canceled before it was answered.',
                ];

                if (isset($dispositions[$model->sip_hangup_disposition])) {
                    $model->call_disposition = $dispositions[$model->sip_hangup_disposition];
                } else {
                    $model->call_disposition = 'Unknown disposition.';
                }
            }

            // When `sip_hangup_disposition` is null and `hangup_cause` is "ORIGINATOR_CANCEL",
            // but only if `call_disposition` hasn't been set yet
            if (empty($model->call_disposition) && is_null($model->sip_hangup_disposition) && $model->hangup_cause == "ORIGINATOR_CANCEL") {
                $model->call_disposition = 'The call was canceled before it was answered.';
            }

            // When `sip_hangup_disposition` is null and `hangup_cause` is "LOSE_RACE",
            // but only if `call_disposition` hasn't been set yet
            if (empty($model->call_disposition) && is_null($model->sip_hangup_disposition) && $model->hangup_cause == "LOSE_RACE") {
                $model->call_disposition = 'The call was answered somewhere else.';
            }

            // logger('here');
            // logger($model->waitsec);
            // logger($model->waitsec_formatted);

            if (
                isset($model->status) &&
                isset($model->hangup_cause) &&
                isset($model->hangup_cause_q850) &&
                isset($model->sip_hangup_disposition) &&
                isset($model->voicemail_message) &&
                isset($model->missed_call)
            ) {
                // Missed call
                if ($model->voicemail_message == false && $model->missed_call == true && $model->hangup_cause == "NORMAL_CLEARING") {
                    $model->status = "missed call";
                }

                // Abandon call
                if (
                    isset($model->cc_cancel_reason) &&
                    isset($model->cc_cause) &&
                    $model->status == "missed call" &&
                    $model->cc_cancel_reason == "BREAK_OUT" &&
                    $model->cc_cause == "cancel"
                ) {
                    $model->status = "abandoned";
                }
            }


            // Call center answered call
            if ($model->cc_cause == 'answered') {
                $model->cc_result = 'Answered';
            }

            if ($model->cc_cause == 'cancel') {
                switch ($model->cc_cancel_reason) {
                    case 'NONE':
                        $model->cc_result = "No specific reason";
                        break;
                    case 'NO_AGENT_TIMEOUT':
                        $model->cc_result = "No agents in queue";
                        break;
                    case 'BREAK_OUT':
                        $model->cc_result = "Abandoned";
                        break;
                    case 'EXIT_WITH_KEY':
                        $model->cc_result = "The caller pressed the exit key";
                        break;
                    case 'TIMEOUT':
                        $model->cc_result = "Queue timeout reached";
                        break;
                }
            }



            // }
            // $model->destroy_route = route('devices.destroy', $model);

            return $model;
        });
    }

    public function getFormattedDuration($value)
    {
        // Calculate hours, minutes, and seconds
        $hours = floor($value / 3600);
        $minutes = floor(($value % 3600) / 60);
        $seconds = $value % 60;

        // Format each component to be two digits with leading zeros if necessary
        $formattedHours = str_pad($hours, 2, "0", STR_PAD_LEFT);
        $formattedMinutes = str_pad($minutes, 2, "0", STR_PAD_LEFT);
        $formattedSeconds = str_pad($seconds, 2, "0", STR_PAD_LEFT);

        // Concatenate the formatted components
        $formattedDuration = $formattedHours . ':' . $formattedMinutes . ':' . $formattedSeconds;

        return $formattedDuration;
    }


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    public function archive_recording()
    {
        return $this->hasOne(ArchiveRecording::class, 'xml_cdr_uuid', 'xml_cdr_uuid');
    }

    /**
     * Get domain that this model belongs to 
     */
    public function domain()
    {
        return $this->belongsTo(Domain::class, 'domain_uuid', 'domain_uuid');
    }

    /**
     * Get domain that this model belongs to 
     */
    public function extension()
    {
        return $this->belongsTo(Extensions::class, 'extension_uuid', 'extension_uuid');
    }

    public function formatPhoneNumber($value)
    {
        //Get libphonenumber object
        $phoneNumberUtil = PhoneNumberUtil::getInstance();

        //try to convert phone number to National format
        try {
            $phoneNumberObject = $phoneNumberUtil->parse($value, 'US');
            if ($phoneNumberUtil->isValidNumber($phoneNumberObject)) {
                $number_formatted = $phoneNumberUtil
                    ->format($phoneNumberObject, PhoneNumberFormat::NATIONAL);
            } else {
                $number_formatted = $value;
            }
        } catch (NumberParseException $e) {
            // Do nothing and leave the numbner as is
            $number_formatted = $value;
        }

        return $number_formatted;
    }

    public function relatedQueueCalls()
    {
        return $this->hasMany(CDR::class, 'cc_member_session_uuid', 'xml_cdr_uuid');
    }

    public function relatedRingGroupCalls()
    {
        return $this->hasMany(CDR::class, 'originating_leg_uuid', 'xml_cdr_uuid');
    }
}

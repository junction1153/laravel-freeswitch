<?php

namespace App\Http\Controllers;


use Throwable;
use Carbon\Carbon;
use Inertia\Inertia;
use App\Models\Faxes;
use App\Models\FaxLogs;
use App\Models\FaxFiles;
use App\Models\Dialplans;
use App\Models\FaxQueues;
use App\Models\FusionCache;
use App\Models\Destinations;
use Illuminate\Http\Request;
use App\Models\DefaultSettings;
use App\Models\FaxAllowedEmails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use libphonenumber\PhoneNumberUtil;
use App\Models\FaxAllowedDomainNames;
use libphonenumber\PhoneNumberFormat;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use App\Jobs\SendFaxNotificationToSlack;
use Illuminate\Support\Facades\Response;
use libphonenumber\NumberParseException;
use Illuminate\Support\Facades\Validator;

class FaxesController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        if ($request->hasHeader('X-Inertia')) {
            return Inertia::location(route($request->route()->getName()));
        }
        
        // Check permissions
        if (!userCheckPermission("fax_view")) {
            return redirect('/');
        }
        // $list = Session::get('permissions', false);
        // pr($list);exit;

        $searchString = $request->get('search');

        $domain_uuid = Session::get('domain_uuid');

        $faxes =  Faxes::where('domain_uuid', $domain_uuid);
        if ($searchString) {
            $faxes->where(function ($query) use ($searchString) {
                $query->where('fax_name', 'ilike', '%' . str_replace('-', '', $searchString) . '%')
                    ->orWhere('fax_extension', 'ilike', '%' . str_replace('-', '', $searchString) . '%')
                    ->orWhere('fax_email', 'ilike', '%' . str_replace('-', '', $searchString) . '%');
            });
        }

        $faxes = $faxes
            ->paginate(10)
            ->onEachSide(1);

        // Set the start and end dates for the period
        $endDate = Carbon::now();  // Current date
        // dd($endDate);
        $startDate = Carbon::now()->subDays(30);  // 30 days ago
        $period = [
            Carbon::now()->startOfDay()->subDays(30),
            Carbon::now()->endOfDay()
        ];
        // dd(Carbon::now()->endOfDay());
        // Convert the dates to the desired format for the query
        // $period = [$startDate->toDateString(), $endDate->toDateString()];

        // Calculate total of sent faxes in the last month
        $totalReceived = FaxFiles::where('fax_mode', 'rx')
            ->where('domain_uuid', $domain_uuid)
            ->whereBetween('fax_date', $period)
            ->count();
        // ->toSql();

        // Calculate total of sent faxes in the last month
        $totalSent = FaxFiles::where('fax_mode', 'tx')
            ->where('domain_uuid', $domain_uuid)
            ->whereBetween('fax_date', $period)
            ->count();
        // ->toSql();


        // Get recent outbound faxes
        $outboundFaxes = FaxQueues::select(
            'v_fax_queue.fax_queue_uuid',
            'v_fax_queue.fax_caller_id_name',
            'v_fax_queue.fax_caller_id_number',
            'v_fax_queue.fax_number',
            'v_fax_queue.fax_date',
            'v_fax_queue.fax_status',
            'v_fax_queue.fax_uuid',
            'v_fax_queue.fax_date',
            'v_fax_queue.fax_status',
            'v_fax_queue.fax_retry_date',
            'v_fax_queue.fax_retry_count',
            'v_fax_queue.fax_notify_date',
            'v_fax_files.fax_destination'
        )
            ->where('v_fax_queue.domain_uuid', $domain_uuid)
            ->whereBetween('v_fax_queue.fax_date', $period)
            ->leftJoin('v_fax_files', 'fax_file_path', 'fax_file')
            ->orderBy('v_fax_queue.fax_date', 'desc')
            ->limit(5)
            ->get();

        $timeZone = get_local_time_zone($domain_uuid);
        /** @var FaxQueues $file */
        foreach ($outboundFaxes as $file) {
            $file->fax_date = \Illuminate\Support\Carbon::parse($file->fax_date)->setTimezone($timeZone);
            if (!empty($file->fax_notify_date)) {
                $file->fax_notify_date = Carbon::parse($file->fax_notify_date)->setTimezone($timeZone);
            }
            if (!empty($file->fax_retry_date)) {
                $file->fax_retry_date = Carbon::parse($file->fax_retry_date)->setTimezone($timeZone);
            }
        }

        $inboundFaxes = FaxFiles::where('fax_mode', 'rx')
            ->where('domain_uuid', $domain_uuid)
            ->whereBetween('fax_date', $period)
            ->orderBy('fax_date', 'desc')
            ->limit(5)
            ->get();

        //Get libphonenumber object
        $phoneNumberUtil = PhoneNumberUtil::getInstance();

        foreach ($inboundFaxes as $file) {
            $file->fax_date = \Illuminate\Support\Carbon::parse($file->fax_date);
            // Try to convert caller ID number to National format
            try {
                $phoneNumberObject = $phoneNumberUtil->parse($file->fax_caller_id_number, 'US');
                if ($phoneNumberUtil->isValidNumber($phoneNumberObject)) {
                    $file->fax_caller_id_number = $phoneNumberUtil
                        ->format($phoneNumberObject, PhoneNumberFormat::NATIONAL);
                }
            } catch (NumberParseException $e) {
                // Do nothing and leave the numner as is
            }

            // Try to convert destination number to National format
            try {
                $phoneNumberObject = $phoneNumberUtil->parse($file->fax->fax_caller_id_number, 'US');
                if ($phoneNumberUtil->isValidNumber($phoneNumberObject)) {
                    $file->fax->fax_caller_id_number = $phoneNumberUtil
                        ->format($phoneNumberObject, PhoneNumberFormat::NATIONAL);
                }
            } catch (NumberParseException $e) {
                // Do nothing and leave the numner as is
            }
        }


        $data['faxes'] = $faxes;
        $data['searchString'] = $searchString;
        $data['totalReceived'] = $totalReceived;
        $data['totalSent'] = $totalSent;
        $data['files'] = $outboundFaxes;
        $data['inboundFaxes'] = $inboundFaxes;
        $data['national_phone_number_format'] = PhoneNumberFormat::NATIONAL;
        $permissions['add_new'] = userCheckPermission('fax_add');
        $permissions['edit'] = userCheckPermission('fax_edit');
        $permissions['delete'] = userCheckPermission('fax_delete');
        $permissions['view'] = userCheckPermission('fax_view');
        $permissions['send'] = userCheckPermission('fax_send');
        $permissions['fax_inbox_view'] = userCheckPermission('fax_inbox_view');
        $permissions['fax_sent_view'] = userCheckPermission('fax_sent_view');
        $permissions['fax_active_view'] = userCheckPermission('fax_active_view');
        $permissions['fax_log_view'] = userCheckPermission('fax_log_view');
        $permissions['fax_send'] = userCheckPermission('fax_send');

        foreach ($data['faxes'] as $fax) {
            if (!empty($fax->fax_email)) {
                $fax->fax_email = explode(',', $fax->fax_email);
            } else {
                $fax->fax_email = [];
            }
        }

        return view('layouts.fax.list')
            ->with($data)
            ->with('permissions', $permissions);
    }

    public function inbox(Request $request)
    {
        // Check permissions
        if (!userCheckPermission("fax_inbox_view")) {
            return redirect('/');
        }
        $domain_uuid = Session::get('domain_uuid');

        //Get libphonenumber object
        $phoneNumberUtil = PhoneNumberUtil::getInstance();

        $searchString = $request->get('search');
        $searchPeriod = $request->get('period');
        $period = [
            Carbon::now()->startOfDay()->subDays(30),
            Carbon::now()->endOfDay()
        ];

        if (preg_match('/^(0[1-9]|1[1-2])\/(0[1-9]|1[0-9]|2[0-9]|3[0-1])\/([1-9+]{2})\s(0[0-9]|1[0-2]:([0-5][0-9]?\d))\s(AM|PM)\s-\s(0[1-9]|1[1-2])\/(0[1-9]|1[0-9]|2[0-9]|3[0-1])\/([1-9+]{2})\s(0[0-9]|1[0-2]:([0-5][0-9]?\d))\s(AM|PM)$/', $searchPeriod)) {
            $e = explode("-", $searchPeriod);
            $period[0] = Carbon::createFromFormat('m/d/y h:i A', trim($e[0]));
            $period[1] = Carbon::createFromFormat('m/d/y h:i A', trim($e[1]));
        }

        $files = FaxFiles::where('fax_uuid', $request->id)
            ->where('fax_mode', 'rx')
            ->where('domain_uuid', $domain_uuid)
            ->whereBetween('fax_date', $period);

        if ($searchString) {
            try {
                $phoneNumberUtil = PhoneNumberUtil::getInstance();
                $phoneNumberObject = $phoneNumberUtil->parse($searchString, 'US');
                if ($phoneNumberUtil->isValidNumber($phoneNumberObject)) {
                    $files->andWhereLike('fax_caller_id_number', $phoneNumberUtil->format($phoneNumberObject, PhoneNumberFormat::E164));
                } else {
                    $files->andWhereLike('fax_caller_id_number', str_replace("-", "",  $searchString));
                }
            } catch (NumberParseException $e) {
                $files->andWhereLike('fax_caller_id_number', str_replace("-", "",  $searchString));
            }
        }
        $files = $files
            ->orderBy('fax_date', 'desc')
            ->paginate(10)
            ->onEachSide(1);

        $timeZone = get_local_time_zone($domain_uuid);
        foreach ($files as $file) {
            $file->fax_date = \Illuminate\Support\Carbon::parse($file->fax_date);
            //$file->fax_notify_date = Carbon::parse($file->fax_notify_date)->setTimezone($timeZone);
            //$file->fax_retry_date = Carbon::parse($file->fax_retry_date)->setTimezone($timeZone);
            // if (Storage::disk('fax')->exists($file->domain->domain_name . '/' . $file->fax->fax_extension . "/inbox/" . substr(basename($file->fax_file_path), 0, (strlen(basename($file->fax_file_path)) - 4)) . '.' . $file->fax_file_type)) {
            //     $file->fax_file_path = Storage::disk('fax')->path($file->domain->domain_name . '/' . $file->fax->fax_extension . "/inbox/" . substr(basename($file->fax_file_path), 0, (strlen(basename($file->fax_file_path)) - 4)) . '.' . $file->fax_file_type);
            // }

            // Try to convert caller ID number to National format
            try {
                $phoneNumberObject = $phoneNumberUtil->parse($file->fax_caller_id_number, 'US');
                if ($phoneNumberUtil->isValidNumber($phoneNumberObject)) {
                    $file->fax_caller_id_number = $phoneNumberUtil
                        ->format($phoneNumberObject, PhoneNumberFormat::NATIONAL);
                }
            } catch (NumberParseException $e) {
                // Do nothing and leave the numner as is
            }

            // Try to convert destination number to National format
            try {
                $phoneNumberObject = $phoneNumberUtil->parse($file->fax->fax_caller_id_number, 'US');
                if ($phoneNumberUtil->isValidNumber($phoneNumberObject)) {
                    $file->fax->fax_caller_id_number = $phoneNumberUtil
                        ->format($phoneNumberObject, PhoneNumberFormat::NATIONAL);
                }
            } catch (NumberParseException $e) {
                // Do nothing and leave the numner as is
            }

            // Try to convert the date to human redable format
            //$file->fax_date = Carbon::createFromTimestamp($file->fax_epoch, $timeZone)->toDayDateTimeString();
        }

        $permissions['delete'] = userCheckPermission('fax_inbox_delete');

        $data['files'] = $files;
        $data['searchString'] = $searchString;
        $data['searchPeriodStart'] = $period[0]->format('m/d/y h:i A');
        $data['searchPeriodEnd'] = $period[1]->format('m/d/y h:i A');
        $data['searchPeriod'] = implode(" - ", [$data['searchPeriodStart'], $data['searchPeriodEnd']]);
        $data['national_phone_number_format'] = PhoneNumberFormat::NATIONAL;
        return view('layouts.fax.inbox.list')
            ->with($data)
            ->with('permissions', $permissions);
    }

    public function downloadInboxFaxFile(FaxFiles $file)
    {

        $path = $file->domain->domain_name . '/' . $file->fax->fax_extension . "/inbox/" . substr(basename($file->fax_file_path), 0, (strlen(basename($file->fax_file_path)) - 4)) . '.pdf';
        // $path = $file->domain->domain_name . '/' . $file->fax->fax_extension .  "/inbox/" . substr(basename($file->fax_file_path), 0, (strlen(basename($file->fax_file_path)) -4)) . '.'.$file->fax_file_type;

        if (!Storage::disk('fax')->exists($path)) {
            abort(404);
        }

        $file = Storage::disk('fax')->path($path);
        $type = Storage::disk('fax')->mimeType($path);
        $headers = array(
            'Content-Type: ' . $type,
        );

        $response = Response::download($file, basename($file), $headers);

        return $response;
    }

    public function downloadSentFaxFile(FaxFiles $file)
    {

        // $path = $file->domain->domain_name . '/' . $file->fax->fax_extension .  "/sent/" . substr(basename($file->fax_file_path), 0, (strlen(basename($file->fax_file_path)) -4)) . '.'.$file->fax_file_type;
        $path = $file->domain->domain_name . '/' . $file->fax->fax_extension . "/sent/" . substr(basename($file->fax_file_path), 0, (strlen(basename($file->fax_file_path)) - 4)) . '.pdf';

        if (!Storage::disk('fax')->exists($path)) {
            abort(404);
        }

        $file = Storage::disk('fax')->path($path);
        $type = Storage::disk('fax')->mimeType($path);
        $headers = array(
            'Content-Type: ' . $type,
        );

        $response = Response::download($file, basename($file), $headers);

        return $response;
    }


    public function sent(Request $request)
    {
        // Check permissions
        if (!userCheckPermission("fax_sent_view")) {
            return redirect('/');
        }

        $statuses = ['all' => 'Show All', 'sent' => 'Sent', 'waiting' => 'Waiting', 'failed' => 'Failed', 'sending' => 'Sending'];
        $selectedStatus = $request->get('status');
        $searchString = $request->get('search');
        $searchPeriod = $request->get('period');
        $period = [
            Carbon::now()->startOfDay()->subDays(30),
            Carbon::now()->endOfDay()
        ];

        if (preg_match('/^(0[1-9]|1[1-2])\/(0[1-9]|1[0-9]|2[0-9]|3[0-1])\/([1-9+]{2})\s(0[0-9]|1[0-2]:([0-5][0-9]?\d))\s(AM|PM)\s-\s(0[1-9]|1[1-2])\/(0[1-9]|1[0-9]|2[0-9]|3[0-1])\/([1-9+]{2})\s(0[0-9]|1[0-2]:([0-5][0-9]?\d))\s(AM|PM)$/', $searchPeriod)) {
            $e = explode("-", $searchPeriod);
            $period[0] = Carbon::createFromFormat('m/d/y h:i A', trim($e[0]));
            $period[1] = Carbon::createFromFormat('m/d/y h:i A', trim($e[1]));
        }

        $domainUuid = Session::get('domain_uuid');

        $files = FaxQueues::select(
            'v_fax_queue.fax_queue_uuid',
            'v_fax_queue.fax_caller_id_name',
            'v_fax_queue.fax_caller_id_number',
            'v_fax_queue.fax_number',
            'v_fax_queue.fax_date',
            'v_fax_queue.fax_status',
            'v_fax_queue.fax_uuid',
            'v_fax_queue.fax_date',
            'v_fax_queue.fax_status',
            'v_fax_queue.fax_retry_date',
            'v_fax_queue.fax_retry_count',
            'v_fax_queue.fax_notify_date',
            'v_fax_files.fax_destination'
        )
            ->where('v_fax_queue.fax_uuid', $request->id)
            ->where('v_fax_queue.domain_uuid', $domainUuid)
            ->whereBetween('v_fax_queue.fax_date', $period);
        if (array_key_exists($selectedStatus, $statuses) && $selectedStatus != 'all') {
            $files
                ->where('v_fax_queue.fax_status', $selectedStatus);
        }
        $files->leftJoin('v_fax_files', 'fax_file_path', 'fax_file');
        if ($searchString) {
            try {
                $phoneNumberUtil = PhoneNumberUtil::getInstance();
                $phoneNumberObject = $phoneNumberUtil->parse($searchString, 'US');
                if ($phoneNumberUtil->isValidNumber($phoneNumberObject)) {
                    $files->andWhereLike('v_fax_queue.fax_number', $phoneNumberUtil->format($phoneNumberObject, PhoneNumberFormat::E164));
                } else {
                    $files->andWhereLike('v_fax_queue.fax_number', str_replace("-", "",  $searchString));
                }
            } catch (NumberParseException $e) {
                $files->andWhereLike('v_fax_queue.fax_number', str_replace("-", "",  $searchString));
            }
        }

        $files = $files
            ->orderBy('v_fax_queue.fax_date', 'desc')
            ->paginate(10)
            ->onEachSide(1);

        $timeZone = get_local_time_zone($domainUuid);
        /** @var FaxQueues $file */
        foreach ($files as $file) {
            $file->fax_date = \Illuminate\Support\Carbon::parse($file->fax_date)->setTimezone($timeZone);
            if (!empty($file->fax_notify_date)) {
                $file->fax_notify_date = Carbon::parse($file->fax_notify_date)->setTimezone($timeZone);
            }
            if (!empty($file->fax_retry_date)) {
                $file->fax_retry_date = Carbon::parse($file->fax_retry_date)->setTimezone($timeZone);
            }
        }

        $data['files'] = $files;
        $data['statuses'] = $statuses;
        $data['selectedStatus'] = $selectedStatus;
        $data['searchString'] = $searchString;
        $data['searchPeriodStart'] = $period[0]->format('m/d/y h:i A');
        $data['searchPeriodEnd'] = $period[1]->format('m/d/y h:i A');
        $data['searchPeriod'] = implode(" - ", [$data['searchPeriodStart'], $data['searchPeriodEnd']]);
        $data['national_phone_number_format'] = PhoneNumberFormat::NATIONAL;
        $permissions['delete'] = userCheckPermission('fax_sent_delete');
        return view('layouts.fax.sent.list')
            ->with($data)
            ->with('permissions', $permissions);
    }

    public function log(Request $request)
    {
        // Check permissions
        if (!userCheckPermission("fax_log_view")) {
            return redirect('/');
        }

        $statuses = ['all' => 'Show All', 'success' => 'Success', 'failed' => 'Failed'];
        $selectedStatus = $request->get('status');
        $searchString = $request->get('search');
        $searchPeriod = $request->get('period');
        $period = [
            Carbon::now()->startOfDay()->subDays(30),
            Carbon::now()->endOfDay()
        ];

        if (preg_match('/^(0[1-9]|1[1-2])\/(0[1-9]|1[0-9]|2[0-9]|3[0-1])\/([1-9+]{2})\s(0[0-9]|1[0-2]:([0-5][0-9]?\d))\s(AM|PM)\s-\s(0[1-9]|1[1-2])\/(0[1-9]|1[0-9]|2[0-9]|3[0-1])\/([1-9+]{2})\s(0[0-9]|1[0-2]:([0-5][0-9]?\d))\s(AM|PM)$/', $searchPeriod)) {
            $e = explode("-", $searchPeriod);
            $period[0] = Carbon::createFromFormat('m/d/y h:i A', trim($e[0]));
            $period[1] = Carbon::createFromFormat('m/d/y h:i A', trim($e[1]));
        }

        $domain_uuid = Session::get('domain_uuid');

        $timeZone = get_local_time_zone(Session::get('domain_uuid'));
        $logs = FaxLogs::where('fax_uuid', $request->id)
            ->where('domain_uuid', $domain_uuid);
        if (array_key_exists($selectedStatus, $statuses) && $selectedStatus != 'all') {
            $logs
                ->where('fax_success', ($selectedStatus == 'success'));
        }
        if ($searchString) {
            $logs->where(function ($query) use ($searchString) {
                $query
                    ->orWhereLike('fax_local_station_id', strtolower($searchString))
                    ->orWhereLike('fax_uri', strtolower($searchString));
            });
        }
        $logs->whereBetween('fax_date', $period);
        $logs = $logs->orderBy('fax_date', 'desc')->paginate(10)->onEachSide(1);

        $phoneNumberUtil = PhoneNumberUtil::getInstance();

        $timeZone = get_local_time_zone(Session::get('domain_uuid'));

        foreach ($logs as $i => $log) {
            $logs[$i]['fax_date'] = Carbon::parse($log['fax_date']);

            // Check if the values are not empty and contain a phone number
            if (!empty($logs[$i]['fax_uri']) && preg_match("/\+\d{11}/", $logs[$i]['fax_uri'], $matches1)) {
                $logs[$i]['fax_uri'] = $matches1[0]; // Extract the phone number from the matched value
            }

            // Try to convert fax_uri number to National format
            try {
                $phoneNumberObject = $phoneNumberUtil->parse($logs[$i]['fax_uri'], 'US');
                if ($phoneNumberUtil->isValidNumber($phoneNumberObject)) {
                    $logs[$i]['fax_uri'] = $phoneNumberUtil
                        ->format($phoneNumberObject, PhoneNumberFormat::NATIONAL);
                }
            } catch (NumberParseException $e) {
                // Do nothing and leave the numner as is
            }

            // Try to convert fax_local_station_id number to National format
            try {
                $phoneNumberObject = $phoneNumberUtil->parse($logs[$i]['fax_local_station_id'], 'US');
                if ($phoneNumberUtil->isValidNumber($phoneNumberObject)) {
                    $logs[$i]['fax_local_station_id'] = $phoneNumberUtil
                        ->format($phoneNumberObject, PhoneNumberFormat::NATIONAL);
                }
            } catch (NumberParseException $e) {
                // Do nothing and leave the numner as is
            }
        }

        $data['logs'] = $logs;
        $data['statuses'] = $statuses;
        $data['selectedStatus'] = $selectedStatus;
        $data['searchString'] = $searchString;
        $data['searchPeriodStart'] = $period[0]->format('m/d/y h:i A');
        $data['searchPeriodEnd'] = $period[1]->format('m/d/y h:i A');
        $data['searchPeriod'] = implode(" - ", [$data['searchPeriodStart'], $data['searchPeriodEnd']]);

        unset($statuses, $logs, $log, $domainUuid, $timeZone, $selectedStatus, $searchString, $selectedScope);

        $permissions['delete'] = userCheckPermission('fax_log_delete');
        return view('layouts.fax.log.list')
            ->with($data)
            ->with('permissions', $permissions);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        // Check permissions
        if (!userCheckPermission("fax_add")) {
            return redirect('/');
        }


        // Get all phone numbers
        $destinations = Destinations::where('destination_enabled', 'true')
            ->where('domain_uuid', Session::get('domain_uuid'))
            ->get([
                'destination_uuid',
                'destination_number',
                'destination_enabled',
                'destination_description',
                DB::Raw("coalesce(destination_description , '') as destination_description"),
            ])
            ->sortBy('destination_number');

        //Get libphonenumber object
        $phoneNumberUtil = PhoneNumberUtil::getInstance();

        foreach ($destinations as $destination) {
            try {
                $phoneNumberObject = $phoneNumberUtil->parse($destination->destination_number, 'US');
                if ($phoneNumberUtil->isValidNumber($phoneNumberObject)) {
                    $destination->destination_number = $phoneNumberUtil
                        ->format($phoneNumberObject, PhoneNumberFormat::E164);
                }

                // Set the label
                $phoneNumber = $phoneNumberUtil->format($phoneNumberObject, PhoneNumberFormat::NATIONAL);
                $destination->label = isset($destination->destination_description) && !empty($destination->destination_description)
                    ? $phoneNumber . " - " . $destination->destination_description
                    : $phoneNumber;
            } catch (NumberParseException $e) {
                // Do nothing and leave the numbner as is

                //Set the label
                $destination->label = isset($destination->destination_description) && !empty($destination->destination_description)
                    ? $destination->destination_number . " - " . $destination->destination_description
                    : $destination->destination_number;
            }

            $destination->isCallerID = false;
        }


        $data = [];
        $fax = new Faxes;
        $data['fax'] = $fax;
        $data['domain'] = Session::get('domain_name');
        $data['destinations'] = $destinations;
        $data['national_phone_number_format'] = PhoneNumberFormat::NATIONAL;
        $data['allowed_emails'] = $fax->allowed_emails;
        $data['allowed_domain_names'] = $fax->allowed_domain_names;


        return view('layouts.fax.createOrUpdate')->with($data);;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, Faxes $fax)
    {

        if (!userCheckPermission('fax_add') || !userCheckPermission('fax_edit')) {
            return redirect('/');
        }

        //Setting variables to use
        $domain_id = Session::get('domain_uuid');
        $domain_name = Session::get('domain_name');


        //Validation check
        $attributes = [
            'fax_name' => 'Fax Name',
            'fax_extension' => 'Fax Extension',
            // 'accountcode' =>'Account Code',
            // 'fax_destination_number' => 'Destination Number',
            // 'fax_prefix' => 'Prefix',
            'fax_email' => 'Email',
            'fax_caller_id_name' => 'Caller ID name',
            'fax_caller_id_number' => 'Caller ID number',
            'fax_forward_number' => 'Fax Forward Number',
            'fax_toll_allow' => 'Fax Toll Allow',
            'fax_send_channels' => 'Fax Send Channels',
            'fax_description' => 'Description',
        ];

        $validator = Validator::make($request->all(), [

            'fax_name' => 'required',
            'fax_extension' => 'required',
            // 'accountcode' => 'nullable',
            // 'fax_destination_number' => 'nullable',
            // 'fax_prefix' => 'nullable',
            'fax_email' => 'nullable|array',
            'fax_caller_id_name' => 'nullable',
            'fax_caller_id_number' => 'required',
            'fax_forward_number' => 'nullable',
            'fax_toll_allow' => 'nullable',
            'fax_send_channels' => 'nullable',
            'fax_description' => 'nullable|string|max:100',
            'email_list' => 'nullable|array',

        ], [], $attributes);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()]);
        }

        // Retrieve the validated input assign all attributes
        $attributes = $validator->validated();
        $attributes['domain_uuid'] = $domain_id;
        $attributes['accountcode'] = $domain_name;
        $attributes['fax_prefix'] = 9999;
        $attributes['fax_destination_number'] = $attributes['fax_extension'];
        $fax_email = '';
        if (isset($attributes['fax_email'])) {
            $fax_email = implode(',', $attributes['fax_email']);
        }
        $attributes['fax_email'] = $fax_email;
        $fax->fill($attributes);
        $fax->save();

        $dialplan = new Dialplans;
        $dialplan->domain_uuid = $domain_id;
        $dialplan->app_uuid = "24108154-4ac3-1db6-1551-4731703a4440";
        $dialplan->dialplan_name = $attributes['fax_name'];
        $dialplan->dialplan_number = $attributes['fax_extension'];
        $dialplan->dialplan_context = $domain_name;
        $dialplan->dialplan_continue = 'false';
        $dialplan->dialplan_order = '310';
        $dialplan->dialplan_enabled = 'true';
        $dialplan->dialplan_description = $attributes['fax_description'];
        $dialplan->save();
        $dialplan->dialplan_xml = get_fax_dial_plan($fax, $dialplan);
        $dialplan->save();
        $fax->dialplan_uuid = $dialplan->dialplan_uuid;
        $fax->save();

        // Create all fax directories
        try {

            $settings = DefaultSettings::where('default_setting_category', 'switch')
                ->get([
                    'default_setting_subcategory',
                    'default_setting_name',
                    'default_setting_value',
                ]);

            foreach ($settings as $setting) {
                if ($setting->default_setting_subcategory == 'storage') {
                    $fax_dir = $setting->default_setting_value . '/fax/' . $domain_name;
                    $stor_dir = $setting->default_setting_value;
                }
            }

            // Set variables for all directories
            $dir_fax_inbox = $fax_dir . '/' . $fax->fax_extension . '/inbox';
            $dir_fax_sent = $fax_dir . '/' . $fax->fax_extension . '/sent';
            $dir_fax_temp = $fax_dir . '/' . $fax->fax_extension . '/temp';

            //make sure the directories exist
            if (!is_dir($stor_dir)) {
                mkdir($stor_dir, 0770);
            }
            if (!is_dir($stor_dir . '/fax')) {
                mkdir($stor_dir . '/fax', 0770);
            }
            if (!is_dir($stor_dir . '/fax/' . $domain_name)) {
                mkdir($stor_dir . '/fax/' . $domain_name, 0770);
            }
            if (!is_dir($fax_dir . '/' . $fax->fax_extension)) {
                mkdir($fax_dir . '/' . $fax->fax_extension, 0770);
            }
            if (!is_dir($dir_fax_inbox)) {
                mkdir($dir_fax_inbox, 0770);
            }
            if (!is_dir($dir_fax_sent)) {
                mkdir($dir_fax_sent, 0770);
            }
            if (!is_dir($dir_fax_temp)) {
                mkdir($dir_fax_temp, 0770);
            }
        } catch (Throwable $e) {
            $message = $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . '\n';
            Log::alert($message);
            SendFaxNotificationToSlack::dispatch($message)->onQueue('faxes');
            //Process errors
        }


        // If allowed email list is submitted save it to database
        if (isset($attributes['email_list'])) {
            foreach ($attributes['email_list'] as $email) {
                $allowed_email = new FaxAllowedEmails();
                $allowed_email->fax_uuid = $fax->fax_uuid;
                $allowed_email->email = $email;
                $allowed_email->save();
            }
        }

        // If allowed domain list is submitted save it to database
        if (isset($attributes['domain_list'])) {
            foreach ($attributes['domain_list'] as $domain) {
                $allowed_domain = new FaxAllowedDomainNames();
                $allowed_domain->fax_uuid = $fax->fax_uuid;
                $allowed_domain->domain = $domain;
                $allowed_domain->save();
            }
        }

        $fp = event_socket_create(
            config('eventsocket.ip'),
            config('eventsocket.port'),
            config('eventsocket.password')
        );

        //clear fusionpbx cache
        FusionCache::clear("dialplan:" . $domain_name);

        //clear the destinations session array
        if (isset($_SESSION['destinations']['array'])) {
            unset($_SESSION['destinations']['array']);
        }

        return response()->json([
            'fax' => $fax->fax_uuid,
            'redirect_url' => route('faxes.edit', ['fax' => $fax->fax_uuid]),
            'status' => 'success',
            'message' => 'Fax has been created'
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Faxes $fax)
    {
        //check permissions
        if (!userCheckPermission('fax_edit')) {
            return redirect('/');
        }

        // Get all phone numbers
        $destinations = Destinations::where('destination_enabled', 'true')
            ->where('domain_uuid', Session::get('domain_uuid'))
            ->get([
                'destination_uuid',
                'destination_number',
                'destination_enabled',
                'destination_description',
                DB::Raw("coalesce(destination_description , '') as destination_description"),
            ])
            ->sortBy('destination_number');

        //Get libphonenumber object
        $phoneNumberUtil = PhoneNumberUtil::getInstance();

        //try to convert caller ID to e164 format
        if ($fax->fax_caller_id_number) {
            try {
                $phoneNumberObject = $phoneNumberUtil->parse($fax->fax_caller_id_number, 'US');
                if ($phoneNumberUtil->isValidNumber($phoneNumberObject)) {
                    $fax->fax_caller_id_number = $phoneNumberUtil
                        ->format($phoneNumberObject, PhoneNumberFormat::E164);
                }
            } catch (NumberParseException $e) {
                // Do nothing and leave the numbner as is
            }
        }

        foreach ($destinations as $destination) {
            try {
                $phoneNumberObject = $phoneNumberUtil->parse($destination->destination_number, 'US');
                if ($phoneNumberUtil->isValidNumber($phoneNumberObject)) {
                    $destination->destination_number = $phoneNumberUtil
                        ->format($phoneNumberObject, PhoneNumberFormat::E164);
                }

                // Set the label
                $phoneNumber = $phoneNumberUtil->format($phoneNumberObject, PhoneNumberFormat::NATIONAL);
                $destination->label = isset($destination->destination_description) && !empty($destination->destination_description)
                    ? $phoneNumber . " - " . $destination->destination_description
                    : $phoneNumber;
            } catch (NumberParseException $e) {
                // Do nothing and leave the numbner as is

                //Set the label
                $destination->label = isset($destination->destination_description) && !empty($destination->destination_description)
                    ? $destination->destination_number . " - " . $destination->destination_description
                    : $destination->destination_number;
            }

            $destination->isCallerID = ($destination->destination_number === $fax->fax_caller_id_number);
        }


        if (isset($fax->fax_email)) {
            if (!empty($fax->fax_email)) {
                $fax->fax_email = explode(',', $fax->fax_email);
            }
        }


        $data = array();
        $data['fax'] = $fax;
        $data['domain'] = Session::get('domain_name');
        $data['destinations'] = $destinations;
        $data['national_phone_number_format'] = PhoneNumberFormat::NATIONAL;
        $data['allowed_emails'] = $fax->allowed_emails;
        $data['allowed_domain_names'] = $fax->allowed_domain_names;

        return view('layouts.fax.createOrUpdate')->with($data);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */

    function update(Request $request, Faxes $fax)
    {
        if (!userCheckPermission('fax_add') || !userCheckPermission('fax_edit')) {
            return redirect('/');
        }

        $attributes = [
            'fax_name' => 'Fax Name',
            'fax_extension' => 'Fax Extension',
            // 'accountcode' =>'Account Code',
            // 'fax_destination_number' => 'Destination Number',
            // 'fax_prefix' => 'Prefix',
            'fax_email' => 'Email',
            'fax_caller_id_name' => 'Caller ID name',
            'fax_caller_id_number' => 'Caller ID number',
            'fax_forward_number' => 'Fax Forward Number',
            'fax_toll_allow' => 'Fax Toll Allow',
            'fax_send_channels' => 'Fax Send Channels',
            'fax_description' => 'Description',
        ];

        $validator = Validator::make($request->all(), [

            'fax_name' => 'required',
            'fax_extension' => 'required',
            // 'accountcode' => 'nullable',
            // 'fax_destination_number' => 'nullable',
            // 'fax_prefix' => 'nullable',
            'fax_email' => 'nullable|array',
            'fax_caller_id_name' => 'nullable',
            'fax_caller_id_number' => 'nullable',
            'fax_forward_number' => 'nullable',
            'fax_toll_allow' => 'nullable',
            'fax_send_channels' => 'nullable',
            'fax_description' => 'nullable|string|max:100',
            'email_list' => 'nullable|array',
            'domain_list' => 'nullable|array',

        ], [], $attributes);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()]);
        }

        // Retrieve the validated input assign all attributes
        $attributes = $validator->validated();
        $attributes['fax_destination_number'] = $attributes['fax_extension'];
        $fax_email = '';
        if (isset($attributes['fax_email'])) {
            $fax_email = implode(',', $attributes['fax_email']);
        }
        $attributes['fax_email'] = $fax_email;
        $fax->fill($attributes);
        $fax->update($attributes);


        //Setting variables to use
        $domain_id = Session::get('domain_uuid');
        $domain_name = Session::get('domain_name');

        $old_dialplan = Dialplans::where('dialplan_uuid', $fax->dialplan_uuid)->first();
        if (!empty($old_dialplan)) {
            $old_dialplan->delete();
        }

        $dialplan = new Dialplans;
        $dialplan->domain_uuid = $domain_id;
        $dialplan->app_uuid = "24108154-4ac3-1db6-1551-4731703a4440";
        $dialplan->dialplan_name = $attributes['fax_name'];
        $dialplan->dialplan_number = $attributes['fax_extension'];
        $dialplan->dialplan_context = $domain_name;
        $dialplan->dialplan_continue = 'false';
        $dialplan->dialplan_order = '310';
        $dialplan->dialplan_enabled = 'true';
        $dialplan->dialplan_description = $attributes['fax_description'];
        $dialplan->save();
        $dialplan->dialplan_xml = get_fax_dial_plan($fax, $dialplan);
        $dialplan->save();
        $fax->dialplan_uuid = $dialplan->dialplan_uuid;
        $fax->save();


        // Remove current allowed emails from the database
        if (isset($fax->allowed_emails)) {
            foreach ($fax->allowed_emails as $email) {
                $email->delete();
            }
        }

        // Remove current allowed domains from the database
        if (isset($fax->allowed_domain_names)) {
            foreach ($fax->allowed_domain_names as $domain_name) {
                $domain_name->delete();
            }
        }

        // If allowed email list is submitted save it to database
        if (isset($attributes['email_list'])) {
            foreach ($attributes['email_list'] as $email) {
                $allowed_email = new FaxAllowedEmails();
                $allowed_email->fax_uuid = $fax->fax_uuid;
                $allowed_email->email = $email;
                $allowed_email->save();
            }
        }

        // If allowed domain list is submitted save it to database
        if (isset($attributes['domain_list'])) {
            foreach ($attributes['domain_list'] as $domain) {
                $allowed_domain = new FaxAllowedDomainNames();
                $allowed_domain->fax_uuid = $fax->fax_uuid;
                $allowed_domain->domain = $domain;
                $allowed_domain->save();
            }
        }

        $fp = event_socket_create(
            config('eventsocket.ip'),
            config('eventsocket.port'),
            config('eventsocket.password')
        );

        //clear fusionpbx cache
        FusionCache::clear("dialplan:" . $domain_name);

        //clear the destinations session array
        if (isset($_SESSION['destinations']['array'])) {
            unset($_SESSION['destinations']['array']);
        }
        return response()->json([
            'fax' => $fax->fax_uuid,
            //'request' => $attributes,
            'status' => 'success',
            'message' => 'Fax has been updated'
        ]);
    }


    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Faxes $fax)
    {
        try {
            // Delete related records first
            $fax->allowed_emails()->delete();
            $fax->allowed_domain_names()->delete();

            // Delete the fax itself
            $fax->delete();

            return response()->json([
                'status' => 200,
                'success' => [
                    'message' => 'The fax and all related records have been successfully deleted.'
                ]
            ]);
        } catch (\Exception $e) {
            logger('Fax deletion error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());

            return response()->json([
                'status' => 500,
                'error' => [
                    'message' => 'An error occurred while deleting the fax.'
                ]
            ]);
        }
    }


    public function deleteSentFax($id)
    {
        /** @var FaxQueues $fax */
        $fax = FaxQueues::findOrFail($id);

        if (isset($fax)) {
            if ($fax->getFaxFile()) {
                $file = $fax->getFaxFile();
                $file->delete();
            }

            $deleted = $fax->delete();
            if ($deleted) {
                return response()->json([
                    'status' => 200,
                    'success' => [
                        'message' => 'Selected fax have been deleted'
                    ]
                ]);
            } else {
                return response()->json([
                    'status' => 401,
                    'error' => [
                        'message' => 'There was an error deleting selected fax'
                    ]
                ]);
            }
        }
    }

    public function deleteReceivedFax($id)
    {
        $fax = FaxFiles::findOrFail($id);

        if (isset($fax)) {
            $deleted = $fax->delete();
            if ($deleted) {
                return response()->json([
                    'status' => 200,
                    'success' => [
                        'message' => 'Selected fax have been deleted'
                    ]
                ]);
            } else {
                return response()->json([
                    'status' => 401,
                    'error' => [
                        'message' => 'There was an error deleting selected fax'
                    ]
                ]);
            }
        }
    }

    public function deleteFaxLog($id)
    {
        $fax = FaxLogs::findOrFail($id);

        if (isset($fax)) {
            $deleted = $fax->delete();
            if ($deleted) {
                return response()->json([
                    'status' => 200,
                    'success' => [
                        'message' => 'Selected log has been deleted'
                    ]
                ]);
            } else {
                return response()->json([
                    'status' => 401,
                    'error' => [
                        'message' => 'There was an error deleting selected log'
                    ]
                ]);
            }
        }
    }


    /**
     * Display new fax page
     *
     * 
     * @return \Illuminate\Http\Response
     */

    public function new(Request $request)
    {
        // Check permissions
        if (!userCheckPermission("fax_send")) {
            return redirect('/');
        }

        if ($request->get('id') != "") {
            // logger($request->get('id'));
            $fax = Faxes::find($request->get('id'));
        } else {
            $fax = null;
        }

        // Get all phone numbers
        $destinations = Destinations::where('destination_enabled', 'true')
            ->where('domain_uuid', Session::get('domain_uuid'))
            ->get([
                'destination_uuid',
                'destination_number',
                'destination_enabled',
                'destination_description',
                DB::Raw("coalesce(destination_description , '') as destination_description"),
            ])
            ->sortBy('destination_number');

        $fax_numbers = Faxes::where('domain_uuid', Session::get('domain_uuid'))
            ->get(
                ['fax_caller_id_number']
            )
            ->sortBy('fax_caller_id_number');

        $data = [];
        $data['domain'] = Session::get('domain_name');
        $data['fax_numbers'] = $fax_numbers;
        $data['fax'] = $fax;
        $data['national_phone_number_format'] = PhoneNumberFormat::NATIONAL;

        //Set default allowed extensions
        $fax_allowed_extensions = DefaultSettings::where('default_setting_category', 'fax')
            ->where('default_setting_subcategory', 'allowed_extension')
            ->where('default_setting_enabled', 'true')
            ->pluck('default_setting_value')
            ->toArray();

        if (empty($fax_allowed_extensions)) {
            $fax_allowed_extensions = array('.pdf', '.tiff', '.tif');
        }

        $fax_allowed_extensions = implode(',', $fax_allowed_extensions);

        $data['fax_allowed_extensions'] = $fax_allowed_extensions;

        return view('layouts.fax.new.sendFax')->with($data);
    }

    /**
     *  This function accespt a request to send new fax
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */

    public function sendFax(Request $request)
    {
        $data = $request->all();

        // If files attached
        if (isset($data['file'])) {
            $files = $data['file'];
        }

        // Convert form fields to associative array
        // parse_str($data['data'], $data);

        // Validate the input
        $attributes = [
            'recipient' => 'fax recipient',
        ];

        $validator = Validator::make($data, [
            'recipient' => 'numeric|required|phone:US',
            'fax_message' => 'string|nullable',
            'send_confirmation' => 'present',

        ], [], $attributes);

        if ($validator->fails()) {
            // return response()->json(['error' => $validator->errors()]);
            return response()->json([
                'error' => $validator->errors()->first() // Sending the first error message for simplicity
            ], 400); // Bad Request status code
        }

        $data['send_confirmation'] = $request->has('send_confirmation') && $data['send_confirmation'] == 'true';
        // logger($data['send_confirmation']);

        if (!isset($data['fax_uuid'])) {
            $fax = Faxes::where('domain_uuid', Session::get('domain_uuid'))
                ->where('fax_caller_id_number', $data['sender_fax_number'])
                ->first();
            $data['fax_uuid'] = $fax->fax_uuid;
        }


        if (!isset($files) || sizeof($files) == 0) {
            return response()->json(['error' => 'At least one file must be uploaded'], 400);
        }

        // Start creating the payload variable that will be passed to next step
        $payload = array(
            'From' => Session::get('user.user_email'),
            'FromFull' => array(
                'Email' => ($data['send_confirmation']) ? Session::get('user.user_email') : '',
            ),
            'To' => $data['recipient'] . '@fax.domain.com',
            'Subject' => ($data['fax_message'] == "") ? $data['fax_subject'] : $data['fax_subject'] . " body",
            'TextBody' => strip_tags($data['fax_message']),
            'HtmlBody' => strip_tags($data['fax_message']),
            'fax_destination' => $data['recipient'],
            'fax_uuid' => $data['fax_uuid'],
        );

        $redirect_url = route('faxes.sent.list', $data['fax_uuid']);
        $payload['Attachments'] = array();

        // Parse files
        foreach ($files as $file) {
            // $splited = explode(',', substr($file['data'], 5), 2);
            // $mime = $splited[0];
            // $data = $splited[1];
            // $mime_split_without_base64 = explode(';', $mime, 2);
            // $mime = $mime_split_without_base64[0];
            // // $mime_split=explode('/', $mime_split_without_base64[0],2);

            $mime = $file->getClientMimeType();

            // Get original file name
            $fileName = $file->getClientOriginalName();

            // Read the file content
            $content = file_get_contents($file->getRealPath());

            // Encode the content to base64 if needed
            $base64Content = base64_encode($content);

            array_push(
                $payload['Attachments'],
                array(
                    'Content' => $base64Content,
                    'ContentType' => $mime,
                    'Name' => $fileName,
                )
            );
        }

        $fax = new Faxes();
        $result = $fax->EmailToFax($payload);

        return response()->json([
            'redirect_url' => $redirect_url,
            'status' => 200,
            'success' => [
                'message' => 'Fax is scheduled for delivery'
            ]
        ]);
    }

    public function updateStatus(FaxQueues $faxQueue, $status = null)
    {
        $faxQueue->update([
            'fax_status' => $status,
            'fax_retry_count' => 0,
            'fax_retry_date' => null
        ]);

        return redirect()->back();
    }
}

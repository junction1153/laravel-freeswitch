<?php

namespace App\Http\Controllers;

use App\Models\CDR;
use Inertia\Inertia;
use App\Jobs\ExportCdrs;
use App\Models\Dialplans;
use App\Models\Extensions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\CallCenterQueues;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use App\Services\CdrDataService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CdrsController extends Controller
{

    public $model;
    public $filters = [];
    public $sortField;
    public $sortOrder;
    protected $viewName = 'Cdrs';
    protected $searchable = ['caller_id_name', 'caller_id_number', 'caller_destination', 'destination_number', 'sip_call_id', 'cc_member_session_uuid', 'status'];
    public $item_domain_uuid;
    protected $cdrDataService;

    public function __construct(CdrDataService $cdrDataService)
    {
        $this->cdrDataService = $cdrDataService;
        $this->model = new CDR();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // logger($request->all());
        // Check permissions
        if (!userCheckPermission("xml_cdr_view")) {
            return redirect('/');
        }


        if ($request->callUuid) {
            $callUuid = $request->callUuid;
        }

        return Inertia::render(
            $this->viewName,
            [
                'data' => function () {
                    return $this->getData();
                },
                'startPeriod' => function () {
                    return $this->filters['startPeriod'];
                },
                'endPeriod' => function () {
                    return $this->filters['endPeriod'];
                },
                'timezone' => function () {
                    return $this->getTimezone();
                },
                'direction' => function () {
                    return isset($this->filters['direction']) ? $this->filters['direction'] : null;
                },
                'selectedEntity' => function () {
                    return isset($this->filters['entity']) ? $this->filters['entity'] : null;
                },
                'selectedEntityType' => function () {
                    return isset($this->filters['entityType']) ? $this->filters['entityType'] : null;
                },
                'recordingUrl' => Inertia::lazy(
                    fn() =>
                    $this->getRecordingUrl($callUuid)
                ),
                'statusOptions' => function () {
                    return $this->getStatusOptions();
                },
                'entities' => Inertia::lazy(
                    fn() =>
                    $this->getEntities()
                ),
                // 'itemData' => Inertia::lazy(
                //     fn () =>
                //     $this->getItemData()
                // ),
                'routes' => [
                    'current_page' => route('cdrs.index'),
                    'export' => route('cdrs.export'),
                    'item_options' => route('cdrs.item.options'),
                ]

            ]
        );
    }

    public function getItemOptions()
    {
        try {

            // Get item data
            $item = $this->model::where($this->model->getKeyName(), request('item_uuid'))
                ->select([
                    'xml_cdr_uuid',
                    'domain_uuid',
                    'sip_call_id',
                    'extension_uuid',
                    'direction',
                    'caller_id_name',
                    'caller_id_number',
                    'caller_destination',
                    'start_epoch',
                    'answer_epoch',
                    'end_epoch',
                    'duration',
                    'billsec',
                    'waitsec',
                    'call_flow',
                    'voicemail_message',
                    'missed_call',
                    'hangup_cause',
                    'hangup_cause_q850',
                    'call_center_queue_uuid',
                    'cc_cancel_reason',
                    'cc_cause',
                    'sip_hangup_disposition',
                    'status',

                ])
                ->first();

            // logger($itemData);

            // If item doesn't exist throw and error 
            if (!$item) {
                throw new \Exception("Failed to fetch item details. Item not found");
            }

            $this->item_domain_uuid = $item->domain_uuid;

            // $callFlowData = collect(json_decode($item->call_flow, true));

            // Get the main call flow
            $mainCallFlowData = collect(json_decode($item->call_flow, true));

            // Initialize a collection to hold the combined call flow data
            $combinedCallFlowData = $mainCallFlowData;

            // Check if the call is a queue call (call_center_queue_uuid is not null)
            if (!empty($item->call_center_queue_uuid)) {
                // Fetch related queue calls and their call_flow if this is a queue call
                $relatedCalls = $item->relatedQueueCalls;

                // Loop through each related queue call and merge its call_flow into the combined call flow data
                foreach ($relatedCalls as $relatedCall) {
                    $relatedCallFlow = collect(json_decode($relatedCall->call_flow, true));
                    // Iterate through each flow step to insert the call_disposition
                    $relatedCallFlow = $relatedCallFlow->map(function ($flow) use ($relatedCall) {
                        // Ensure the 'times' array exists before adding call_disposition
                        if (isset($flow['times'])) {
                            $flow['times']['call_disposition'] = $relatedCall->call_disposition;
                        }
                        return $flow;
                    });

                    // logger($relatedCallFlow->toArray());
                    $combinedCallFlowData = $combinedCallFlowData->merge($relatedCallFlow);
                }
            }

            // Check if there are any other related calls 
            // Fetch related calls and their call_flow
            $relatedCalls = $item->relatedRingGroupCalls;

            // Loop through each related call and merge its call_flow into the combined call flow data
            foreach ($relatedCalls as $relatedCall) {
                $relatedCallFlow = collect(json_decode($relatedCall->call_flow, true));
                // Iterate through each flow step to insert the call_disposition
                $relatedCallFlow = $relatedCallFlow->map(function ($flow) use ($relatedCall) {
                    // Ensure the 'times' array exists before adding call_disposition
                    if (isset($flow['times'])) {
                        $flow['times']['call_disposition'] = $relatedCall->call_disposition;
                    }
                    return $flow;
                });

                // logger($relatedCallFlow->toArray());
                $combinedCallFlowData = $combinedCallFlowData->merge($relatedCallFlow);
            }

            // logger($combinedCallFlowData->toArray());

            // Add new rows for transfers
            $combinedCallFlowData = $this->handleCallFlowSteps($combinedCallFlowData);

            // Build the call flow summary
            $callFlowSummary = $combinedCallFlowData->map(function ($row) {
                return $this->buildSummaryItem($row);
            });

            // Sort the call flow summary by profile_created_time
            $callFlowSummary = $callFlowSummary->sortBy('profile_created_time')->values();

            // logger($callFlowSummary->toArray());

            //calculate the time line and format it
            $startEpoch = $item->start_epoch;
            $direction = $item->direction;
            $callFlowSummary = $callFlowSummary->map(function ($row) use ($startEpoch, $direction) {
                $timeDifference = $row['profile_created_time'] - $startEpoch;
                $row['time_line'] = sprintf('%02d:%02d', floor($timeDifference / 60), $timeDifference % 60); // Human-readable format
                if ($direction == "outbound") {
                    $row['dialplan_app'] = "Outbound Call";
                }
                return $row;
            });

            // Format times
            $callFlowSummary = $this->formatTimes($callFlowSummary);

            // logger($callFlowSummary->toArray());

            // Get Dialplan App details
            $callFlowSummary = $callFlowSummary->map(function ($row) {
                $row = $this->getAppDetails($row);

                return $row;
            });

            logger($callFlowSummary->toArray());

            $item->call_flow = $callFlowSummary;

            // logger($callFlowSummary->all());

            // Construct the itemOptions object
            $itemOptions = [
                'item' => $item,
            ];

            return $itemOptions;
        } catch (\Exception $e) {
            // Log the error message
            logger($e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            // report($e);

            // Handle any other exception that may occur
            return response()->json([
                'success' => false,
                'errors' => ['server' => ['Failed to fetch item details']]
            ], 500);  // 500 Internal Server Error for any other errors
        }
    }

    /**
     * Get app details associated with call flow step
     *
     */
    public function getAppDetails($row)
    {
        // Convert to E164 format if this is a valid number
        $destination = formatPhoneNumber($row['destination_number'], "US", 0); // 0 is E164 format

        // Check if the number starts with '+1' and remove it if present
        if (strpos($destination, '+1') === 0) {
            $bareNumber = substr($destination, 2);
        } else {
            $bareNumber = $destination;
        }

        $dialplan = Dialplans::where('dialplan_context', $row['context'])
            ->where(function ($query) use ($destination, $bareNumber) {
                $query->where('dialplan_number', $destination)
                    ->orWhere('dialplan_number', '=', $bareNumber)
                    ->orWhere('dialplan_number', '=', '1' . $bareNumber);
            })
            ->where('dialplan_enabled', 'true')
            ->select(
                'dialplan_uuid',
                'dialplan_name',
                'dialplan_number',
                'dialplan_xml',
                'dialplan_description',
            )
            ->first();

        if ($dialplan) {
            $patterns = [
                'ring_group_uuid' => [
                    'pattern' => '/ring_group_uuid=([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})/',
                    'app' => 'Ring Group',
                ],
                'ivr_menu_uuid' => [
                    'pattern' => '/ivr_menu_uuid=([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})/',
                    'app' => 'Auto Receptionist',
                ],
                'call_center_queue_uuid' => [
                    'pattern' => '/call_center_queue_uuid=([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})/',
                    'app' => 'Contact Center Queue',
                ],
                'call_direction_inbound' => [
                    'pattern' => '/call_direction=inbound/',
                    'app' => 'Inbound Call',
                ],
                'date_time' => [
                    'pattern' => '/\b(?:year|yday|mon|mday|week|mweek|wday|hour|minute|minute-of-day|time-of-day|date-time)=/',
                    'app' => 'Schedule',
                ],
                'application_rxfax' => [
                    'pattern' => '/application="rxfax"/',
                    'app' => 'Virtual Fax',
                ],
                'call_flow_uuid' => [
                    'pattern' => '/call_flow_uuid=([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})/',
                    'app' => 'Call Flow',
                ],
            ];

            foreach ($patterns as $key => $info) {
                if (preg_match($info['pattern'], $dialplan->dialplan_xml, $matches)) {
                    $row['dialplan_app'] = $info['app'];
                    $row['dialplan_name'] = $dialplan->dialplan_name;
                    $row['dialplan_description'] = $dialplan->dialplan_description;
                    break; // Stop checking after the first match
                }
            }

            return $row;
        }

        // Check if destination is Park
        if (strpos($row['destination_number'], "park+") !== false) {
            $row['dialplan_app'] = "Park";
            $row['dialplan_name'] = substr($row['destination_number'], 6);
            $row['dialplan_description'] = '';
            return $row;
        }

        // Check if destination is voicemail
        if ((substr($row['destination_number'], 0, 3) == '*99') !== false) {
            $row['dialplan_app'] = "Voicemail";
            $row['dialplan_name'] = substr($row['destination_number'], 3);
            $row['dialplan_description'] = '';
            return $row;
        }

        // Check if destination is extension
        $extension = Extensions::where('domain_uuid', $this->item_domain_uuid)
            ->where('extension', $row['destination_number'])
            ->first();

        if ($extension) {
            $row['dialplan_app'] = "Extension";
            $row['dialplan_name'] = $extension->effective_caller_id_name;
            $row['dialplan_description'] = $extension->description;
            return $row;
        }

        $row['dialplan_app'] = "Misc. Destination";
        $row['dialplan_name'] = $row['destination_number'];
        $row['dialplan_description'] = null;
        return $row;


    }

    /**
     * Handle transfers in the call flow array
     *
     * @param Collection $callFlowData
     * @return Collection
     */
    protected function handleCallFlowSteps($callFlowData)
    {
        $newRows = collect();

        $callFlowData->reduce(function ($carry, $row) use ($newRows) {

            // Check if 'ring_group_uuid' exists in the 'application' array
            if (isset($row['extension']['application'])) {
                foreach ($row['extension']['application'] as $application) {
                    if (isset($application['@attributes']['app_data']) && strpos($application['@attributes']['app_data'], 'ring_group_uuid') !== false) {
                        // Extract the ring_group_uuid value
                        preg_match('/ring_group_uuid=([a-f0-9\-]+)/', $application['@attributes']['app_data'], $matches);
                        if (isset($matches[1]) && $row['times']['bridged_time'] != '0') {

                            $newRow = [
                                'caller_profile' => [
                                    'destination_number' => $row['caller_profile']['destination_number'],
                                    'context' => !empty($row['caller_profile']['context']) ? $row['caller_profile']['context'] : '',
                                    'caller_id_name' => $row['caller_profile']['callee_id_name'],
                                    'caller_id_number' => $row['caller_profile']['caller_id_number'],
                                ],
                                'times' => [
                                    'bridged_time' => '0',
                                    'created_time' => $row['times']['profile_created_time'],
                                    'answered_time' => '0',
                                    'progress_time' => $row['times']['profile_created_time'],
                                    'transfer_time' => $row['times']['answered_time'],
                                    'progress_media_time' => $row['times']['profile_created_time'],
                                    'hangup_time' => 0,
                                    'profile_created_time' => $row['times']['profile_created_time'],
                                    'profile_end_time' => $row['times']['bridged_time'] != '0' ? $row['times']['bridged_time'] : $row['times']['profile_end_time']
                                ]
                            ];

                            // Insert the new row right before the current row
                            $newRows->push($newRow);

                            // Adjust created time for current row
                            $row['times']['profile_created_time'] = $row['times']['bridged_time'] != '0' ? $row['times']['bridged_time'] : $row['times']['transfer_time'];
                            $row['times']['progress_media_time'] = $row['times']['bridged_time'] != '0' ? $row['times']['bridged_time'] : $row['times']['transfer_time'];
                        } else {
                            $row['caller_profile']['callee_id_number'] = $row['caller_profile']['destination_number'];
                        }
                    }
                }
            }

            // Push the current row (updated or not) to the new collection
            $newRows->push($row);

            // Return the carry for reduce
            return $carry;
        }, $callFlowData);

        return $newRows;
    }



    /**
     * Format the times in the call flow array
     *
     * @param Collection $callFlowSummary
     * @return Collection
     */
    protected function formatTimes($callFlowSummary)
    {
        return $callFlowSummary->map(function ($item) {
            // Define the keys that need to be formatted
            $timeKeys = [
                'created_time',
                'answered_time',
                'progress_time',
                'bridged_time',
                'transfer_time',
                'profile_created_time',
                'profile_end_time',
                'progress_media_time',
                'hangup_time'
            ];

            // Loop through each key and format the time
            foreach ($timeKeys as $key) {
                if (isset($item[$key]) && $item[$key] != 0) {
                    $item[$key] = Carbon::createFromTimestamp($item[$key])->toDateTimeString();
                }
            }

            return $item;
        });
    }


    /**
     * Build a summary item for the call flow
     *
     * @param array $row
     * @return array
     */
    protected function buildSummaryItem(array $row): array
    {
        // $app = $this->findApp($row['caller_profile']['destination_number']);

        $profileCreatedEpoch = $this->formatTime($row['times']['profile_created_time']);
        $profileEndEpoch = $this->formatTime($row['times']['profile_end_time']);


        // logger($row);

        if (!empty($row["caller_profile"]["destination_number"]) && (substr($row["caller_profile"]["destination_number"], 0, 4) == 'park' || (substr($row["caller_profile"]["destination_number"], 0, 3) == '*59' && strlen($row["caller_profile"]["destination_number"]) > 3))) {
            if (strpos($row['caller_profile']['transfer_source'], "park+") !== false) {
                $destinationNumber = $row['caller_profile']['destination_number'];
            } else {
                $destinationNumber = $row['caller_profile']['callee_id_number'];
            }
        } else {
            $destinationNumber = !empty($row['caller_profile']['callee_id_number']) ? $row['caller_profile']['callee_id_number'] : $row['caller_profile']['destination_number'];
        }

        $durationInSeconds = $profileEndEpoch - $profileCreatedEpoch;
        $minutes = floor($durationInSeconds / 60);
        $seconds = $durationInSeconds % 60;

        if ($minutes > 0) {
            $durationFormatted = sprintf('%d min %02d s', $minutes, $seconds);
        } else {
            $durationFormatted = sprintf('%02d s', $seconds);
        }

        return [
            'destination_number' => $destinationNumber,
            // 'destination_number' => !empty($row['caller_profile']['callee_id_number']) ? $row['caller_profile']['callee_id_number'] : $row['caller_profile']['destination_number'],
            'context' => !empty($row['caller_profile']['context']) ? $row['caller_profile']['context'] : '',
            'bridged_time' => $row['times']['bridged_time'] == 0 ? 0 : $this->formatTime($row['times']['bridged_time']),
            'created_time' => $row['times']['created_time'] == 0 ? 0 : $this->formatTime($row['times']['created_time']),
            'answered_time' => $row['times']['answered_time'] == 0 ? 0 : $this->formatTime($row['times']['answered_time']),
            'progress_time' => $row['times']['progress_time'] == 0 ? 0 : $this->formatTime($row['times']['progress_time']),
            'transfer_time' => $row['times']['transfer_time'] == 0 ? 0 : $this->formatTime($row['times']['transfer_time']),
            'profile_created_time' => $row['times']['profile_created_time'] == 0 ? 0 : $this->formatTime($row['times']['profile_created_time']),
            'profile_end_time' => $row['times']['profile_end_time'] == 0 ? 0 : $this->formatTime($row['times']['profile_end_time']),
            'progress_media_time' => $row['times']['progress_media_time'] == 0 ? 0 : $this->formatTime($row['times']['progress_media_time']),
            'hangup_time' => $row['times']['hangup_time'] == 0 ? 0 : $this->formatTime($row['times']['hangup_time']),
            'duration_seconds' => $durationInSeconds,
            'duration_formatted' => $durationFormatted,
            'call_disposition' =>  isset($row['times']['call_disposition']) ? $row['times']['call_disposition'] : null,
        ];
    }

    private function formatTime($time)
    {
        return (int) round($time / 1000000);
    }


    public function getEntities()
    {
        $extensions = Extensions::where('domain_uuid', Session::get('domain_uuid'))
            ->selectRaw("
            extension_uuid as value, 
            CASE
                WHEN directory_first_name IS NOT NULL AND TRIM(directory_first_name) != '' 
                     AND directory_last_name IS NOT NULL AND TRIM(directory_last_name) != '' THEN CONCAT(directory_first_name, ' ', directory_last_name, ' - ', extension)
                WHEN directory_first_name IS NOT NULL AND TRIM(directory_first_name) != '' THEN CONCAT(directory_first_name, ' - ', extension)
                WHEN description IS NOT NULL AND TRIM(description) != '' THEN CONCAT(description, ' - ', extension)
                ELSE CONCAT(extension, ' - ', extension)
            END as name,
            'extension' as type
        ")
            ->get();


        $contactCenters = CallCenterQueues::where('domain_uuid', Session::get('domain_uuid'))
            ->select([
                'call_center_queue_uuid as value',
                'queue_name as name'
            ])
            ->selectRaw("'queue' as type")
            ->get();

        // Initialize an empty collection for entities
        $entities = collect();

        // Merge extensions into entities if extensions is not empty
        if (!$extensions->isEmpty()) {
            $entities = $entities->merge($extensions);
        }

        // Merge contactCenters into entities if contactCenters is not empty
        if (!$contactCenters->isEmpty()) {
            $entities = $entities->merge($contactCenters);
        }

        return $entities;
    }

    public function getStatusOptions()
    {
        return [
            [
                'name' => 'Answered',
                'value' => 'answered'
            ],
            [
                'name' => 'No Answer',
                'value' => 'no_answer'
            ],
            [
                'name' => 'Cancelled',
                'value' => 'cancelled'
            ],
            [
                'name' => 'Voicemail',
                'value' => 'voicemail'
            ],
            [
                'name' => 'Missed Call',
                'value' => 'missed call'
            ],
            [
                'name' => 'Abandoned',
                'value' => 'abandoned'
            ],
        ];
    }


    public function getRecordingUrl($callUuid)
    {
        try {
            $recording = CDR::where('xml_cdr_uuid', $callUuid)->select('xml_cdr_uuid', 'record_path', 'record_name')->firstOrFail();
            // You can use $call here
        } catch (ModelNotFoundException $e) {
            // Handle the case when the model is not found
            // For example, return a response or redirect
            return response()->json(['error' => 'Record not found'], 404);
        }

        //-----For local files------
        if ($recording->record_path != 'S3') {

            // $filePath = str_replace('/var/lib/freeswitch/recordings/', '', $recording->record_path . '/' . $recording->record_name);
            $filePath = $recording->record_path;
            $fileName = $recording->record_name;

            // Encrypt the file path
            $encryptedFilePath = encrypt($filePath);
            // Encrypt the file name
            $encryptedFileName = encrypt($fileName);

            // logger($encryptedFilePath);
            // logger($encryptedFileName);

            // Generate the URL
            $url = route('serve.recording', [
                'filePath' => $encryptedFilePath,
                'fileName' => $encryptedFileName,
            ]);

            if (isset($url)) return $url;
        }
        // -----End for local files----

        // -----For S3 files-----
        if ($recording->record_path == 'S3') {
            $setting = getS3Setting(Session::get('domain_uuid'));


            $disk = Storage::build([
                'driver' => 's3',
                'key' => $setting['key'],
                'secret' => $setting['secret'],
                'region' => $setting['region'],
                'bucket' => $setting['bucket'],
            ]);

            //Special case when recording name is empty. 
            if (empty($recording->record_name)) {
                // Check if archive recording is set
                if ($recording->archive_recording) {
                    $options = [
                        'ResponseContentDisposition' => 'attachment; filename="' . basename($recording->archive_recording->object_key) . '"'
                    ];
                    $url = $disk->temporaryUrl($recording->archive_recording->object_key, now()->addMinutes(10), $options);
                }
                if (isset($url)) return $url;
            }

            if (!empty($recording->record_name)) {
                $options = [
                    'ResponseContentDisposition' => 'attachment; filename="' . basename($recording->record_name) . '"'
                ];
                $url = $disk->temporaryUrl($recording->record_name, now()->addMinutes(10), $options);
                if (isset($url)) return $url;
            }

            // logger($url);
            if (isset($url)) return $url;
        }

        return null;
    }


    public function serveRecording($filePath, $fileName)
    {
        $filePath = decrypt($filePath); // Assuming the path is encrypted for security
        $fileName = decrypt($fileName); // Assuming the name is encrypted for security

        $disk = Storage::build([
            'driver' => 'local',
            'root' => $filePath,
        ]);

        if (!$disk->exists($fileName)) {
            return null;
        }

        // return response($fileContent, 200)->header('Content-Type', $mimeType);
        return response()->file($disk->path($fileName));
    }


    //Most of this function has been moved to CdrDataService service container
    public function getData()
    {
        $params['paginate'] = 50;
        $params['filterData'] = request()->filterData;
        $params['domain_uuid'] = session('domain_uuid');
        if (session('domains')) {
            $params['domains'] = session('domains')->pluck('domain_uuid');
        }
        $params['searchable'] = $this->searchable;

        if (!empty(request('filterData.dateRange'))) {
            $startPeriod = Carbon::parse(request('filterData.dateRange')[0])->setTimeZone('UTC');
            $endPeriod = Carbon::parse(request('filterData.dateRange')[1])->setTimeZone('UTC');
        } else {
            $startPeriod = Carbon::now($this->getTimezone())->startOfDay()->setTimeZone('UTC');
            $endPeriod = Carbon::now($this->getTimezone())->endOfDay()->setTimeZone('UTC');
        }

        $params['filterData']['startPeriod'] = $startPeriod;
        $params['filterData']['endPeriod'] = $endPeriod;
        $params['filterData']['sortField'] = request()->get('sortField', 'start_epoch');
        $params['filterData']['sortOrder'] = request()->get('sortField', 'desc');

        $params['permissions']['xml_cdr_lose_race'] = userCheckPermission('xml_cdr_lose_race');

        $this->filters = [
            'startPeriod' => $startPeriod,
            'endPeriod' => $endPeriod,
            'showGlobal' => request('filterData.showGlobal') ?? null,
            'direction' => request('filterData.direction') ?? null,
            'search' => request('filterData.search') ?? null,
            'entity' => request('filterData.entity') ?? null,
            'entityType' => request('filterData.entityType') ?? null
        ];

        if (!empty(request('filterData.statuses'))) {
            $statuses = request('filterData.statuses');

            $selectedStatuses = array_map(function ($status) {
                return $status['value'];
            }, array_filter($statuses, function ($status) {
                return isset($status['value']);
            }));
            $params['filterData']['selectedStatuses'] = $selectedStatuses;
        }

        return $this->cdrDataService->getData($params);
    }

    // This function has been moved to CdrDataService service container
    // public function builder($filters = [])
    // {
    // }

    protected function getTimezone()
    {

        if (!Cache::has(auth()->user()->user_uuid . '_' . Session::get('domain_uuid') . '_timeZone')) {
            $timezone = get_local_time_zone(Session::get('domain_uuid'));
            Cache::put(auth()->user()->user_uuid . Session::get('domain_uuid') .  '_timeZone', $timezone, 600);
        } else {
            $timezone = Cache::get(auth()->user()->user_uuid . '_' . Session::get('domain_uuid') . '_timeZone');
        }
        return $timezone;
    }


    /**
     * Get all items
     *
     * @return \Illuminate\Http\Response
     */
    public function export()
    {
        try {
            $params['paginate'] = false;
            $params['filterData'] = request()->filterData;
            $params['domain_uuid'] = session('domain_uuid');
            if (session('domains')) {
                $params['domains'] = session('domains')->pluck('domain_uuid');
            }
            $params['searchable'] = $this->searchable;

            if (!empty(request('filterData.dateRange'))) {
                $startPeriod = Carbon::parse(request('filterData.dateRange')[0])->setTimeZone('UTC');
                $endPeriod = Carbon::parse(request('filterData.dateRange')[1])->setTimeZone('UTC');
            } else {
                $startPeriod = Carbon::now($this->getTimezone())->startOfDay()->setTimeZone('UTC');
                $endPeriod = Carbon::now($this->getTimezone())->endOfDay()->setTimeZone('UTC');
            }

            $params['filterData']['startPeriod'] = $startPeriod;
            $params['filterData']['endPeriod'] = $endPeriod;
            $params['filterData']['sortField'] = request()->get('sortField', 'start_epoch');
            $params['filterData']['sortOrder'] = request()->get('sortField', 'desc');

            $params['permissions']['xml_cdr_lose_race'] = userCheckPermission('xml_cdr_lose_race');

            $params['user_email'] = auth()->user()->user_email;

            // $cdrs = $this->getData(false); // returns lazy collection

            ExportCdrs::dispatch($params, $this->cdrDataService);

            // Return a JSON response indicating success
            return response()->json([
                'messages' => ['success' => ['Report is being generated in the background. We\'ll email you a link when it\'s ready to download.']],
            ], 200);
        } catch (\Exception $e) {
            logger($e->getMessage());
            // Handle any other exception that may occur
            return response()->json([
                'success' => false,
                'errors' => ['server' => ['Failed to export items']]
            ], 500); // 500 Internal Server Error for any other errors
        }

        return response()->json([
            'success' => false,
            'errors' => ['server' => ['Failed to export']]
        ], 500); // 500 Internal Server Error for any other errors
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\CDR  $cDR
     * @return \Illuminate\Http\Response
     */
    public function show(CDR $cDR)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\CDR  $cDR
     * @return \Illuminate\Http\Response
     */
    public function edit(CDR $cDR)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\CDR  $cDR
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, CDR $cDR)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\CDR  $cDR
     * @return \Illuminate\Http\Response
     */
    public function destroy(CDR $cDR)
    {
        //
    }
}

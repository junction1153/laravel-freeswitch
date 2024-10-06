<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Support\Collection;
use Illuminate\Pagination\Paginator;
use App\Services\DeviceActionService;
use App\Services\FreeswitchEslService;
use Illuminate\Pagination\LengthAwarePaginator;

class RegistrationsController extends Controller
{

    public $filters = [];
    public $sortField;
    public $sortOrder;
    protected $viewName = 'Registrations';
    protected $searchable = ['lan_ip','wan_ip', 'port', 'agent', 'transport', 'sip_profile_name', 'sip_auth_user', 'sip_auth_realm'];

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(FreeswitchEslService $eslService)
    {

        return Inertia::render(
            $this->viewName,
            [
                'data' => function () use ($eslService) {
                    return $this->getData($eslService);
                },
                'showGlobal' => function () {
                    return request('filterData.showGlobal') === 'true';
                },

                'routes' => [
                    'current_page' => route('registrations.index'),
                    'select_all' => route('registrations.select.all'),
                    // 'bulk_delete' => route('messages.bulk.delete'),
                    // 'bulk_update' => route('messages.bulk.update'),
                    'action' => route('registrations.action'),
                ]
            ]
        );
    }


    /**
     *  Get data
     */
    public function getData(FreeswitchEslService $eslService, $paginate = 50)
    {
        // Check if search parameter is present and not empty
        if (!empty(request('filterData.search'))) {
            $this->filters['search'] = request('filterData.search');
        }

        // Check if showGlobal parameter is present and not empty
        if (!empty(request('filterData.showGlobal'))) {
            $this->filters['showGlobal'] = request('filterData.showGlobal') === 'true';
        } else {
            $this->filters['showGlobal'] = null;
        }

        $data = $this->builder($this->filters, $eslService);

        // Apply pagination manually
        if ($paginate) {
            $data = $this->paginateCollection($data, $paginate);
        }

        // logger($data);

        return $data;
    }

    /**
     * @param  array  $filters
     * @return Builder
     */
    public function builder(array $filters = [], FreeswitchEslService $eslService)
    {

        // get a list of current registrations
        $data = $eslService->getAllSipRegistrations();

        // Apply sorting using sortBy or sortByDesc depending on the sort order
        if ($this->sortOrder === 'asc') {
            $data = $data->sortBy($this->sortField);
        } else {
            $data = $data->sortByDesc($this->sortField);
        }

        // Check if showGlobal is set to true, otherwise filter by sip_auth_realm
        if (empty($filters['showGlobal']) || $filters['showGlobal'] !== true) {
            $domainName = session('domain_name');

            $data = $data->filter(function ($item) use ($domainName) {
                return $item['sip_auth_realm'] === $domainName;
            });
        }

        // Apply additional filters, if any
        if (is_array($filters)) {
            foreach ($filters as $field => $value) {
                if (method_exists($this, $method = "filter" . ucfirst($field))) {
                    // Pass the collection by reference to modify it directly
                    $data = $this->$method($data, $value);
                }
            }
        }

        // logger($data);

        return $data->values(); // Ensure re-indexing of the collection
    }

    /**
     * Paginate a given collection.
     *
     * @param \Illuminate\Support\Collection $items
     * @param int $perPage
     * @param int|null $page
     * @param array $options
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function paginateCollection($items, $perPage = 50, $page = null, $options = [])
    {
        $page = $page ?: (Paginator::resolveCurrentPage() ?: 1);
        $items = $items instanceof Collection ? $items : Collection::make($items);

        $paginator = new LengthAwarePaginator(
            $items->forPage($page, $perPage),
            $items->count(),
            $perPage,
            $page,
            $options
        );

        // Manually set the path to the current route with proper parameters
        $paginator->setPath(url()->current());

        return $paginator;
    }

    /**
     * @param $collection
     * @param $value
     * @return void
     */
    protected function filterSearch($collection, $value)
    {
        $searchable = $this->searchable;

        // Case-insensitive partial string search in the specified fields
        $collection = $collection->filter(function ($item) use ($value, $searchable) {
            foreach ($searchable as $field) {
                if (stripos($item[$field], $value) !== false) {
                    return true;
                }
            }
            return false;
        });

        return $collection;
    }


    public function handleAction(DeviceActionService $deviceActionService)
    {
        try {
            foreach (request('regs') as $reg) {
                $deviceActionService->handleDeviceAction($reg, request('action'));
            }

            // Return a JSON response indicating success
            return response()->json([
                'messages' => ['success' => ['Request has been succesfully processed']]
            ], 201);
        } catch (\Exception $e) {
            logger($e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            return response()->json([
                'success' => false,
                'errors' => ['server' => [$e->getMessage()]]
            ], 500); // 500 Internal Server Error for any other errors
        }
    }

    /**
     * Get all items
     *
     * @return \Illuminate\Http\Response
     */
    public function selectAll()
    {
        try {
            // Fetch all registrations without pagination
            $allRegistrations = $this->builder($this->filters);
    
            return response()->json([
                'messages' => ['success' => ['All items selected']],
                'items' => $allRegistrations,  // Returning full row instead of just call_id
            ], 200);
        } catch (\Exception $e) {
            logger($e->getMessage());
    
            return response()->json([
                'success' => false,
                'errors' => ['server' => ['Failed to select all items']]
            ], 500); // 500 Internal Server Error for any other errors
        }
    }

}

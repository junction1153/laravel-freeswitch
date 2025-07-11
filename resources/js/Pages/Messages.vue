<template>
    <MainLayout />

    <div class="m-3">
        <DataTable @search-action="handleSearchButtonClick" @reset-filters="handleFiltersReset">
            <template #title>Messages</template>

            <template #filters>
                <div class="relative min-w-64 focus-within:z-10 mb-2 sm:mr-4">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <MagnifyingGlassIcon class="h-5 w-5 text-gray-400" aria-hidden="true" />
                    </div>
                    <input type="text" v-model="filterData.search" name="mobile-search-candidate"
                        id="mobile-search-candidate"
                        class="block w-full rounded-md border-0 py-1.5 pl-10 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:hidden"
                        placeholder="Search" />
                    <input type="text" v-model="filterData.search" name="desktop-search-candidate"
                        id="desktop-search-candidate"
                        class="hidden w-full rounded-md border-0 py-1.5 pl-10 text-sm leading-6 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:block"
                        placeholder="Search" />
                </div>
            </template>

            <template #action>
                <button v-if="page.props.auth.can.device_create" type="button" @click.prevent="handleCreateButtonClick()"
                    class="rounded-md bg-indigo-600 px-2.5 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                    Create
                </button>

                <button v-if="!showGlobal && page.props.auth.can.device_view_global" type="button"
                    @click.prevent="handleShowGlobal()"
                    class="rounded-md bg-white px-2.5 py-1.5 ml-2 sm:ml-4 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                    Show global
                </button>

                <button v-if="showGlobal && page.props.auth.can.device_view_global" type="button"
                    @click.prevent="handleShowLocal()"
                    class="rounded-md bg-white px-2.5 py-1.5 ml-2 sm:ml-4 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                    Show local
                </button>
            </template>

            <template #navigation>
                <Paginator :previous="data.prev_page_url" :next="data.next_page_url" :from="data.from" :to="data.to"
                    :total="data.total" :currentPage="data.current_page" :lastPage="data.last_page" :links="data.links"
                    @pagination-change-page="renderRequestedPage" />
            </template>
            <template #table-header>
                <TableColumnHeader header="Date"
                    class="flex whitespace-nowrap px-4 py-1.5 text-left text-sm font-semibold text-gray-900 items-center justify-start">
                    <input type="checkbox" v-model="selectPageItems" @change="handleSelectPageItems"
                        class="h-4 w-4 rounded border-gray-300 text-indigo-600">
                    <BulkActionButton :actions="bulkActions" @bulk-action="handleBulkActionRequest"
                        :has-selected-items="selectedItems.length > 0" />
                    <span class="pl-4">Date</span>
                </TableColumnHeader>

                <TableColumnHeader v-if="showGlobal" header="Domain"
                    class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />


                <TableColumnHeader header="In/Out" class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />
                <TableColumnHeader header="Source" class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />
                <TableColumnHeader header="Destination" class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />
                <TableColumnHeader header="Message" class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />
                <TableColumnHeader header="Type" class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />
                <TableColumnHeader header="Status" class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />
                <TableColumnHeader header="Action" class="px-2 py-3.5 text-left text-sm font-semibold text-gray-900" />
            </template>

            <template v-if="selectPageItems" v-slot:current-selection>
                <td colspan="6">
                    <div class="text-sm text-center m-2">
                        <span class="font-semibold ">{{ selectedItems.length }} </span> items are selected.
                        <button v-if="!selectAll && selectedItems.length != data.total"
                            class="text-blue-500 rounded py-2 px-2 hover:bg-blue-200  hover:text-blue-500 focus:outline-none focus:ring-1 focus:bg-blue-200 focus:ring-blue-300 transition duration-500 ease-in-out"
                            @click="handleSelectAll">
                            Select all {{ data.total }} items
                        </button>
                        <button v-if="selectAll"
                            class="text-blue-500 rounded py-2 px-2 hover:bg-blue-200  hover:text-blue-500 focus:outline-none focus:ring-1 focus:bg-blue-200 focus:ring-blue-300 transition duration-500 ease-in-out"
                            @click="handleClearSelection">
                            Clear selection
                        </button>
                    </div>
                </td>
            </template>

            <template #table-body>
                <tr v-for="row in data.data" :key="row.message_uuid">
                    <TableField class="whitespace-nowrap px-4 py-2 text-sm text-gray-500 " :text="row.created_at_formatted">
                        <div class="flex items-center">
                            <input v-if="row.message_uuid" v-model="selectedItems" type="checkbox"
                                name="action_box[]" :value="row.message_uuid"
                                class="h-4 w-4 rounded border-gray-300 text-indigo-600">
                            <div class="ml-9">
                                {{ row.created_at_formatted ??  row.created_at}}
                            </div>

                        </div>
                    </TableField>

                    <TableField v-if="showGlobal" class="whitespace-nowrap px-2 py-2 text-sm text-gray-500"
                        :text="row.domain?.domain_description">
                        <ejs-tooltip :content="row.domain?.domain_name" position='TopLeft' target="#domain_tooltip_target">
                            <div id="domain_tooltip_target">
                                {{ row.domain?.domain_description }}
                            </div>
                        </ejs-tooltip>
                    </TableField>

                    <TableField class="whitespace-nowrap px-2 py-2 text-sm text-gray-500" :text="row.direction" />

                    <TableField class="whitespace-nowrap px-2 py-2 text-sm text-gray-500" :text="row.source_formatted ?? row.source" />

                    <TableField class="whitespace-nowrap px-2 py-2 text-sm text-gray-500"
                        :text="row.destination_formatted ?? row.destination" />

                    <TableField class=" px-2 py-2 text-sm text-gray-500" :text="row.message" />
                    <TableField class="whitespace-nowrap px-2 py-2 text-sm text-gray-500" :text="row.type" />
                    <TableField class="whitespace-nowrap px-2 py-2 text-sm text-gray-500" :text="row.status">
                        <Badge :text="row.status" :backgroundColor="determineColor(row.status).backgroundColor"
                            :textColor="determineColor(row.status).textColor"
                            :ringColor="determineColor(row.status).ringColor" />

                    </TableField>

                    <TableField class="whitespace-nowrap px-2 py-1 text-sm text-gray-500">
                        <template #action-buttons>
                            <div class="flex items-center whitespace-nowrap">
                                <!-- <ejs-tooltip v-if="page.props.auth.can.device_update" :content="'Edit'" position='TopCenter'
                                    target="#destination_tooltip_target">
                                    <div id="destination_tooltip_target">
                                        <PencilSquareIcon @click="handleEditRequest(row.device_uuid)"
                                            class="h-9 w-9 transition duration-500 ease-in-out py-2 rounded-full text-gray-400 hover:bg-gray-200 hover:text-gray-600 active:bg-gray-300 active:duration-150 cursor-pointer" />

                                    </div>
                                </ejs-tooltip> -->

                                <ejs-tooltip :content="'Retry'" position='TopCenter' target="#restart_tooltip_target">
                                    <div id="restart_tooltip_target">
                                        <RestartIcon @click="handleRetry(row.message_uuid)"
                                            class="h-9 w-9 transition duration-500 ease-in-out py-2 rounded-full text-gray-400 hover:bg-gray-200 hover:text-gray-600 active:bg-gray-300 active:duration-150 cursor-pointer" />
                                    </div>
                                </ejs-tooltip>

                                <!-- <ejs-tooltip v-if="page.props.auth.can.device_destroy" :content="'Delete'" position='TopCenter'
                                    target="#delete_tooltip_target">
                                    <div id="delete_tooltip_target">
                                        <TrashIcon @click="handleSingleItemDeleteRequest(row.destroy_route)"
                                            class="h-9 w-9 transition duration-500 ease-in-out py-2 rounded-full text-gray-400 hover:bg-gray-200 hover:text-gray-600 active:bg-gray-300 active:duration-150 cursor-pointer" />
                                    </div>
                                </ejs-tooltip> -->
                            </div>
                        </template>
                    </TableField>
                </tr>
            </template>
            <template #empty>
                <!-- Conditional rendering for 'no records' message -->
                <div v-if="data.data.length === 0" class="text-center my-5 ">
                    <MagnifyingGlassIcon class="mx-auto h-12 w-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-semibold text-gray-900">No results found</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Adjust your search and try again.
                    </p>
                </div>
            </template>

            <template #loading>
                <Loading :show="loading" />
            </template>

            <template #footer>
                <Paginator :previous="data.prev_page_url" :next="data.next_page_url" :from="data.from" :to="data.to"
                    :total="data.total" :currentPage="data.current_page" :lastPage="data.last_page" :links="data.links"
                    @pagination-change-page="renderRequestedPage" />
            </template>
        </DataTable>
        <div class="px-4 sm:px-6 lg:px-8"></div>
    </div>


    <NotificationSimple :show="restartRequestNotificationErrorTrigger" :isSuccess="false" :header="'Warning'"
        :text="'Please select at least one device'" @update:show="restartRequestNotificationErrorTrigger = false" />
    <NotificationSimple :show="restartRequestNotificationSuccessTrigger" :isSuccess="true" :header="'Success'"
        :text="'Restart request has been submitted'" @update:show="restartRequestNotificationSuccessTrigger = false" />


    <AddEditItemModal :show="createModalTrigger" :header="'Add New'" :loading="loadingModal" @close="handleModalClose">
        <template #modal-body>
            <CreateDeviceForm :options="itemOptions" :errors="formErrors" :is-submitting="createFormSubmiting"
                @submit="handleCreateRequest" @cancel="handleModalClose" />
        </template>
    </AddEditItemModal>

    <AddEditItemModal :show="editModalTrigger" :header="'Edit Device'" :loading="loadingModal" @close="handleModalClose">
        <template #modal-body>
            <UpdateDeviceForm :item="itemData" :options="itemOptions" :errors="formErrors"
                :is-submitting="updateFormSubmiting" @submit="handleUpdateRequest" @cancel="handleModalClose"
                @domain-selected="getItemOptions" />
        </template>
    </AddEditItemModal>

    <AddEditItemModal :show="bulkUpdateModalTrigger" :header="'Bulk Edit'" :loading="loadingModal"
        @close="handleModalClose">
        <template #modal-body>
            <BulkUpdateDeviceForm :items="selectedItems" :options="itemOptions" :errors="formErrors"
                :is-submitting="bulkUpdateFormSubmiting" @submit="handleBulkUpdateRequest" @cancel="handleModalClose"
                @domain-selected="getItemOptions" />
        </template>
    </AddEditItemModal>

    <DeleteConfirmationModal :show="confirmationModalTrigger" @close="confirmationModalTrigger = false"
        @confirm="confirmDeleteAction" />

    <ConfirmationModal :show="confirmationRetryTrigger" @close="confirmationRetryTrigger = false"
        @confirm="confirmRetryAction" :header="'Are you sure?'" :text="'Confirm resending selected messages.'"
        :confirm-button-label="'Retry'" cancel-button-label="Cancel" />

    <Notification :show="notificationShow" :type="notificationType" :messages="notificationMessages"
        @update:show="hideNotification" />
</template>

<script setup>
import { computed, onMounted, ref } from "vue";
import { usePage } from '@inertiajs/vue3'
import axios from 'axios';
import { router } from "@inertiajs/vue3";
import DataTable from "./components/general/DataTable.vue";
import TableColumnHeader from "./components/general/TableColumnHeader.vue";
import TableField from "./components/general/TableField.vue";
import Paginator from "./components/general/Paginator.vue";
import NotificationSimple from "./components/notifications/Simple.vue";
import AddEditItemModal from "./components/modal/AddEditItemModal.vue";
import DeleteConfirmationModal from "./components/modal/DeleteConfirmationModal.vue";
import ConfirmationModal from "./components/modal/ConfirmationModal.vue";
import Loading from "./components/general/Loading.vue";
import Badge from "./components/general/Badge.vue";
import { registerLicense } from '@syncfusion/ej2-base';
import { MagnifyingGlassIcon, } from "@heroicons/vue/24/solid";
import { TooltipComponent as EjsTooltip } from "@syncfusion/ej2-vue-popups";
import BulkUpdateDeviceForm from "./components/forms/BulkUpdateDeviceForm.vue";
import BulkActionButton from "./components/general/BulkActionButton.vue";
import MainLayout from "../Layouts/MainLayout.vue";
import RestartIcon from "./components/icons/RestartIcon.vue";
import CreateDeviceForm from "./components/forms/CreateDeviceForm.vue";
import UpdateDeviceForm from "./components/forms/UpdateDeviceForm.vue";
import Notification from "./components/notifications/Notification.vue";

const page = usePage()
const loading = ref(false)
const loadingModal = ref(false)
const selectAll = ref(false);
const selectedItems = ref([]);
const selectPageItems = ref(false);
const restartRequestNotificationSuccessTrigger = ref(false);
const restartRequestNotificationErrorTrigger = ref(false);
const createModalTrigger = ref(false);
const editModalTrigger = ref(false);
const bulkUpdateModalTrigger = ref(false);
const confirmationModalTrigger = ref(false);
const confirmationRetryTrigger = ref(false);
const confirmationModalDestroyPath = ref(null);
const createFormSubmiting = ref(null);
const updateFormSubmiting = ref(null);
const confirmDeleteAction = ref(null);
const confirmRetryAction = ref(null);
const bulkUpdateFormSubmiting = ref(null);
const formErrors = ref(null);
const notificationType = ref(null);
const notificationMessages = ref(null);
const notificationShow = ref(null);
let tooltipCopyContent = ref('Copy to Clipboard');

const props = defineProps({
    data: Object,
    showGlobal: Boolean,
    routes: Object,
});


const filterData = ref({
    search: null,
    showGlobal: props.showGlobal,
});

const showGlobal = ref(props.showGlobal);

// Computed property for bulk actions based on permissions
const bulkActions = computed(() => {
    const actions = [
        {
            id: 'bulk_retry',
            label: 'Retry',
            icon: 'RestartIcon'
        },
        {
            id: 'bulk_delete',
            label: 'Delete',
            icon: 'TrashIcon'
        }
    ];

    return actions;
});

onMounted(() => {
    // console.log(props.data);
});

const handleEditRequest = (itemUuid) => {
    editModalTrigger.value = true
    formErrors.value = null;
    loadingModal.value = true

    router.get(props.routes.current_page,
        {
            itemUuid: itemUuid,
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: [
                'itemData',
                'itemOptions',
            ],
            onSuccess: (page) => {
                loadingModal.value = false;
            },
            onFinish: () => {
                loadingModal.value = false;
            },
            onError: (errors) => {
                console.log(errors);
            },

        });
}

const handleCreateRequest = (form) => {
    createFormSubmiting.value = true;
    formErrors.value = null;

    axios.post(props.routes.store, form)
        .then((response) => {
            createFormSubmiting.value = false;
            showNotification('success', response.data.messages);
            handleSearchButtonClick();
            handleModalClose();
            handleClearSelection();
        }).catch((error) => {
            createFormSubmiting.value = false;
            handleClearSelection();
            handleFormErrorResponse(error);
        });

};

const handleUpdateRequest = (form) => {
    updateFormSubmiting.value = true;
    formErrors.value = null;

    axios.put(props.itemData.update_url, form)
        .then((response) => {
            updateFormSubmiting.value = false;
            showNotification('success', response.data.messages);
            handleSearchButtonClick();
            handleModalClose();
            handleClearSelection();
        }).catch((error) => {
            updateFormSubmiting.value = false;
            handleClearSelection();
            handleFormErrorResponse(error);
        });

};

const handleSingleItemDeleteRequest = (url) => {
    confirmationModalTrigger.value = true;
    confirmDeleteAction.value = () => executeSingleDelete(url);
}

const executeSingleDelete = (url) => {
    router.delete(url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            if (page.props.flash.error) {
                showNotification('error', page.props.flash.error);
            }
            if (page.props.flash.message) {
                showNotification('success', page.props.flash.message);
            }
            confirmationModalTrigger.value = false;
            confirmationModalDestroyPath.value = null;
        },
        onFinish: () => {
            confirmationModalTrigger.value = false;
            confirmationModalDestroyPath.value = null;
        },
        onError: (errors) => {
            console.log(errors);
        },
    });
}

const handleBulkActionRequest = (action) => {
    if (action === 'bulk_delete') {
        confirmationModalTrigger.value = true;
        confirmDeleteAction.value = () => executeBulkDelete();
    }

    if (action === 'bulk_retry') {
        confirmationRetryTrigger.value = true;
        confirmRetryAction.value = () => executeBulkRetry();
    }
}

const executeBulkRetry = () => {
    axios.post(props.routes.retry,
        { 'items': selectedItems.value },
    )
        .then((response) => {
            showNotification('success', response.data.messages);
            handleModalClose();
            handleClearSelection();
        }).catch((error) => {
            handleClearSelection();
            handleModalClose();
            handleFormErrorResponse(error);
        });
}

const executeBulkDelete = () => {
    axios.post(`${props.routes.bulk_delete}`, { items: selectedItems.value })
        .then((response) => {
            handleModalClose();
            showNotification('success', response.data.messages);
            handleSearchButtonClick();
        })
        .catch((error) => {
            handleClearSelection();
            handleModalClose();
            handleErrorResponse(error);
        });
}

const handleBulkUpdateRequest = (form) => {
    bulkUpdateFormSubmiting.value = true
    axios.post(`${props.routes.bulk_update}`, form)
        .then((response) => {
            bulkUpdateFormSubmiting.value = false;
            handleModalClose();
            showNotification('success', response.data.messages);
            handleSearchButtonClick();
        })
        .catch((error) => {
            bulkUpdateFormSubmiting.value = false;
            handleFormErrorResponse(error);
        });
}

const handleCreateButtonClick = () => {
    createModalTrigger.value = true
    formErrors.value = null;
    loadingModal.value = true
    getItemOptions();
}

const handleSelectAll = () => {
    axios.post(props.routes.select_all, filterData._rawValue)
        .then((response) => {
            selectedItems.value = response.data.items;
            selectAll.value = true;
            showNotification('success', response.data.messages);

        }).catch((error) => {
            handleClearSelection();
            handleErrorResponse(error);
        });

};


const handleRetry = (message_uuid) => {
    axios.post(props.routes.retry,
        { 'items': [message_uuid] },
    )
        .then((response) => {
            showNotification('success', response.data.messages);

            handleClearSelection();
        }).catch((error) => {
            handleClearSelection();
            handleFormErrorResponse(error);
        });
}


const handleShowGlobal = () => {
    filterData.value.showGlobal = true;
    showGlobal.value = true;
    handleSearchButtonClick();
}

const handleShowLocal = () => {
    filterData.value.showGlobal = false;
    showGlobal.value = false;
    handleSearchButtonClick();
}

const handleSearchButtonClick = () => {
    loading.value = true;
    router.visit(props.routes.current_page, {
        data: {
            filterData: filterData._rawValue,
        },
        preserveScroll: true,
        preserveState: true,
        only: [
            "data",
            'showGlobal',
        ],
        onSuccess: (page) => {
            loading.value = false;
            handleClearSelection();
        }
    });
};

const handleFiltersReset = () => {
    filterData.value.search = null;
    // After resetting the filters, call handleSearchButtonClick to perform the search with the updated filters
    handleSearchButtonClick();
}


const renderRequestedPage = (url) => {
    loading.value = true;
    router.visit(url, {
        data: {
            filterData: filterData._rawValue,
        },
        preserveScroll: true,
        preserveState: true,
        only: ["data"],
        onSuccess: (page) => {
            loading.value = false;
        }
    });
};


const getItemOptions = (domain_uuid) => {
    router.get(props.routes.current_page,
        {
            'domain_uuid': domain_uuid,
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: [
                'itemOptions',
            ],
            onSuccess: (page) => {
                loadingModal.value = false;
            },
            onFinish: () => {
                loadingModal.value = false;
            },
            onError: (errors) => {
                console.log(errors);
            },

        });
}

const handleFormErrorResponse = (error) => {
    if (error.request?.status == 419) {
        showNotification('error', { request: ["Session expired. Reload the page"] });
    } else if (error.response) {
        // The request was made and the server responded with a status code
        // that falls out of the range of 2xx
        // console.log(error.response.data);
        showNotification('error', error.response.data.errors || { request: [error.message] });
        formErrors.value = error.response.data.errors;
    } else if (error.request) {
        // The request was made but no response was received
        // `error.request` is an instance of XMLHttpRequest in the browser and an instance of
        // http.ClientRequest in node.js
        showNotification('error', { request: [error.request] });
        console.log(error.request);
    } else {
        // Something happened in setting up the request that triggered an Error
        showNotification('error', { request: [error.message] });
        console.log(error.message);
    }

}

const handleErrorResponse = (error) => {
    if (error.response) {
        // The request was made and the server responded with a status code
        // that falls out of the range of 2xx
        // console.log(error.response.data);
        showNotification('error', error.response.data.errors || { request: [error.message] });
    } else if (error.request) {
        // The request was made but no response was received
        // `error.request` is an instance of XMLHttpRequest in the browser and an instance of
        // http.ClientRequest in node.js
        showNotification('error', { request: [error.request] });
        console.log(error.request);
    } else {
        // Something happened in setting up the request that triggered an Error
        showNotification('error', { request: [error.message] });
        console.log(error.message);
    }
}

const handleSelectPageItems = () => {
    if (selectPageItems.value) {
        selectedItems.value = props.data.data.map(item => item.device_uuid);
    } else {
        selectedItems.value = [];
    }
};



const handleClearSelection = () => {
    selectedItems.value = [],
        selectPageItems.value = false;
    selectAll.value = false;
}

const handleModalClose = () => {
    createModalTrigger.value = false;
    editModalTrigger.value = false;
    confirmationModalTrigger.value = false;
    confirmationRetryTrigger.value = false;
    bulkUpdateModalTrigger.value = false;
}

const hideNotification = () => {
    notificationShow.value = false;
    notificationType.value = null;
    notificationMessages.value = null;
}

const showNotification = (type, messages = null) => {
    notificationType.value = type;
    notificationMessages.value = messages;
    notificationShow.value = true;
}

const determineColor = (status) => {
  switch (status) {
    case 'success':
    case 'emailed':
    case 'delivered':
      return {
        backgroundColor: 'bg-green-50',
        textColor: 'text-green-700',
        ringColor: 'ring-green-600/20'
      };
    case 'queued':
      return {
        backgroundColor: 'bg-blue-50',
        textColor: 'text-blue-700',
        ringColor: 'ring-blue-600/20'
      };
    default:
      return {
        backgroundColor: 'bg-yellow-50',
        textColor: 'text-yellow-700',
        ringColor: 'ring-yellow-600/20'
      };
  }
};

registerLicense('Ngo9BigBOggjHTQxAR8/V1NAaF5cWWdCf1FpRmJGdld5fUVHYVZUTXxaS00DNHVRdkdnWX5eeHVSQ2hYUkB3WEI=');

</script>

<style>
@import "@syncfusion/ej2-base/styles/tailwind.css";
@import "@syncfusion/ej2-vue-popups/styles/tailwind.css";
</style>

<template>
    <TransitionRoot as="div" :show="show">
        <Dialog as="div" class="relative z-10">
            <TransitionChild as="div" enter="ease-out duration-300" enter-from="opacity-0" enter-to="opacity-100"
                leave="ease-in duration-200" leave-from="opacity-100" leave-to="opacity-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" />
            </TransitionChild>
            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <TransitionChild as="template" enter="ease-out duration-300"
                        enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to="opacity-100 translate-y-0 sm:scale-100" leave="ease-in duration-200"
                        leave-from="opacity-100 translate-y-0 sm:scale-100"
                        leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">

                        <DialogPanel
                            class="relative transform  rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-5xl sm:p-6">

                            <DialogTitle as="h3" class="mb-4 pr-8 text-base font-semibold leading-6 text-gray-900">
                                {{ header }}
                            </DialogTitle>

                            <div class="absolute right-0 top-0 pr-4 pt-4 sm:block">
                                <button type="button"
                                    class="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                    @click="emit('close')">
                                    <span class="sr-only">Close</span>
                                    <XMarkIcon class="h-6 w-6" aria-hidden="true" />
                                </button>
                            </div>

                            <div v-if="loading" class="w-full h-full">
                                <div class="flex justify-center items-center space-x-3">
                                    <div>
                                        <svg class="animate-spin  h-10 w-10 text-blue-600"
                                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                stroke-width="4">
                                            </circle>
                                            <path class="opacity-75" fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                            </path>
                                        </svg>
                                    </div>
                                    <div class="text-lg text-blue-600 m-auto">Loading...</div>
                                </div>
                            </div>


                            <Vueform v-if="!loading" ref="form$" :endpoint="submitForm" @success="handleSuccess"
                                @error="handleError" @response="handleResponse" :display-errors="false" :default="{
                                    device_address: options.item?.device_address ?? null,
                                    device_template: options.item?.device_template ?? null,
                                    device_profile_uuid: options.item?.device_profile_uuid,
                                    domain_uuid: options.item?.domain_uuid,
                                    device_keys: options.lines,
                                    device_description: options.item?.device_description ?? null,
                                }">

                                <template #empty>

                                    <div class="lg:grid lg:grid-cols-12 lg:gap-x-5">
                                        <div class="px-2 py-6 sm:px-6 lg:col-span-3 lg:px-0 lg:py-0">
                                            <FormTabs view="vertical" @select="handleTabSelected">
                                                <FormTab name="page0" label="Device Settings" :elements="[
                                                    'h4',
                                                    'device_address',
                                                    'device_template',
                                                    'device_profile_uuid',
                                                    'domain_uuid',
                                                    'device_description',
                                                    'container_3',
                                                    'submit',

                                                ]" />
                                                <FormTab name="page1" label="Keys" :elements="[
                                                    'password_reset',
                                                    'security_title',
                                                    'keys_container',
                                                    'keys_title',
                                                    'assign_existing',
                                                    'add_key',
                                                    'device_keys',
                                                    'advanced',
                                                    'keys_container2',
                                                    'submit_keys',

                                                ]" />

                                            </FormTabs>
                                        </div>

                                        <div
                                            class="sm:px-6 lg:col-span-9 shadow sm:rounded-md space-y-6 text-gray-600 bg-gray-50 px-4 py-6 sm:p-6">
                                            <FormElements>

                                                <StaticElement name="h4" tag="h4" content="Device Settings" />

                                                <TextElement name="device_address" label="MAC Address"
                                                    placeholder="Enter MAC address" :floating="false" />

                                                <SelectElement name="device_template" :items="options.templates"
                                                    :search="true" :native="false" label="Device Template"
                                                    input-type="search" autocomplete="off" label-prop="name"
                                                    value-prop="value" :floating="false"
                                                    placeholder="Select Template" />

                                                <SelectElement name="device_profile_uuid" :items="options.profiles"
                                                    :search="true" :native="false" label="Device Profile"
                                                    input-type="search" autocomplete="off" label-prop="name"
                                                    value-prop="value" placeholder="Select Profile (Optional)"
                                                    :floating="false" />

                                                <TextElement name="device_description" label="Description"
                                                    placeholder="Enter description" :floating="false" />


                                                <GroupElement name="container_3" />

                                                <ButtonElement name="submit" button-label="Save" :submits="true"
                                                    align="right" />


                                                <!-- Lines tab-->
                                                <StaticElement name="keys_title" tag="h4" content="Device Keys"
                                                    description="Assign functions to the device keys." />


                                                <GroupElement name="keys_container" />

                                                <ListElement name="device_keys" :sort="true" size="sm"
                                                    store-order="line_number"
                                                    :controls="{ add: options.permissions.device_key_create, remove: options.permissions.destination_delete, sort: options.permissions.destination_update }"
                                                    :add-classes="{ ListElement: { listItem: 'bg-white p-4 mb-4 rounded-lg shadow-md' } }">
                                                    <template #default="{ index }">
                                                        <ObjectElement :name="index">
                                                            <HiddenElement name="device_line_uuid" :meta="true" />
                                                            <HiddenElement name="server_address" :meta="true"
                                                                :default="options.default_line_options?.server_address" />
                                                            <HiddenElement name="server_address_primary" :meta="true"
                                                                :default="options.default_line_options?.server_address_primary" />
                                                            <HiddenElement name="server_address_secondary" :meta="true"
                                                                :default="options.default_line_options?.server_address_secondary" />
                                                            <HiddenElement name="sip_port" :meta="true"
                                                                :default="options.default_line_options?.sip_port" />
                                                            <HiddenElement name="sip_transport" :meta="true"
                                                                :default="options.default_line_options?.sip_transport" />
                                                            <HiddenElement name="register_expires" :meta="true"
                                                                :default="options.default_line_options?.register_expires" />
                                                            <HiddenElement name="user_id" :meta="true"
                                                                :default="null" />
                                                            <HiddenElement name="shared_line" :meta="true"
                                                                :default="null" />


                                                            <TextElement name="line_number" label="Key" :rules="[
                                                                'nullable',
                                                                'numeric',
                                                            ]" autocomplete="off" :columns="{

                                                                sm: {
                                                                    container: 1,
                                                                },
                                                            }" />

                                                            <SelectElement name="line_type_id" label="Function"
                                                                :items="options.line_key_types" :search="true"
                                                                label-prop="name" :native="false" input-type="search"
                                                                autocomplete="off" :columns="{

                                                                    sm: {
                                                                        container: 3,
                                                                    },
                                                                }" placeholder="Choose Function" :floating="false"
                                                                @change="(newValue, oldValue, el$) => {

                                                                    if (newValue == 'sharedline') {
                                                                        el$.form$.el$('device_keys').children$[index].children$['shared_line'].update('1');
                                                                    } else {
                                                                        el$.form$.el$('device_keys').children$[index].children$['shared_line'].update(null);
                                                                    }


                                                                }" />

                                                            <SelectElement name="auth_id" label="Ext/Number"
                                                                :items="options.extensions" label-prop="name"
                                                                :search="true" :native="false" input-type="search"
                                                                autocomplete="off" :columns="{

                                                                    sm: {
                                                                        container: 4,
                                                                    },
                                                                }" placeholder="Choose Ext/Number" :floating="false"
                                                                @change="(newValue, oldValue, el$) => {

                                                                    el$.form$.el$('device_keys').children$[index].children$['display_name'].update(newValue);
                                                                    el$.form$.el$('device_keys').children$[index].children$['user_id'].update(newValue);


                                                                }" />

                                                            <TextElement name="display_name" label="Display Name"
                                                                :columns="{

                                                                    default: {
                                                                        container: 10,
                                                                    },
                                                                    sm: {
                                                                        container: 3,
                                                                    },
                                                                }" placeholder="Display Name" :floating="false" />

                                                            <StaticElement label="&nbsp;" name="key_advanced" :columns="{

                                                                default: {
                                                                    container: 1,
                                                                },
                                                                sm: {
                                                                    container: 1,
                                                                },
                                                            }">
                                                                <!-- <div
                                                                    class="text-sm font-medium leading-6 text-gray-900 text-end">

                                                                    <Menu as="div"
                                                                        class="relative inline-block text-left">
                                                                        <div>
                                                                            <MenuButton
                                                                                class="flex items-center rounded-full bg-gray-100 text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-gray-100">
                                                                                <span class="sr-only">Open
                                                                                    options</span>
                                                                                <EllipsisVerticalIcon
                                                                                    class="h-9 w-9 transition duration-500 ease-in-out py-2 rounded-full text-gray-500 hover:bg-gray-200 hover:text-gray-900 active:bg-gray-300 active:duration-150 cursor-pointer"
                                                                                    aria-hidden="true" />
                                                                            </MenuButton>
                                                                        </div>

                                                                        <transition
                                                                            enter-active-class="transition ease-out duration-100"
                                                                            enter-from-class="transform opacity-0 scale-95"
                                                                            enter-to-class="transform opacity-100 scale-100"
                                                                            leave-active-class="transition ease-in duration-75"
                                                                            leave-from-class="transform opacity-100 scale-100"
                                                                            leave-to-class="transform opacity-0 scale-95">
                                                                            <MenuItems
                                                                                class="absolute right-0 z-10 mt-2 w-36 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none">
                                                                                <div class="py-1">
                                                                                    <MenuItem v-slot="{ active }">
                                                                                    <a href="#"
                                                                                        @click.prevent="showLineAdvSettings(index)"
                                                                                        :class="[active ? 'bg-gray-100 text-gray-900' : 'text-gray-700', 'block px-4 py-2 text-sm']">Advanced</a>
                                                                                    </MenuItem>
                                                                                    <MenuItem v-slot="{ active }">
                                                                                    <a href="#"
                                                                                        @click.prevent="deleteLineKey(index)"
                                                                                        :class="[active ? 'bg-gray-100 text-gray-900' : 'text-gray-700', 'block px-4 py-2 text-sm']">Delete</a>
                                                                                    </MenuItem>

                                                                                </div>
                                                                            </MenuItems>
                                                                        </transition>
                                                                    </Menu>

                                                                </div> -->

                                                                <Cog8ToothIcon @click="showLineAdvSettings(index)"
                                                                    class="h-9 w-9 transition duration-500 ease-in-out py-2 rounded-full text-gray-400 hover:bg-gray-200 hover:text-gray-600 active:bg-gray-300 active:duration-150 cursor-pointer" />

                                                            </StaticElement>

                                                            <FormChildModal :show="advModalIndex === index"
                                                                header="Advanced Line Settings" :loading="false"
                                                                @close="closeAdvSettings">
                                                                <div class="px-5 grid gap-y-4">
                                                                    <TextElement name="server_address" label="Domain"
                                                                        placeholder="Enter domain name"
                                                                        :floating="false"
                                                                        :default="options.default_line_options?.server_address" />

                                                                    <TextElement name="server_address_primary"
                                                                        label="Primary Server Address"
                                                                        placeholder="Enter primary server address"
                                                                        :floating="false"
                                                                        :default="options.default_line_options?.server_address_primary" />

                                                                    <TextElement name="server_address_secondary"
                                                                        label="Secondary Server Address"
                                                                        placeholder="Enter secondary server address"
                                                                        :floating="false" />

                                                                    <TextElement name="sip_port" label="SIP Port"
                                                                        placeholder="Enter SIP port" :floating="false"
                                                                        :default="options.default_line_options?.sip_port" />

                                                                    <SelectElement name="sip_transport"
                                                                        label="SIP Transport"
                                                                        :items="options.sip_transport_types"
                                                                        :search="true" label-prop="name" :native="false"
                                                                        input-type="search" autocomplete="off"
                                                                        placeholder="Select SIP Transport"
                                                                        :floating="false"
                                                                        :default="options.default_line_options?.sip_transport" />

                                                                    <TextElement name="register_expires"
                                                                        label="Register Expires (Seconds)"
                                                                        placeholder="Enter expiry time (seconds)"
                                                                        :floating="false"
                                                                        :default="options.default_line_options?.register_expires" />

                                                                    <ButtonElement name="close_advanced"
                                                                        button-label="Close" align="center" :full="true"
                                                                        @click="closeAdvSettings" />
                                                                </div>
                                                            </FormChildModal>


                                                        </ObjectElement>
                                                    </template>
                                                </ListElement>

                                                <!-- <ButtonElement name="add_key" button-label="Add Key" align="right"
                                                    @click="handleAddKeyButtonClick" :loading="isModalLoading"
                                                    :conditions="[() => options.permissions.device_key_create]"/>


                                                <StaticElement name="key_table">
                                                    <DeviceKeys :keys="options.lines" :loading="isKeysLoading"
                                                        :permissions="options.permissions"
                                                        @edit-item="handleKeyEditButtonClick"
                                                        @delete-item="handleDeleteKeyButtonClick" />
                                                </StaticElement> -->

                                                <GroupElement name="keys_container2" />

                                                <ButtonElement name="submit_keys" button-label="Save" :submits="true"
                                                    align="right" />

                                            </FormElements>
                                        </div>
                                    </div>
                                </template>
                            </Vueform>
                        </DialogPanel>


                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<script setup>
import { ref } from "vue";
import { usePage } from '@inertiajs/vue3';

import { Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue'

import { XMarkIcon } from "@heroicons/vue/24/solid";
import { Cog8ToothIcon } from "@heroicons/vue/24/outline";
import FormChildModal from "../FormChildModal.vue"


const props = defineProps({
    show: Boolean,
    options: Object,
    header: String,
    loading: Boolean,
});

const advModalIndex = ref(null)


const emit = defineEmits(['close', 'error', 'success', 'refresh-data'])


function showLineAdvSettings(index) {
    advModalIndex.value = index
}

function closeAdvSettings() {
    advModalIndex.value = null
}

const handleTabSelected = (activeTab, previousTab) => {

}

const submitForm = async (FormData, form$) => {
    // Using form$.requestData will EXCLUDE conditional elements and it 
    // will submit the form as Content-Type: application/json . 
    const requestData = form$.requestData
    // console.log(requestData);

    // Using form$.data will INCLUDE conditional elements and it
    // will submit the form as "Content-Type: application/json".
    // const data = form$.data

    return await form$.$vueform.services.axios.post(props.options.routes.store_route, requestData)
};


function clearErrorsRecursive(el$) {
    // clear this element’s errors
    el$.messageBag?.clear()

    // if it has child elements, recurse into each
    if (el$.children$) {
        Object.values(el$.children$).forEach(childEl$ => {
            clearErrorsRecursive(childEl$)
        })
    }
}

const handleResponse = (response, form$) => {
    // Clear form including nested elements 
    Object.values(form$.elements$).forEach(el$ => {
        clearErrorsRecursive(el$)
    })

    // Display custom errors for elements
    if (response.data.errors) {
        Object.keys(response.data.errors).forEach((elName) => {
            if (form$.el$(elName)) {
                form$.el$(elName).messageBag.append(response.data.errors[elName][0])
            }
        })
    }
}

const handleSuccess = (response, form$) => {
    // console.log(response) // axios response
    // console.log(response.status) // HTTP status code
    // console.log(response.data) // response data

    emit('success', 'success', response.data.messages);
    emit('close');
    emit('refresh-data');
}

const handleError = (error, details, form$) => {
    form$.messageBag.clear() // clear message bag

    switch (details.type) {
        // Error occured while preparing elements (no submit happened)
        case 'prepare':
            console.log(error) // Error object

            form$.messageBag.append('Could not prepare form')
            break

        // Error occured because response status is outside of 2xx
        case 'submit':
            emit('error', error);
            console.log(error) // AxiosError object
            // console.log(error.response) // axios response
            // console.log(error.response.status) // HTTP status code
            // console.log(error.response.data) // response data

            // console.log(error.response.data.errors)


            break

        // Request cancelled (no response object)
        case 'cancel':
            console.log(error) // Error object

            form$.messageBag.append('Request cancelled')
            break

        // Some other errors happened (no response object)
        case 'other':
            console.log(error) // Error object

            form$.messageBag.append('Couldn\'t submit form')
            break
    }
}

</script>

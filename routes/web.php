<?php

use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AppsController;
use App\Http\Controllers\FaxesController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\RoutingController;
use App\Http\Controllers\FaxQueueController;
use App\Http\Controllers\MessagesController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UserGroupController;
use App\Http\Controllers\VoicemailController;
use App\Http\Controllers\EmailQueueController;
use App\Http\Controllers\ExtensionsController;
use App\Http\Controllers\PolycomLogController;
use App\Http\Controllers\SmsWebhookController;
use App\Http\Controllers\UserSettingsController;
use App\Http\Controllers\VoicemailMessagesController;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/extensions/callerid', [ExtensionsController::class, 'callerID'])->withoutMiddleware(['auth','web']) ->name('callerID');
Route::post('/extensions/{extension}/callerid/update/', [ExtensionsController::class, 'updateCallerID'])->withoutMiddleware(['auth','web']) ->name('updateCallerID');

// STIR SHAKEN
Route::post('/hello', function () {
    Log::alert("STIR SHAKEN");
    return response('Hello World', 200)
    ->header('Content-Type', 'text/plain');
})->withoutMiddleware(['auth','web']);

//Polycom log handling
Route::put('/polycom/log/{name}', [PolycomLogController::class, 'store'])->withoutMiddleware(['auth','web']) ->name('log.store');
Route::get('/polycom/log/{name}', [PolycomLogController::class, 'show'])->withoutMiddleware(['auth','web']) ->name('log.get');
// Route::get('/extensions', [ExtensionsController::class, 'index']) ->name('extensionsList');

// Extensions
Route::resource('extensions', 'ExtensionsController');
Route::post('/extensions/import',[ExtensionsController::class, 'import']) ->name('extensions.import');
Route::post('/extensions/{extension}/assign-device', [ExtensionsController::class, 'assignDevice'])->name('extensions.assign-device');
Route::delete('/extensions/{extension}/unassign/{deviceLine}/device', [ExtensionsController::class, 'unAssignDevice'])->name('extensions.unassign-device');
Route::delete('/extensions/{extension}/callforward/{type}', [ExtensionsController::class, 'clearCallforwardDestination'])->name('extensions.clear-callforward-destination');


// Groups
Route::resource('groups', 'GroupsController');

//Fax
Route::resource('faxes', 'FaxesController');
Route::get('/faxes/new/{fax}', [FaxesController::class, 'new']) ->name('faxes.new');
Route::get('/faxes/inbox/{id}', [FaxesController::class, 'inbox']) ->name('faxes.inbox.list');
Route::get('/faxes/sent/{id}', [FaxesController::class, 'sent']) ->name('faxes.sent.list');
Route::get('/faxes/active/{id}', [FaxesController::class, 'active']) ->name('faxes.active.list');
Route::get('/faxes/log/{id}', [FaxesController::class, 'log']) ->name('faxes.log.list');
Route::delete('/faxes/deleteFaxFile/{id}', [FaxesController::class, 'deleteFaxFile']) ->name('faxes.file.deleteFaxFile');
Route::delete('/faxes/deleteFaxLog/{id}', [FaxesController::class, 'deleteFaxLog']) ->name('faxes.file.deleteFaxLog');
Route::get('/fax/inbox/{file}/download', [FaxesController::class, 'downloadInboxFaxFile']) ->name('downloadInboxFaxFile');
Route::get('/fax/sent/{file}/download', [FaxesController::class, 'downloadSentFaxFile']) ->name('downloadSentFaxFile');
Route::get('/fax/sent/{faxQueue}/{status?}', [FaxesController::class, 'updateStatus'])->name('faxes.file.updateStatus');
Route::post('/faxes/send', [FaxesController::class, 'sendFax']) -> name ('faxes.sendFax');

// Domain Groups
Route::resource('domaingroups', 'DomainGroupsController');

// Voicemail Messages
Route::get('/voicemails/{voicemail}/messages/', [VoicemailMessagesController::class, 'index']) ->name('voicemails.messages.index');
Route::delete('/voicemails/messages/{message}', [VoicemailMessagesController::class, 'destroy']) ->name('voicemails.messages.destroy');
Route::get('/voicemails/messages/{message}', [VoicemailMessagesController::class, 'getVoicemailMessage']) ->name('getVoicemailMessage');
Route::get('/voicemails/messages/{message}/download', [VoicemailMessagesController::class, 'downloadVoicemailMessage']) ->name('downloadVoicemailMessage');
Route::get('/voicemails/messages/{message}/delete', [VoicemailMessagesController::class, 'deleteVoicemailMessage']) ->name('deleteVoicemailMessage');


// SIP Credentials
Route::get('/extensions/{extension}/sip/show', [ExtensionsController::class, 'sipShow']) ->name('extensions.sip.show');

// Webhooks
Route::webhooks('webhook/postmark','postmark');
Route::webhooks('webhook/commio/sms','commio');

Route::resource('users','UsersController');
Route::resource('voicemails','VoicemailController');
Route::post('user/{user}/settings', [UserSettingsController::class, 'store'])->name('users.settings.store');
Route::delete('user/settings/{setting}', [UserSettingsController::class, 'destroy'])->name('users.settings.destroy');

// Route::get('preview-email', function () {
//     $markdown = new \Illuminate\Mail\Markdown(view(), config('mail.markdown'));
//     $data = "Your data to be use in blade file";
//     return $markdown->render("emails.app.credentials");
//    });

Route::group(['middleware' => 'auth'], function(){
    // Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
    Route::get('/', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
    Route::get('/logout', [App\Http\Controllers\Auth\LoginController::class, 'logout']);
    Route::resource('devices', 'DeviceController');
    Route::post('/domains/switch', [DomainController::class, 'switchDomain'])->name('switchDomain');
    Route::get('/domains/switch', function () {
        return redirect('/dashboard');
    });
    Route::get('/domains/switch/{domain}', [DomainController::class, 'switchDomainFusionPBX'])->name('switchDomainFusionPBX');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    //Users
    // Route::get('/users', [UsersController::class, 'index']) ->name('usersList');
    //Route::get('/users/create', [UsersController::class, 'createUser']) ->name('usersCreateUser');
    //Route::get('/users/edit/{id}', [UsersController::class, 'editUser']) ->name('editUser');
    // Route::post('/saveUser', [UsersController::class, 'saveUser']) ->name('saveUser');
    // Route::post('/updateUser', [UsersController::class, 'updateUser']) ->name('updateUser');
    Route::post('/deleteUser', [UsersController::class, 'deleteUser']) ->name('deleteUser');
    Route::post('/addSetting', [UsersController::class, 'addSetting']) ->name('addSetting');


    //Voicemails
    Route::post('/voicemails/greetings/upload/{voicemail}', [VoicemailController::class, 'uploadVoicemailGreeting']) ->name('uploadVoicemailGreeting');
    Route::get('/voicemails/{voicemail}/greetings/{filename}', [VoicemailController::class, 'getVoicemailGreeting']) ->name('getVoicemailGreeting');
    Route::get('/voicemails/{voicemail}/greetings/{filename}/download', [VoicemailController::class, 'downloadVoicemailGreeting']) ->name('downloadVoicemailGreeting');
    Route::get('/voicemails/{voicemail}/greetings/{filename}/delete', [VoicemailController::class, 'deleteVoicemailGreeting']) ->name('deleteVoicemailGreeting');

    //Apps
    Route::get('/apps', [AppsController::class, 'index']) ->name('appsStatus');
    Route::post('/apps/organization/create', [AppsController::class, 'createOrganization']) ->name('appsCreateOrganization');
    Route::delete('/apps/organization/{domain}', [AppsController::class, 'destroyOrganization']) ->name('appsDestroyOrganization');
    Route::get('/apps/organization/', [AppsController::class, 'getOrganizations']) ->name('appsGetOrganizations');
    Route::post('/apps/organization/sync', [AppsController::class, 'syncOrganizations']) ->name('appsSyncOrganizations');
    Route::post('/apps/users/{extension}', [AppsController::class, 'mobileAppUserSettings']) ->name('mobileAppUserSettings');
    //Route::get('/apps/organization/update', [AppsController::class, 'updateOrganization']) ->name('appsUpdateOrganization');
    Route::post('/apps/connection/create', [AppsController::class, 'createConnection']) ->name('appsCreateConnection');
    Route::get('/apps/connection/update', [AppsController::class, 'updateConnection']) ->name('appsUpdateConnection');
    Route::post('/apps/user/create', [AppsController::class, 'createUser']) ->name('appsCreateUser');
    Route::post('/apps/{domain}/user/sync', [AppsController::class, 'syncUsers']) ->name('appsSyncUsers');
    Route::delete('/apps/users/{extension}', [AppsController::class, 'deleteUser']) ->name('appsDeleteUser');
    Route::post('/apps/users/{extension}/resetpassword', [AppsController::class, 'ResetPassword']) ->name('appsResetPassword');
    Route::post('/apps/users/{extension}/status', [AppsController::class, 'SetStatus']) ->name('appsSetStatus');
    Route::get('/apps/email', [AppsController::class, 'emailUser']) ->name('emailUser');

    // SMS for testing
    // Route::get('/sms/ringotelwebhook', [SmsWebhookController::class,"messageFromRingotel"]);

    // Messages
    Route::get('/messages', [MessagesController::class, 'index']) ->name('messagesStatus');

    // Email Queues
    Route::get('emailqueue', [EmailQueueController::class, 'index'])->name('emailqueue.list');
    Route::delete('emailqueue/{id}', [EmailQueueController::class, 'delete'])->name('emailqueue.destroy');
    Route::get('emailqueue/{emailQueue}/{status?}', [EmailQueueController::class, 'updateStatus'])->name('emailqueue.updateStatus');

    // Fax Queue
    Route::get('faxqueue',[FaxQueueController::class, 'index'])->name('faxQueue.list');
    Route::delete('faxqueue/{id}',[FaxQueueController::class, 'destroy'])->name('faxQueue.destroy');
    Route::get('faxqueue/{faxQueue}/{status?}', [FaxQueueController::class, 'updateStatus'])->name('faxQueue.updateStatus');

});

// Route::group(['prefix' => '/'], function () {
//     Route::get('', [RoutingController::class, 'index'])->name('root');
//     Route::get('{first}/{second}/{third}', [RoutingController::class, 'thirdLevel'])->name('third');
//     Route::get('{first}/{second}', [RoutingController::class, 'secondLevel'])->name('second');
//     Route::get('/any/{any}', [RoutingController::class, 'root'])->name('any');
// });

Auth::routes();

//Route::get('/dashboard', [App\Http\Controllers\HomeController::class, 'index'])->name('dashboard');

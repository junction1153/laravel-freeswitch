<label for="{{$id}}" class="form-label">Greeting</label>
<div class="d-flex flex-row">
    <div class="w-100 me-1">
        <select class="select2 form-control"
                data-toggle="select2"
                data-placeholder="Choose ..."
                id="{{$id}}"
                name="{{$id}}">
            <option value="disabled">Disabled</option>
            @if (!$allRecordings->isEmpty())
                <optgroup label="Recordings">
                    @foreach ($allRecordings as $recording)
                        <option value="{{ $recording->recording_filename }}"
                                @if($recording->recording_filename == $value)
                                    selected
                                @endif>
                            {{ $recording->recording_name }}
                        </option>
                    @endforeach
                </optgroup>
            @endif
        </select>
    </div>
    <button disabled type="button" class="btn btn-light me-1 @if($value == null) d-none @endif"
            id="{{$id}}_play_pause_button" title="Play/Pause"><i class="uil uil-play"></i></button>
    <button type="button" class="btn btn-light" id="{{$id}}_manage_greeting_button" title="Manage greetings"><i
                class="uil uil-cog"></i></button>
    <audio id="{{$id}}_audio_file"
           @if ($value) src="{{ route('recordings.file', ['filename' => $value] ) }}" @endif ></audio>
</div>
<div class="modal fade" id="{{$id}}_manage_greeting_modal" role="dialog"
     aria-labelledby="{{$id}}_manage_greeting_modal" aria-hidden="true">
    <div class="modal-dialog w-75" style="max-width: initial;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Manage Greetings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="{{$id}}_manage_greeting_modal_body"></div>
                <div class="border border-dark-subtle p-3">
                    <h5 class="modal-title mb-3">Create New Greeting</h5>
                    <div class="mb-2">
                        <label for="{{$id}}_name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" id="{{$id}}_name" name="greeting_name" class="form-control" value=""/>
                        <div class="text-danger error_message {{$id}}_greeting_name_err"></div>
                    </div>
                    <div class="mb-2">
                        <label for="{{$id}}_description" class="form-label">Description</label>
                        <textarea class="form-control" id="{{$id}}_description" name="greeting_description"
                                  rows="2"></textarea>
                        <div class="text-danger error_message {{$id}}_greeting_description_err"></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-6 mb-2">
                            <label for="{{$id}}_filename" class="form-label">Sound File <span
                                        class="text-danger">*</span></label>
                            <input type="file" id="{{$id}}_filename" name="greeting_filename" accept=".wav"
                                   class="form-control"/>
                            <div class="text-danger error_message {{$id}}_greeting_filename_err"></div>
                        </div>
                        <div id="{{$id}}_record_wrapper" class="col-md-6 mb-2 d-none">
                            <label for="{{$id}}_filename_record" class="form-label">Or Record a New One</label>
                            <div class="mb-1">
                                <button type="button" id="{{$id}}_record_button"
                                        class="btn btn-light p-1 px-2 me-1 fs-4" title="Start/Stop recording"><i
                                            class="mdi mdi-record"></i></button>
                                <button disabled type="button" id="{{$id}}_recorded_play_pause_button"
                                        class="btn btn-light p-1 px-2 me-1 fs-4" title="Play/Pause recorded audio"><i
                                            class="mdi mdi-play"></i></button>
                            </div>
                            <div id="{{$id}}_record_in_progress_status" class="d-none recording-in-progress">
                                Recording in progress... Please speak, hit "<b>Stop</b>" when done.
                            </div>
                            <div id="{{$id}}_record_is_done_status" class="d-none recording-is-done text-muted">
                                Recording in done... hit "<b>Play</b>" to start playing recorded greeting. Hit "<b>Save
                                    new greeting</b>" if you want to save the greeting, either "<b>Record</b>" to record
                                a new one.
                            </div>
                            <audio id="{{$id}}_recorded_audio_file" class="d-none"></audio>
                            <input type="hidden" name="recorded_audio_file_stored"
                                   id="{{$id}}_recorded_audio_file_stored" value=""/>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <button type="button" class="btn btn-success save-recording-btn">Save new greeting</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@if($hint ?? false)
    <span class="help-block"><small>{{$hint}}</small></span>
@endif
<div id="{{$id}}_err" class="text-danger error_message"></div>
<style>
    .mdi-record {
        color: red;
    }

    .recording-in-progress {
        animation: blinker 1s linear infinite;
        color: red;
        display: flex;
    }

    @keyframes blinker {
        50% {
            opacity: 0;
        }
    }
</style>
@if($inlineScripts ?? true)
    @push('scripts')
        <script>
            $(document).ready(function () {
                const recordWrapper = $('#{{$id}}_record_wrapper');
                const greetingPlayPauseButton = $('#{{$id}}_play_pause_button');
                const greetingManageButton = $('#{{$id}}_manage_greeting_button');
                const greetingManageModal = $('#{{$id}}_manage_greeting_modal');
                const greetingManageModalBody = $('#{{$id}}_manage_greeting_modal_body');
                const audioElement = document.getElementById('{{$id}}_audio_file');
                const greetingRecordButton = $('#{{$id}}_record_button');
                const greetingRecordedPlayPauseButton = $('#{{$id}}_recorded_play_pause_button');
                const greetingRecordInProgress = $('#{{$id}}_record_in_progress_status');
                const greetingRecordIsDone = $('#{{$id}}_record_is_done_status');
                const audioElementRecorded = document.getElementById('{{$id}}_recorded_audio_file');
                const greetingRecordedAudioFileStored = $('#{{$id}}_recorded_audio_file_stored');
                let gumStream;
                let mediaRecorder;
                let chunks = [];
                let extension;

                if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
                    extension = "webm"
                    /*extension="webm";
                } else if (MediaRecorder.isTypeSupported('audio/mp4;codecs=mp4a')){
                    extension="mp4";
                } else {
                    extension="ogg"*/
                    recordWrapper.removeClass('d-none');
                } else {
                    console.warn('Your browser does not support recording audio.');
                }

                greetingRecordButton.on('click', function () {
                    if (mediaRecorder instanceof MediaRecorder && mediaRecorder.state === "recording") {
                        mediaRecorder.stop();
                        gumStream.getAudioTracks()[0].stop();
                        greetingRecordButton.html('<i class="mdi mdi-record"></i>');
                        greetingRecordInProgress.addClass('d-none');
                        greetingRecordIsDone.removeClass('d-none');
                        greetingRecordedPlayPauseButton.attr('disabled', false);
                        return;
                    }

                    greetingRecordButton.html('<i class="mdi mdi-stop"></i>');
                    greetingRecordInProgress.removeClass('d-none');
                    greetingRecordIsDone.addClass('d-none');
                    greetingRecordedPlayPauseButton.attr('disabled', true);
                    const constraints = {audio: true}
                    navigator.mediaDevices.getUserMedia(constraints).then(function (stream) {
                        console.log("getUserMedia() success, stream created, initializing MediaRecorder");
                        gumStream = stream;
                        mediaRecorder = new MediaRecorder(stream, {
                            audioBitsPerSecond: 256000,
                            videoBitsPerSecond: 2500000,
                            bitsPerSecond: 2628000,
                            mimeType: 'audio/' + extension + ';codecs=opus'
                        });

                        //when data becomes available add it to our attay of audio data
                        mediaRecorder.ondataavailable = function (e) {
                            console.log("recorder.ondataavailable:" + e.data);
                            console.log("recorder.audioBitsPerSecond:" + mediaRecorder.audioBitsPerSecond)
                            console.log("recorder.videoBitsPerSecond:" + mediaRecorder.videoBitsPerSecond)
                            console.log("recorder.bitsPerSecond:" + mediaRecorder.bitsPerSecond)
                            // add stream data to chunks
                            chunks.push(e.data);
                            // if recorder is 'inactive' then recording has finished
                            if (mediaRecorder.state === 'inactive') {
                                // convert stream data chunks to a 'webm' audio format as a blob
                                const blob = new Blob(chunks, {type: 'audio/' + extension, bitsPerSecond: 128000});
                                console.log("Saving chunk : " + blob.size);
                                const url = URL.createObjectURL(blob);
                                audioElementRecorded.setAttribute('src', url);
                                const formData = new FormData();
                                formData.append('recorded_file', blob, 'recordedAudio');
                                $.ajax({
                                    type: "POST",
                                    url: '{{ route('recordings.storeBlob') }}',
                                    cache: false,
                                    data: formData,
                                    processData: false,
                                    contentType: false,
                                    success: function (result) {
                                        console.log(result);
                                        greetingRecordedAudioFileStored.val(result.tempfile);
                                    },
                                    error: function (error) {
                                        console.err(error);
                                    }
                                });
                            }
                        };
                        mediaRecorder.onerror = function (e) {
                            console.err(e.error);
                        }
                        mediaRecorder.start(1000);
                    }).catch(function (err) {
                        greetingRecordButton.html('<i class="mdi mdi-record"></i>');
                        greetingRecordIsDone.addClass('d-none');
                        greetingRecordInProgress.addClass('d-none');
                        console.err("navigator.mediaDevices.getUserMedia() error: " + err);
                        alert("The unexpected issue is occurred. Probably we could not access to microphone. Please try again.");
                    });
                })

                $('#{{$id}}').on('change', function (e) {
                    greetingPlayPauseButton.attr('disabled', true)
                    if (e.target.value === '' || e.target.value === 'disabled') {
                        greetingPlayPauseButton.addClass('d-none');
                    } else {
                        greetingPlayPauseButton.removeClass('d-none');
                        document.getElementById('{{$id}}_audio_file').setAttribute('src', '{{ route('recordings.file', ['filename' => '/'] ) }}/' + e.target.value);
                        audioElement.load();
                    }
                })
                greetingManageButton.on('click', function () {
                    greetingManageModal.modal('show');
                });
                greetingManageModal.on('shown.bs.modal', function () {
                    loadAllRecordings(greetingManageModalBody);
                });
                greetingManageModal.on('hidden.bs.modal', function () {
                    greetingManageModalBody.empty()
                });
                greetingPlayPauseButton.click(function () {
                    if (audioElement.paused) {
                        console.log('Audio paused. Start')
                        greetingPlayPauseButton.find('i').removeClass('uil-play').addClass('uil-pause')
                        audioElement.play();
                    } else {
                        console.log('Audio playing. Pause')
                        greetingPlayPauseButton.find('i').removeClass('uil-pause').addClass('uil-play')
                        audioElement.currentTime = 0;
                        audioElement.pause();
                    }
                });
                audioElement.addEventListener('ended', (event) => {
                    console.log('Audio ended ' + event.target.src)
                    greetingPlayPauseButton.find('i').removeClass('uil-pause').addClass('uil-play')
                });
                audioElement.addEventListener('canplay', (event) => {
                    console.log('Audio loaded ' + event.target.src)
                    greetingPlayPauseButton.attr('disabled', false)
                });
                greetingRecordedPlayPauseButton.click(function () {
                    if (audioElementRecorded.paused) {
                        console.log('Recorded audio paused. Start')
                        greetingRecordedPlayPauseButton.find('i').removeClass('mdi-play').addClass('mdi-pause')
                        audioElementRecorded.play();
                    } else {
                        console.log('Recorded audio playing. Pause')
                        greetingRecordedPlayPauseButton.find('i').removeClass('mdi-pause').addClass('mdi-play')
                        audioElementRecorded.currentTime = 0;
                        audioElementRecorded.pause();
                    }
                });
                $('.save-recording-btn').on('click', function (e) {
                    e.preventDefault();

                    var formData = new FormData();
                    formData.append('greeting_filename', document.getElementById('{{$id}}_filename').files[0]);
                    formData.append('greeting_name', $('#{{$id}}_name').val());
                    formData.append('greeting_description', $('#{{$id}}_description').val());
                    formData.append('greeting_recorded_file', greetingRecordedAudioFileStored.val());

                    $.ajax({
                        type: "POST",
                        url: '{{ route('recordings.store') }}',
                        cache: false,
                        data: formData,
                        processData: false,
                        contentType: false,
                        beforeSend: function () {
                            //Reset error messages
                            greetingManageModal.find('.error_message').text('');
                            greetingManageModal.find('.save-recording-btn').attr('disabled', true);
                            $('.loading').show();
                        },
                        complete: function (xhr, status) {
                            greetingManageModal.find('.save-recording-btn').attr('disabled', false);
                            $('.loading').hide();
                        },
                        success: function (result) {
                            $('.loading').hide();
                            greetingManageModal.find('#{{$id}}_filename').val('');
                            greetingManageModal.find('#{{$id}}_name').val('');
                            greetingManageModal.find('#{{$id}}_description').val('');
                            audioElementRecorded.src = '';
                            greetingRecordedAudioFileStored.val('');
                            greetingRecordedPlayPauseButton.attr('disabled', true);
                            greetingRecordIsDone.addClass('d-none');
                            greetingRecordInProgress.addClass('d-none');
                            $.NotificationApp.send("Success", result.message, "top-right", "#10c469", "success");
                            $('#{{$id}}').append(new Option(result.name, result.filename, true, true)).trigger('change');
                            loadAllRecordings(greetingManageModalBody);
                        },
                        error: function (error) {
                            $('.loading').hide();
                            greetingManageModal.find('.btn').attr('disabled', false);
                            if (error.status === 422) {
                                if (error.responseJSON.errors) {
                                    let errors = {};
                                    for (const key in error.responseJSON.errors) {
                                        errors['{{$id}}_' + key] = error.responseJSON.errors[key];
                                    }
                                    printErrorMsg(errors);
                                } else {
                                    printErrorMsg(error.responseJSON.message);
                                }
                            } else {
                                printErrorMsg(error.responseJSON.message);
                            }
                        }
                    });
                })
            });

            function loadAllRecordings(tgt) {
                tgt.empty();
                $.ajax({
                    type: "GET",
                    url: '{{ route('recordings.index' ) }}'
                }).done(function (response) {
                    if (response.collection.length > 0) {
                        let tb = $('<table>');
                        tb.addClass('table');
                        tb.append('<thead><tr><th>Name</th><th>Description</th><th>Action</th></tr></thead>')
                        tb.append('<tbody>')
                        $.each(response.collection, function (i, item) {
                            let tr = $('<tr>').attr('id', 'id' + item.id).attr('data-filename', item.filename).append(`<td>${item.name}</td><td>${item.description}</td><td>
<a href="javascript:playCurrentRecording('${item.filename}')" class="action-icon">
<i class="uil uil-play" data-bs-container="#tooltip-container-actions" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Play/Pause"></i>
</a>
<a href="javascript:confirmDeleteRecordingAction('{{ route('recordings.destroy', ':id' ) }}','${item.id}');" class="action-icon"><i class="mdi mdi-delete" data-bs-container="#tooltip-container-actions" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Delete"></i></a>
</td>`)
                            tb.append(tr)
                        })
                        tgt.append(tb);
                    }
                });
            }

            function playCurrentRecording(filename) {
                $('#{{$id}}').val(filename);
                $('#{{$id}}').trigger('change');
                $('#{{$id}}_play_pause_button').click();
            }

            function confirmDeleteRecordingAction(url, setting_id) {
                dataObj = new Object();
                dataObj.url = url;
                dataObj.setting_id = setting_id;
                $('#confirmDeleteRecordingModal').data(dataObj).modal('show');
                // deleteSetting(setting_id);
            }

            function performConfirmedDeleteRecordingAction() {
                var confirmDeleteRecordingModal = $("#confirmDeleteRecordingModal");
                var setting_id = confirmDeleteRecordingModal.data("setting_id");
                confirmDeleteRecordingModal.modal('hide');
                var url = confirmDeleteRecordingModal.data("url");
                url = url.replace(':id', setting_id);
                $.ajax({
                    type: 'POST',
                    url: url,
                    cache: false,
                    data: {
                        '_method': 'DELETE',
                    }
                }).done(function (response) {
                    if (response.error) {
                        $.NotificationApp.send("Warning", response.message, "top-right", "#ff5b5b", "error");
                    } else {
                        $.NotificationApp.send("Success", response.message, "top-right", "#10c469", "success");
                        $("#{{$id}} option[value='" + response.filename + "']").remove();
                        /*var newArray = [];
                        let newData = $.grep($('#{{$id}}').select2('data'), function (value) {
                            console.log(value)
                            return value['id'] !== response.filename;
                        });
                        newData.forEach(function(data) {
                            newArray.push(+data.id);
                        });*/
                        $("#{{$id}}")/*.val(newArray)*/.select2();
                        $("#id" + setting_id).fadeOut("slow");
                    }
                }).fail(function (jqXHR, testStatus, error) {
                    printErrorMsg(error);
                });
            }
        </script>
    @endpush
@endif

<div class="modal fade" id="confirmDeleteRecordingModal" data-bs-backdrop="static" data-bs-keyboard="false"
     tabindex="-1" aria-labelledby="staticBackdropLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-body p-4">
                <div class="text-center">
                    {{-- <i class=" dripicons-question h1 text-danger"></i> --}}
                    <i class="uil uil-times-circle h1 text-danger"></i>
                    <h3 class="mt-3">Are you sure?</h3>
                    <p class="mt-3">Do you really want to delete this? This process cannot be undone.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light me-2" data-bs-dismiss="modal">Cancel</button>
                <a href="javascript:performConfirmedDeleteRecordingAction();" class="btn btn-danger me-2">Delete</a>
            </div> <!-- end modal footer -->
        </div> <!-- end modal content-->
    </div> <!-- end modal dialog-->
</div> <!-- end modal-->

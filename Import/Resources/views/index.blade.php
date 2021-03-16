<head>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <script>
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });

        // Class definition
        let ImportWizard = function () {
            // Base elements
            let wizardEl = $('#m_wizard');
            let formEl = $('#m_form');
            let validator;
            let wizard;
            let fileId;
            let mappingFileID;
            let stepCounter = 1;
            let successBlock = $('#import-results-success');
            let failedBlock = $('#import-results-failed');
            let importedRecordsSuccessCounter = 0;
            let importedRecordsFailedCounter = 0;
            let importedRecordsSuccessData = [];
            let importedRecordsFailedData = [];
            let importedRecords = 0;
            let totalRecords = 0;
            let blockImport = false;
            let requiredFields = [
                'product.code',
                'product.text.name',
            ];

            // Private functions
            let initWizard = function () {
                // Initialize form wizard
                wizard = new mWizard('m_wizard', {
                    startStep: 1
                });

                // Validation before going to next page
                wizard.on('beforeNext', function(wizardObj) {
                    if (validator.form() !== true) {
                        wizardObj.stop(); //  don't go to the next step
                    }
                });

                // Change event
                wizard.on('change', function(wizard) {
                    mUtil.scrollTop();

                    if (wizard.getStep() > stepCounter) {
                        stepCounter = ++stepCounter;
                    }

                    if (wizard.getStep() === 1) {
                        $('.m-wizard__step-icon').css('color', 'white');
                        stepCounter = 1;
                    } else if (wizard.getStep() === 2) {
                        if (wizard.getStep() < stepCounter) {
                            $('.m-wizard__step-icon:eq(1)').css('color', 'white');
                            $('.m-wizard__step-icon:eq(2)').css('color', 'white');
                            $('.m-wizard__step-icon:eq(3)').css('color', 'white');
                            stepCounter = 2;
                        }

                        $('.m-wizard__step-icon:eq(0)').css('color', '#34bfa3');
                        if (fileId !== mappingFileID) {
                            $.ajax({
                                type: 'get',
                                url: '{{ route('import.excel.headers') }}/' + fileId,
                                beforeSend: mApp.block('#m-content', true),
                                success: function(data) {
                                    mappingFileID = fileId;

                                    if (data.rows !== null) {
                                        $.each(data.rows, function(pos, name) {
                                            $('#field_mapping').append(
                                                '<div class="form-group m-form__group row" id="position-' + pos + '">\n' +
                                                '<label class="col-lg-4 col-form-label">' + name + '</label>\n' +
                                                '<div class="col-lg-8">\n' +
                                                '<select class="form-control m-input" name="position[' + pos + ']">\n' +
                                                '<option value="">-</option>\n' +
                                                @foreach($fields as $field => $fieldData)
                                                    @if (isset($fieldData['headings']))
                                                    '<option ' + ( {!! json_encode($fieldData['headings']) !!}.includes(name.toLowerCase()) ? 'selected' : '') + ' value="{{$field}}">{{ ucfirst($fieldData['name']) }}</option>\n' +
                                            @else
                                                '<option value="{{$field}}">{{$field}}</option>\n' +
                                            @endif
                                                @endforeach
                                                '</select>\n' +
                                            '</div>\n' +
                                            '</div>'
                                        );
                                        });

                                        if (typeof(headerAsProductName) !== 'undefined' && headerAsProductName === true) {
                                            $.each(data.rows, function (pos, name) {
                                                $('#field_mapping_headers_select').append(
                                                    '<label class="m-checkbox">' +
                                                    '<input type="checkbox" name="headers[' + pos + ']" value="' + name.replace(/(\r\n|\n|\r)/gm, "").replace(/(\s+)/gm, " ").trim() + '">' + name.replace(/(\r\n|\n|\r)/gm, "").replace(/(\s+)/gm, " ").trim() +
                                                    '<span></span>' +
                                                    '</label>' +
                                                    '<br>'
                                                );
                                            })
                                        }

                                        if (typeof(data.highest_row) !== 'undefined') {
                                            totalRecords = data.highest_row;
                                        }
                                    }

                                    $('.next.btn').prop('disabled', false);
                                    mApp.unblock('#m-content');
                                },
                                error: function (data) {
                                    notify('danger', data.responseText);
                                }
                            });
                        }
                    } else if (wizard.getStep() === 3) {
                        if (wizard.getStep() < stepCounter) {
                            $('.m-wizard__step-icon:eq(2)').css('color', 'white');
                            $('.m-wizard__step-icon:eq(3)').css('color', 'white');
                            blockImport = true;
                        }
                        $('.m-wizard__step-icon:eq(1)').css('color', '#34bfa3');

                        let mapping = [];
                        $('#import-results-success').empty();
                        $('#import-results-failed').empty();
                        $('#field_mapping .form-control.m-input').each(function() {
                            mapping.push(this.value);
                        });

                        if (!blockImport && validateFields(mapping)) {
                            runImport(0);
                        } else {
                            if (importedRecordsSuccessData.length) {
                                $.each(importedRecordsSuccessData, function (index, value) {
                                    successBlock.append(value);
                                });
                            }

                            if (importedRecordsFailedData.length) {
                                $.each(importedRecordsFailedData, function (index, value) {
                                    failedBlock.append(value);
                                });
                            }
                        }
                    } else if (wizard.getStep() === 4) {
                        $('.m-wizard__step-icon:eq(2)').css('color', '#34bfa3');
                        $('.m-wizard__step-icon:eq(3)').css('color', '#34bfa3');
                    }
                });
            };

            let runImport = function(batch) {
                if (batch === 0) {
                    importedRecords = 0;
                    importedRecordsSuccessCounter = 0;
                    importedRecordsFailedCounter = 0;
                }

                mApp.block('#m-content', true);
                $('#import_wizard_modal').modal({show:true});
                $('#modal-body').text(importedRecords + ' regels van ' + totalRecords + ' geïmporteert');

                $.ajax({
                    type: 'get',
                    url: formEl.attr('action') + '/' + fileId + '/' + batch,
                    data: formEl.serialize(),
                    success: function(data) {
                        importedRecords += data.info.number;
                        $('#modal-body').text(importedRecords + ' regels van ' + totalRecords + ' geïmporteert');

                        if (typeof(data.message) !== 'undefined') {
                            wizard.goPrev();
                            notify('danger', data.message);
                        }

                        if (typeof(data.info.results) !== 'undefined') {
                            $('#import_multiple_records').css('display', 'flex');

                            if (typeof(data.info.results.success) !== 'undefined') {
                                importedRecordsSuccessCounter += Object.keys(data.info.results.success).length;
                                $('#import-results-success-nav').text(importedRecordsSuccessCounter + ' bijgewerkte resultaten');

                                $.each(data.info.results.success, function (index, value) {
                                    successBlock.append(
                                        '<li id="result-' + index + '">'
                                        + value +
                                        '</li>'
                                    );

                                    importedRecordsSuccessData.push(
                                        '<li id="result-' + index + '">'
                                        + value +
                                        '</li>'
                                    );
                                });
                            }

                            if (typeof(data.info.results.failed) !== 'undefined') {
                                importedRecordsFailedCounter += Object.keys(data.info.results.failed).length;
                                $('#import-results-failed-nav').text(importedRecordsFailedCounter + ' niet geïmporteerde resultaten');

                                $.each(data.info.results.failed, function (index, value) {
                                    failedBlock.append(
                                        '<li id="result-' + index + '">'
                                        + value +
                                        '</li>'
                                    );

                                    importedRecordsFailedData.push(
                                        '<li id="result-' + index + '">'
                                        + value +
                                        '</li>'
                                    );
                                });
                            }
                        }

                        if (data.info.next_page) {
                            runImport(data.info.next_page);
                        } else {
                            mApp.unblock('#m-content');
                            $('#import_wizard_modal').modal('hide');
                        }
                    },
                    error: function (data) {
                        notify('danger', data.responseText);
                    }
                });
            };

            let initValidation = function() {
                validator = formEl.validate({
                    // Validate only visible fields
                    ignore: ":hidden",

                    // Validation rules
                    rules: {
                        name: {
                            required: true
                        }
                    },

                    // Display error
                    invalidHandler: function(event, validator) {
                        mUtil.scrollTop();

                        swal({
                            "title": "",
                            "text": "There are some errors in your submission. Please correct them.",
                            "type": "error",
                            "confirmButtonClass": "btn btn-secondary m-btn m-btn--wide"
                        });
                    },

                    // Submit valid form
                    submitHandler: function (form) {

                    }
                });
            };

            let initSubmit = function() {
                let btn = formEl.find('[data-wizard-action="submit"]');

                btn.on('click', function(e) {
                    e.preventDefault();

                    if (validator.form()) {
                        // See: src\js\framework\base\app.js
                        mApp.progress(btn);
                        mApp.block(formEl, true);

                        // See: http:malsup.com/jquery/form/#ajaxSubmit
                        formEl.ajaxSubmit({
                            success: function() {
                                mApp.unprogress(btn);
                                mApp.unblock(formEl);

                                swal({
                                    "title": "",
                                    "text": "The application has been successfully submitted!",
                                    "type": "success",
                                    "confirmButtonClass": "btn btn-secondary m-btn m-btn--wide"
                                });
                            }
                        });
                    }
                });
            };

            let initDropzone = function() {
                $("div#csv_dropzone").dropzone({
                    url: "{{ route('files.upload') }}",
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    paramName: "files",
                    params: {
                        'model': "App\\User",
                        'id': '{{ Auth::user() ? Auth::user()->id : 1 }}'
                    },
                    uploadMultiple: true,
                    maxFilesize: 15,
                    acceptedFiles: '.xlsx, .xls',
                    dictDefaultMessage: "<strong>{{ __('filemanagement::t.Choose a file') }} </strong><br /><span> {{ __('filemanagement::t.or drop it here') }}</span>.",
                    dictFallbackMessage: "<strong>{{ __('filemanagement::t.Choose a file') }} </strong><br /><span> {{ __('filemanagement::t.or drop it here') }}</span>.",
                    init: function () {
                        this.on("totaluploadprogress", function() {
                            mApp.block('#m-content', true);
                        });

                        this.on("complete", function(file) {
                            mApp.unblock('#m-content');
                            let responseText = $.parseJSON(file.xhr.responseText);
                            fileId = responseText.media[0].id;

                            if (this.getUploadingFiles().length === 0 && this.getQueuedFiles().length === 0) {
                                if (file.xhr.status === 200) {
                                    $('.next.btn').prop('disabled', false);
                                    $('.dz-success-mark svg g path').css('fill', 'green');
                                    notify('success', "{{ __('filemanagement::t.Upload complete!') }}");
                                } else {
                                    $('.dz-error-mark svg g g').css('fill', 'red');
                                    notify('danger', this.errorMsg);
                                }
                            }
                        });
                    }
                });
            };

            let notify = function(type, message) {
                notifySettings.type = type;
                if (type === 'danger') {
                    $.notify('<span data-notify="icon" class="icon la la-exclamation-triangle"></span><span data-notify="message">' + message + '</span>', notifySettings);
                }
                if (type === 'success') {
                    $.notify('<span data-notify="icon" class="icon la la-check"></span><span data-notify="message">' + message + '</span>', notifySettings);
                }
            }

            let validateFields = function(mapping) {
                let fields = '';
                let valid = true;

                if (typeof(requiredFields) !== 'undefined') {
                    $.each(requiredFields, function (key, field) {
                        fields += field + ', ';

                        if (!mapping.includes(field)) {
                            wizard.goPrev();
                            notify('danger', 'Verplichte velden (' + fields + ') zijn niet ingesteld');
                            valid = false;
                        }
                    });
                }

                return valid;
            }

            return {
                // public functions
                init: function() {
                    wizardEl = $('#m_wizard');
                    formEl = $('#m_form');

                    initWizard();
                    initValidation();
                    initSubmit();
                    initDropzone();
                }
            };
        };

        $(document).ready(function() {
            ImportWizard().init();
        });
    </script>
</head>

<body>
<div class="m-content">
    <!--begin::Portlet-->
    <div class="m-portlet m-portlet--mobile m-portlet--full-height">
        <div class="m-portlet__head">
            <div class="m-portlet__head-caption">
                <div class="m-portlet__head-title">
                    <span class="m-portlet__head-icon m--hide">
                        <i class="la la-gear"></i>
                    </span>
                    <h3 class="m-portlet__head-text">
                        {{ ucfirst(__('models.import')) }}
                    </h3>
                </div>
            </div>

            <div class="m-portlet__head-tools">
{{--                <a href="{{ route('product.download.example') }}" class="btn btn-warning m-btn m-btn--custom">Voorbeeld</a>--}}
            </div>
        </div>
        <!--begin: Form Wizard-->
        <div class="m-wizard m-wizard--5 m-wizard--success" id="m_wizard">
            <!--begin: Message container -->
            <div class="m-portlet__padding-x">
                @if ($errors->any())
                    <div class="alert alert-danger">
                        {{ __('system.Whoops! There were some problems with your input.') }}
                        <br /><br />
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
            <!--end: Message container -->

            <!--begin: Form Wizard Head -->
            <div class="m-wizard__head m-portlet__padding-x">
                <div class="row">
                    <div class="col-xl-10 offset-xl-1">
                        <!--begin: Form Wizard Nav -->
                        <div class="m-wizard__nav">
                            <div class="m-wizard__steps">
                                <div class="m-wizard__step m-wizard__step--current" m-wizard-target="m_wizard_form_step_1">
                                    <div class="m-wizard__step-info">
                                        <a href="#" class="m-wizard__step-number">
                                            <span class="m-wizard__step-seq">1.</span>
                                            <span class="m-wizard__step-label">
                                                Bestand uploaden
                                            </span>
                                            <span class="m-wizard__step-icon"><i class="la la-check"></i></span>
                                        </a>
                                    </div>
                                </div>

                                <div class="m-wizard__step" m-wizard-target="m_wizard_form_step_2">
                                    <div class="m-wizard__step-info">
                                        <a href="#" class="m-wizard__step-number">
                                            <span class="m-wizard__step-seq">2.</span>
                                            <span class="m-wizard__step-label">
                                                Veld selectie
                                            </span>
                                            <span class="m-wizard__step-icon"><i class="la la-check"></i></span>
                                        </a>
                                    </div>
                                </div>

                                <div class="m-wizard__step" m-wizard-target="m_wizard_form_step_3">
                                    <div class="m-wizard__step-info">
                                        <a href="#" class="m-wizard__step-number">
                                            <span class="m-wizard__step-seq">3.</span>
                                            <span class="m-wizard__step-label">
                                                Importeren
                                            </span>
                                            <span class="m-wizard__step-icon"><i class="la la-check"></i></span>
                                        </a>
                                    </div>
                                </div>

                                <div class="m-wizard__step" m-wizard-target="m_wizard_form_step_4">
                                    <div class="m-wizard__step-info">
                                        <a href="#" class="m-wizard__step-number">
                                            <span class="m-wizard__step-seq">4.</span>
                                            <span class="m-wizard__step-label">
                                                Afgerond
                                            </span>
                                            <span class="m-wizard__step-icon"><i class="la la-check"></i></span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!--end: Form Wizard Nav -->
                    </div>
                </div>
            </div>
            <!--end: Form Wizard Head -->

            <!--begin: Form Wizard Form-->
            <div class="m-wizard__form">
                <form method="POST" action="{{ $action_url }}" class="m-form m-form--label-align-left- m-form--state-" id="m_form" autocomplete="off" enctype="multipart/form-data">
                @csrf
                <!--begin: Form Body -->
                    <div class="m-portlet__body">
                        <!--begin: Form Wizard Step 1-->
                        <div class="m-wizard__form-step m-wizard__form-step--current" id="m_wizard_form_step_1">
                            <div class="row">
                                <div class="col-xl-10 offset-xl-1">
                                    <div class="m-form__section m-form__section--first">
                                        <div id="csv_dropzone" class="m-dropzone m-dropzone--primary">
                                            Bestand uploaden
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!--end: Form Wizard Step 1-->

                        <!--begin: Form Wizard Step 2-->
                        <div class="m-wizard__form-step" id="m_wizard_form_step_2">
                            <div class="row">
                                <div class="col-xl-10 offset-xl-1">
                                    <div class="m-form__section m-form__section--first">
                                        <div class="m-form__heading">
                                            <h3 class="m-form__heading-title">Veld selectie</h3>
                                        </div>

                                        <div class="alert alert-info" role="alert">
                                            <strong>Let op!</strong>
                                            <ul>
                                                <li>Stel ten minste SKU, naam en 1 categorie in.</li>
                                                <li>Indien er variabele producten worden geimporteerd stel dan altijd een groeperings code in en het attribuut als attribuut naam (variabel).</li>
                                                <li>Zorg er voor dat bij afbeeldingen alleen een bestandsnaam invoeren is bijvoorbeeld "afbeelding1.jpg".</li>
                                                <li>Indien er categorien in de webshop staan met dezelfde naam zorg er dan voor dat de categorie code gebruikt wordt. Anders weet het systeem niet welke van de twee er gekoppeld moet worden.</li>
                                                <li>Schakel de optie <i>"Leverancier SKU als groeperingscode"</i> alleen uit als de import <strong>geen</strong> variabele producten bevat.</li>
                                                <li>Elk veld kan maar één keer geselecteerd worden</li>
                                            </ul>
                                        </div>

                                        <div id="field_mapping">

                                        </div>

                                        <br><br>

                                        <label class="m-checkbox">
                                            <input type="checkbox" name="force_update"> Bestaande producten bijwerken.
                                            <span></span>
                                        </label>

                                        <br><br>

                                        <label class="m-checkbox">
                                            <input type="checkbox" name="supplier_sku_as_parent" checked> Leverancier SKU als groeperingscode.
                                            <span></span>
                                        </label>

                                    </div>
                                </div>
                            </div>
                        </div>
                        <!--end: Form Wizard Step 2-->

                        <!--begin: Form Wizard Step 3-->
                        <div class="m-wizard__form-step" id="m_wizard_form_step_3">
                            <div class="row">
                                <div class="col-xl-10 offset-xl-1">
                                    <div class="m-form__section m-form__section--first">
                                        <div class="m-form__heading">
                                            <h3 class="m-form__heading-title">Importing in progress</h3>
                                        </div>

                                        <div class="modal fade" id="import_wizard_modal" tabindex="-1" role="dialog">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-body">
                                                        <p id="modal-body"></p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <ul class="nav nav-tabs">
                                            <li class="nav-item"><a data-toggle="tab" href="#import-success" class="nav-link" id="import-results-success-nav">Import resultaten bijgewerkt</a></li>
                                            <li class="nav-item"><a data-toggle="tab" href="#import-failed" class="nav-link" id="import-results-failed-nav">Import resultaten niet gelukt</a></li>
                                        </ul>

                                        <div class="tab-content">
                                            <div id="import-success" class="tab-pane container active">
                                                <ul id="import-results-success">

                                                </ul>
                                            </div>
                                            <div id="import-failed" class="tab-pane container">
                                                <ul id="import-results-failed">

                                                </ul>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </div>
                        </div>
                        <!--end: Form Wizard Step 3-->

                        <!--begin: Form Wizard Step 4-->
                        <div class="m-wizard__form-step" id="m_wizard_form_step_4">
                            <div class="row">
                                <div class="col-xl-10 offset-xl-1">
                                    <div class="m-form__section m-form__section--first">
                                        <div class="m-form__heading">
                                            <h3 class="m-form__heading-title">Import afgerond</h3>
                                        </div>

                                        <svg class="d-flex mx-auto" width="54px" height="54px">
                                            <g id="Page-4" stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                                                <path id="Oval-2"
                                                      d="M23.5,31.8431458 L17.5852419,25.9283877 C16.0248253,24.3679711 13.4910294,24.366835 11.9289322,25.9289322 C10.3700136,27.4878508 10.3665912,30.0234455 11.9283877,31.5852419 L20.4147581,40.0716123 C20.5133999,40.1702541 20.6159315,40.2626649 20.7218615,40.3488435 C22.2835669,41.8725651 24.794234,41.8626202 26.3461564,40.3106978 L43.3106978,23.3461564 C44.8771021,21.7797521 44.8758057,19.2483887 43.3137085,17.6862915 C41.7547899,16.1273729 39.2176035,16.1255422 37.6538436,17.6893022 L23.5,31.8431458 Z M27,53 C41.3594035,53 53,41.3594035 53,27 C53,12.6405965 41.3594035,1 27,1 C12.6405965,1 1,12.6405965 1,27 C1,41.3594035 12.6405965,53 27,53 Z"
                                                      stroke-opacity="0.816519475"
                                                      stroke="#747474"
                                                      fill-opacity="0.816519475"
                                                      fill="#FFFFF"
                                                      style="fill: green"
                                                ></path>
                                            </g>
                                        </svg>

                                        <p id="import_multiple_records" class="d-flex flex-column text-center" style="display: none;">
                                            {{--		Wees je bewust van het feit dat er dubbele Sku's zijn toegevoegd--}}
                                        </p>

                                    </div>
                                </div>
                            </div>
                        </div>
                        <!--end: Form Wizard Step 4-->
                    </div>
                    <!--end: Form Body -->

                    <!--begin: Form Actions -->
                    <div class="m-portlet__foot m-portlet__foot--fit m--margin-top-40">
                        <div class="m-form__actions m-form__actions">
                            <div class="row">
                                <div class="col-lg-1"></div>
                                <div class="col-lg-4 m--align-left">
                                    <a href="#" class="btn btn-secondary m-btn m-btn--custom m-btn--icon" data-wizard-action="prev">
                                        <span>
                                            <i class="la la-arrow-left"></i>&nbsp;&nbsp;
                                            <span>Back</span>
                                        </span>
                                    </a>
                                </div>
                                <div class="col-lg-6 m--align-right">
                                    <a href="#" disabled="disabled" class="next btn btn-warning m-btn m-btn--custom m-btn--icon" data-wizard-action="next">
                                        <span>
                                            <span>Continue</span>&nbsp;&nbsp;
                                            <i class="la la-arrow-right"></i>
                                        </span>
                                    </a>
                                </div>
                                <div class="col-lg-1"></div>
                            </div>
                        </div>
                    </div>
                    <!--end: Form Actions -->
                </form>
            </div>
            <!--end: Form Wizard Form-->
        </div>
        <!--end: Form Wizard-->
    </div>
    <!--end::Portlet-->
</div>
</body>

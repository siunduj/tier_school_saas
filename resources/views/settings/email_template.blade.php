@extends('layouts.master')

@section('title')
    {{ __('email_template') }}
@endsection


@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{ __('email_template') }}
            </h3>
        </div>
        <div class="row grid-margin">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body">
                        <form id="formdata" class="email-template-setting-form" action="{{ route('system-settings.email-template.update', 1) }}" method="POST" novalidate="novalidate">
                            @csrf
                           
                            <div class="form-group">
                                <label>{{ __('template') }} <span class="text-danger">*</span></label>
                                <div class="col-12 d-flex row">
                                    <div class="form-check form-check-inline">
                                        <label class="form-check-label">
                                            <input type="radio" class="form-check-input email-template" checked name="template" id="email-template" value="school-email-template" required="required">
                                            {{ __('school_register_email_template') }}
                                        </label>
                                    </div>

                                    <div class="form-check form-check-inline">
                                        <label class="form-check-label">
                                            <input type="radio" class="form-check-input email-template" name="template" id="email-template" value="school-reject-template" required="required">
                                            {{ __('school_application_reject_email_template') }}
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="row school-email-template">
                                <div class="form-group col-md-12 col-sm-12">
                                    <textarea id="tinymce_message" name="email_template_school_registration" required placeholder="{{ __('email_template') }}">{{ htmlspecialchars_decode($settings['email_template_school_registration'] ?? '') }}</textarea>
                                </div>  
                                <div class="form-group col-sm-12 col-md-12">
                                    <a data-value="{school_admin_name}" class="btn btn-gradient-light btn_tag mt-2">{ {{ __('school_admin_name') }} }</a>
                                    <a data-value="{code}" class="btn btn-gradient-light btn_tag mt-2">{ {{ __('code') }} }</a>
                                    <a data-value="{email}" class="btn btn-gradient-light btn_tag mt-2">{ {{ __('email') }} }</a>
                                    <a data-value="{password}" class="btn btn-gradient-light btn_tag mt-2">{ {{ __('password') }} }</a>
                                    <a data-value="{school_name}" class="btn btn-gradient-light btn_tag mt-2">{ {{ __('school_name') }} }</a>
                                </div>
                                

                                <div class="form-group col-sm-12 col-md-12">
                                    <hr>
                                    <a data-value="{super_admin_name}" class="btn btn-gradient-light btn_tag mt-2">{ {{ __('super_admin_name') }} }</a>
                                    <a data-value="{support_email}" class="btn btn-gradient-light btn_tag mt-2">{ {{ __('support_email') }} }</a>
                                    <a data-value="{contact}" class="btn btn-gradient-light btn_tag mt-2">{ {{ __('contact') }} }</a>
                                    <a data-value="{system_name}" class="btn btn-gradient-light btn_tag mt-2">{ {{ __('system_name') }} }</a>
                                    <a data-value="{url}" class="btn btn-gradient-light btn_tag mt-2">{ {{ __('url') }} }</a>
                                </div>
                            </div>

                            <div class="row school-reject-template">
                                <div class="form-group col-md-12 col-sm-12">
                                    <textarea id="tinymce_message" name="school_reject_template" required placeholder="{{ __('email_template') }}">{{ htmlspecialchars_decode($settings['school_reject_template'] ?? '') }}</textarea>
                                </div>

                                <div class="form-group col-sm-12 col-md-12">
                                    <a data-value="{school_name}" class="btn btn-gradient-light btn_tag mt-2">{ {{ __('school_name') }} }</a>
                                    <a data-value="{super_admin_name}" class="btn btn-gradient-light btn_tag mt-2">{ {{ __('super_admin_name') }} }</a>
                                    <a data-value="{support_email}" class="btn btn-gradient-light btn_tag mt-2">{ {{ __('support_email') }} }</a>
                                    <a data-value="{contact}" class="btn btn-gradient-light btn_tag mt-2">{ {{ __('contact') }} }</a>
                                    <a data-value="{system_name}" class="btn btn-gradient-light btn_tag mt-2">{ {{ __('system_name') }} }</a>
                                    <a data-value="{url}" class="btn btn-gradient-light btn_tag mt-2">{ {{ __('url') }} }</a>
                                </div>        
                            </div>
                            <input class="btn btn-theme float-right" type="submit" value="{{ __('submit') }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('js')
    <script>
        window.onload = setTimeout(() => {
            $('.email-template').trigger('change');
        }, 500);
        $('.email-template').change(function (e) { 
            e.preventDefault();
            let type = $('input[name="template"]:checked').val();

            if (type == 'school-email-template') {
                $('.school-email-template').show(500);
                $('.school-reject-template').hide(500);
           
            } else if(type == 'school-reject-template') {
                $('.school-reject-template').show(500);
                $('.school-email-template').hide(500);
               
            
            }
        });
    </script>
@endsection

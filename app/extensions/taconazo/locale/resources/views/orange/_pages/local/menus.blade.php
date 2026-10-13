---
title: igniter.orange::default.menus_title
permalink: '/:location?local/menus/:category?'
description: ''
layout: default

'[igniter-orange::local-header]':
    showThumb: 0
'[igniter-orange::fulfillment]': []
'[igniter-orange::category-list]': []
'[igniter-orange::menu-item-list]':
    showThumb: 1
    menuThumbWidth: 180
    menuThumbHeight: 180
'[igniter-orange::cart-box]': []
'[igniter-orange::fulfillment-modal]': []
---
{{-- Slim location bar (Mellow-style) + larger dish photos. --}}
@php
    $hour = (int) now()->timezone(config('app.timezone', 'UTC'))->format('G');
    $showBreakfast = $hour >= 7 && $hour < 11;
@endphp
<div class="tz-local-bar border-bottom">
    <div class="container py-2">
        <div class="d-flex flex-wrap align-items-center gap-2 gap-md-3">
            <a
                class="text-decoration-none tz-back small"
                href="{{ page_url('locations') }}"
            >
                <i class="fa fa-arrow-left-long"></i>
                @lang('igniter.orange::default.button_back')
            </a>
            <div class="flex-grow-1 min-w-0">
                <x-igniter-orange::local-header :showThumb="false"/>
            </div>
            <div class="tz-fulfillment-chip">
                <x-igniter-orange::fulfillment/>
            </div>
        </div>
    </div>
</div>

@if($showBreakfast)
    <div class="tz-breakfast-callout border-bottom">
        <div class="container py-2 small text-center">
            @lang('taconazo.locale::default.breakfast_callout')
        </div>
    </div>
@endif

<div class="tz-promo-rail border-bottom d-lg-none">
    <div class="container py-2">
        @include('igniter-orange::includes.promo-rail')
    </div>
</div>

<div class="sticky-top tz-category-rail border-bottom">
    <div class="container">
        <x-igniter-orange::category-list/>
    </div>
</div>
<div class="container pt-3 pb-5">
    <div class="row">
        <div class="col-lg-8">
            <livewire:igniter-orange::menu-item-list showThumb="1" menuThumbWidth="180" menuThumbHeight="180"/>
        </div>

        <div class="col-lg-4 d-none d-lg-inline-block">
            <div class="tz-promo-rail mb-3 d-none d-lg-block">
                @include('igniter-orange::includes.promo-rail')
            </div>
            <livewire:igniter-orange::cart-box/>
        </div>
    </div>
</div>
<livewire:igniter-orange::fulfillment-modal/>

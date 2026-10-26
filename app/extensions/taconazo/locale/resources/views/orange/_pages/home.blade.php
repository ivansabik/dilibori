---
title: igniter.orange::default.home_title
description: ''
permalink: /
layout: default

'[igniter-orange::slider]':
    code: home-slider
'[igniter-orange::local-search]': []
---
{{-- Food-first home: slider, promos, branch CTAs, favoritos. No orphaned banners. --}}
<div class="tz-home">
    <x-igniter-orange::slider />

    <div class="tz-promo-rail border-bottom">
        <div class="container py-3">
            @include('igniter-orange::includes.promo-rail')
        </div>
    </div>

    <div class="border-bottom tz-hero-band">
        <div class="container">
            <div class="row justify-content-md-center">
                <div class="col-lg-8 py-5">
                    <livewire:igniter-orange::local-search/>
                </div>
            </div>
        </div>
    </div>

    @include('igniter-orange::includes.favoritos')
</div>

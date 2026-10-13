{{-- Compact location header: no giant storefront thumb on menus. --}}
<div class="panel-local tz-local-header">
    <div class="min-w-0">
        <h1 class="h5 mb-0 text-truncate">{{ $locationInfo->name }}</h1>
        <div class="d-flex flex-wrap align-items-center gap-2 small mt-1">
            <span @class([
                'tz-open-pill' => $currentSchedule($locationInfo)->isOpen(),
                'text-muted' => !$currentSchedule($locationInfo)->isOpen(),
            ])>
                @if($currentSchedule($locationInfo)->isOpen())
                    @lang('igniter.local::default.text_is_opened')
                @elseif($currentSchedule($locationInfo)->isOpening())
                    {!! sprintf(lang('igniter.local::default.text_opening_time'), make_carbon($currentSchedule($locationInfo)->getOpenTime())->isoFormat(lang('igniter::system.moment.day_time_format_short'))) !!}
                @else
                    @lang('igniter.local::default.text_closed')
                @endif
            </span>
            <span class="text-muted text-truncate">{{ format_address($locationInfo->address, false) }}</span>
            <a
                class="link-dark fw-bold cursor-pointer"
                data-bs-toggle="offcanvas"
                data-bs-target="#localInfoCanvas"
                aria-controls="localInfoCanvas"
            >@lang('igniter.local::default.text_more_info')</a>
            @if($allowReviews)
                <x-igniter-orange::star-rating :score="$locationInfo->reviewsScore()">
                    <a
                        data-bs-toggle="offcanvas"
                        data-bs-target="#reviewsOffCanvas"
                        aria-controls="reviewsOffCanvas"
                        class="link-primary cursor-pointer"
                    ><span class="small">({{ $locationInfo->reviewsCount() }})</span></a>
                </x-igniter-orange::star-rating>
            @endif
        </div>
    </div>
    @include('igniter-orange::includes.local.info-offcanvas')
    @include('igniter-orange::includes.local.reviews-offcanvas')
</div>

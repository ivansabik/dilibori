<a
    class="card w-100 p-3 mb-3 shadow-sm text-decoration-none"
    href="{{ $locationData->url($menusPage) }}"
>
    <div class="boxes d-sm-flex g-0 align-items-start">
        <div class="col-12 col-sm-7 min-w-0 pe-sm-3">
            <div class="d-sm-flex align-items-start">
                @if($showThumb)
                    <div class="col-sm-3 p-0 me-sm-4 mb-3 mb-sm-0 flex-shrink-0">
                        <img
                            class="img-fluid"
                            src="{{ $locationData->getThumb() }}"
                            alt="{{$locationData->name}}"
                        />
                    </div>
                @endif
                <div class="min-w-0 flex-grow-1 overflow-hidden">
                    <h2 class="h5 mb-1 text-body text-break">{{ $locationData->name }}</h2>
                    @if($allowReviews)
                        <div class="rating rating-sm small text-muted mb-1">
                            <x-igniter-orange::star-rating :score="$locationData->reviewsScore()">
                                <span class="small">({{ $locationData->reviewsCount() }})</span>
                            </x-igniter-orange::star-rating>
                        </div>
                    @endif
                    <div class="text-muted text-break">
                        {{ format_address($locationData->address, false) }}
                    </div>
                    @if($locationData->distance())
                        <div class="my-1">
                            <span
                                class="text-muted small"
                            ><i class="fa fa-map-marker"></i>&nbsp;&nbsp;{{ number_format($locationData->distance(), 1) }} {{ $distanceUnit }}</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-5 flex-shrink-0 ps-sm-2 mt-3 mt-sm-0">
            <div>
                @if($locationData->openingSchedule()->isOpen())
                    @lang('igniter.local::default.text_is_opened')
                @elseif($locationData->openingSchedule()->isOpening())
                    <span class="text-muted">{!! sprintf(lang('igniter.local::default.text_opening_time'), make_carbon($locationData->openingSchedule()->getOpenTime())->isoFormat(lang('igniter::system.moment.day_time_format_short'))) !!}</span>
                @else
                    <span class="text-muted">@lang('igniter.local::default.text_closed')</span>
                @endif
            </div>
            <div>
                @foreach($locationData->orderTypes() as $code => $orderType)
                    <div class="text-muted">
                        @if($orderType->isDisabled())
                            {!! $orderType->getDisabledDescription() !!}
                        @elseif($orderType->getSchedule()->isOpen())
                            {!! $orderType->getOpenDescription() !!}
                        @elseif($orderType->getSchedule()->isOpening())
                            {!! $orderType->getOpeningDescription(lang('igniter::system.moment.day_time_format_short')) !!}
                        @else
                            {!! $orderType->getClosedDescription() !!}
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</a>

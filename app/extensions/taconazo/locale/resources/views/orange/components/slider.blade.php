<div>
    <div
        id="slider-{{ $code }}"
        class="carousel slide carousel-fade"
        data-bs-ride="carousel"
    >
        @unless ($hideIndicators)
            <div class="carousel-indicators">
                @foreach ($slides as $slide)
                    <button
                        type="button"
                        class="{{ $loop->first ? 'active' : '' }}"
                        data-bs-target="#slider-{{ $code }}"
                        data-bs-slide-to="{{ $loop->index }}"
                    ></button>
                @endforeach
            </div>
        @endunless

        <div class="carousel-inner">
            @foreach ($slides as $slide)
                @php
                    $thumbSrc = orange_media_src((string) $slide->getThumb([
                        'width' => 1600,
                        'height' => 900,
                    ]));
                @endphp
                <div
                    class="carousel-item {{ $loop->first ? 'active' : '' }}"
                    style="max-height:{{ $height }};"
                >
                    <img
                        src="{{ $thumbSrc }}"
                        class="d-block w-100"
                        alt="{{ $slide->getCustomProperty('title') }}"
                    />

                    @if (!$hideCaptions && (strlen((string) $slide->getCustomProperty('title')) || strlen((string) $slide->getCustomProperty('description'))))
                        <div class="carousel-caption">
                            @if(strlen((string) $slide->getCustomProperty('title')))
                                <h5>{{ $slide->getCustomProperty('title') }}</h5>
                            @endif
                            @if(strlen((string) $slide->getCustomProperty('description')))
                                <p class="d-none d-md-block mb-0">{{ $slide->getCustomProperty('description') }}</p>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
        @if (!$hideControls && count($slides) > 1)
            <button
                type="button"
                class="carousel-control-prev"
                data-bs-target="#slider-{{ $code }}"
                data-bs-slide="prev"
            ><span class="carousel-control-prev-icon" aria-hidden="true"></span><span class="visually-hidden">Previous</span></button>
            <button
                type="button"
                class="carousel-control-next"
                data-bs-target="#slider-{{ $code }}"
                data-bs-slide="next"
            ><span class="carousel-control-next-icon" aria-hidden="true"></span><span class="visually-hidden">Next</span></button>
        @endif
    </div>
</div>

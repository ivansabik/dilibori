@php
    $thumbW = (int) ($menuThumbWidth ?? 180);
    $thumbH = (int) ($menuThumbHeight ?? 180);
    $rawThumb = $showThumb
        ? (string) $menuItemData->getThumb(['width' => $thumbW, 'height' => $thumbH])
        : '';
    $thumbSrc = $rawThumb !== '' ? orange_media_src($rawThumb) : '';
    $hasThumb = $thumbSrc !== '' && !str_starts_with($thumbSrc, 'data:');
@endphp
<div
    id="menu{{ $menuItemData->id }}"
    @class([
        'tz-menu-item h-100 p-3',
        'tz-menu-item-available cursor-pointer' => $menuItemData->mealtimeIsAvailable(),
        'cursor-no-drop' => !$menuItemData->mealtimeIsAvailable(),
    ])
    @if($menuItemData->mealtimeIsAvailable())
        @if($menuItemData->hasOptions())
            data-toggle="orange-modal"
            data-component="igniter-orange::cart-item-modal"
            data-arguments='{"menuId": {{ $menuItemData->id }}}'
        @else
            wire:click="$dispatch('cart-box:add-item', {menuId: {{ $menuItemData->id }}, quantity: {{ $menuItemData->minimumQuantity }}})"
            data-control="menu-item"
        @endif
    @endif
>
    @unless($showThumb)
    <button
        type="button"
        class="btn btn-outline-secondary rounded py-1 px-2 float-end tz-add-btn"
    >
        <i @class([
            'fa fa-plus' => $menuItemData->mealtimeIsAvailable(),
            'far fa-clock' => !$menuItemData->mealtimeIsAvailable()
        ])
           wire:loading.class="fa-spinner fa-spin"
        ></i>
    </button>
    @endunless
    <div class="d-flex flex-row align-items-start">
        <div class="menu-content flex-grow-1 me-3">
            <h6 class="menu-name fw-bold mb-1">{{ $menuItemData->name }}</h6>
            <p class="menu-desc text-muted mb-2 tz-menu-desc">
                {!! $menuItemData->description !!}
            </p>
            <div style="--bs-breadcrumb-divider: '·';">
                <div class="breadcrumb mb-0">
                    <div class="breadcrumb-item">
                        <span class="menu-price tz-price">
                            @if ($menuItemData->specialIsActive())
                                <s class="text-muted">{!! currency_format($menuItemData->priceBeforeSpecial) !!}</s>
                                <span class="tz-badge-special ms-1">@lang('taconazo.locale::default.badge_special')</span>
                            @endif
                            {!! $menuItemData->price() > 0 ? currency_format($menuItemData->price()) : lang('igniter::main.text_free') !!}
                        </span>
                    </div>
                    @if ($menuItemData->specialIsActive() && $menuItemData->specialDaysRemaining())
                        <div class="breadcrumb-item menu-meta">
                            <span class="text-warning">{!! sprintf(lang('igniter.local::default.text_end_elapsed'), $menuItemData->specialDaysRemaining()) !!}</span>
                        </div>
                    @endif
                    @if (!$menuItemData->mealtimeIsAvailable())
                        <div class="breadcrumb-item">
                            <i class="far fa-clock text-danger"></i>
                            <small class="text-danger">@lang('igniter.cart::default.mealtimes.text_available') {{ $menuItemData->mealtimeTitles() }}</small>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        @if($showThumb && $hasThumb)
            <div class="tz-menu-thumb-wrap position-relative flex-shrink-0">
                <img
                    class="tz-menu-thumb rounded"
                    src="{{ $thumbSrc }}"
                    alt="{{ $menuItemData->name }}"
                    width="{{ $thumbW }}"
                    height="{{ $thumbH }}"
                    loading="lazy"
                />
                @if($menuItemData->mealtimeIsAvailable())
                    <span class="tz-thumb-add" aria-hidden="true"><i class="fa fa-plus"></i></span>
                @endif
            </div>
        @endif
    </div>
    <div class="layout-scrollable w-100">
        @includeWhen($menuItemData->hasIngredients(), 'igniter-orange::includes.menu.ingredients', [
            'ingredients' => $menuItemData->ingredients()
        ])
    </div>
</div>

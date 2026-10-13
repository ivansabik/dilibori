@php
    use Igniter\Cart\Models\Menu;
    use Igniter\Local\Facades\Location as LocationFacade;

    $upsellNames = ['Horchata', 'Jamaica', 'Esquites', 'Guacamole'];
    $locationId = null;
    try {
        $current = LocationFacade::current();
        $locationId = $current?->getKey();
    } catch (\Throwable) {
    }
    $upsells = Menu::query()
        ->where('menu_status', 1)
        ->where(function ($q) use ($upsellNames) {
            foreach ($upsellNames as $i => $name) {
                $i === 0
                    ? $q->where('menu_name', 'like', '%'.$name.'%')
                    : $q->orWhere('menu_name', 'like', '%'.$name.'%');
            }
        })
        ->when($locationId, fn ($q) => $q->whereHas(
            'locations',
            fn ($lq) => $lq->where('locations.location_id', $locationId)
        ))
        ->orderBy('menu_priority')
        ->take(3)
        ->get()
        ->filter(fn ($m) => (int) $m->getKey() !== (int) $menuItemData->id);
@endphp
<div
    x-data="OrangeCartItem()"
    class="modal-dialog"
    data-control="cart-item"
    data-min-quantity="{{ $minQuantity }}"
    data-price-amount="{{ $price }}"
>
    <form method="POST" wire:submit="onSave">
        <div class="modal-content border-0">
            <div class="modal-header border-0">
                <h4 class="modal-title">{{ $menuItemData->name }}</h4>
                <button
                    type="button"
                    class="btn-close px-2"
                    data-bs-dismiss="modal"
                ></button>
            </div>
            @if ($showThumb)
                @php
                    $thumbSrc = orange_media_src((string) $menuItemData->getThumb([
                        'width' => $thumbWidth,
                        'height' => $thumbHeight,
                    ]));
                @endphp
                @if($thumbSrc !== '' && !str_starts_with($thumbSrc, 'data:'))
                    <div class="modal-top">
                        <img
                            class="img-fluid w-100"
                            src="{{ $thumbSrc }}"
                            alt="{{ $menuItemData->name }}"
                        />
                    </div>
                @endif
            @endif

            <div class="modal-body">
                @if (strlen($menuItemData->description))
                    <p class="text-muted tz-menu-desc">{!! $menuItemData->description !!}</p>
                @endif

                <input type="hidden" wire:model="menuId" />
                <input type="hidden" wire:model="rowId" />

                <div
                    id="menu-options"
                    class="menu-options"
                    x-ref="item-options"
                >
                    @include('igniter-orange::includes.cartbox.item-options')
                </div>
                <x-igniter-orange::forms.error field="menuOptions" class="text-danger mb-3"/>

                <div class="menu-comment">
                    <textarea
                        wire:model="comment"
                        name="comment"
                        class="form-control"
                        rows="2"
                        placeholder="@lang('igniter.cart::default.label_add_comment')"
                    >{{ $cartItem ? $cartItem->comment : null }}</textarea>
                    <x-igniter-orange::forms.error field="comment" class="text-danger"/>
                </div>

                @if($upsells->isNotEmpty())
                    <div class="tz-upsell mt-4">
                        <p class="small fw-bold mb-2">@lang('taconazo.locale::default.upsell_title')</p>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach($upsells as $upsell)
                                <button
                                    type="button"
                                    class="btn btn-sm tz-upsell-chip"
                                    wire:click="$dispatch('cart-box:add-item', {menuId: {{ $upsell->getKey() }}, quantity: 1})"
                                >
                                    {{ $upsell->menu_name }}
                                    <span class="tz-price">· {!! currency_format($upsell->menu_price) !!}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="modal-footer border-0">
                <div class="row g-0 w-100">
                    <div class="col-sm-5 pb-3 pb-sm-0">
                        <div class="input-group input-group-lg">
                            <button
                                x-on:click="decrementQuantity()"
                                class="btn btn-outline-secondary border-2 rounded-circle p-0"
                                type="button"
                                style="width:44px;height:44px;"
                            ><i class="fa fa-minus"></i></button>
                            <input
                                x-model="quantity"
                                type="text"
                                name="quantity"
                                class="form-control bg-transparent shadow-none border-0 text-center p-0"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                min="0"
                                readonly
                                autocomplete="off"
                                style="max-width:50px;"
                            >
                            <button
                                x-on:click="incrementQuantity()"
                                class="btn btn-outline-secondary border-2 rounded-circle p-0"
                                type="button"
                                style="width:44px;height:44px;"
                            ><i class="fa fa-plus fa-fw"></i></button>
                        </div>
                    </div>
                    <div class="col-sm-7 ps-sm-3">
                        <button type="submit" class="btn btn-primary btn-lg text-white w-100 tz-add-btn" data-attach-loading>
                            <div class="d-flex align-items-center">
                                <div class="col text-nowrap">{!! $cartItem
                                    ? lang('igniter.cart::default.button_update')
                                    : lang('igniter.cart::default.button_add_to_order')
                                !!}</div>
                                <div class="col text-end fw-normal fs-6" x-text="total"></div>
                            </div>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

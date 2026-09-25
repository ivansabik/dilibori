<?php

declare(strict_types=1);

return [
    'text_side_menu' => 'Sliders y banners',

    'banners' => [
        'text_tab_general' => 'General',
        'text_edit_banner' => 'Editar banner',
        'text_title' => 'Banners',
        'text_form_name' => 'Banner',
        'text_filter_search' => 'Buscar por nombre, tipo o estado.',
        'text_empty' => 'No hay banners disponibles.',
        'text_image' => 'Imagen',
        'text_custom' => 'Personalizado',

        'column_banner' => 'Banner',
        'column_dimension' => 'Dimensión (An x Al)',
        'column_layout_partial' => 'Diseño - área parcial',
        'column_status' => 'Estado',
        'column_id' => 'ID',

        'label_banner' => 'Banner',
        'label_dimension' => 'Dimensión (An x Al)',
        'label_width' => 'Ancho',
        'label_height' => 'Alto',
        'label_status' => 'Estado',
        'label_layout_partial' => 'Diseño - área parcial',
        'label_type' => 'Tipo',
        'label_click_url' => 'URL al hacer clic',
        'label_language' => 'Idioma',
        'label_alt_text' => 'Texto alternativo',
        'label_image' => 'Imagen',
        'label_custom_code' => 'Código personalizado',

        'button_banners' => 'Agregar banner nuevo',

        'help_layouts' => 'Elija un diseño para agregar uno o más banners.',
        'help_image' => 'Elija varias imágenes para mostrar el banner como carrusel',
        'help_click_url' => 'Puede usar una URL relativa o absoluta del sitio',

        'alert_set_banners' => 'Primero debe agregar el módulo de banners a uno o más diseños',
    ],

    'slider' => [
        'text_title' => 'Sliders',
        'text_tab_general' => 'General',
        'text_form_name' => 'Slider',
        'text_empty' => 'No hay sliders disponibles.',

        'text_tab_slides' => 'Diapositivas',

        'column_updated_at' => 'Actualizado',

        'label_code' => 'El código único del slider a mostrar.',
        'label_slider' => 'Código del slider',
        'label_effect' => 'El efecto al pasar entre diapositivas.',
        'label_interval' => 'El intervalo de retardo entre diapositivas en milisegundos.',
        'label_caption' => 'Leyenda',
        'label_images' => 'Imágenes',
        'label_hide_controls' => 'Ocultar los controles del slider.',
        'label_hide_indicators' => 'Ocultar los indicadores del slider.',
        'label_hide_captions' => 'Ocultar las leyendas del slider.',
    ],

    'featured' => [
        'text_tab_general' => 'General',
        'text_featured_menus' => 'Menú destacado',

        'column_menu_name' => 'Nombre del platillo',
        'column_menu_remove' => 'Quitar',

        'label_title' => 'Título',
        'label_menus' => 'Platillos',
        'label_limit' => 'Límite',
        'label_items_per_row' => 'Platillos por fila',
        'label_dimension' => 'Dimensión:',
        'label_dimension_w' => 'Ancho de dimensión',
        'label_dimension_h' => 'Alto de dimensión',

        'help_dimension' => '(Ancho x Alto)',
        'help_items_per_row' => 'Cantidad de platillos a mostrar por fila',
    ],

    'captcha' => [
        'label_api_site_key' => 'Clave del sitio',
        'label_api_secret_key' => 'Clave secreta',
        'label_version' => 'Versión',
        'label_version_v2' => 'reCAPTCHA v2',
        'label_version_invisible' => 'reCAPTCHA v2 Invisible',
        'label_version_v3' => 'reCAPTCHA v3',
        'label_lang' => 'Idioma',

        'error_recaptcha' => '¡Confirma que eres humano!',

        'help_lang' => 'Fuerza al widget a mostrarse en un idioma específico. Auto detecta el idioma del usuario si no se indica.',
    ],
];

<?php

declare(strict_types=1);

return [
    'text_title' => 'Pagos',
    'text_side_menu' => 'Pasarelas de pago',
    'text_form_name' => 'Pago',
    'text_tab_config' => 'Configuración',
    'text_filter_search' => 'Buscar por nombre o descripción.',
    'text_empty' => 'No hay pagos disponibles.',
    'text_this_payment' => 'este método de pago',
    'text_save_card_profile' => 'Guardar datos de la tarjeta para después.',
    'text_refund_title' => 'Reembolso: %s',
    'text_refund_full' => 'Reembolso total',
    'text_refund_partial' => 'Reembolso parcial',
    'text_payment_logs' => 'Intentos de pago',
    'text_permission_group' => 'Pago',

    'label_payments' => 'Pasarelas de pago',
    'label_code' => 'Código único',
    'label_default' => 'Es predeterminado',
    'label_priority' => 'Prioridad',
    'label_order_fee_type' => 'Tipo de cargo adicional',
    'label_order_fee' => 'Cargo adicional',
    'label_order_total' => 'Monto mínimo del pedido',
    'label_order_status' => 'Estado del pedido',
    'label_capture_status' => 'Estado del pedido para capturar el pago',
    'label_refund_type' => 'Tipo de reembolso',
    'label_refund_amount' => 'Monto parcial del reembolso',
    'label_refund_reason' => 'Motivo del reembolso',

    'onboarding_payments' => 'Configure cómo recibe pagos',
    'help_onboarding_payments' => 'Habilite y configure al menos un método de pago para empezar a aceptar pedidos, en línea o fuera de línea.',

    'button_delete_card' => 'Eliminar y usar otra tarjeta.',
    'button_refund' => 'Reembolsar',

    'alert_min_total' => 'El total del pedido está por debajo del mínimo para %s.',
    'alert_min_order_total' => 'Debes gastar %s o más para pagar con %s.',
    'alert_order_fee' => 'Hay un cargo adicional de %s cuando pagas con %s.',
    'alert_missing_applicable_fee' => 'Falta el cargo adicional para el pago %s.',
    'alert_set_default' => 'Pago establecido como predeterminado',
    'alert_setting_missing_id' => 'No se especificó el código de configuración de la extensión.',
    'alert_invalid_code' => 'Código de pasarela de pago no válido.',
    'alert_code_not_found' => 'No se encontró la pasarela de pago con código %s',
    'alert_refund_success' => 'Pago reembolsado correctamente',

    'help_order_total' => 'Monto total que debe alcanzar el pedido antes de que esta pasarela se active',
    'help_order_fee' => 'Cargo extra al total del pedido cuando esta pasarela se activa',
    'help_order_status' => 'Estado de pedido predeterminado al usar este método. Déjelo en blanco para usar el de la configuración de pedidos (Administrar > Configuración > Pedido).',
    'help_capture_status' => 'Estado del pedido que captura un pago autorizado.',

    'help_permission' => 'Administrar pasarelas de pago',
    'help_payments' => 'Elija los pagos disponibles en esta sucursal. Déjelo en blanco para usar todos los pagos habilitados',
    'help_no_payments' => 'No se encontró ningún pago habilitado.',

    'cod' => [
        'text_tab_general' => 'General',
        'text_payment_title' => 'Pago contra entrega',
        'text_payment_desc' => 'Paga en efectivo cuando recojas tu pedido o cuando te lo lleven a domicilio',

        'label_title' => 'Título',
        'label_status' => 'Estado',
        'label_priority' => 'Prioridad',
    ],

    'paypal' => [
        'text_tab_general' => 'General',
        'text_payment_title' => 'PayPal Express',
        'text_payment_desc' => 'Paga de forma segura con tu cuenta de PayPal',

        'text_sandbox' => 'Sandbox',
        'text_go_live' => 'Producción',
        'text_sale' => 'SALE',
        'text_authorization' => 'AUTHORIZATION',

        'label_title' => 'Título',
        'label_api_user' => 'Client ID',
        'label_api_pass' => 'Client Secret',
        'label_api_sandbox_user' => 'Sandbox Client ID',
        'label_api_sandbox_pass' => 'Sandbox Client Secret',
        'label_api_mode' => 'Modo',
        'label_api_action' => 'Acción de pago',
        'label_priority' => 'Prioridad',
        'label_status' => 'Estado',

        'alert_error_server' => '<p class="alert-danger">Ocurrió un error, inténtalo de nuevo</p>',
    ],

    'authorize_net_aim' => [
        'text_payment_title' => 'Authorize.Net (AIM)',
        'text_payment_desc' => 'Paga con tu tarjeta de crédito vía Authorize.Net',
        'text_go_live' => 'Producción',
        'text_test' => 'Prueba',
        'text_test_live' => 'Prueba en vivo',
        'text_sale' => 'SALE',
        'text_auth_only' => 'Solo autorización',
        'text_auth_capture' => 'Autorización y captura',
        'text_visa' => 'Visa',
        'text_mastercard' => 'MasterCard',
        'text_american_express' => 'American Express',
        'text_jcb' => 'JCB',
        'text_diners_club' => 'Diners Club',

        'label_title' => 'Título',
        'label_api_login_id' => 'API Login ID',
        'label_client_key' => 'Client Key',
        'label_transaction_key' => 'Transaction Key',
        'label_transaction_mode' => 'Modo de transacción',
        'label_transaction_type' => 'Tipo de transacción',
        'label_priority' => 'Prioridad',
        'label_status' => 'Estado',

        'alert_acceptable_cards' => 'Solo aceptamos %s',
    ],

    'stripe' => [
        'text_tab_general' => 'General',
        'text_payment_title' => 'Pago con Stripe',
        'text_payment_desc' => 'Paga con tu tarjeta de crédito usando Stripe',
        'text_credit_or_debit' => 'Tarjeta de crédito o débito',

        'text_auth_only' => 'Solo autorización',
        'text_auth_capture' => 'Autorización y captura',
        'text_description' => 'Pagar con tarjeta de crédito usando Stripe',
        'text_live' => 'Producción',
        'text_test' => 'Prueba',
        'text_stripe_charge_description' => 'Cargo de %s por %s',
        'text_payment_status' => 'Pago %s (%s)',

        'label_title' => 'Título',
        'label_description' => 'Descripción',
        'label_transaction_mode' => 'Modo de transacción',
        'label_transaction_type' => 'Tipo de transacción',
        'label_test_secret_key' => 'Clave secreta de prueba',
        'label_test_publishable_key' => 'Clave pública de prueba',
        'label_live_secret_key' => 'Clave secreta de producción',
        'label_live_publishable_key' => 'Clave pública de producción',
        'label_test_webhook_secret' => 'Secreto de webhook de prueba',
        'label_live_webhook_secret' => 'Secreto de webhook de producción',
        'label_locale_code' => 'Código de locale',
        'label_priority' => 'Prioridad',
        'label_status' => 'Estado',
        'label_offsite_enabled' => 'Modo externo (Stripe Checkout)',
        'help_offsite_enabled' => 'Al activar el modo externo, los clientes se redirigen a una página de pago alojada por Stripe.',

        'help_locale_code' => 'Consulte <a href="https://stripe.com/docs/js/appendix/supported_locales">locales admitidos de Stripe.js</a',
    ],

    'mollie' => [
        'text_payment_title' => 'Pago con Mollie',
        'text_payment_desc' => 'Paga con tu tarjeta de crédito a través de Mollie',

        'text_live' => 'Producción',
        'text_test' => 'Prueba',
        'text_description' => 'Pagar con tarjeta de crédito usando Mollie',
        'text_payment_status' => 'Pago %s (%s)',

        'label_transaction_mode' => 'Modo de transacción',
        'label_test_api_key' => 'API Key de prueba',
        'label_live_api_key' => 'API Key de producción',
    ],

    'square' => [
        'text_payment_title' => 'Pago con Square',
        'text_payment_desc' => 'Paga con tu tarjeta de crédito usando Square',

        'text_description' => 'Pagar con tarjeta de crédito usando Square',
        'text_live' => 'Producción',
        'text_test' => 'Prueba',
        'text_payment_status' => 'Pago %s (%s)',

        'label_title' => 'Título',
        'label_description' => 'Descripción',
        'label_transaction_mode' => 'Modo de transacción',
        'label_test_app_id' => 'Application ID de prueba',
        'label_test_access_token' => 'Access Token de prueba',
        'label_test_location_id' => 'ID de sucursal de prueba',
        'label_live_app_id' => 'Application ID de producción',
        'label_live_access_token' => 'Access Token de producción',
        'label_live_location_id' => 'ID de sucursal de producción',

        'help_square' => 'Obtenga las claves API de Square <a href="https://developer.squareup.com">aquí</a>',
    ],
];

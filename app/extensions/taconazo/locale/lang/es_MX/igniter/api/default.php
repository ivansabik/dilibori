<?php

declare(strict_types=1);

return [
    'search_prompt' => 'Buscar nombre de API',
    'search_tokens_prompt' => 'Buscar tokens',

    'text_tab_general' => 'General',
    'text_tokens_title' => 'Tokens de acceso',
    'text_token_type_staff' => 'Usuario',
    'text_token_type_customer' => 'Cliente',
    'text_guest' => 'Invitados',
    'text_admin' => 'Usuario',
    'text_customer' => 'Clientes',
    'text_admin_customer' => 'Usuario o clientes',
    'text_all' => 'Todos',
    'text_allow_only' => 'Permitir solo',

    'button_tokens' => '<i class="fa fa-key"></i>&nbsp;&nbsp;Tokens de acceso',

    'column_api_name' => 'Nombre de la API',
    'column_base_endpoint' => 'Endpoint base',
    'column_description' => 'Descripción',

    'column_issued_to' => 'Emitido a',
    'column_abilities' => 'Permisos',
    'column_token_type' => 'Tipo',
    'column_device_name' => 'Nombre del dispositivo',
    'column_created' => 'Creado el',
    'column_lastused' => 'Último uso',

    'label_api_name' => 'Nombre de la API',
    'label_base_endpoint' => 'Endpoint base',
    'label_description' => 'Descripción',
    'label_api_name_comment' => 'Nombre del recurso de su API',
    'label_description_comment' => 'Describa el recurso de su API',
    'label_base_endpoint_comment' => 'https://example.com/api/<b>endpoint</b>',

    'label_controller' => 'Controlador',
    'label_actions' => 'Acciones',
    'help_actions' => 'Deje en blanco para desactivar el endpoint.',
    'label_require_authorization' => 'Requerir autorización',
    'label_setup' => 'Documentación de referencia',

    'actions' => [
        'text_index' => 'Listar todos los recursos (GET)',
        'text_show' => 'Mostrar un recurso (GET)',
        'text_store' => 'Crear un recurso (POST)',
        'text_update' => 'Actualizar un recurso (PUT/PATCH)',
        'text_destroy' => 'Eliminar un recurso (DELETE)',
    ],

    'alert_auth_failed' => 'No se proporcionó un token de API válido.',
    'alert_auth_restricted' => 'El token de API no tiene permisos para realizar la solicitud.',
    'alert_token_restricted' => 'El token de API no tiene los permisos necesarios para esta acción',
    'alert_validation_failed' => 'No se pudieron validar los parámetros de la solicitud',

    'orders' => [
        'label_customer_id' => 'ID de cliente',
    ],

    'addresses' => [
        'label_customer_id' => 'ID de cliente',
    ],
];

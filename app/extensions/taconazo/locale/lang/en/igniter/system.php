<?php

declare(strict_types=1);

/**
 * English de-brand overrides for upstream system strings.
 * Merges over vendor keys via array_replace_recursive.
 */
return [
    'system_name' => 'System',
    'system_powered' => 'Powered by the system',
    'copyright' => 'Thank you for using the system',

    'languages' => [
        'help_publish_translations' => 'Want to share your translations? You can upload them to the <a href="%s" target="_blank">translation community project</a>, where they will be reviewed by a core team member.',
    ],

    'permissions' => [
        'updates' => 'Ability to apply updates when a new version of the system is available',
    ],

    'settings' => [
        'text_test_email_message' => 'This is a test email. If you have received this, it means emails are working in the system.',
        'help_use_mailer_config_file' => 'The mailer configuration file stores your mail settings; changes made here will not take effect. For more information, see the <a href="%s" target="_blank">Mail Configuration</a> section of the system documentation.',
    ],

    'themes' => [
        'help_components' => 'Render the component on a layout or page by adding <code>@themeComponent(&#x27;componentAlias&#x27;)</code> or <code>&#x3C;livewire:component-code /&#x3E;</code> to the markup.',
    ],

    'updates' => [
        'text_search' => 'Search to install %s from the marketplace',
        'error_meta_package' => 'The package is not compatible with this system version.',
        'help_carte_key' => 'A Carte key is required to add and update items from the marketplace. Attach a key if marketplace installs are enabled for this site.',
    ],
];

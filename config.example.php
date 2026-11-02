<?php
/** Copy to config.php locally. Provide secrets through your deployment environment. */
return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'name' => getenv('DB_NAME') ?: 'commerce_assistant',
        'user' => getenv('DB_USER') ?: '',
        'pass' => getenv('DB_PASSWORD') ?: '',
    ],
    'console' => ['password' => getenv('CONSOLE_PASSWORD') ?: ''],
    'wa' => [
        'token' => getenv('WHATSAPP_ACCESS_TOKEN') ?: '',
        'phone_number_id' => getenv('WHATSAPP_PHONE_NUMBER_ID') ?: '',
        'business_number' => getenv('WHATSAPP_BUSINESS_NUMBER') ?: '',
        'waba_id' => getenv('WHATSAPP_BUSINESS_ACCOUNT_ID') ?: '',
        'app_secret' => getenv('META_APP_SECRET') ?: '',
        'verify_token' => getenv('WHATSAPP_VERIFY_TOKEN') ?: '',
    ],
    'owner' => [
        'wa' => getenv('OWNER_WHATSAPP_NUMBER') ?: '',
        'notification_template' => 'owner_workspace_alert',
        'notification_language' => 'en',
    ],
    'woo' => [
        'url' => getenv('WOOCOMMERCE_URL') ?: 'https://shop.example.com',
        'key' => getenv('WOOCOMMERCE_KEY') ?: '',
        'secret' => getenv('WOOCOMMERCE_SECRET') ?: '',
    ],
    'llm' => [
        'provider' => getenv('LLM_PROVIDER') ?: 'openai',
        'model' => getenv('LLM_MODEL') ?: 'gpt-4.1-mini',
        'key' => getenv('LLM_API_KEY') ?: '',
        'base' => '',
        'embed' => [
            'provider' => 'openai', 'model' => 'text-embedding-3-small',
            'key' => getenv('EMBEDDING_API_KEY') ?: '', 'base' => '',
        ],
    ],
    'app' => ['timezone' => 'Asia/Dhaka'],
    'rules' => [
        'timezone' => 'Asia/Dhaka', 'office_start' => '10:00',
        'office_end' => '18:00', 'weekend' => [5],
        'stock_office_start' => '10:00', 'stock_office_end' => '18:00',
        'stock_valid_days' => 3, 'owner_reply_wait' => 30,
        'catalog_cache_min' => 5, 'knowledge_min_sim' => 0.88,
        'dry_run' => true,
    ],
    'media' => ['image_hosts' => []],
];

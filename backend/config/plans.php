<?php

return [
    'trial_days' => 14,
    'plans' => [
        'trial' => [
            'name' => 'Testversion',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'max_workers' => 10,
            'max_customers' => 20,
            'max_users' => 3,
            'max_objects' => 30,
            'features' => ['disposition', 'orders', 'worker_app', 'messages', 'reports_basic'],
        ],
        'basic' => [
            'name' => 'Basic',
            'price_monthly' => 49,
            'price_yearly' => 470,
            'max_workers' => 25,
            'max_customers' => 50,
            'max_users' => 5,
            'max_objects' => 75,
            'features' => ['disposition', 'orders', 'worker_app', 'messages', 'reports_basic', 'documents', 'subcontractors'],
        ],
        'pro' => [
            'name' => 'Professional',
            'price_monthly' => 99,
            'price_yearly' => 950,
            'max_workers' => 100,
            'max_customers' => 200,
            'max_users' => 15,
            'max_objects' => 300,
            'features' => ['disposition', 'orders', 'worker_app', 'messages', 'reports_basic', 'reports_advanced', 'documents', 'subcontractors', 'nu_portal', 'price_history', 'custom_branding'],
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'price_monthly' => 199,
            'price_yearly' => 1900,
            'max_workers' => -1,
            'max_customers' => -1,
            'max_users' => -1,
            'max_objects' => -1,
            'features' => ['disposition', 'orders', 'worker_app', 'messages', 'reports_basic', 'reports_advanced', 'documents', 'subcontractors', 'nu_portal', 'price_history', 'custom_branding', 'api_access', 'priority_support', 'white_label'],
        ],
    ],
];

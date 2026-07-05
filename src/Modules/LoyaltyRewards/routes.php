<?php

return [
    ['method' => 'GET', 'path' => '/api/admin/loyalty/dashboard', 'handler' => 'App\\Modules\\LoyaltyRewards\\Controllers\\LoyaltyController@dashboard', 'capability' => 'loyalty.admin'],
    ['method' => 'GET', 'path' => '/api/admin/loyalty/customers', 'handler' => 'App\\Modules\\LoyaltyRewards\\Controllers\\LoyaltyController@customers', 'capability' => 'loyalty.admin'],
    ['method' => 'GET', 'path' => '/api/admin/loyalty/customers/{memberId}', 'handler' => 'App\\Modules\\LoyaltyRewards\\Controllers\\LoyaltyController@customerDetail', 'capability' => 'loyalty.admin'],
    ['method' => 'GET', 'path' => '/api/admin/loyalty/rewards', 'handler' => 'App\\Modules\\LoyaltyRewards\\Controllers\\LoyaltyController@rewards', 'capability' => 'loyalty.admin'],
    ['method' => 'POST', 'path' => '/api/admin/loyalty/rewards', 'handler' => 'App\\Modules\\LoyaltyRewards\\Controllers\\LoyaltyController@createReward', 'capability' => 'loyalty.admin'],
    ['method' => 'POST', 'path' => '/api/admin/loyalty/redemptions', 'handler' => 'App\\Modules\\LoyaltyRewards\\Controllers\\LoyaltyController@redeemReward', 'capability' => 'loyalty.admin'],
    ['method' => 'POST', 'path' => '/api/admin/loyalty/purchases', 'handler' => 'App\\Modules\\LoyaltyRewards\\Controllers\\LoyaltyController@registerPurchase', 'capability' => 'loyalty.admin'],
    ['method' => 'PATCH', 'path' => '/api/admin/loyalty/members/{memberId}/wallet', 'handler' => 'App\\Modules\\LoyaltyRewards\\Controllers\\LoyaltyController@updateWallet', 'capability' => 'loyalty.admin'],
    ['method' => 'GET', 'path' => '/api/admin/loyalty/members/{memberId}/pass-preview', 'handler' => 'App\\Modules\\LoyaltyRewards\\Controllers\\LoyaltyController@passPreview', 'capability' => 'loyalty.admin'],
    ['method' => 'POST', 'path' => '/api/admin/loyalty/wallet/google-link', 'handler' => 'App\\Modules\\LoyaltyRewards\\Controllers\\LoyaltyController@googleWalletLink', 'capability' => 'loyalty.admin'],
];

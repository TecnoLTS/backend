<?php

return [
    ['method' => 'GET', 'path' => '/api/products', 'handler' => 'App\\Modules\\CatalogInventory\\Controllers\\ProductController@index', 'capability' => 'catalog.public'],
    ['method' => 'GET', 'path' => '/api/products/{id}/reviews', 'handler' => 'App\\Modules\\CatalogInventory\\Controllers\\ProductReviewController@indexForProduct', 'capability' => 'catalog.reviews'],
    ['method' => 'POST', 'path' => '/api/products/{id}/reviews', 'handler' => 'App\\Modules\\CatalogInventory\\Controllers\\ProductReviewController@storeForProduct', 'capability' => 'catalog.reviews'],
    ['method' => 'GET', 'path' => '/api/products/{id}/movement', 'handler' => 'App\\Modules\\CatalogInventory\\Controllers\\ProductController@movement', 'capability' => 'catalog.public'],
    ['method' => 'GET', 'path' => '/api/products/{id}', 'handler' => 'App\\Modules\\CatalogInventory\\Controllers\\ProductController@show', 'capability' => 'catalog.public'],
    ['method' => 'POST', 'path' => '/api/products', 'handler' => 'App\\Modules\\CatalogInventory\\Controllers\\ProductController@store', 'capability' => 'catalog.admin'],
    ['method' => 'PUT', 'path' => '/api/products/{id}', 'handler' => 'App\\Modules\\CatalogInventory\\Controllers\\ProductController@update', 'capability' => 'catalog.admin'],
    ['method' => 'DELETE', 'path' => '/api/products/{id}', 'handler' => 'App\\Modules\\CatalogInventory\\Controllers\\ProductController@destroy', 'capability' => 'catalog.admin'],
    ['method' => 'GET', 'path' => '/api/admin/reviews', 'handler' => 'App\\Modules\\CatalogInventory\\Controllers\\ProductReviewController@adminIndex', 'capability' => 'catalog.reviews.admin'],
    ['method' => 'PATCH', 'path' => '/api/admin/reviews/{id}', 'handler' => 'App\\Modules\\CatalogInventory\\Controllers\\ProductReviewController@adminUpdate', 'capability' => 'catalog.reviews.admin'],
    ['method' => 'GET', 'path' => '/api/admin/purchase-invoices', 'handler' => 'App\\Modules\\CatalogInventory\\Controllers\\PurchaseInvoiceController@index', 'capability' => 'admin.procurement'],
    ['method' => 'GET', 'path' => '/api/admin/purchase-invoices/{id}', 'handler' => 'App\\Modules\\CatalogInventory\\Controllers\\PurchaseInvoiceController@show', 'capability' => 'admin.procurement'],
    ['method' => 'GET', 'path' => '/api/admin/settings/product-reference-data', 'handler' => 'App\\Modules\\CatalogInventory\\Controllers\\ProductReferenceDataController@getProductReferenceData', 'capability' => 'admin.settings'],
    ['method' => 'PUT', 'path' => '/api/admin/settings/product-reference-data', 'handler' => 'App\\Modules\\CatalogInventory\\Controllers\\ProductReferenceDataController@updateProductReferenceData', 'capability' => 'admin.settings'],
];

<?php

return [
    ['method' => 'GET', 'path' => '/api/admin/mailer/health', 'handler' => 'App\\Modules\\Mailer\\Controllers\\MailerController@health', 'capability' => 'system.health'],
    ['method' => 'GET', 'path' => '/api/admin/mailer/outbox', 'handler' => 'App\\Modules\\Mailer\\Controllers\\MailerController@outbox', 'capability' => 'mail.service'],
    ['method' => 'GET', 'path' => '/api/admin/mailer/delivery-log', 'handler' => 'App\\Modules\\Mailer\\Controllers\\MailerController@deliveryLog', 'capability' => 'mail.service'],
];

<?php

namespace App\Modules\Commerce\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Modules\Commerce\Infrastructure\CommerceCollaborationPortsFactory;
use App\Repositories\OrderRepository;

class ShippingController {
    private function normalizeProviders($rawProviders) {
        if (!is_array($rawProviders)) return [];
        $providers = [];
        foreach ($rawProviders as $idx => $provider) {
            if (!is_array($provider)) continue;
            $name = trim((string)($provider['name'] ?? ''));
            if ($name === '') continue;
            $providers[] = [
                'id' => $provider['id'] ?? ($idx + 1),
                'name' => $name,
                'status' => trim((string)($provider['status'] ?? 'Activo')) ?: 'Activo'
            ];
        }
        return $providers;
    }

    private function parseAddress($raw) {
        if (!$raw) return [];
        if (is_array($raw)) return $raw;
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function index() {
        Auth::requireAdmin();
        $providersRaw = CommerceCollaborationPortsFactory::settings()->get('shipping_providers');
        $providers = [];
        if ($providersRaw) {
            $decoded = json_decode($providersRaw, true);
            $providers = $this->normalizeProviders($decoded);
        }

        if (count($providers) === 0) {
            $providers = [
                ['id' => 1, 'name' => 'Servientrega', 'status' => 'Activo'],
                ['id' => 2, 'name' => 'DHL Express', 'status' => 'Activo']
            ];
        }

        $orderRepo = new OrderRepository();
        $pickupOrders = $orderRepo->getPickupQueue(12);
        $pickups = [];
        foreach ($pickupOrders as $order) {
            $shippingAddress = $this->parseAddress($order['shipping_address'] ?? null);
            $window = trim((string)($shippingAddress['pickupWindow'] ?? '')) ?: null;
            $provider = trim((string)($shippingAddress['provider'] ?? '')) ?: 'Retiro en tienda';
            $pickups[] = [
                'id' => $order['id'],
                'provider' => $provider,
                'status' => $order['status'] ?? 'ready',
                'scheduled_at' => $order['created_at'] ?? null,
                'reference' => $order['id'],
                'order_id' => $order['id'],
                'window' => $window,
                'notes' => $order['user_name'] ? ('Cliente: ' . $order['user_name']) : null
            ];
        }

        Response::json([
            'providers' => $providers,
            'pickups' => $pickups
        ]);
    }
}

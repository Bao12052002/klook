<?php
// Klook API configuration

class KlookConfig {
    const BASE_URL = 'https://partner-api.klook.com/v1/';
    const SANDBOX_URL = 'https://partner-api-sandbox.klook.com/v1/';
    
    public static function getConfig() {
        return [
            'base_url' => $_ENV['KLOOK_ENV'] === 'production' ? self::BASE_URL : self::SANDBOX_URL,
            'api_key' => $_ENV['KLOOK_API_KEY'] ?? '',
            'secret_key' => $_ENV['KLOOK_SECRET_KEY'] ?? '',
            'supplier_id' => $_ENV['KLOOK_SUPPLIER_ID'] ?? '',
            'timeout' => 30,
            'retry_attempts' => 3
        ];
    }
}
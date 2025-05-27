<?php
// HTTP client
class HttpClient {
    private $timeout;
    private $retryAttempts;
    
    public function __construct($timeout = 30, $retryAttempts = 3) {
        $this->timeout = $timeout;
        $this->retryAttempts = $retryAttempts;
    }
    
    public function request($method, $url, $options = []) {
        $attempt = 0;
        
        while ($attempt < $this->retryAttempts) {
            try {
                return $this->makeRequest($method, $url, $options);
            } catch (Exception $e) {
                $attempt++;
                if ($attempt >= $this->retryAttempts) {
                    throw $e;
                }
                sleep(pow(2, $attempt)); // Exponential backoff
            }
        }
    }
    
    private function makeRequest($method, $url, $options) {
        $ch = curl_init();
        
        $defaultOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $options['timeout'] ?? $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3
        ];
        
        // Set headers
        if (isset($options['headers'])) {
            $headers = [];
            foreach ($options['headers'] as $key => $value) {
                $headers[] = "$key: $value";
            }
            $defaultOptions[CURLOPT_HTTPHEADER] = $headers;
        }
        
        // Set JSON data
        if (isset($options['json']) && $options['json']) {
            $defaultOptions[CURLOPT_POSTFIELDS] = json_encode($options['json']);
        }
        
        curl_setopt_array($ch, $defaultOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            throw new Exception("HTTP request failed: $error");
        }
        
        if ($httpCode >= 400) {
            throw new Exception("HTTP error $httpCode: $response");
        }
        
        return $response;
    }
}
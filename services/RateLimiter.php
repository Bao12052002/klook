<?php
// Rate limiter

class RateLimiter {
    private $maxRequests = 100;
    private $timeWindow = 3600; // 1 hour
    private $cacheFile;
    
    public function __construct() {
        $this->cacheFile = 'cache/rate_limit.json';
        
        // Create cache directory if not exists
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    
    public function checkLimit() {
        $now = time();
        $data = $this->loadData();
        
        // Clean old entries
        $data = array_filter($data, function($timestamp) use ($now) {
            return ($now - $timestamp) < $this->timeWindow;
        });
        
        if (count($data) >= $this->maxRequests) {
            throw new Exception('Rate limit exceeded');
        }
        
        // Add current request
        $data[] = $now;
        $this->saveData($data);
    }
    
    private function loadData() {
        if (!file_exists($this->cacheFile)) {
            return [];
        }
        
        $content = file_get_contents($this->cacheFile);
        return json_decode($content, true) ?: [];
    }
    
    private function saveData($data) {
        file_put_contents($this->cacheFile, json_encode($data), LOCK_EX);
    }
}
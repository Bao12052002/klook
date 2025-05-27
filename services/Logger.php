<?php
// Logger service

class Logger {
    private $logFile;
    
    public function __construct($logFile = 'logs/klook.log') {
        $this->logFile = $logFile;
        
        // Create logs directory if not exists
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    
    public function info($message, $context = []) {
        $this->log('INFO', $message, $context);
    }
    
    public function error($message, $context = []) {
        $this->log('ERROR', $message, $context);
    }
    
    public function warning($message, $context = []) {
        $this->log('WARNING', $message, $context);
    }
    
    private function log($level, $message, $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = $context ? ' ' . json_encode($context) : '';
        $logLine = "[$timestamp] [$level] $message$contextStr" . PHP_EOL;
        
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
}
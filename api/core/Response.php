<?php
class Response {
    private static $instance = null;
    private $statusCode = 200;
    private $headers = [];
    private $body = [];
    private $cookies = [];
    private $sent = false;

    private function __construct() {
        $this->setDefaultHeaders();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function setDefaultHeaders() {
        $this->headers['Content-Type'] = 'application/json; charset=UTF-8';
        
        // CORS headers
        $allowedOrigins = Environment::getAllowedOrigins();

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        // Handle empty origin - use first allowed origin as fallback
        if (empty($origin) && !empty($allowedOrigins)) {
            $origin = $allowedOrigins[0];
        }
        
        if (in_array($origin, $allowedOrigins)) {
            $this->headers['Access-Control-Allow-Origin'] = $origin;
        } else {
            $this->headers['Access-Control-Allow-Origin'] = $allowedOrigins[0];
        }

        $this->headers['Access-Control-Allow-Methods'] = 'GET, POST, PUT, DELETE, OPTIONS';
        $this->headers['Access-Control-Allow-Headers'] = 'Content-Type, Authorization, X-Requested-With';
        $this->headers['Access-Control-Allow-Credentials'] = 'true';
        $this->headers['Access-Control-Max-Age'] = '3600';
    }

    public function setStatusCode($code) {
        $this->statusCode = (int)$code;
        return $this;
    }

    public function setHeader($name, $value) {
        $this->headers[$name] = $value;
        return $this;
    }

    public function setCookie($name, $value, $expire = 0, $path = '/', $domain = '', $secure = false, $httponly = true) {
        $this->cookies[] = [
            'name' => $name,
            'value' => $value,
            'expire' => $expire,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly
        ];
        return $this;
    }

    public function json($data, $message = null, $success = true) {
        $response = [
            'success' => $success,
            'message' => $message,
            'timestamp' => time(),
            'data' => $data
        ];

        // Remove data if empty
        if (empty($data)) {
            unset($response['data']);
        }

        $this->body = $response;
        return $this;
    }

    public function success($data = [], $message = "Success") {
        return $this->json($data, $message, true);
    }

    public function error($message = "Error", $code = 400, $errorCode = null) {
        $response = [
            'success' => false,
            'message' => $message,
            'timestamp' => time()
        ];

        if ($errorCode) {
            $response['error_code'] = $errorCode;
        }

        $this->body = $response;
        $this->setStatusCode($code);
        return $this;
    }

    public function redirect($url, $permanent = false) {
        $this->setStatusCode($permanent ? 301 : 302);
        $this->setHeader('Location', $url);
        return $this;
    }

    public function download($filePath, $fileName = null) {
        if (!file_exists($filePath)) {
            return $this->error('File not found', 404);
        }

        if ($fileName === null) {
            $fileName = basename($filePath);
        }

        $this->setHeader('Content-Type', 'application/octet-stream');
        $this->setHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        $this->setHeader('Content-Length', filesize($filePath));
        $this->body = file_get_contents($filePath);
        
        return $this;
    }

    public function send() {
        if ($this->sent) {
            return;
        }

        // Set status code
        http_response_code($this->statusCode);

        // Set headers
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        // Set cookies
        foreach ($this->cookies as $cookie) {
            setcookie(
                $cookie['name'],
                $cookie['value'],
                $cookie['expire'],
                $cookie['path'],
                $cookie['domain'],
                $cookie['secure'],
                $cookie['httponly']
            );
        }

        // Send body
        if (!empty($this->body)) {
            if (is_array($this->body)) {
                echo json_encode($this->body, JSON_UNESCAPED_SLASHES);
            } else {
                echo $this->body;
            }
        }

        $this->sent = true;

        Logger::debug("Response sent", [
            'status_code' => $this->statusCode,
            'headers' => $this->headers,
            'body_size' => is_string($this->body) ? strlen($this->body) : 'array'
        ]);
    }

    public function isSent() {
        return $this->sent;
    }

    // Convenience methods for common responses
    public static function notFound($message = "Resource not found") {
        return self::getInstance()
            ->setStatusCode(404)
            ->error($message, 404, 'NOT_FOUND');
    }

    public static function unauthorized($message = "Unauthorized") {
        return self::getInstance()
            ->setStatusCode(401)
            ->error($message, 401, 'UNAUTHORIZED');
    }

    public static function forbidden($message = "Forbidden") {
        return self::getInstance()
            ->setStatusCode(403)
            ->error($message, 403, 'FORBIDDEN');
    }

    public static function rateLimit($retryAfter = null) {
        $response = self::getInstance()
            ->setStatusCode(429)
            ->error("Rate limit exceeded. Please try again later.", 429, 'RATE_LIMIT_EXCEEDED');

        if ($retryAfter) {
            $response->setHeader('Retry-After', $retryAfter);
            $response->body['retry_after'] = $retryAfter;
        }

        return $response;
    }

    public static function serverError($message = "Internal server error") {
        return self::getInstance()
            ->setStatusCode(500)
            ->error($message, 500, 'INTERNAL_ERROR');
    }
}
?>
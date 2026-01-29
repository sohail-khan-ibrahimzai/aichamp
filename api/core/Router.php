<?php
class Router {
    private $routes = [];
    private $db;
    private $request;
    private $response;

    public function __construct($db) {
        $this->db = $db; // This should be the Database instance, not PDO connection
        $this->request = Request::getInstance();
        $this->response = Response::getInstance();
    }

    public function addRoute($method, $path, $handler) {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler
        ];
        
        Logger::debug("Route registered", [
            'method' => $method,
            'path' => $path,
            'handler' => $handler
        ]);
    }

    public function handleRequest() {
        $startTime = microtime(true);

        try {
            $request_method = $this->request->getMethod();
            $request_uri = $this->request->getUri();

            // Handle OPTIONS requests for CORS preflight
            if ($request_method === 'OPTIONS') {
                $this->response->setStatusCode(200)->send();
                return;
            }

            // Only strip '/api' prefix if the URI starts with '/api/'
            if (strpos($request_uri, '/api/') === 0) {
                $path = substr($request_uri, 4); // Remove '/api' prefix
            } else {
                $path = $request_uri;
            }

            foreach ($this->routes as $route) {
                if ($route['method'] === $request_method) {
                    $params = $this->matchRoute($route['path'], $path);
                    if ($params !== false) {
                        $this->request->setRouteParams($params);
                        $this->executeHandler($route['handler'], $params);
                        $duration = round((microtime(true) - $startTime) * 1000, 2);
                        Logger::info("Request handled successfully", [
                            'method' => $request_method,
                            'uri' => $request_uri,
                            'duration_ms' => $duration
                        ]);
                        return;
                    }
                }
            }

            Logger::warning("Route not found", [
                'method' => $request_method,
                'path' => $path,
                'available_routes' => array_map(fn($r) => $r['method'] . ' ' . $r['path'], $this->routes)
            ]);

            $this->response->notFound()->send();
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("Request handled with 404", [
                'method' => $request_method,
                'uri' => $request_uri,
                'status' => 404,
                'duration_ms' => $duration
            ]);
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Request handling failed", [
                'method' => $this->request->getMethod(),
                'uri' => $this->request->getUri(),
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            $this->response->serverError()->send();
        }
    }

    private function executeHandler($handler, $params = []) {
        try {
            // Apply authentication middleware for protected routes
            if ($this->isProtectedRoute($handler)) {
                if (!AuthMiddleware::authenticate($this->request, $this->response)) {
                    // AuthMiddleware already sent the response
                    return;
                }
            }

            if (is_callable($handler)) {
                call_user_func($handler);
            } elseif (is_string($handler) && strpos($handler, '@') !== false) {
                $this->executeControllerHandler($handler, $params);
            } else {
                throw new RuntimeException("Invalid handler type");
            }
        } catch (Exception $e) {
            Logger::error("Handler execution failed", [
                'handler' => $handler,
                'error' => $e->getMessage()
            ]);
            $this->response->serverError()->send();
        }
    }

    private function executeControllerHandler($handler, $params = []) {
        list($controller, $method) = explode('@', $handler);
        $controllerClass = $controller . 'Controller';

        if (!class_exists($controllerClass)) {
            Logger::error("Controller class not found", ['class' => $controllerClass]);
            throw new RuntimeException("Controller not found");
        }

        // Pass the Database instance to controller, not PDO connection
        $controllerInstance = new $controllerClass($this->db);

        if (!method_exists($controllerInstance, $method)) {
            Logger::error("Controller method not found", [
                'class' => $controllerClass,
                'method' => $method
            ]);
            throw new RuntimeException("Method not found");
        }

        // Call method with parameters (always pass params array, even if empty)
        $controllerInstance->$method($params);

        // Send response if not already sent
        if (!$this->response->isSent()) {
            $this->response->send();
        }
    }

    /**
     * Match route pattern with actual path and extract parameters
     */
    public function matchRoute($routePattern, $path) {
        // Convert route pattern to regex
        $pattern = preg_replace('/\{([^}]+)\}/', '([^/]+)', $routePattern);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $path, $matches)) {
            // Extract parameter names from route pattern
            preg_match_all('/\{([^}]+)\}/', $routePattern, $paramNames);

            $params = [];
            // Skip the first match (full string) and create associative array
            for ($i = 1; $i < count($matches); $i++) {
                $paramName = $paramNames[1][$i-1] ?? 'param' . $i;
                $params[$paramName] = $matches[$i];
            }

            return $params;
        }

        return false;
    }

    private function isProtectedRoute($handler) {
        // Public routes that don't require authentication
        $publicRoutes = [
            'Auth@signup',
            'Auth@login',
            'Auth@forgotPassword',
            'Auth@resetPassword',
            'Auth@verifyEmail',
            'AI@getModels', // OpenAI compatible models endpoint
            'Billing@handleWebhook' // Webhooks
        ];

        return !in_array($handler, $publicRoutes);
    }

    public function getRoutes() {
        return $this->routes;
    }
}
?>
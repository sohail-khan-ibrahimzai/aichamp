<?php
class BaseController {
    protected $db;
    protected $user;
    protected $request;
    protected $response;
    protected $validator;

    public function __construct($db) {
        $this->db = $db;
        $this->request = Request::getInstance();
        $this->response = Response::getInstance();
        $this->validator = new Validator();
        $this->initialize();
    }

    protected function initialize() {
        // Common initialization for all controllers
        $this->user = new User($this->db);
    }

    protected function getJsonInput() {
        $data = $this->request->getBody();
        $hasErrors = false;

        if ($data === null || empty($data)) {
            $this->validator->addCustomError('body', 'Empty request body');
            $hasErrors = true;
        }

        if (!$this->request->isJson()) {
            $this->validator->addCustomError('content_type', 'Content-Type must be application/json');
            $hasErrors = true;
        }

        if ($hasErrors) {
            throw new InvalidArgumentException("Validation failed");
        }

        return $data;
    }

    /**
     * Validate request data using common validator
     */
    protected function validate($data, $rules, $messages = []) {
        $this->validator->setData($data)->rules($rules)->messages($messages);
        
        if (!$this->validator->validate()) {
            throw new InvalidArgumentException("Validation failed");
        }
        
        return $this->validator->getValidated();
    }

    /**
     * Get validation errors in standardized format
     */
    protected function getValidationErrorResponse() {
        return $this->error("Validation failed", 400, 'VALIDATION_ERROR', [
            'errors' => $this->validator->getErrors()
        ]);
    }

    protected function success($data = [], $message = "Success", $code = 200) {
        return $this->response
            ->setStatusCode($code)
            ->success($data, $message);
    }

    protected function error($message = "Error", $code = 400, $errorCode = null, $additionalData = []) {
        $response = [
            'success' => false,
            'message' => $message,
            'timestamp' => time()
        ];

        if ($errorCode) {
            $response['error_code'] = $errorCode;
        }

        // Merge additional data (like validation errors)
        if (!empty($additionalData)) {
            $response = array_merge($response, $additionalData);
        }

        // Use the json method instead of directly setting body
        return $this->response
            ->setStatusCode($code)
            ->json($response, $message, false);
    }


    protected function getAuthenticatedUser() {
        return $this->request->getUser();
    }

    protected function getAuthenticatedUserId() {
        $user = $this->getAuthenticatedUser();
        return $user['user_id'] ?? null;
    }

    protected function getRouteParam($param) {
        return $this->request->getParam($param);
    }


    /**
     * Handle service calls with standardized error handling
     */
    protected function handleServiceCall(callable $serviceCall, $successMessage, $errorCode = 'OPERATION_FAILED') {
        try {
            $result = $serviceCall();
            return $this->success($result, $successMessage);
        } catch (InvalidArgumentException $e) {
            // Check if there are actual validation errors
            if (!empty($this->validator->getErrors())) {
                return $this->getValidationErrorResponse();
            } else {
                // Treat as regular business logic error
                return $this->error($e->getMessage(), 400, 'INVALID_ARGUMENT');
            }
        } catch (Exception $e) {
            Logger::error("Service call failed", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return $this->error($e->getMessage(), 500, $errorCode);
        }
    }

    /**
     * Validate required fields in data array
     */
    protected function validateRequiredFields($data, $requiredFields) {
        $missing = [];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            throw new InvalidArgumentException("Missing required fields: " . implode(', ', $missing));
        }

        return true;
    }
}
?>
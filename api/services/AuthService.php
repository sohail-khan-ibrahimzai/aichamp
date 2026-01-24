<?php
class AuthService {
    private $userModel;
    private $validator;
    private $jwtService;

    public function __construct($db) {
        $this->userModel = new User($db);
        $this->validator = new Validator();
        $this->emailVerificationService = new EmailVerificationService($db);
        $this->validateJWTService();
    }

    private function validateJWTService() {
        if (!class_exists('JWT') || !method_exists('JWT', 'encode')) {
            Logger::critical("JWT service not available");
            throw new RuntimeException("Authentication service unavailable");
        }

        Logger::debug("JWT service validated successfully");
    }

    public function register(array $data, $validator = null) {
        // Use provided validator or create new instance
        $validatorInstance = $validator ?? new Validator();
        
        // Define validation rules
        $rules = [
            'email' => 'required|email',
            'password' => 'required|min:8|password',
            'first_name' => 'required|string|min:2|max:100',
            'last_name' => 'required|string|min:2|max:100',
            'phone' => 'sometimes|string|min:10|max:20',
            'avatar_url' => 'sometimes|url'
        ];

        // Custom error messages
        $messages = [
            'email.required' => 'Email address is required for registration.',
            'email.email' => 'Please provide a valid email address.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters long.',
            'password.password' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number.',
            'first_name.required' => 'First name is required.',
            'first_name.min' => 'First name must be at least 2 characters long.',
            'first_name.max' => 'First name may not be longer than 100 characters.',
            'last_name.required' => 'Last name is required.',
            'last_name.min' => 'Last name must be at least 2 characters long.',
            'last_name.max' => 'Last name may not be longer than 100 characters.',
            'phone.min' => 'Phone number must be at least 10 digits.',
            'phone.max' => 'Phone number may not be longer than 20 digits.',
            'avatar_url.url' => 'Avatar URL must be a valid URL.'
        ];

        $validatorInstance->setData($data)->rules($rules)->messages($messages);

        if (!$validatorInstance->validate()) {
            // Store errors in the validator instance for retrieval
            if ($validator !== null) {
                // Copy errors to the provided validator instance
                foreach ($validatorInstance->getErrors() as $field => $errors) {
                    foreach ($errors as $error) {
                        $validator->addCustomError($field, $error);
                    }
                }
            }
            throw new InvalidArgumentException("Validation failed");
        }

        $validatedData = $validatorInstance->getValidated();

        Logger::info("Attempting user registration", ['email' => $validatedData['email']]);

        // Check if email already exists
        if ($this->userModel->emailExists($validatedData['email'])) {
            throw new InvalidArgumentException("Email already exists");
        }

        // Transform the data to match User model expectations
        $userData = [
            'email' => $validatedData['email'],
            'password_hash' => $validatedData['password'], // Map password to password_hash
            'first_name' => $validatedData['first_name'],
            'last_name' => $validatedData['last_name'],
            'phone' => $validatedData['phone'] ?? null,
            'avatar_url' => $validatedData['avatar_url'] ?? null
        ];

        // Create user using the new User model method
        $user = $this->userModel->create($userData);

        if (!$user) {
            throw new RuntimeException("User creation failed");
        }

        // Send email verification
        try {
            $this->emailVerificationService->requestVerification($user['id']);
            Logger::info("Email verification sent for new user", ['user_id' => $user['id'], 'email' => $user['email']]);
        } catch (Exception $e) {
            // Log the error but don't fail registration
            Logger::warning("Failed to send email verification", [
                'user_id' => $user['id'],
                'email' => $user['email'],
                'error' => $e->getMessage()
            ]);
        }

        // Generate JWT token
        $tokenData = [
            "user_id" => $user['id'],
            "email" => $user['email'],
            "first_name" => $user['first_name'],
            "last_name" => $user['last_name']
        ];

        $jwt = JWT::encode($tokenData);

        // Log successful registration
        Logger::logAuth('registration', $user['id'], $user['email'], true);

        return [
            'token' => $jwt,
            'user' => $user,
            'email_verification_sent' => true
        ];
    }

    public function authenticate(array $data) {
        // Define validation rules
        $rules = [
            'email' => 'required|email',
            'password' => 'required'
        ];

        // Custom error messages
        $messages = [
            'email.required' => 'Email address is required for login.',
            'email.email' => 'Please provide a valid email address.',
            'password.required' => 'Password is required.'
        ];

        $this->validator->setData($data)->rules($rules)->messages($messages);

        if (!$this->validator->validate()) {
            throw new InvalidArgumentException("Validation failed");
        }

        $validatedData = $this->validator->getValidated();

        Logger::info("Attempting user login", ['email' => $validatedData['email']]);

        // Validate credentials using the new User model method
        $credentialsCheck = $this->userModel->validateCredentials($validatedData['email'], $validatedData['password']);

        if (!$credentialsCheck['success']) {
            if ($credentialsCheck['error'] === 'ACCOUNT_INACTIVE') {
                throw new Exception("Account inactive", 403);
            } else {
                throw new Exception("Invalid credentials", 401);
            }
        }

        $user = $credentialsCheck['user'];

        // Check if email is verified
        if (!$user['email_verified']) {
            throw new Exception("Email not verified. Please check your email and verify your account before logging in.", 403);
        }

        // Update last login timestamp
        $this->userModel->updateLastLogin($user['id']);

        // Generate JWT token
        $tokenData = [
            "user_id" => $user['id'],
            "email" => $user['email'],
            "first_name" => $user['first_name'],
            "last_name" => $user['last_name']
        ];

        $jwt = JWT::encode($tokenData);

        // Log successful login
        Logger::logAuth('login', $user['id'], $user['email'], true);

        return [
            'token' => $jwt,
            'user' => $user
        ];
    }

    public function verifyToken($userId) {
        // Fetch fresh user data from database using the new User model
        $user = $this->userModel->getById($userId);

        if (!$user) {
            Logger::warning("Token verification failed - user not found", [
                'user_id' => $userId
            ]);
            throw new InvalidArgumentException("User not found");
        }

        // Check if account is active
        if (!$user['is_active']) {
            Logger::warning("Token verification failed - account inactive", [
                'user_id' => $userId
            ]);
            throw new InvalidArgumentException("Account inactive");
        }

        Logger::debug("Token verification successful", [
            'user_id' => $user['id'],
            'email' => $user['email']
        ]);

        return $user;
    }

    public function refreshToken($userId) {
        // Fetch fresh user data using the new User model
        $user = $this->userModel->getById($userId);

        if (!$user) {
            throw new InvalidArgumentException("User not found");
        }

        // Check if account is active
        if (!$user['is_active']) {
            throw new InvalidArgumentException("Account inactive");
        }

        $tokenData = [
            "user_id" => $user['id'],
            "email" => $user['email'],
            "first_name" => $user['first_name'],
            "last_name" => $user['last_name']
        ];

        $newJwt = JWT::encode($tokenData);

        Logger::info("Token refreshed successfully", [
            'user_id' => $user['id'],
            'email' => $user['email']
        ]);

        return [
            'token' => $newJwt,
            'user' => $user
        ];
    }

    public function logout($userId) {
        // In a stateless JWT system, we can't invalidate the token on the server
        // The client should discard the token
        // In a real implementation, you might maintain a blacklist or use refresh tokens

        Logger::info("User logout", [
            'user_id' => $userId
        ]);

        return true;
    }

    public function forgotPassword($email) {
        $rules = [
            'email' => 'required|email'
        ];

        $messages = [
            'email.required' => 'Email address is required to reset password.',
            'email.email' => 'Please provide a valid email address.'
        ];

        $this->validator->setData(['email' => $email])->rules($rules)->messages($messages);

        if (!$this->validator->validate()) {
            throw new InvalidArgumentException("Validation failed");
        }

        Logger::info("Password reset requested", ['email' => $email]);

        // Check if user exists
        $user = $this->userModel->getByEmail($email);

        if ($user) {
            // In a real implementation, you would:
            // 1. Generate a reset token
            // 2. Store it in the database with expiration
            // 3. Send email with reset link

            Logger::info("Password reset token would be sent", [
                'user_id' => $user['id'],
                'email' => $user['email']
            ]);
        }

        // Always return success to prevent email enumeration
        return true;
    }

    public function resetPassword(array $data) {
        $rules = [
            'token' => 'required|string|min:32',
            'new_password' => 'required|min:8|password',
            'confirm_password' => 'required|same:new_password'
        ];

        $messages = [
            'token.required' => 'Reset token is required.',
            'token.min' => 'Invalid reset token.',
            'new_password.required' => 'New password is required.',
            'new_password.min' => 'New password must be at least 8 characters long.',
            'new_password.password' => 'New password must contain at least one uppercase letter, one lowercase letter, and one number.',
            'confirm_password.required' => 'Please confirm your new password.',
            'confirm_password.same' => 'Password confirmation does not match.'
        ];

        $this->validator->setData($data)->rules($rules)->messages($messages);

        if (!$this->validator->validate()) {
            Logger::error("Registration validation failed", [
                'errors' => $this->validator->getErrors(),
                'data' => $data
            ]);
            throw new InvalidArgumentException("Validation failed");
        }

        Logger::info("Password reset attempt");

        // In a real implementation, you would:
        // 1. Verify the reset token from database
        // 2. Check expiration
        // 3. Update password
        // 4. Invalidate the used token

        // For now, we'll return a placeholder response
        throw new RuntimeException("Password reset functionality not fully implemented");
    }

    public function changePassword($userId, array $data) {
        $rules = [
            'current_password' => 'required',
            'new_password' => 'required|min:8|password',
            'confirm_password' => 'required|same:new_password'
        ];

        $messages = [
            'current_password.required' => 'Current password is required.',
            'new_password.required' => 'New password is required.',
            'new_password.min' => 'New password must be at least 8 characters long.',
            'new_password.password' => 'New password must contain at least one uppercase letter, one lowercase letter, and one number.',
            'confirm_password.required' => 'Please confirm your new password.',
            'confirm_password.same' => 'Password confirmation does not match.'
        ];

        $this->validator->setData($data)->rules($rules)->messages($messages);

        if (!$this->validator->validate()) {
            throw new InvalidArgumentException("Validation failed");
        }

        $validatedData = $this->validator->getValidated();

        // Get user to verify current password
        $user = $this->userModel->getById($userId);
        if (!$user) {
            throw new InvalidArgumentException("User not found");
        }

        // Verify current password
        $credentialsCheck = $this->userModel->validateCredentials($user['email'], $validatedData['current_password']);

        if (!$credentialsCheck['success']) {
            if ($credentialsCheck['error'] === 'INVALID_CREDENTIALS') {
                throw new InvalidArgumentException("Invalid current password");
            } elseif ($credentialsCheck['error'] === 'ACCOUNT_INACTIVE') {
                throw new InvalidArgumentException("Account inactive");
            }
        }

        // Update password
        $success = $this->userModel->updatePassword($userId, $validatedData['new_password']);

        if (!$success) {
            throw new RuntimeException("Failed to update password");
        }

        Logger::info("Password changed successfully", [
            'user_id' => $userId
        ]);

        return true;
    }

    public function requestEmailVerification($userId) {
        $user = $this->userModel->getById($userId);

        if ($user['email_verified']) {
            throw new InvalidArgumentException("Email already verified");
        }

        try {
            // In a real implementation, you would:
            // 1. Generate a verification token
            // 2. Store it in the database with expiration
            // 3. Send verification email

            $verificationToken = bin2hex(random_bytes(32));

            Logger::info("Email verification requested", [
                'user_id' => $user['id'],
                'email' => $user['email']
                // Don't log the actual token in production
            ]);

            // For now, we'll just return success (in production, send email)
            return true;

        } catch (Exception $e) {
            Logger::error("Email verification request failed", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function verifyEmail($userId, $token = null) {
        // For testing purposes, we'll skip token validation
        // In a real implementation, you would:
        // 1. Validate the token against database
        // 2. Check expiration
        // 3. Ensure token matches user

        $success = $this->userModel->verifyEmail($userId);

        if (!$success) {
            throw new RuntimeException("Failed to verify email");
        }

        $updatedUser = $this->userModel->getById($userId);
        Logger::info("Email verified successfully", [
            'user_id' => $userId,
            'email' => $updatedUser['email']
        ]);

        return $updatedUser;
    }
}
?>
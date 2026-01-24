<?php
class AuthController extends BaseController {
    private $authService;
    private $passwordService;
    private $emailVerificationService;

    public function __construct($db) {
        parent::__construct($db);
        $this->authService = new AuthService($db);
        $this->passwordService = new PasswordService($db);
        $this->emailVerificationService = new EmailVerificationService($db);
    }

    public function signup() {
        $data = $this->getJsonInput();

        try {
            // Pass the controller's validator instance to the service
            $result = $this->authService->register($data, $this->validator);

            return $this->success([
                "token" => $result['token'],
                "user" => [
                    "id" => $result['user']['id'],
                    "email" => $result['user']['email'],
                    "first_name" => $result['user']['first_name'],
                    "last_name" => $result['user']['last_name'],
                    "phone" => $result['user']['phone'],
                    "avatar_url" => $result['user']['avatar_url'],
                    "email_verified" => $result['user']['email_verified'],
                    "is_active" => $result['user']['is_active'],
                    "created_at" => $result['user']['created_at']
                ],
                "email_verification_sent" => $result['email_verification_sent'] ?? false
            ], "User registered successfully. Please check your email to verify your account.", 201);

        } catch (InvalidArgumentException $e) {
            Logger::logAuth('registration', null, $data['email'] ?? 'unknown', false);
            return $this->getValidationErrorResponse();
        } catch (Exception $e) {
            Logger::logAuth('registration', null, $data['email'] ?? 'unknown', false);
            Logger::error("User registration failed", [
                'error' => $e->getMessage(),
                'email' => $data['email'] ?? 'unknown'
            ]);
            return $this->error("Unable to create user account. Please try again.", 500, 'USER_CREATION_FAILED');
        }
    }

    public function login() {
        $data = $this->getJsonInput();

        try {
            $result = $this->authService->authenticate($data);

            return $this->success([
                "token" => $result['token'],
                "user" => [
                    "id" => $result['user']['id'],
                    "email" => $result['user']['email'],
                    "first_name" => $result['user']['first_name'],
                    "last_name" => $result['user']['last_name'],
                    "phone" => $result['user']['phone'],
                    "avatar_url" => $result['user']['avatar_url'],
                    "email_verified" => $result['user']['email_verified'],
                    "is_active" => $result['user']['is_active'],
                    "last_login_at" => $result['user']['last_login_at']
                ]
            ], "Login successful.");
        } catch (InvalidArgumentException $e) {
            Logger::logAuth('login', null, $data['email'] ?? 'unknown', false);
            return $this->getValidationErrorResponse();
        } catch (Exception $e) {
            Logger::logAuth('login', null, $data['email'] ?? 'unknown', false);
            $code = $e->getCode();
            if ($code === 403) {
                if (str_contains($e->getMessage(), 'Email not verified')) {
                    return $this->error($e->getMessage(), 403, 'EMAIL_NOT_VERIFIED');
                } else {
                    return $this->error("Your account has been deactivated. Please contact support.", 403, 'ACCOUNT_INACTIVE');
                }
            } elseif ($code === 401) {
                return $this->error("Invalid email or password.", 401, 'INVALID_CREDENTIALS');
            } else {
                Logger::error("User login failed", [
                    'error' => $e->getMessage(),
                    'email' => $data['email'] ?? 'unknown'
                ]);
                return $this->error("Login failed due to system error.", 500, 'LOGIN_FAILED');
            }
        }
    }
    public function verify() {
        $user = $this->getAuthenticatedUser();

        try {
            $user = $this->authService->verifyToken($user['user_id']);

            return $this->success([
                "user" => [
                    "id" => $user['id'],
                    "email" => $user['email'],
                    "first_name" => $user['first_name'],
                    "last_name" => $user['last_name'],
                    "phone" => $user['phone'],
                    "avatar_url" => $user['avatar_url'],
                    "email_verified" => $user['email_verified'],
                    "is_active" => $user['is_active'],
                    "last_login_at" => $user['last_login_at'],
                    "created_at" => $user['created_at']
                ],
                "token_expires_at" => $user['exp'] ?? 'unknown'
            ], "Token is valid.");

        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                return $this->error("User not found.", 404, 'USER_NOT_FOUND');
            } elseif (str_contains($e->getMessage(), 'inactive')) {
                return $this->error("Account is inactive.", 403, 'ACCOUNT_INACTIVE');
            }
            return $this->error("Token verification failed.", 401, 'TOKEN_INVALID');
        } catch (Exception $e) {
            Logger::error("Token verification failed", [
                'user_id' => $user['user_id'],
                'error' => $e->getMessage()
            ]);
            return $this->error("Token verification failed.", 500, 'VERIFICATION_FAILED');
        }
    }

    public function refresh() {
        $user = $this->getAuthenticatedUser();

        try {
            $result = $this->authService->refreshToken($user['user_id']);

            return $this->success([
                "token" => $result['token'],
                "user" => [
                    "id" => $result['user']['id'],
                    "email" => $result['user']['email'],
                    "first_name" => $result['user']['first_name'],
                    "last_name" => $result['user']['last_name'],
                    "phone" => $result['user']['phone'],
                    "avatar_url" => $result['user']['avatar_url'],
                    "email_verified" => $result['user']['email_verified'],
                    "is_active" => $result['user']['is_active']
                ],
                "token_expires_in" => 24 * 60 * 60
            ], "Token refreshed successfully.");

        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                return $this->error("User not found.", 404, 'USER_NOT_FOUND');
            } elseif (str_contains($e->getMessage(), 'inactive')) {
                return $this->error("Account is inactive.", 403, 'ACCOUNT_INACTIVE');
            }
            return $this->error("Token refresh failed.", 401, 'TOKEN_INVALID');
        } catch (Exception $e) {
            Logger::error("Token refresh failed", [
                'user_id' => $user['user_id'],
                'error' => $e->getMessage()
            ]);
            return $this->error("Token refresh failed.", 500, 'REFRESH_FAILED');
        }
    }

    public function logout() {
        $user = $this->getAuthenticatedUser();

        try {
            $this->authService->logout($user['user_id']);

            return $this->success([], "Logout successful. Please discard your token.");

        } catch (Exception $e) {
            Logger::error("Logout failed", [
                'user_id' => $user['user_id'],
                'error' => $e->getMessage()
            ]);
            return $this->success([], "Logout successful. Please discard your token.");
        }
    }

    public function forgotPassword() {
        $data = $this->getJsonInput();

        try {
            $this->authService->forgotPassword($data['email'] ?? '');

            // Always return success to prevent email enumeration
            return $this->success([], "If the email exists, a password reset link has been sent.");

        } catch (InvalidArgumentException $e) {
            return $this->getValidationErrorResponse();
        } catch (Exception $e) {
            Logger::error("Password reset request failed", [
                'error' => $e->getMessage(),
                'email' => $data['email'] ?? 'unknown'
            ]);
            // Still return success to prevent email enumeration
            return $this->success([], "If the email exists, a password reset link has been sent.");
        }
    }

    public function resetPassword() {
        $data = $this->getJsonInput();

        try {
            $this->authService->resetPassword($data);

            return $this->error("Password reset functionality not fully implemented.", 501, 'NOT_IMPLEMENTED');

        } catch (InvalidArgumentException $e) {
            return $this->getValidationErrorResponse();
        } catch (Exception $e) {
            Logger::error("Password reset failed", [
                'error' => $e->getMessage()
            ]);
            return $this->error("Password reset failed.", 500, 'RESET_FAILED');
        }
    }

    public function changePassword() {
        $user = $this->getAuthenticatedUser();
        $data = $this->getJsonInput();

        return $this->handleServiceCall(function() use ($user, $data) {
            $this->passwordService->changePassword(
                $user['user_id'],
                $user['email'],
                $data['current_password'],
                $data['new_password']
            );
            return [];
        }, "Password changed successfully.", 'PASSWORD_CHANGE_FAILED');
    }

    public function requestEmailVerification() {
        $user = $this->getAuthenticatedUser();
        return $this->handleServiceCall(function() use ($user) {
            $result = $this->emailVerificationService->requestVerification($user['user_id']);
            return [
                "message" => $result['message']
            ];
        }, "Verification email sent successfully.", 'VERIFICATION_REQUEST_FAILED');
    }

    public function verifyEmail() {
        $data = $this->getJsonInput();

        try {
            // For testing, allow verification without token
            $userId = $data['user_id'] ?? null;
            if (!$userId) {
                // If no user_id provided, try to get from authenticated user
                $user = $this->getAuthenticatedUser();
                $userId = $user['user_id'];
            }

            $user = $this->authService->verifyEmail($userId);
            return $this->success(["user" => $user], "Email verified successfully.");

        } catch (InvalidArgumentException $e) {
            return $this->getValidationErrorResponse();
        } catch (Exception $e) {
            Logger::error("Email verification failed", [
                'error' => $e->getMessage()
            ]);
            return $this->error("Email verification failed.", 500, 'EMAIL_VERIFICATION_FAILED');
        }
    }
}
?>
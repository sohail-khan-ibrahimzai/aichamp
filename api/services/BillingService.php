<?php
class BillingService {
    private $db;
    private $subscriptionModel;
    private $subscriptionPlanModel;
    private $subscriptionInvoiceModel;
    private $paymentGatewayModel;
    private $paymentTransactionModel;
    private $paymentMethodModel;
    private $subscriptionManager;
    private $validator;
    private $stripeService;
    private $paypalService;

    public function __construct($db) {
        $this->db = $db;
        $this->subscriptionModel = new Subscription($db);
        $this->subscriptionPlanModel = new SubscriptionPlan($db);
        $this->subscriptionInvoiceModel = new SubscriptionInvoice($db);
        $this->paymentGatewayModel = new PaymentGateway($db);
        $this->paymentTransactionModel = new PaymentTransaction($db);
        $this->paymentMethodModel = new PaymentMethod($db);
        $this->subscriptionManager = new SubscriptionManager($db);
        $this->validator = new Validator();

        // Initialize payment services
        try {
            $this->stripeService = new StripeService();
        } catch (Exception $e) {
            Logger::warning("Stripe service not available", ['error' => $e->getMessage()]);
            $this->stripeService = null;
        }

        try {
            $this->paypalService = new PayPalService();
        } catch (Exception $e) {
            Logger::warning("PayPal service not available", ['error' => $e->getMessage()]);
            $this->paypalService = null;
        }
    }

    /**
     * Create or get Stripe customer for user
     */
    public function createStripeCustomer($userId) {
        $userModel = new User($this->db);
        $user = $userModel->getById($userId);

        if (!$user) {
            throw new InvalidArgumentException("User not found");
        }

        // Check if user already has a Stripe customer ID
        if ($user['stripe_customer_id']) {
            return $user['stripe_customer_id'];
        }

        // Create Stripe customer
        if (!$this->stripeService) {
            throw new RuntimeException("Stripe service not available");
        }

        $customerData = [
            'email' => $user['email'],
            'name' => $user['first_name'] . ' ' . $user['last_name'],
            'metadata' => [
                'user_id' => $userId
            ]
        ];

        $customer = $this->stripeService->createCustomer($customerData);

        // Update user with Stripe customer ID
        $userModel->updateStripeCustomerId($userId, $customer['id']);

        Logger::info("Stripe customer created for user", [
            'user_id' => $userId,
            'stripe_customer_id' => $customer['id']
        ]);

        return $customer['id'];
    }

    /**
     * Create Stripe subscription
     */
    public function createStripeSubscription($userId, $planId, $paymentMethodId = null) {
        $plan = $this->subscriptionPlanModel->getById($planId);
        if (!$plan) {
            throw new InvalidArgumentException("Invalid plan ID");
        }

        // Get or create Stripe customer
        $customerId = $this->createStripeCustomer($userId);

        if (!$this->stripeService) {
            throw new RuntimeException("Stripe service not available");
        }

        // Create subscription data
        $subscriptionData = [
            'customer_id' => $customerId,
            'plan_name' => $plan['name'],
            'amount' => $plan['price_monthly'] ?? $plan['price_yearly'] ?? 0,
            'currency' => $plan['currency'],
            'interval' => isset($plan['price_monthly']) ? 'month' : 'year',
            'metadata' => [
                'user_id' => $userId,
                'plan_id' => $planId
            ]
        ];

        if ($paymentMethodId) {
            $subscriptionData['default_payment_method'] = $paymentMethodId;
        }

        $stripeSubscription = $this->stripeService->createSubscription($subscriptionData);

        // Create local subscription record
        $tier = $this->mapPlanTypeToTier($plan['plan_type']);
        $subscription = $this->subscriptionModel->create([
            'user_id' => $userId,
            'tier' => $tier,
            'status' => 'active',
            'stripe_subscription_id' => $stripeSubscription['id'],
            'stripe_price_id' => null, // Will be set from webhook
            'current_period_start' => date('Y-m-d H:i:s', $stripeSubscription['current_period_start']),
            'current_period_end' => date('Y-m-d H:i:s', $stripeSubscription['current_period_end'])
        ]);

        Logger::info("Stripe subscription created", [
            'user_id' => $userId,
            'stripe_subscription_id' => $stripeSubscription['id'],
            'local_subscription_id' => $subscription['id']
        ]);

        return [
            'subscription' => $subscription,
            'stripe_subscription' => $stripeSubscription
        ];
    }

    /**
     * Cancel Stripe subscription
     */
    public function cancelStripeSubscription($userId, $cancelAtPeriodEnd = true) {
        $subscription = $this->subscriptionModel->getActiveByUserId($userId);
        if (!$subscription || !$subscription['stripe_subscription_id']) {
            throw new InvalidArgumentException("No active Stripe subscription found");
        }

        if (!$this->stripeService) {
            throw new RuntimeException("Stripe service not available");
        }

        $result = $this->stripeService->cancelSubscription($subscription['stripe_subscription_id']);

        // Update local subscription
        $updateData = [
            'status' => $cancelAtPeriodEnd ? 'active' : 'cancelled',
            'cancel_at_period_end' => $cancelAtPeriodEnd
        ];

        if (!$cancelAtPeriodEnd) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
        }

        $this->subscriptionModel->update($subscription['id'], $updateData);

        Logger::info("Stripe subscription cancelled", [
            'user_id' => $userId,
            'stripe_subscription_id' => $subscription['stripe_subscription_id'],
            'cancel_at_period_end' => $cancelAtPeriodEnd
        ]);

        return $result;
    }

    /**
     * Get current user subscription
     */
    public function getCurrentSubscription($userId) {
        $subscription = $this->subscriptionModel->getActiveByUserId($userId);

        if (!$subscription) {
            // Return free tier info
            return [
                'tier' => 'free',
                'status' => 'active',
                'features' => ['thinking_traces', 'vector_memory', 'summarization'],
                'limits' => [
                    'context_window_size' => 10,
                    'max_sessions' => 1,
                    'max_messages_per_session' => 100,
                    'max_models_per_prompt' => 1
                ]
            ];
        }

        return $subscription;
    }

    /**
     * Get available subscription plans
     */
    public function getSubscriptionPlans() {
        $result = $this->subscriptionPlanModel->getAllActive();
        return $result;
    }

    /**
     * Upgrade user subscription
     */
    public function upgradeSubscription($userId, $planId = null, $tier = null) {
        // If plan_id provided, get tier from plan
        if ($planId) {
            $plan = $this->subscriptionPlanModel->getById($planId);
            if (!$plan) {
                throw new InvalidArgumentException("Invalid plan ID");
            }
            $tier = $this->mapPlanTypeToTier($plan['plan_type']);
        }

        if (!$tier) {
            throw new InvalidArgumentException("Tier or plan ID required");
        }

        // Use subscription manager to upgrade
        $success = $this->subscriptionManager->upgradeSubscription($userId, $tier);

        if (!$success) {
            throw new RuntimeException("Failed to upgrade subscription");
        }

        // Get updated subscription
        $subscription = $this->subscriptionModel->getActiveByUserId($userId);

        return [
            'subscription' => $subscription,
            'message' => "Subscription upgraded to {$tier} tier"
        ];
    }

    /**
     * Cancel user subscription
     */
    public function cancelSubscription($userId) {
        $success = $this->subscriptionManager->cancelSubscription($userId);

        if (!$success) {
            throw new RuntimeException("Failed to cancel subscription");
        }

        return [
            'message' => 'Subscription cancelled successfully'
        ];
    }

    /**
     * Create payment intent
     */
    public function createPaymentIntent($userId, $planId, $gatewayKey = 'stripe') {
        $plan = $this->subscriptionPlanModel->getById($planId);
        if (!$plan) {
            throw new InvalidArgumentException("Invalid plan ID");
        }

        $gateway = $this->paymentGatewayModel->getByKey($gatewayKey);
        if (!$gateway || !$gateway['is_active']) {
            throw new InvalidArgumentException("Payment gateway not available");
        }

        // Get or create Stripe customer for user
        $customerId = $this->createStripeCustomer($userId);

        // Create invoice first
        $invoice = $this->subscriptionInvoiceModel->create([
            'subscription_id' => null, // Will be set after subscription creation
            'amount' => $plan['price_monthly'] ?? $plan['price_yearly'] ?? 0,
            'currency' => $plan['currency'],
            'status' => 'pending'
        ]);

        // Create payment transaction
        $transaction = $this->paymentTransactionModel->create([
            'user_id' => $userId,
            'gateway_id' => $gateway['id'],
            'amount' => $plan['price_monthly'] ?? $plan['price_yearly'] ?? 0,
            'currency' => $plan['currency'],
            'status' => 'pending',
            'metadata' => [
                'plan_id' => $planId,
                'invoice_id' => $invoice['id'],
                'type' => 'subscription_upgrade'
            ]
        ]);

        // Create payment intent with gateway
        $paymentIntent = $this->createGatewayPaymentIntent($gatewayKey, [
            'amount' => $transaction['amount'] * 100, // Convert to cents
            'currency' => $transaction['currency'],
            'customer_id' => $customerId,
            'metadata' => [
                'transaction_id' => $transaction['id'],
                'invoice_id' => $invoice['id'],
                'plan_id' => $planId
            ]
        ]);

        return [
            'payment_intent' => $paymentIntent,
            'transaction_id' => $transaction['id'],
            'invoice_id' => $invoice['id'],
            'amount' => $transaction['amount'],
            'currency' => $transaction['currency']
        ];
    }

    /**
     * Process payment
     */
    public function processPayment($userId, $paymentData) {
        $transactionId = $paymentData['transaction_id'] ?? null;
        $gatewayKey = $paymentData['gateway_key'] ?? 'stripe';

        if (!$transactionId) {
            throw new InvalidArgumentException("Transaction ID required");
        }

        $transaction = $this->paymentTransactionModel->getById($transactionId);
        if (!$transaction || $transaction['user_id'] !== $userId) {
            throw new InvalidArgumentException("Invalid transaction");
        }

        // Process payment with gateway
        $result = $this->processGatewayPayment($gatewayKey, $paymentData);

        if ($result['success']) {
            // Update transaction status
            $this->paymentTransactionModel->updateStatus($transactionId, 'completed', [
                'gateway_transaction_id' => $result['gateway_transaction_id']
            ]);

            // Update invoice
            $this->subscriptionInvoiceModel->markAsPaid($transaction['metadata']['invoice_id']);

            // Handle subscription if this was for a plan
            if (isset($transaction['metadata']['plan_id'])) {
                $planId = $transaction['metadata']['plan_id'];
                $plan = $this->subscriptionPlanModel->getById($planId);
                
                // Check if user has an active subscription
                $activeSubscription = $this->subscriptionModel->getActiveByUserId($userId);
                
                if ($activeSubscription) {
                    // Upgrade existing subscription
                    $this->upgradeSubscription($userId, $planId);
                } else {
                    // Create new subscription for first-time payment
                    $tier = $this->mapPlanTypeToTier($plan['plan_type']);
                    $this->subscriptionModel->create([
                        'user_id' => $userId,
                        'tier' => $tier,
                        'status' => 'active',
                        'stripe_subscription_id' => null,
                        'stripe_price_id' => null,
                        'current_period_start' => date('Y-m-d H:i:s'),
                        'current_period_end' => date('Y-m-d H:i:s', strtotime('+1 month'))
                    ]);
                }
            }

            return [
                'success' => true,
                'transaction' => $this->paymentTransactionModel->getById($transactionId)
            ];
        } else {
            // Update transaction status to failed
            $this->paymentTransactionModel->updateStatus($transactionId, 'failed', [
                'error' => $result['error']
            ]);

            throw new RuntimeException("Payment failed: " . $result['error']);
        }
    }

    /**
     * Get user invoices
     */
    public function getUserInvoices($userId, $page = 1, $limit = 50) {
        // Get user's subscriptions first
        $subscriptions = $this->subscriptionModel->getByUserId($userId);
        $subscriptionIds = array_column($subscriptions['subscriptions'], 'id');

        if (empty($subscriptionIds)) {
            return [
                'invoices' => [],
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => 0,
                    'pages' => 0
                ]
            ];
        }

        // Build placeholders for IN clause
        $placeholders = str_repeat('?,', count($subscriptionIds) - 1) . '?';

        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM subscription_invoices
                     WHERE subscription_id IN ($placeholders)";
        $countResult = $this->db->query($countSql, $subscriptionIds);
        $total = $countResult[0]['total'] ?? 0;

        // Get paginated invoices
        $offset = ($page - 1) * $limit;
        $sql = "SELECT * FROM subscription_invoices
                WHERE subscription_id IN ($placeholders)
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?";

        $params = array_merge($subscriptionIds, [$limit, $offset]);
        $invoices = $this->db->query($sql, $params);

        return [
            'invoices' => $invoices,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ];
    }

    /**
     * Get specific invoice
     */
    public function getInvoice($invoiceId, $userId) {
        $invoice = $this->subscriptionInvoiceModel->getById($invoiceId);

        if (!$invoice) {
            throw new InvalidArgumentException("Invoice not found");
        }

        // Check if user owns this invoice (through subscription)
        $subscription = $this->subscriptionModel->getById($invoice['subscription_id']);
        if (!$subscription || $subscription['user_id'] !== $userId) {
            throw new InvalidArgumentException("Access denied");
        }

        return $invoice;
    }

    /**
     * Get payment methods for user
     */
    public function getPaymentMethods($userId) {
        Logger::debug("Getting payment methods for user", ['user_id' => $userId]);

        try {
            // Get Stripe customer ID for user
            $userModel = new User($this->db);
            $user = $userModel->getById($userId);

            Logger::debug("User lookup result", [
                'user_id' => $userId,
                'user_found' => $user ? true : false,
                'has_stripe_customer_id' => $user && $user['stripe_customer_id'] ? true : false,
                'stripe_customer_id' => $user['stripe_customer_id'] ?? null
            ]);

            if (!$user || !$user['stripe_customer_id']) {
                Logger::info("User has no Stripe customer ID, returning empty payment methods", ['user_id' => $userId]);
                return [
                    'payment_methods' => []
                ];
            }

            // Get payment methods from Stripe
            if (!$this->stripeService) {
                Logger::error("Stripe service not available for getPaymentMethods", ['user_id' => $userId]);
                throw new RuntimeException("Stripe service not available");
            }

            Logger::debug("Calling Stripe API to get payment methods", ['user_id' => $userId, 'stripe_customer_id' => $user['stripe_customer_id']]);
            $stripeMethods = $this->stripeService->getPaymentMethods($user['stripe_customer_id']);
            Logger::debug("Stripe API call result", ['user_id' => $userId, 'stripe_methods_count' => count($stripeMethods)]);

            // Sync with local database
            $localMethods = $this->paymentMethodModel->getByUserId($userId);
            $localMethodIds = array_column($localMethods, 'gateway_payment_method_id');

            // Add new methods from Stripe
            foreach ($stripeMethods as $stripeMethod) {
                if (!in_array($stripeMethod['id'], $localMethodIds)) {
                    $methodData = [
                        'user_id' => $userId,
                        'gateway_id' => $this->getStripeGatewayId(),
                        'gateway_payment_method_id' => $stripeMethod['id'],
                        'type' => $stripeMethod['type'],
                        'last4' => $stripeMethod['card']['last4'] ?? null,
                        'brand' => $stripeMethod['card']['brand'] ?? null,
                        'expiry_month' => $stripeMethod['card']['exp_month'] ?? null,
                        'expiry_year' => $stripeMethod['card']['exp_year'] ?? null,
                        'is_default' => false, // Will be set later if needed
                        'metadata' => $stripeMethod
                    ];

                    $this->paymentMethodModel->create($methodData);
                }
            }

            // Remove local methods that no longer exist in Stripe
            foreach ($localMethods as $localMethod) {
                $existsInStripe = false;
                foreach ($stripeMethods as $stripeMethod) {
                    if ($stripeMethod['id'] === $localMethod['gateway_payment_method_id']) {
                        $existsInStripe = true;
                        break;
                    }
                }

                if (!$existsInStripe) {
                    $this->paymentMethodModel->delete($localMethod['id']);
                }
            }

            // Get updated local methods
            $methods = $this->paymentMethodModel->getByUserId($userId);

            // Format for API response
            $formattedMethods = array_map(function($method) {
                return [
                    'id' => $method['id'],
                    'type' => $method['type'],
                    'last4' => $method['last4'],
                    'brand' => $method['brand'],
                    'expiry_month' => $method['expiry_month'],
                    'expiry_year' => $method['expiry_year'],
                    'is_default' => $method['is_default'],
                    'created_at' => $method['created_at']
                ];
            }, $methods);

            return [
                'payment_methods' => $formattedMethods
            ];

        } catch (Exception $e) {
            Logger::error("Failed to get payment methods", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            // Return local methods as fallback
            $methods = $this->paymentMethodModel->getByUserId($userId);
            $formattedMethods = array_map(function($method) {
                return [
                    'id' => $method['id'],
                    'type' => $method['type'],
                    'last4' => $method['last4'],
                    'brand' => $method['brand'],
                    'expiry_month' => $method['expiry_month'],
                    'expiry_year' => $method['expiry_year'],
                    'is_default' => $method['is_default'],
                    'created_at' => $method['created_at']
                ];
            }, $methods);

            return [
                'payment_methods' => $formattedMethods
            ];
        }
    }

    /**
     * Add payment method
     */
    public function addPaymentMethod($userId, $methodData) {
        try {
            // Get user and ensure they have a Stripe customer
            $customerId = $this->createStripeCustomer($userId);

            if (!$this->stripeService) {
                throw new RuntimeException("Stripe service not available");
            }

            $gateway = $this->paymentGatewayModel->getByKey('stripe');
            if (!$gateway || !$gateway['is_active']) {
                throw new InvalidArgumentException("Stripe gateway not available");
            }

            $paymentMethodId = $methodData['payment_method_id'] ?? null;
            if (!$paymentMethodId) {
                throw new InvalidArgumentException("Payment method ID is required");
            }

            // Attach existing payment method to customer
            $this->stripeService->attachPaymentMethodToCustomer($paymentMethodId, $customerId);

            // Get payment method details from Stripe
            $paymentMethods = $this->stripeService->getPaymentMethods($customerId);
            $stripePaymentMethod = null;
            foreach ($paymentMethods as $method) {
                if ($method['id'] === $paymentMethodId) {
                    $stripePaymentMethod = $method;
                    break;
                }
            }

            if (!$stripePaymentMethod) {
                throw new RuntimeException("Failed to retrieve payment method details from Stripe");
            }

            // Save to local database
            $localMethodData = [
                'user_id' => $userId,
                'gateway_id' => $gateway['id'],
                'gateway_payment_method_id' => $stripePaymentMethod['id'],
                'type' => $stripePaymentMethod['type'],
                'last4' => $stripePaymentMethod['card']['last4'] ?? null,
                'brand' => $stripePaymentMethod['card']['brand'] ?? null,
                'expiry_month' => $stripePaymentMethod['card']['exp_month'] ?? null,
                'expiry_year' => $stripePaymentMethod['card']['exp_year'] ?? null,
                'is_default' => $methodData['is_default'] ?? false,
                'metadata' => $stripePaymentMethod
            ];

            $localMethod = $this->paymentMethodModel->create($localMethodData);

            // Set as default if requested
            if ($methodData['is_default']) {
                $this->paymentMethodModel->setAsDefault($localMethod['id'], $userId);
                // Also set as default in Stripe
                $this->stripeService->updateCustomerDefaultPaymentMethod($customerId, $stripePaymentMethod['id']);
            }

            Logger::info("Payment method added successfully", [
                'user_id' => $userId,
                'stripe_payment_method_id' => $stripePaymentMethod['id'],
                'local_method_id' => $localMethod['id']
            ]);

            return [
                'payment_method' => [
                    'id' => $localMethod['id'],
                    'stripe_payment_method_id' => $stripePaymentMethod['id'],
                    'type' => $localMethod['type'],
                    'last4' => $localMethod['last4'],
                    'brand' => $localMethod['brand'],
                    'expiry_month' => $localMethod['expiry_month'],
                    'expiry_year' => $localMethod['expiry_year'],
                    'is_default' => $localMethod['is_default'],
                    'created_at' => $localMethod['created_at']
                ],
                'message' => 'Payment method added successfully'
            ];

        } catch (Exception $e) {
            Logger::error("Failed to add payment method", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Remove payment method
     */
    public function removePaymentMethod($userId, $methodId) {
        try {
            // Get the payment method
            $method = $this->paymentMethodModel->getById($methodId);

            if (!$method || $method['user_id'] !== $userId) {
                throw new InvalidArgumentException("Payment method not found or access denied");
            }

            if (!$this->stripeService) {
                throw new RuntimeException("Stripe service not available");
            }

            // Detach from Stripe customer
            $this->stripeService->detachPaymentMethod($method['gateway_payment_method_id']);

            // Remove from local database
            $this->paymentMethodModel->delete($methodId);

            Logger::info("Payment method removed successfully", [
                'user_id' => $userId,
                'method_id' => $methodId,
                'stripe_payment_method_id' => $method['gateway_payment_method_id']
            ]);

            return [
                'message' => 'Payment method removed successfully'
            ];

        } catch (Exception $e) {
            Logger::error("Failed to remove payment method", [
                'user_id' => $userId,
                'method_id' => $methodId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get billing history
     */
    public function getBillingHistory($userId, $page = 1, $limit = 50) {
        $result = $this->paymentTransactionModel->getByUserId($userId, $page, $limit);
        return $result;
    }

    /**
     * Handle webhook
     */
    public function handleWebhook($gatewayKey, $postData, $getData) {
        Logger::info("Processing webhook", ['gateway' => $gatewayKey, 'gateway_type' => gettype($gatewayKey), 'post_data_keys' => array_keys($postData)]);

        switch ($gatewayKey) {
            case 'stripe':
                return $this->handleStripeWebhook($postData);
            case 'paypal':
                return $this->handlePayPalWebhook($postData);
            default:
                throw new InvalidArgumentException("Unsupported gateway");
        }
    }

    /**
     * Process webhook event and update database
     */
    private function processWebhookEvent($gatewayKey, $event) {
        Logger::info("Processing webhook event", [
            'gateway' => $gatewayKey,
            'event_type' => $event['event_type'] ?? 'unknown',
            'resource_id' => $event['resource_id'] ?? $event['id'] ?? null
        ]);

        switch ($gatewayKey) {
            case 'stripe':
                return $this->processStripeWebhookEvent($event);
            case 'paypal':
                return $this->processPayPalWebhookEvent($event);
            default:
                return ['processed' => false, 'message' => 'Unsupported gateway'];
        }
    }

    /**
     * Process Stripe webhook event
     */
    private function processStripeWebhookEvent($event) {
        $result = ['processed' => false, 'message' => 'Event not handled'];

        switch ($event['type']) {
            case 'customer.subscription.created':
                $subscription = $event['data']['object'];
                $result = $this->handleSubscriptionCreated($subscription);
                break;

            case 'customer.subscription.updated':
                $subscription = $event['data']['object'];
                $result = $this->handleSubscriptionUpdated($subscription);
                break;

            case 'customer.subscription.deleted':
                $subscription = $event['data']['object'];
                $result = $this->handleSubscriptionDeleted($subscription);
                break;

            case 'payment_intent.succeeded':
                $paymentIntent = $event['data']['object'];
                $transactionId = $paymentIntent['metadata']['transaction_id'] ?? null;
                $invoiceId = $paymentIntent['metadata']['invoice_id'] ?? null;

                if ($transactionId) {
                    // Update transaction status
                    $this->paymentTransactionModel->updateStatus($transactionId, 'completed', [
                        'gateway_transaction_id' => $paymentIntent['latest_charge']
                    ]);

                    // Update invoice if exists
                    if ($invoiceId) {
                        $this->subscriptionInvoiceModel->markAsPaid($invoiceId);
                    }

                    // Check if this was for subscription upgrade
                    $transaction = $this->paymentTransactionModel->getById($transactionId);
                    if ($transaction && isset($transaction['metadata']['plan_id'])) {
                        // This would need user_id from transaction metadata or lookup
                        // For now, just log that upgrade should happen
                        Logger::info("Subscription upgrade payment completed", [
                            'transaction_id' => $transactionId,
                            'plan_id' => $transaction['metadata']['plan_id']
                        ]);
                    }

                    $result = [
                        'processed' => true,
                        'message' => 'Payment intent succeeded',
                        'transaction_id' => $transactionId
                    ];
                }
                break;

            case 'payment_intent.payment_failed':
                $paymentIntent = $event['data']['object'];
                $transactionId = $paymentIntent['metadata']['transaction_id'] ?? null;

                if ($transactionId) {
                    $this->paymentTransactionModel->updateStatus($transactionId, 'failed', [
                        'error' => 'Payment failed'
                    ]);

                    $result = [
                        'processed' => true,
                        'message' => 'Payment intent failed',
                        'transaction_id' => $transactionId
                    ];
                }
                break;

            default:
                $result = ['processed' => true, 'message' => 'Event type not processed'];
        }

        return $result;
    }

    /**
     * Handle subscription created webhook
     */
    private function handleSubscriptionCreated($stripeSubscription) {
        Logger::info("Processing subscription created", [
            'stripe_subscription_id' => $stripeSubscription['id'],
            'customer_id' => $stripeSubscription['customer']
        ]);

        // Find user by Stripe customer ID
        $userModel = new User($this->db);
        $user = $userModel->getByStripeCustomerId($stripeSubscription['customer']);

        if (!$user) {
            Logger::error("User not found for Stripe customer", [
                'stripe_customer_id' => $stripeSubscription['customer']
            ]);
            return ['processed' => false, 'message' => 'User not found'];
        }

        // Update subscription with Stripe data
        $subscription = $this->subscriptionModel->getById(null, [
            'stripe_subscription_id' => $stripeSubscription['id']
        ]);

        if ($subscription) {
            $updateData = [
                'status' => $this->mapStripeStatus($stripeSubscription['status']),
                'current_period_start' => date('Y-m-d H:i:s', $stripeSubscription['current_period_start']),
                'current_period_end' => date('Y-m-d H:i:s', $stripeSubscription['current_period_end']),
                'cancel_at_period_end' => $stripeSubscription['cancel_at_period_end']
            ];

            $this->subscriptionModel->update($subscription['id'], $updateData);
        }

        return [
            'processed' => true,
            'message' => 'Subscription created',
            'subscription_id' => $stripeSubscription['id']
        ];
    }

    /**
     * Handle subscription updated webhook
     */
    private function handleSubscriptionUpdated($stripeSubscription) {
        Logger::info("Processing subscription updated", [
            'stripe_subscription_id' => $stripeSubscription['id'],
            'status' => $stripeSubscription['status']
        ]);

        // Find subscription by Stripe subscription ID
        $subscription = $this->subscriptionModel->getById(null, [
            'stripe_subscription_id' => $stripeSubscription['id']
        ]);

        if ($subscription) {
            $updateData = [
                'status' => $this->mapStripeStatus($stripeSubscription['status']),
                'current_period_start' => date('Y-m-d H:i:s', $stripeSubscription['current_period_start']),
                'current_period_end' => date('Y-m-d H:i:s', $stripeSubscription['current_period_end']),
                'cancel_at_period_end' => $stripeSubscription['cancel_at_period_end']
            ];

            $this->subscriptionModel->update($subscription['id'], $updateData);
        }

        return [
            'processed' => true,
            'message' => 'Subscription updated',
            'subscription_id' => $stripeSubscription['id']
        ];
    }

    /**
     * Handle subscription deleted webhook
     */
    private function handleSubscriptionDeleted($stripeSubscription) {
        Logger::info("Processing subscription deleted", [
            'stripe_subscription_id' => $stripeSubscription['id']
        ]);

        // Find subscription by Stripe subscription ID
        $subscription = $this->subscriptionModel->getById(null, [
            'stripe_subscription_id' => $stripeSubscription['id']
        ]);

        if ($subscription) {
            $this->subscriptionModel->update($subscription['id'], [
                'status' => 'cancelled',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        return [
            'processed' => true,
            'message' => 'Subscription deleted',
            'subscription_id' => $stripeSubscription['id']
        ];
    }

    /**
     * Map Stripe subscription status to local status
     */
    private function mapStripeStatus($stripeStatus) {
        $statusMap = [
            'active' => 'active',
            'canceled' => 'cancelled',
            'incomplete' => 'inactive',
            'incomplete_expired' => 'cancelled',
            'past_due' => 'inactive',
            'trialing' => 'active',
            'unpaid' => 'inactive'
        ];

        return $statusMap[$stripeStatus] ?? 'inactive';
    }

    /**
     * Process PayPal webhook event
     */
    private function processPayPalWebhookEvent($event) {
        $result = ['processed' => false, 'message' => 'Event not handled'];

        switch ($event['event_type']) {
            case 'PAYMENT.CAPTURE.COMPLETED':
                $capture = $event['resource'];
                $transactionId = $capture['custom_id'] ?? null;

                if ($transactionId) {
                    // Update transaction status
                    $this->paymentTransactionModel->updateStatus($transactionId, 'completed', [
                        'gateway_transaction_id' => $capture['id']
                    ]);

                    // Find and update invoice
                    $transaction = $this->paymentTransactionModel->getById($transactionId);
                    if ($transaction && isset($transaction['metadata']['invoice_id'])) {
                        $this->subscriptionInvoiceModel->markAsPaid($transaction['metadata']['invoice_id']);
                    }

                    $result = [
                        'processed' => true,
                        'message' => 'Payment capture completed',
                        'transaction_id' => $transactionId
                    ];
                }
                break;

            case 'PAYMENT.CAPTURE.DENIED':
                $capture = $event['resource'];
                $transactionId = $capture['custom_id'] ?? null;

                if ($transactionId) {
                    $this->paymentTransactionModel->updateStatus($transactionId, 'failed', [
                        'error' => 'Payment denied'
                    ]);

                    $result = [
                        'processed' => true,
                        'message' => 'Payment capture denied',
                        'transaction_id' => $transactionId
                    ];
                }
                break;

            default:
                $result = ['processed' => true, 'message' => 'Event type not processed'];
        }

        return $result;
    }

    /**
     * Get subscription usage
     */
    public function getSubscriptionUsage($userId) {
        $limits = $this->subscriptionManager->getUserLimits($userId);

        // In a real implementation, you'd calculate current usage
        // For now, return limits with placeholder usage
        return [
            'limits' => $limits,
            'usage' => [
                'sessions_used' => 0, // Would calculate from database
                'messages_used' => 0, // Would calculate from database
                'tokens_used' => 0    // Would calculate from database
            ]
        ];
    }

    /**
     * Get all subscriptions (admin)
     */
    public function getAllSubscriptions($page = 1, $limit = 50, $filters = []) {
        // This would be admin functionality
        // For now, return empty
        return [
            'subscriptions' => [],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => 0,
                'pages' => 0
            ]
        ];
    }

    /**
     * Get billing statistics (admin)
     */
    public function getBillingStats() {
        $subscriptionStats = $this->subscriptionModel->getStats();
        $invoiceStats = $this->subscriptionInvoiceModel->getStats();
        $transactionStats = $this->paymentTransactionModel->getStats();

        return [
            'subscriptions' => $subscriptionStats,
            'invoices' => $invoiceStats,
            'transactions' => $transactionStats
        ];
    }

    /**
     * Private helper methods
     */
    private function mapPlanTypeToTier($planType) {
        $mapping = [
            'free' => 'free',
            'basic' => 'pro',
            'premium' => 'enterprise'
        ];
        return $mapping[$planType] ?? 'free';
    }

    private function getStripeGatewayId() {
        $gateway = $this->paymentGatewayModel->getByKey('stripe');
        return $gateway ? $gateway['id'] : null;
    }

    private function createGatewayPaymentIntent($gatewayKey, $data) {
        switch ($gatewayKey) {
            case 'stripe':
                return $this->createStripePaymentIntent($data);
            case 'paypal':
                return $this->createPayPalPaymentIntent($data);
            default:
                throw new InvalidArgumentException("Unsupported gateway");
        }
    }

    private function processGatewayPayment($gatewayKey, $data) {
        switch ($gatewayKey) {
            case 'stripe':
                return $this->processStripePayment($data);
            case 'paypal':
                return $this->processPayPalPayment($data);
            default:
                throw new InvalidArgumentException("Unsupported gateway");
        }
    }

    private function createStripePaymentIntent($data) {
        if (!$this->stripeService) {
            throw new RuntimeException("Stripe service not available");
        }

        return $this->stripeService->createPaymentIntent($data);
    }

    private function processStripePayment($data) {
        if (!$this->stripeService) {
            throw new RuntimeException("Stripe service not available");
        }

        $paymentIntentId = $data['payment_intent_id'] ?? null;
        if (!$paymentIntentId) {
            throw new InvalidArgumentException("Payment intent ID required");
        }

        // Retrieve the payment intent to check its status
        // Note: The payment intent should already be confirmed by the frontend
        try {
            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);

            Logger::info("Retrieved payment intent for processing", [
                'payment_intent_id' => $paymentIntentId,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency
            ]);

            // Check if payment intent is in a successful state
            $success = in_array($paymentIntent->status, ['succeeded', 'processing']);

            if (!$success && $paymentIntent->status === 'requires_confirmation') {
                // If it still needs confirmation, try to confirm it (fallback)
                Logger::warning("Payment intent still requires confirmation, attempting to confirm", [
                    'payment_intent_id' => $paymentIntentId
                ]);

                $paymentMethodId = $data['payment_method_id'] ?? null;
                $result = $this->stripeService->confirmPaymentIntent($paymentIntentId, $paymentMethodId);
                $success = $result['status'] === 'succeeded';
                $gatewayTransactionId = $result['charge_id'] ?? $result['id'];
            } else {
                $gatewayTransactionId = $paymentIntent->latest_charge ?? $paymentIntent->id;
            }

            return [
                'success' => $success,
                'gateway_transaction_id' => $gatewayTransactionId
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            Logger::error("Failed to retrieve payment intent", [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'gateway_transaction_id' => null
            ];
        }
    }

    private function createPayPalPaymentIntent($data) {
        if (!$this->paypalService) {
            throw new RuntimeException("PayPal service not available");
        }

        return $this->paypalService->createPaymentOrder($data);
    }

    private function processPayPalPayment($data) {
        if (!$this->paypalService) {
            throw new RuntimeException("PayPal service not available");
        }

        $orderId = $data['order_id'] ?? null;
        if (!$orderId) {
            throw new InvalidArgumentException("Order ID required");
        }

        $result = $this->paypalService->capturePaymentOrder($orderId);

        return [
            'success' => $result['status'] === 'COMPLETED',
            'gateway_transaction_id' => $result['capture_id'] ?? $result['id']
        ];
    }

    private function handleStripeWebhook($data) {
        if (!$this->stripeService) {
            throw new RuntimeException("Stripe service not available");
        }

        // Get raw payload and signature from request
        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        if (!$signature) {
            throw new RuntimeException("Stripe signature missing");
        }

        $webhookResult = $this->stripeService->handleWebhook($payload, $signature);

        // Process the webhook event to update database
        if (isset($webhookResult['event'])) {
            $processResult = $this->processStripeWebhookEvent($webhookResult['event']);
            return array_merge($webhookResult, ['database_update' => $processResult]);
        }

        return $webhookResult;
    }

    private function handlePayPalWebhook($data) {
        if (!$this->paypalService) {
            throw new RuntimeException("PayPal service not available");
        }

        // PayPal sends raw JSON payload
        $payload = file_get_contents('php://input');

        $webhookResult = $this->paypalService->handleWebhook($payload);

        // Process the webhook event to update database
        if (isset($webhookResult['event'])) {
            $processResult = $this->processPayPalWebhookEvent($webhookResult['event']);
            return array_merge($webhookResult, ['database_update' => $processResult]);
        }

        return $webhookResult;
    }
}
?>
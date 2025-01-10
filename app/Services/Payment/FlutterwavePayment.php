<?php
namespace App\Services\Payment;
use Auth;
use Illuminate\Support\Facades\URL;
use Exception;
use Illuminate\Support\Facades\Log;
class FlutterwavePayment implements PaymentInterface {
    private string $secretKey;
    private string $currencyCode;
    public function __construct($currencyCode,$secretKey) {
        $this->secretKey = $secretKey;
        $this->currencyCode = $currencyCode;
    }
    /**
     * Create a payment intent using Flutterwave
     * @param $amount
     * @param $customMetaData
     * @return array
     * @throws Exception
     */
    public function createPaymentIntent($amount, $customMetaData): array {
        try {
            $finalAmount = $this->minimumAmountValidation($this->currencyCode, $amount);
            $tx_ref = uniqid();
            $redirectUrl = URL::to(route('flutterwave.success'));
            $headers = [
                'Authorization: Bearer ' . $this->secretKey,
                'Content-Type: application/json',
            ];
            $data = [
                'tx_ref' => $tx_ref,
                'amount' =>  (string)$finalAmount,
                'currency' => $this->currencyCode,
                'payment_type' => 'mobilemoneyghana',
                'customer' => [
                    'name' => $customMetaData['name'],
                    'email' => $customMetaData['email'],
                    'mobile' => $customMetaData['mobile']
                ],
                'meta' => $customMetaData,
                'redirect_url' => $redirectUrl,
                'order_id' => (string)$customMetaData['payment_transaction_id']
            ];

            $Data = json_encode($data, JSON_UNESCAPED_SLASHES);
      
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, "https://api.flutterwave.com/v3/payments");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        
            // Disabling SSL Certificate support temporarly
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $Data);
        
            // Execute post
            $response = curl_exec($ch);
           
            if ($response == FALSE) {
                die('Curl failed: ' . curl_error($ch));
            }
        
            // Close connection
            curl_close($ch);
            $error = curl_error($ch);
        
            if ($error) {
                throw new Exception("cURL error: " . $error);
            }
            $responseArray = json_decode($response, true);
            // Log the raw response for debugging
            Log::info('Flutterwave Create Payment Intent Response: ' . $response);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Invalid JSON response: ' . json_last_error_msg());
                throw new Exception('Invalid JSON response: ' . json_last_error_msg());
            }
            if ($responseArray['status'] !== 'success') {
                throw new Exception("Error creating payment intent: " . $responseArray['message']);
            }
            return $responseArray; // Return raw response
        } catch (Exception $e) {
            Log::error('Error in createPaymentIntent: ' . $e->getMessage());
            throw $e;
        }
    }
    /**
     * Create and format a payment intent
     * @param $amount
     * @param $customMetaData
     * @return array
     */
    public function createAndFormatPaymentIntent($amount, $customMetaData): array {
        $paymentIntent = $this->createPaymentIntent($amount, $customMetaData);
        return $this->format($paymentIntent,$this->currencyCode, $amount, $customMetaData);
    }
    /**
     * Retrieve and format a payment intent
     * @param $paymentId
     * @return array
     * @throws Exception
     */
    public function retrievePaymentIntent($paymentId): array {
        try {
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => "https://api.flutterwave.com/v3/transactions/{$paymentId}/verify",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->secretKey,
                    'Content-Type: application/json'
                ],
            ]);
            // Execute cURL request and fetch response
            $response = curl_exec($curl);
            $error = curl_error($curl);
            curl_close($curl);
            // Check for cURL errors
            if ($error) {
                throw new Exception("cURL error: " . $error);
            }
            // Log the raw response for debugging
            Log::info('Flutterwave Raw API Response: ' . $response);
            // Check if response is empty or malformed
            if (empty($response)) {
                throw new Exception("Empty response from Flutterwave API");
            }
            // Attempt to decode the JSON response
            $responseArray = json_decode($response, true);
         
            // Handle invalid JSON responses
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Log the invalid JSON error and raw response
                Log::error('Invalid JSON response: ' . json_last_error_msg());
                throw new Exception('Invalid JSON response: ' . json_last_error_msg());
            }
            // Check if the response status is 'success'
            if (!isset($responseArray['status']) || $responseArray['status'] !== 'success') {
                // Log the error message from Flutterwave
                Log::error('Error retrieving payment intent: ' . json_encode($responseArray));
                throw new Exception("Error retrieving payment intent: " . ($responseArray['message'] ?? 'Unknown error'));
            }
            // Return formatted response data
            return $this->format($responseArray['data'],$responseArray['data']['amount'],$responseArray['data']['currency'], $metadata = null);
        } catch (Exception $e) {
            // Log the exception for debugging purposes
            Log::error('Error in retrievePaymentIntent: ' . $e->getMessage());
            throw $e;
        }
    }
    /**
     * Format the payment intent response
     * @param $paymentIntent
     * @return array
     */
    public function format($paymentIntent, $currencyCode, $amount, $metadata) {
        return $this->formatPaymentIntent($paymentIntent['data']['link'], $amount, $currencyCode, $paymentIntent['status'], $metadata, $paymentIntent);
    }
    /**
     * Format payment intent details
     * @param $id
     * @param $amount
     * @param $currency
     * @param $status
     * @param $metadata
     * @param $paymentIntent
     * @return array
     */
    public function formatPaymentIntent($id, $amount, $currency, $status, $metadata, $paymentIntent): array {
        return [
            'id'                       => $id,
            'amount'                   => $amount,
            'currency'                 => $currency,
            'metadata'                 => $metadata,
            'status'                   => match ($status) {
                'cancel'   => 'failed',
                'success' => 'success',
                'pending'    => 'pending',
                default      => 'unknown',
            },
            'payment_gateway_response' => $paymentIntent
        ];
    }

    /**
     * Validate the minimum amount based on currency
     * @param $currency
     * @param $amount
     * @return float|int
     */
    public function minimumAmountValidation($currency, $amount) {
        return max(1, $amount);
    }
}




<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GmailService
{
    private string $clientId;
    private string $clientSecret;
    private string $refreshToken;
    
    public function __construct()
    {
        $this->clientId = config('services.google.client_id');
        $this->clientSecret = config('services.google.client_secret');
        $this->refreshToken = config('services.google.refresh_token');
    }
    
    private function getAccessToken(): ?string
    {
        try {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $this->refreshToken,
                'grant_type' => 'refresh_token',
            ]);
            
            if ($response->successful()) {
                return $response->json('access_token');
            }
            
            Log::error('Gmail OAuth token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            
            return null;
        } catch (\Exception $e) {
            Log::error('Gmail OAuth exception', ['message' => $e->getMessage()]);
            return null;
        }
    }
    
    public function sendEmail(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $cc = null,
        ?string $bcc = null
    ): array {
        if (!$this->clientId || !$this->clientSecret || !$this->refreshToken) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => 'Gmail OAuth credentials not configured.',
            ];
        }
        
        $accessToken = $this->getAccessToken();
        
        if (!$accessToken) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => 'Failed to obtain Gmail access token',
            ];
        }
        
        $from = config('mail.from.address', 'mbfdsupport@gmail.com');
        $fromName = config('mail.from.name', 'MBFD Support Hub');
        
        $message = "From: \"{$fromName}\" <{$from}>\r\n";
        $message .= "To: {$to}\r\n";
        
        if ($cc) {
            $message .= "Cc: {$cc}\r\n";
        }
        
        if ($bcc) {
            $message .= "Bcc: {$bcc}\r\n";
        }
        
        $message .= "Subject: {$subject}\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/html; charset=utf-8\r\n\r\n";
        $message .= $htmlBody;
        
        $encodedMessage = rtrim(strtr(base64_encode($message), '+/', '-_'), '=');
        
        try {
            $response = Http::withToken($accessToken)
                ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                    'raw' => $encodedMessage,
                ]);
            
            if ($response->successful()) {
                return [
                    'success' => true,
                    'message_id' => $response->json('id'),
                    'error' => null,
                ];
            }
            
            Log::error('Gmail API send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            
            return [
                'success' => false,
                'message_id' => null,
                'error' => 'Gmail API error: ' . $response->status(),
            ];
            
        } catch (\Exception $e) {
            Log::error('Gmail send exception', ['message' => $e->getMessage()]);
            
            return [
                'success' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
}

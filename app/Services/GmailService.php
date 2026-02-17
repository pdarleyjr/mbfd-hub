<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Illuminate\Support\Facades\Log;

class GmailService
{
    private GoogleClient $client;
    private Gmail $service;

    public function __construct()
    {
        $this->client = new GoogleClient();
        $this->client->setClientId(config('services.gmail.client_id'));
        $this->client->setClientSecret(config('services.gmail.client_secret'));
        $this->client->setAccessToken([
            'refresh_token' => config('services.gmail.refresh_token'),
        ]);

        // Auto-refresh access token
        if ($this->client->isAccessTokenExpired()) {
            $this->client->fetchAccessTokenWithRefreshToken();
        }

        $this->service = new Gmail($this->client);
    }

    public function sendEmail(array $params): array
    {
        try {
            $to = is_array($params['to']) ? implode(', ', $params['to']) : $params['to'];
            $from = $params['from'] ?? config('services.gmail.sender_email');
            
            $rawMessage = "From: {$from}\r\n";
            $rawMessage .= "To: {$to}\r\n";
            
            if (!empty($params['cc'])) {
                $cc = is_array($params['cc']) ? implode(', ', $params['cc']) : $params['cc'];
                $rawMessage .= "Cc: {$cc}\r\n";
            }
            
            $rawMessage .= "Subject: {$params['subject']}\r\n";
            $rawMessage .= "MIME-Version: 1.0\r\n";
            $rawMessage .= "Content-Type: text/html; charset=utf-8\r\n\r\n";
            $rawMessage .= $params['body'];

            $message = new Message();
            $message->setRaw($this->base64UrlEncode($rawMessage));

            $sentMessage = $this->service->users_messages->send('me', $message);

            Log::info('Gmail email sent successfully', [
                'message_id' => $sentMessage->id,
                'to' => $to,
                'subject' => $params['subject'],
            ]);

            return [
                'success' => true,
                'message_id' => $sentMessage->id,
                'timestamp' => now()->toDateTimeString(),
            ];
        } catch (\Exception $e) {
            Log::error('Gmail send failed', [
                'error' => $e->getMessage(),
                'params' => array_except($params, ['body']),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

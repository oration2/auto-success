<?php

namespace App\Services;

/**
 * Telegram API Service
 * 
 * Handles communication with Telegram Bot API
 */
class TelegramAPI
{
    // API URLs
    private $apiUrl;
    private $fileApiUrl;
    
    // Bot information
    private $token;
    private $username;
    
    // Logger instance
    private $logger;
    
    /**
     * Constructor
     * 
     * @param string $token Bot API token
     * @param string $username Bot username
     * @param object|null $logger Logger instance
     */
    public function __construct($token, $username = '', $logger = null)
    {
        $this->token = $token;
        $this->username = $username;
        $this->apiUrl = 'https://api.telegram.org/bot' . $token;
        $this->fileApiUrl = 'https://api.telegram.org/file/bot' . $token;
        $this->logger = $logger;
    }
    
    /**
     * Make API request
     * 
     * @param string $method API method
     * @param array $params Request parameters
     * @return array|false Response data
     */
    public function request($method, $params = [])
    {
        // Prepare request URL
        $url = $this->apiUrl . '/' . $method;
        
        // Encode array parameters (like reply_markup) as JSON
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = json_encode($value);
            }
        }
        
        // Initialize cURL
        $curl = curl_init();
        
        // Set cURL options
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        
        // Execute request
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        
        // Close cURL
        curl_close($curl);
        
        // Log errors
        if ($status != 200) {
            $errorMessage = "Telegram API error (HTTP {$status}): {$error}";
            if ($this->logger) {
                $this->logger->error($errorMessage, [
                    'method' => $method,
                    'params' => $params,
                    'response' => $response
                ]);
            }
            return false;
        }
        
        // Parse response
        $data = json_decode($response, true);
        
        // Check for API error
        if (!isset($data['ok']) || $data['ok'] !== true) {
            $errorMessage = "Telegram API error: " . ($data['description'] ?? 'Unknown error');
            if ($this->logger) {
                $this->logger->error($errorMessage, [
                    'method' => $method,
                    'params' => $params,
                    'response_data' => $data
                ]);
            }
            return false;
        }
        
        return $data['result'];
    }
    
    /**
     * Send message
     * 
     * @param string $chatId Chat ID
     * @param string $text Message text
     * @param array $options Additional options
     * @return array|false Response data
     */
    public function sendMessage($chatId, $text, $options = [])
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'text' => $text
        ], $options);
        
        return $this->request('sendMessage', $params);
    }
    
    /**
     * Edit message
     * 
     * @param string $chatId Chat ID
     * @param int $messageId Message ID
     * @param string $text New message text
     * @param array $options Additional options
     * @return array|false Response data
     */
    public function editMessageText($chatId, $messageId, $text, $options = [])
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text
        ], $options);
        
        return $this->request('editMessageText', $params);
    }
    
    /**
     * Delete message
     * 
     * @param string $chatId Chat ID
     * @param int $messageId Message ID
     * @return array|false Response data
     */
    public function deleteMessage($chatId, $messageId)
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ];
        
        return $this->request('deleteMessage', $params);
    }
    
    /**
     * Get updates
     * 
     * @param int $offset Update offset
     * @param int $limit Maximum updates to retrieve
     * @param int $timeout Long polling timeout
     * @return array|false Updates data
     */
    public function getUpdates($offset = 0, $limit = 100, $timeout = 30)
    {
        $params = [
            'offset' => $offset,
            'limit' => $limit,
            'timeout' => $timeout
        ];
        
        return $this->request('getUpdates', $params);
    }
    
    /**
     * Get file info
     * 
     * @param string $fileId File ID
     * @return array|false File info
     */
    public function getFile($fileId)
    {
        $params = [
            'file_id' => $fileId
        ];
        
        return $this->request('getFile', $params);
    }
    
    /**
     * Download file
     * 
     * @param string $filePath File path from getFile
     * @param string $localPath Local path to save file
     * @return bool Success status
     */
    public function downloadFile($filePath, $localPath)
    {
        $url = $this->fileApiUrl . '/' . $filePath;
        
        $fileContent = file_get_contents($url);
        if ($fileContent === false) {
            return false;
        }
        
        return file_put_contents($localPath, $fileContent) !== false;
    }
    
    /**
     * Answer callback query
     * 
     * @param string $callbackQueryId Callback query ID
     * @param string $text Notification text
     * @param bool $showAlert Show as alert
     * @return array|false Response data
     */
    public function answerCallbackQuery($callbackQueryId, $text = '', $showAlert = false)
    {
        $params = [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert
        ];
        
        return $this->request('answerCallbackQuery', $params);
    }
    
    /**
     * Set webhook
     * 
     * @param string $url Webhook URL
     * @param array $options Additional options
     * @return array|false Response data
     */
    public function setWebhook($url, $options = [])
    {
        $params = array_merge([
            'url' => $url
        ], $options);
        
        return $this->request('setWebhook', $params);
    }
    
    /**
     * Delete webhook
     * 
     * @return array|false Response data
     */
    public function deleteWebhook()
    {
        return $this->request('deleteWebhook');
    }
    
    /**
     * Get webhook info
     * 
     * @return array|false Webhook info
     */
    public function getWebhookInfo()
    {
        return $this->request('getWebhookInfo');
    }
    
    /**
     * Get bot info
     * 
     * @return array|false Bot info
     */
    public function getMe()
    {
        return $this->request('getMe');
    }
    
    /**
     * Send chat action (typing, etc.)
     * 
     * @param string $chatId Chat ID
     * @param string $action Chat action
     * @return array|false Response data
     */
    public function sendChatAction($chatId, $action = 'typing')
    {
        $params = [
            'chat_id' => $chatId,
            'action' => $action
        ];
        
        return $this->request('sendChatAction', $params);
    }
}

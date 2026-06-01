<?php
require_once __DIR__ . '/../vendor/autoload.php';

class GoogleCalendarHelper {
    private $client;
    private $service;
    private $tokenPath;
    
    public function __construct($userId = null) {
        $this->client = new Google_Client();
        $this->client->setApplicationName('Kyoshi Booking System');
        $this->client->setScopes(Google_Service_Calendar::CALENDAR_EVENTS);
        $this->client->setAuthConfig(__DIR__ . '/credentials.json');
        $this->client->setAccessType('offline');
        $this->client->setPrompt('select_account consent');
        
        // Set redirect URI
        $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
        $redirectUri = $protocol . $_SERVER['HTTP_HOST'] . '/Kyoshi/php/google-calendar-callback.php';
        $this->client->setRedirectUri($redirectUri);
        
        // Store token per user
        $this->tokenPath = __DIR__ . '/token_' . ($userId ?? 'default') . '.json';
        
        $this->loadToken();
    }
    
    private function loadToken() {
        if (file_exists($this->tokenPath)) {
            $accessToken = json_decode(file_get_contents($this->tokenPath), true);
            $this->client->setAccessToken($accessToken);
        }
    }
    
    private function saveToken($token) {
        file_put_contents($this->tokenPath, json_encode($token));
    }
    
    public function getAuthUrl() {
        return $this->client->createAuthUrl();
    }
    
    public function authenticate($code) {
        $token = $this->client->fetchAccessTokenWithAuthCode($code);
        if (!isset($token['error'])) {
            $this->saveToken($token);
            return true;
        }
        return false;
    }
    
    public function isAuthenticated() {
        if ($this->client->getAccessToken()) {
            if ($this->client->isAccessTokenExpired()) {
                $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                $this->saveToken($this->client->getAccessToken());
            }
            return true;
        }
        return false;
    }
    
    public function createEvent($bookingData, $studentEmail, $tutorEmail) {
        if (!$this->isAuthenticated()) {
            throw new Exception('Not authenticated with Google Calendar');
        }
        
        $this->service = new Google_Service_Calendar($this->client);
        
        $startDateTime = date('Y-m-d\TH:i:s', strtotime($bookingData['booking_date'] . ' ' . $bookingData['booking_time']));
        $endDateTime = date('Y-m-d\TH:i:s', strtotime($bookingData['booking_date'] . ' ' . $bookingData['booking_time'] . ' +60 minutes'));
        
        $description = "Tutor: " . $bookingData['tutor_name'] . "\n";
        $description .= "Student: " . $bookingData['student_name'] . "\n";
        $description .= "Language: " . $bookingData['language'] . "\n";
        $description .= "Focus: " . ($bookingData['focus'] ?? 'General') . "\n";
        $description .= "Booking ID: " . ($bookingData['booking_id'] ?? 'N/A') . "\n\n";
        $description .= "Notes: " . ($bookingData['notes'] ?? 'No special notes');
        
        $event = new Google_Service_Calendar_Event([
            'summary' => 'Language Session with ' . $bookingData['tutor_name'],
            'location' => $bookingData['learning_mode'] === 'online' ? 'Online Session' : ($bookingData['meeting_location'] ?? 'Location TBD'),
            'description' => $description,
            'start' => [
                'dateTime' => $startDateTime,
                'timeZone' => 'Asia/Kuala_Lumpur',
            ],
            'end' => [
                'dateTime' => $endDateTime,
                'timeZone' => 'Asia/Kuala_Lumpur',
            ],
            'attendees' => [
                ['email' => $studentEmail],
                ['email' => $tutorEmail],
            ],
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'email', 'minutes' => 24 * 60],
                    ['method' => 'email', 'minutes' => 60],
                    ['method' => 'popup', 'minutes' => 30],
                    ['method' => 'popup', 'minutes' => 10],
                ],
            ],
        ]);
        
        if ($bookingData['learning_mode'] === 'online') {
            $event->setConferenceData(new Google_Service_Calendar_ConferenceData([
                'createRequest' => new Google_Service_Calendar_CreateConferenceRequest([
                    'requestId' => uniqid(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ]),
            ]));
        }
        
        $event = $this->service->events->insert('primary', $event, [
            'conferenceDataVersion' => 1,
            'sendUpdates' => 'all',
            'sendNotifications' => true
        ]);
        
        return [
            'event_id' => $event->getId(),
            'event_link' => $event->getHtmlLink(),
            'meet_link' => $event->getHangoutLink() ?? null
        ];
    }
}
?>
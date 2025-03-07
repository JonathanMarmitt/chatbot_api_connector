<?php
namespace Inbenta\ChatbotConnector;

use \Exception;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\ChatbotConnector\Utils\LanguageManager;
use Inbenta\ChatbotConnector\Utils\ConfigurationLoader;
use Inbenta\ChatbotConnector\Utils\EnvironmentDetector;
use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;

class ChatbotConnector
{
	public 	  $conf;				// App Configuration
	public 	  $lang;				// Language manager
	public 	  $externalClient;		// External service client
	public 	  $session;				// Session manager
	protected $botClient;			// Chatbot Client
	protected $digester;			// External requests digester
	protected $chatClient;			// Hyperchat client
	protected $environment; 		// Application environment

	const ESCALATION_NO_RESULTS        = '__escalation_type_no_results__';
	const ESCALATION_API_FLAG          = '__escalation_type_api_flag__';
	const ESCALATION_NEGATIVE_RATING   = '__escalation_type_negative_rating__';

	function __construct($appPath)
	{
		// Create base components
		$this->conf 		= (new ConfigurationLoader($appPath))->getConf();
		$this->lang 		= new LanguageManager( $this->conf->get('conversation.default.lang'), $appPath );
		$this->environment 	= $this->conf->get('environments.current');

		if (empty($this->conf->get('api.key')) || empty($this->conf->get('api.secret'))) {
			throw new Exception("Empty Chatbot API Key or Secret. Please, review your /conf/custom/api.php file");
		}
	}

	/**
	 * 	Initialize class components specific for an external service
	 */
	public function initComponents($externalClient, $chatClient, $externalDigester)
	{
		//Load application components
		$this->externalClient 		= $externalClient;
		$this->digester 			= $externalDigester;
		$this->chatClient 			= $chatClient;
	}

	/**
	 *	Retrieve Language translations from ExtraInfo
	 */
	protected function getTranslationsFromExtraInfo($parentGroupName, $translationsObjectName)
	{
		$translations = [];
		$extraInfoData = $this->botClient->getExtraInfo($parentGroupName);
		foreach ($extraInfoData->results as $element) {
			if ($element->name == $translationsObjectName) {
				$translations = json_decode(json_encode($element->value), true);
				break;
			}
		}
		$language = $this->conf->get('conversation.default.lang');
		if (isset($translations[$language]) && count($translations[$language]) && is_array($translations[$language][0])) {
			$this->lang->addTranslations($translations[$language][0]);
		}
	}

	/**
	 * 	Handle a request (from external service or from Hyperchat)
	 */
	public function handleRequest()
	{
		try {
			// Return 200 OK response
			$this->returnOkResponse();
			// Store request
			$request = file_get_contents('php://input');
			// Translate the request into a ChatbotAPI request
			$externalRequest = $this->digester->digestToApi($request);
			// Check if it's needed to perform any action other than a standard user-bot interaction
			$this->handleNonBotActions($externalRequest);
			// Handle standard bot actions
			$this->handleBotActions($externalRequest);
		} catch (Exception $e) {
			echo json_encode(["error" => $e->getMessage()]);
			die();
		}
	}

	/**
	 *	Check if it's needed to perform any action other than a standard user-bot interaction
	 */
	protected function handleNonBotActions($digestedRequest)
	{
		// If there is a active chat, send messages to the agent
		if ($this->chatOnGoing()) {
			$this->sendMessagesToChat($digestedRequest);
			die();
		}
		// If user answered to an ask-to-escalate question, handle it
		if ($this->session->get('askingForEscalation', false)) {
			$this->handleEscalation($digestedRequest);
		}
		// If the user clicked in a Federated Bot option, handle its request
		if (count($digestedRequest) && isset($digestedRequest[0]['extendedContentAnswer'])) {
			$selectedAnswer = json_decode(json_encode($digestedRequest[0]['extendedContentAnswer']));
			$answers = $this->session->get('federatedSubanswers');
			if (is_int($selectedAnswer) && is_array($answers) && isset($answers[$selectedAnswer])) {
				$this->displayFederatedBotAnswer($answers[$selectedAnswer]);
			} elseif (is_array($selectedAnswer)) {
				$this->displayFederatedBotAnswer($selectedAnswer);
			}
			die();
		}
	}

	/**
	 *	Handle an incoming request for the ChatBot
	 */
	public function handleBotActions($externalRequest)
	{
		$needEscalation = false;
		$needContentRating = false;
		foreach ($externalRequest as $message) {
			// Check if is needed to execute any preset 'command'
			$this->handleCommands($message);
			// Store the last user text message to session
			$this->saveLastTextMessage($message);
			// Check if is needed to ask for a rating comment
			$message = $this->checkContentRatingsComment($message);
			// Send the messages received from the external service to the ChatbotAPI
			$botResponse = $this->sendMessageToBot($message);
			// Check if escalation to agent is needed
			$needEscalation = $this->checkEscalation($botResponse) ? true : $needEscalation;
			// Check if is needed to display content ratings
			$hasRating = $this->checkContentRatings($botResponse);
			$needContentRating = $hasRating ? $hasRating : $needContentRating;
			// Send the messages received from ChatbotApi back to the external service
			$this->sendMessagesToExternal($botResponse);
		}
		if ($needEscalation) {
			$this->handleEscalation();
		}
		// Display content rating if needed and not in chat nor asking to escalate
		if ($needContentRating && !$this->chatOnGoing() && !$this->session->get('askingForEscalation', false)) {
			$this->displayContentRatings($needContentRating);
		}
	}

	/**
	 * 	Checks if a bot response requires escalation to chat
	 */
	protected function checkEscalation($botResponse)
	{
		if (!$this->chatEnabled()) {
			return false;
		}

		// Parse bot messages
		if (isset($botResponse->answers) && is_array($botResponse->answers)) {
			$messages = $botResponse->answers;
		} else {
			$messages = array($botResponse);
		}

		// Check if BotApi returned 'escalate' flag on message or triesBeforeEscalation has been reached
		foreach ($messages as $msg) {
			$this->updateNoResultsCount($msg);

            $apiEscalateFlag                     = isset($msg->flags) && in_array('escalate', $msg->flags);
            $noResultsToEscalateReached          = $this->shouldEscalateFromNoResults();
            $negativeRatingsToEscalateReached    = $this->shouldEscalateFromNegativeRating();

            if ($apiEscalateFlag || $noResultsToEscalateReached || $negativeRatingsToEscalateReached) {
                // Store into session the escalation type
                if ($apiEscalateFlag) {
                    $escalationType = static::ESCALATION_API_FLAG;
                } elseif ($noResultsToEscalateReached) {
                    $escalationType = static::ESCALATION_NO_RESULTS;
                } elseif ($negativeRatingsToEscalateReached) {
                    $escalationType = static::ESCALATION_NEGATIVE_RATING;
                }
				$this->session->set('escalationType', $escalationType);
				return true;
			}
		}
		return false;
	}

    /**
     * 	Check if should escalate to chat because the configured no-results count has been reached
     */
    protected function shouldEscalateFromNoResults()
    {
        $triesBeforeEscalation = $this->conf->get('chat.triesBeforeEscalation');
        return $this->chatEnabled() && $triesBeforeEscalation && $this->session->get('noResultsCount') >= $triesBeforeEscalation;
    }

    /**
     * 	Check if should escalate to chat because the configured negative-rating count has been reached
     */
    protected function shouldEscalateFromNegativeRating()
    {
        $negativeRatingsBeforeEscalation 	= $this->conf->get('chat.negativeRatingsBeforeEscalation');
        return $this->chatEnabled() && $negativeRatingsBeforeEscalation && $this->session->get('negativeRatingCount') >= $negativeRatingsBeforeEscalation;
    }

    /**
     * 	Updates the number of consecutive no-result answers
     */
	protected function updateNoResultsCount($message)
	{
		$count = $this->session->get('noResultsCount');
		if (isset($message->flags) &&  in_array('no-results', $message->flags)) {
			$count++;
		} else {
			$count = 0;
		}
		$this->session->set('noResultsCount', $count);
	}

	/**
	 *  Reduce the escalation counter that triggered the current escalation try
	 */
	protected function reduceCurrentEscalationCounter()
	{
        $escalationType = $this->session->get('escalationType');
        if ($escalationType == static::ESCALATION_NO_RESULTS) {
            $this->session->set('noResultsCount', $this->session->get('noResultsCount') - 1);
        } elseif ($escalationType == static::ESCALATION_NEGATIVE_RATING) {
            $this->session->set('negativeRatingCount', $this->session->get('negativeRatingCount') - 1);
        }
	}

    /**
     * 	Ask the user if wants to talk with a human and handle the answer
     */
	protected function handleEscalation($userAnswer = null)
	{
		// Ask the user if wants to escalate
		if (!$this->session->get('askingForEscalation', false)) {
			if ($this->checkAgents()) {
				// Ask the user if wants to escalate
				$this->session->set('askingForEscalation', true);
				$escalationMessage = $this->digester->buildEscalationMessage();
				$this->externalClient->sendMessage($escalationMessage);
			} else {
				// Send no-agents-available message if the escalation trigger is an API flag (user asked for having a chat explicitly)
				if ($this->session->get('escalationType') == static::ESCALATION_API_FLAG) {
					$this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('no_agents')));
				}
				// Because no agents available, reduce the current escalation counter to escalate on next counter update
				$this->reduceCurrentEscalationCounter();
				$this->trackContactEvent("CONTACT_UNATTENDED");
			}
		} else {
			// Handle user response to an escalation question
			$this->session->set('askingForEscalation', false);
			// Reset escalation counters
			$this->session->set('noResultsCount', 0);
			$this->session->set('negativeRatingCount', 0);

			if (count($userAnswer) && isset($userAnswer[0]['escalateOption'])) {
			    if ($userAnswer[0]['escalateOption']) {
			        $this->escalateToAgent();
			    } else {
			        $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('escalation_rejected')));
			        $this->trackContactEvent("CONTACT_REJECTED");
			    }
			    die();
			}
		}
	}

    /**
     * 	Tries to start a chat with an agent
     */
	protected function escalateToAgent()
	{
		$agentsAvailable = $this->checkAgents();
		
		if ($agentsAvailable) {
			// Start chat
			$this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('creating_chat')));
			// Build user data for HyperChat API
			$chatData = array(
				'roomId' => $this->conf->get('chat.chat.roomId'),
				'user' => array(
					'name' 			=> $this->externalClient->getFullName(),
					'contact' 		=> $this->externalClient->getEmail(),
					'externalId' 	=> $this->externalClient->getExternalId(),
					'extraInfo' 	=> array()
				)
			);
			$response =  $this->chatClient->openChat($chatData);
			if (!isset($response->error) && isset($response->chat)) {
				$this->session->set('chatOnGoing', $response->chat->id);
				$this->trackContactEvent("CONTACT_ATTENDED");
			} else {
				$this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('error_creating_chat')));
			}
		} else {
			// Send no-agents-available message if the escalation trigger is an API flag (user asked for having a chat explicitly)
			if ($this->session->get('escalationType') == static::ESCALATION_API_FLAG) {
				$this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('no_agents')));
			}
			$this->trackContactEvent("CONTACT_UNATTENDED");
		}
	}

	/**
	 * Check if there are agents available
	 * @return boolean
	 */
	protected function checkAgents()
	{
		$chatConf = $this->conf->get('chat.chat');
		$queueActive = isset($chatConf['queue']) && isset($chatConf['queue']['active']) && $chatConf['queue']['active'];
		return $queueActive ? $this->chatClient->checkAgentsOnline() : $this->chatClient->checkAgentsAvailable();
	}

    /**
     * 	Builds a text message in ChatbotApi format, ready to be sent through method 'sendMessagesToExternal'
     */
	protected function buildTextMessage( $text )
	{
		$message = array(
			'type' => 'answer',
			'message' => $text
		);
		return (object) $message;
	}

    /**
     * 	Send messages to Chatbot API
     */
	protected function sendMessageToBot( $message )
	{
		try {
			if (isset($message['type']) && $message['type'] !== 'answer') {
				// Send event track to bot
				return $this->sendEventToBot($message);
			} elseif (isset($message['message']) || isset($message['option']) || isset($message['directCall'])) {
				// Send message to bot
				$this->externalClient->showBotTyping();
				$botResponse = $this->botClient->sendMessage($message);
				if (isset($botResponse->message) && $botResponse->message == "Endpoint request timed out") {
					$botResponse = $this->buildTextMessage($this->lang->translate('api_timeout'));
				}
				return $botResponse;
			}
		} catch (Exception $e) {
			//If session expired, start new conversation and retry
			if ($e->getCode() == 400 && $e->getMessage() == 'Session expired') {
				$this->botClient->startConversation($this->conf->get('conversation.default'), $this->conf->get('conversation.user_type'), $this->environment);
				return $this->sendMessageToBot($message);
			}
			throw new Exception("Error while sending message to bot: " . $e->getMessage());
		}
	}

    /**
     * 	Send messages to the external service. Messages should be formatted as a ChatbotAPI response
     */
	protected function sendMessagesToExternal( $messages )
	{
		// Digest the bot response into the external service format
		$digestedBotResponse = $this->digester->digestFromApi($messages,  $this->session->get('lastUserQuestion'));
		foreach ($digestedBotResponse as $message) {
			$this->externalClient->sendMessage($message);
		}
	}

    /**
     * 	Send messages received from external service to HyperChat
     */
	protected function sendMessagesToChat($digestedRequest)
	{
		foreach ($digestedRequest as $message) {
			$message = (object)$message;
			$data = array(
				'user' => array(
					'externalId' => $this->externalClient->getExternalId()
				),
				'message' => isset($message->message) ? $message->message : ''
			);
			$response = $this->chatClient->sendMessage($data);
		}
	}

    /**
     * 	Checks if there is a chat session active for the current user
     */
	protected function chatOnGoing()
	{
		if (!$this->chatEnabled()) {
			return false;
		}

		$chat = $this->session->get('chatOnGoing');
		$chatInfo = $this->chatClient->getChatInformation( $chat );
		if ($chat && isset($chatInfo->status)) {
			if ($chatInfo->status != 'closed') {
				return true;
			} else {
				$this->session->set('chatOnGoing', false);
			}
		}
		return false;
	}

    /**
     * 	Return if chat is enabled in configuration
     */
	protected function chatEnabled()
	{
		return $this->conf->get('chat.chat.enabled');
	}

    /**
     * 	Store the last user text message to session
     */
	protected function saveLastTextMessage($message)
	{
		if (isset($message['message']) && is_string($message['message'])) {
			$this->session->set('lastUserQuestion', $message['message']);
		}
	}

    /**
     * 	Check if a bot response should display content-ratings
     */
	protected function checkContentRatings($botResponse)
	{
		$ratingConf = $this->conf->get('conversation.content_ratings');
		if (!$ratingConf['enabled']) {
			return false;
		}

		// Parse bot messages
		if (isset($botResponse->answers) && is_array($botResponse->answers)) {
			$messages = $botResponse->answers;
		} else {
			$messages = array($botResponse);
		}

		// Check messages are answer and have a rate-code
		$rateCode = false;
		foreach ($messages as $msg) {
			$isAnswer            = isset($msg->type) && $msg->type == 'answer';
			$hasEscalationFlag   = isset($msg->flags) && in_array('escalate', $msg->flags);
			$hasNoRatingsFlag    = isset($msg->flags) && in_array('no-rating', $msg->flags);
			$hasRatingCode       = isset($msg->parameters) &&
								   isset($msg->parameters->contents) &&
								   isset($msg->parameters->contents->trackingCode) &&
								   isset($msg->parameters->contents->trackingCode->rateCode);

			if ($isAnswer && $hasRatingCode && !$hasEscalationFlag && !$hasNoRatingsFlag) {
				$rateCode = $msg->parameters->contents->trackingCode->rateCode;
			}
		}
		return $rateCode;
	}

    /**
     * 	Checks if a content-rating answer should ask for a comment
     */
	protected function checkContentRatingsComment($message)
	{
		// If is a rating message
		if (isset($message['ratingData'])) {
            // Update negativeRatingCount to escalate if necessary
            $negativeRatingCount = $this->session->get('negativeRatingCount');
            if (isset($message['isNegativeRating']) && $message['isNegativeRating']) {
                $negativeRatingCount += 1;
            } else {
                $negativeRatingCount = 0;
            }
            $this->session->set('negativeRatingCount', $negativeRatingCount);

            // Handle a rating that should ask for a comment
			if ($message['askRatingComment']) {
				// Save the rating data to session to use later, when user sends his comment
				$this->session->set('askingRatingComment', $message['ratingData']);
			}

			// Return rating data to log the rating
			return $message['ratingData'];

		} elseif ($this->session->has('askingRatingComment') && $this->session->get('askingRatingComment') != false) {
			// Send the rating with comment
			$ratingData = $this->session->get('askingRatingComment');
			$ratingData['data']['comment'] = $message['message'];

			// Forget we're asking for a rating comment
			$this->session->set('askingRatingComment', false);
			return $ratingData;
		}
		return $message;
	}

    /**
     * 	Display content rating message
     */
	protected function displayContentRatings($rateCode)
	{
		$ratingOptions = $this->conf->get('conversation.content_ratings.ratings');
		$ratingMessage = $this->digester->buildContentRatingsMessage($ratingOptions, $rateCode);
		$this->externalClient->sendMessage($ratingMessage);
	}

    /**
     * 	Log an event to the bot
     */
	protected function sendEventToBot($event)
	{
		$bot_tracking_events = ['rate', 'click'];
		if (!in_array($event['type'], $bot_tracking_events)) {
			die();
		}

		$response = $this->botClient->trackEvent($event);
		switch ($event['type']) {
			case 'rate':
				$askingRatingComment    = $this->session->has('askingRatingComment') && $this->session->get('askingRatingComment') != false;
                $willEscalate           = $this->shouldEscalateFromNegativeRating() && $this->checkAgents();
                if ($askingRatingComment && !$willEscalate) {
					// Ask for a comment on a content-rating
					return $this->buildTextMessage($this->lang->translate('ask_rating_comment'));
				} else {
					// Forget we were asking for a rating comment
					$this->session->set('askingRatingComment', false);
					// Send 'Thanks' message after rating
					return $this->buildTextMessage($this->lang->translate('thanks'));
				}

			break;
		}
	}

	/**
	 *	Detect some text 'commands' and perform the attached actions
	 *	@param $message string
	 */
	protected function handleCommands($message)
	{
		// Only in development and preproduction environments
		if (isset($message['message']) && $this->environment !== EnvironmentDetector::PRODUCTION_ENV) {
			switch ($message['message']) {
				case 'clear_cached_appdata':
					$removed = unlink($this->botClient->appDataCacheFile);
					$this->sendMessagesToExternal($this->buildTextMessage('Clear cached AppData response: "' .$removed. '"'));
					die();
					break;

				case 'clear_user_session':
					$this->session->clear();
					$this->sendMessagesToExternal($this->buildTextMessage('User session cleared.'));
					die();
					break;

				case 'show_user_id':
					$this->sendMessagesToExternal($this->buildTextMessage($this->externalClient->getExternalId()));
					die();
					break;
			}
		}
	}

	/**
	 *	Displays a Federated Bot answer and logs the click
	 */
	protected function displayFederatedBotAnswer($answer)
	{
		// Send the Federated Bot message to the external service
		$this->sendMessagesToExternal($answer);

		// Log the content click
		if (isset($answer->parameters) &&
			isset($answer->parameters->contents) &&
			isset($answer->parameters->contents->trackingCode) &&
			isset($answer->parameters->contents->trackingCode->clickCode)
		) {
			$clickData = [
				"type" => "click",
				"data" => [
					"code" => $answer->parameters->contents->trackingCode->clickCode
				]
			];
			$this->sendEventToBot($clickData);
		}
	}

	/**
	 *	Return a 200 OK response before continuing with the script execution
	 */
	protected function returnOkResponse()
	{
	    ob_start();
	    header('Connection: close');
	    header('Content-Length: '.ob_get_length());
	    ob_end_flush();
	    ob_flush();
	    flush();
	}

	/**
	 * Function to track CONTACT events
	 * @param string $type Contact type: "CONTACT_ATTENDED", "CONTACT_UNATTENDED" or "CONTACT_REJECTED"
	 */
	public function trackContactEvent($type)
	{
	    $data = array(
	        "type" => $type,
	        "data" => array(
	            "value" => "true"
	        )
	    );
	    $this->botClient->trackEvent($data);
	}
}

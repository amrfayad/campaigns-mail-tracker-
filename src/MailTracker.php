<?php

namespace amrfayad\CampaignMailTracker;

class MailTracker implements \Swift_Events_SendListener {

    protected $hash;
    protected $user_id;
    protected $campaign_id;

    public function __construct($user_id = null , $campaign_id = null) {
        $this->user_id = $user_id;
        $this->campaign_id = $campaign_id;
    }

    /**
     * Inject the tracking code into the message
     */
    public function beforeSendPerformed(\Swift_Events_SendEvent $event) {
        $message = $event->getMessage();
        $headers = $message->getHeaders();
        $headers->addTextHeader("campaignID", $this->campaign_id);
        $headers->addTextHeader("userID", $this->user_id);
        $hash = str_random(32);
        $original_content = $message->getBody();
        if ($message->getContentType() === 'text/html' ||
                ($message->getContentType() === 'multipart/alternative' && $message->getBody())
        ) {
            $message->setBody($this->addTrackers($message->getBody(), $hash));
        }
        foreach ($message->getChildren() as $part) {
            if (strpos($part->getContentType(), 'text/html') === 0) {
                $converter->setHTML($part->getBody());
                $part->setBody($this->addTrackers($message->getBody(), $hash));
            }
        }
        Model\SentEmail::create([
            'user_id' => $this->user_id,
            'campaign_id' => $this->campaign_id,
            'hash' => $hash,
            'headers' => $headers->toString(),
            'sender' => $headers->get('from')->getFieldBody(),
            'recipient' => $headers->get('to')->getFieldBody(),
            'subject' => $headers->get('subject')->getFieldBody(),
            'content' => $original_content,
        ]);

        // Purge old records
        if (config('campaigns-mail-tracker.expire-days') > 0) {
            Model\SentEmail::where('created_at', '<', \Carbon\Carbon::now()->subDays(config('campaigns-mail-tracker.expire-days')))->delete();
        }
    }

    public function sendPerformed(\Swift_Events_SendEvent $event) {
        //
    }

    protected function addTrackers($html, $hash) {
        if (config('campaigns-mail-tracker.inject-pixel')) {
            $html = $this->injectTrackingPixel($html, $hash);
        }
        if (config('campaigns-mail-tracker.track-links')) {
            $html = $this->injectLinkTracker($html, $hash);
        }

        return $html;
    }

    protected function injectTrackingPixel($html, $hash) {
        // Append the tracking url
        $tracking_pixel = '<img src="' . action('\amrfayad\CampaignMailTracker\MailTrackerController@getT', [$hash]) . '" />';

        $linebreak = str_random(32);
        $html = str_replace("\n", $linebreak, $html);

        if (preg_match("/^(.*<body[^>]*>)(.*)$/", $html, $matches)) {
            $html = $matches[1] . $tracking_pixel . $matches[2];
        } else {
            $html = $tracking_pixel . $html;
        }
        $html = str_replace($linebreak, "\n", $html);

        return $html;
    }

    protected function injectLinkTracker($html, $hash) {
        $this->hash = $hash;

        $html = preg_replace_callback("/(<a[^>]*href=['\"])([^'\"]*)/", array($this, 'inject_link_callback'), $html);

        return $html;
    }

    protected function inject_link_callback($matches) {
        if (empty($matches[2])) {
            $url = app()->make('url')->to('/');
        } else {
            $url = $matches[2];
        }

        return $matches[1] . action('\amrfayad\CampaignMailTracker\MailTrackerController@getL', [
                    MailTracker::hash_url($url),
                    $this->hash
        ]);
    }

    static public function hash_url($url) {
        // Replace "/" with "$"
        return str_replace("/", "$", base64_encode($url));
    }
    public function cheakIfCampaigSendedbefore($user_id, $campaign_id) {
        return Model\SentEmail::where(
                                [
                                    ['user_id', $user_id],
                                    ['campaign_id', $campaign_id],
                        ])
                        ->first();
    }
    static public function getBounces($config) {
        $bounces = new Bounces();
        $bounces->setImapClinet($config);
        $bounces->connect();
        $result = array();
        if ($bounces->getConnectionStatus()) {
            $response = $bounces->getMessages();
            if (count($response) > 0) {
                foreach ($response as $value) {
                    Model\SentEmail::where(
                            [
                                ['user_id', $value['user_id']],
                                ['campaign_id', $value['campaign_id']],
                    ])->update(
                            [
                                'bounces' => 1,
                                'bounce_type' => $value['bounceType']]);
                }
                //$bounces->deleteEmailsFound();
            }
            $bounces->end();
            $result =  ['status' => 'sucess',
                'message' => 'Bounces Emails Inserted to data Base'];
        } else {
            $result =  [
                'status' => 'fail',
                'message' => $bounces->getErrors()
            ];
        }
        return $result;
       /* $bounces->setImapClinet($config);
        return dd($bounces->getMessages());
        /*$bounces->connect();
        /*$imapClient = new ImapClient($config);
        $con = $imapClient->connect();
        return $bounces->getImapClient() ;*/
    }
    public function recordSentEmail($user_id , $campaign_id) {
        $this->user_id = $user_id;
        $this->campaign_id = $campaign_id;
    }
}

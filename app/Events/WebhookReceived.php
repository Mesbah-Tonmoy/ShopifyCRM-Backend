<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebhookReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    public $type; // 'install' or 'uninstall'
    public $shopDomain;

    public function __construct($shopDomain, $type)
    {
        $this->shopDomain = $shopDomain;
        $this->type = $type;
        $this->message = "Store {$shopDomain} just {$type}ed your app!";
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('crm-notifications'),
        ];
    }
    
    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'app.activity';
    }
}

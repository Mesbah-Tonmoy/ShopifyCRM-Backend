<?php

namespace App\Console\Commands;

use App\Models\App;
use Illuminate\Console\Command;

/**
 * Mints a board token the way an embedding Shopify app would, so the board can
 * be opened and tested locally without wiring up the real app first.
 */
class BoardTokenCommand extends Command
{
    protected $signature = 'board:token
                            {slug : The board slug, e.g. ai-job-listing-local}
                            {shop : The shop domain to sign in as, e.g. demo.myshopify.com}
                            {--ttl=300 : Seconds the token stays valid}';

    protected $description = 'Generate a signed board token and embeddable URL for local testing';

    public function handle(): int
    {
        $app = App::where('board_slug', $this->argument('slug'))->first();

        if (! $app || ! $app->hasBoardCredentials()) {
            $this->error("No provisioned board found for slug \"{$this->argument('slug')}\".");

            return self::FAILURE;
        }

        $payload = rtrim(strtr(base64_encode(json_encode([
            'key' => $app->board_public_key,
            'shop' => $this->argument('shop'),
            'iat' => time(),
            'exp' => time() + (int) $this->option('ttl'),
        ])), '+/', '-_'), '=');

        $token = $payload . '.' . hash_hmac('sha256', $payload, $app->board_secret);
        $url = rtrim((string) config('board.url'), '/') . '/board/' . $app->board_slug . '?token=' . $token;

        $this->newLine();
        $this->line('  <fg=gray>App:</> ' . $app->app_name);
        $this->line('  <fg=gray>Valid for:</> ' . $this->option('ttl') . 's');
        $this->newLine();
        $this->line('  <fg=green>' . $url . '</>');
        $this->newLine();

        return self::SUCCESS;
    }
}

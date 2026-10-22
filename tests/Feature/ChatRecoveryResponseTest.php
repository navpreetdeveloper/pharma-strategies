<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatRecoveryResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_combined_recovery_is_scoped_paginated_and_keeps_chat_links(): void
    {
        $company = Company::create(['name' => 'Visible pharmacy', 'province' => 'Ontario']);
        $other = Company::create(['name' => 'Hidden pharmacy', 'province' => 'Ontario']);
        $actor = User::factory()->create();
        $peer = User::factory()->create();
        $company->users()->attach([$actor->id => ['role' => 'staff', 'status' => 'active'], $peer->id => ['role' => 'staff', 'status' => 'active']]);
        $selected = null;
        foreach (range(1, 51) as $number) {
            $conversation = Conversation::create(['company_id' => $company->id, 'type' => 'group', 'name' => 'Visible thread '.$number]);
            $conversation->participants()->attach([$actor->id, $peer->id]);
            Message::create(['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $peer->id, 'body' => 'Synthetic message']);
            $selected ??= $conversation;
        }
        $hidden = Conversation::create(['company_id' => $other->id, 'type' => 'group', 'name' => 'Hidden thread']);
        $hidden->participants()->attach($actor->id);
        Message::create(['company_id' => $other->id, 'conversation_id' => $hidden->id, 'user_id' => $actor->id, 'body' => 'Hidden message']);
        $this->actingAs($actor)->withSession(['company_id' => $company->id]);

        $response = $this->getJson(route('conversations.updates', $selected).'?after=0&sidebar=1')
            ->assertOk()->assertJsonCount(1, 'messages');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $html = $response->json('sidebar');
        $this->assertStringNotContainsString('Hidden thread', $html);
        $this->assertStringNotContainsString('Hidden message', $response->getContent());
        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $cards = 0;
        $pagination = 0;
        foreach ($dom->getElementsByTagName('a') as $link) {
            if ($link->hasAttribute('data-conversation-id')) {
                $cards++;
            }
            $this->assertSame('/chat', parse_url($link->getAttribute('href'), PHP_URL_PATH));
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY) ?? '', $query);
            if (isset($query['conversations_page'])) {
                $pagination++;
            }
        }
        $this->assertSame(50, $cards);
        $this->assertGreaterThan(0, $pagination);
        $this->getJson(route('conversations.updates', $selected).'?after=0')->assertOk()->assertJsonMissingPath('sidebar');
    }
}

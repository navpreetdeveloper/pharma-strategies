<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatProfessionalFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function users(): array
    {
        $company = Company::create(['name'=>'Test Pharmacy','legal_name'=>'Test Pharmacy','province'=>'Ontario']);
        $one = User::create(['name'=>'One','email'=>'one@example.test','password'=>bcrypt('password'),'status'=>'active']);
        $two = User::create(['name'=>'Two','email'=>'two@example.test','password'=>bcrypt('password'),'status'=>'active']);
        $company->users()->attach($one->id,['role'=>'company_super_admin','status'=>'active']);
        $company->users()->attach($two->id,['role'=>'staff','status'=>'active']);
        return [$company,$one,$two];
    }

    public function test_opening_the_same_direct_pair_reuses_the_existing_conversation(): void
    {
        [$company,$one,$two] = $this->users();
        $existing = Conversation::create(['company_id'=>$company->id,'type'=>'direct','direct_key'=>min($one->id,$two->id).':'.max($one->id,$two->id),'created_by'=>$one->id]);
        $existing->participants()->sync([$one->id,$two->id]);

        $response = $this->actingAs($one)->withSession(['company_id'=>$company->id])->post(route('conversations.create'), ['type'=>'direct','user_id'=>$two->id]);
        $response->assertRedirect(route('chat',['conversation'=>$existing->id]));
        $this->assertDatabaseCount('conversations',1);
    }

    public function test_user_can_unsend_their_message_within_five_minutes(): void
    {
        Event::fake();
        [$company,$one,$two] = $this->users();
        $conversation=Conversation::create(['company_id'=>$company->id,'type'=>'direct','direct_key'=>min($one->id,$two->id).':'.max($one->id,$two->id),'created_by'=>$one->id]);
        $conversation->participants()->sync([$one->id,$two->id]);
        $message=Message::create(['company_id'=>$company->id,'conversation_id'=>$conversation->id,'user_id'=>$one->id,'body'=>'Remove this']);

        $this->actingAs($one)->withSession(['company_id'=>$company->id])->deleteJson(route('messages.destroy',$message))->assertOk();
        $this->assertDatabaseHas('messages',['id'=>$message->id,'body'=>null,'message_type'=>'deleted']);
    }

    public function test_user_cannot_unsend_after_five_minutes(): void
    {
        [$company,$one,$two] = $this->users();
        $conversation=Conversation::create(['company_id'=>$company->id,'type'=>'direct','direct_key'=>min($one->id,$two->id).':'.max($one->id,$two->id),'created_by'=>$one->id]);
        $conversation->participants()->sync([$one->id,$two->id]);
        $message=Message::create(['company_id'=>$company->id,'conversation_id'=>$conversation->id,'user_id'=>$one->id,'body'=>'Keep this']);
        $message->forceFill(['created_at'=>Carbon::now()->subMinutes(6)])->saveQuietly();

        $this->actingAs($one)->withSession(['company_id'=>$company->id])->deleteJson(route('messages.destroy',$message))->assertStatus(422);
        $this->assertDatabaseHas('messages',['id'=>$message->id,'body'=>'Keep this']);
    }

    public function test_presence_heartbeat_updates_last_seen(): void
    {
        [$company,$one,$two] = $this->users();
        $this->actingAs($one)->withSession(['company_id'=>$company->id])->postJson(route('presence.heartbeat'))->assertOk();
        $this->assertNotNull($one->fresh()->last_seen_at);
    }
}

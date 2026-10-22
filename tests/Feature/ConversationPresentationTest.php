<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationPresentation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Tests\TestCase;

class ConversationPresentationTest extends TestCase
{
    public function test_direct_chat_shows_name_without_the_employees_role(): void
    {
        $viewer = new User(['name' => 'Viewer', 'status' => 'active']);
        $viewer->id = 1;
        $other = new User(['name' => 'Alex', 'status' => 'active']);
        $other->id = 2;
        $company = new Company(['name' => 'Pharmacy']);
        $company->id = 3;
        $company->setRelation('pivot', new Pivot(['role' => 'pharmacist', 'name' => 'Alex']));
        $other->setRelation('companies', new Collection([$company]));
        $conversation = new Conversation(['type' => 'direct', 'company_id' => 3]);
        $conversation->setRelation('participants', new Collection([$viewer, $other]));

        $presentation = ConversationPresentation::make($conversation, $viewer);

        $this->assertSame('Alex', $presentation['title']);
        $this->assertSame('', $presentation['subtitle']);
        $this->assertSame($other, $presentation['other']);
    }
}

<?php

use App\Jobs\Notifications\SyncCustomerMarketingOptInJob;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Professional\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupCustomersTable();
    setupEmailSubscriptionsTable();
});

/**
 * Save a marketing EmailSubscription via Eloquent so the saved() observer fires.
 * Raw DB::insert bypasses model events.
 */
function saveMarketingSubscription(string $professionalId, string $email, string $status = 'subscribed'): EmailSubscription
{
    $sub = new EmailSubscription;
    $sub->id = (string) Str::uuid();
    $sub->professional_id = $professionalId;
    $sub->list_key = 'marketing';
    $sub->email = $email;
    $sub->email_lc = strtolower($email);
    $sub->status = $status;
    $sub->unsubscribe_token = EmailSubscription::newUnsubscribeToken();
    $sub->save();

    return $sub;
}

it('CACHE-11: EmailSubscription save dispatches the sync job instead of looking up Customer synchronously', function () {
    Queue::fake();

    $proId = (string) Str::uuid();
    saveMarketingSubscription($proId, 'buyer@example.test', 'subscribed');

    Queue::assertPushed(SyncCustomerMarketingOptInJob::class, function ($job) use ($proId) {
        return $job->professionalId === $proId
            && $job->email === 'buyer@example.test'
            && $job->subscribed === true;
    });
});

it('CACHE-11: unsubscribe save dispatches the job with subscribed=false', function () {
    Queue::fake();

    $proId = (string) Str::uuid();
    saveMarketingSubscription($proId, 'buyer@example.test', 'unsubscribed');

    Queue::assertPushed(SyncCustomerMarketingOptInJob::class, function ($job) {
        return $job->subscribed === false;
    });
});

it('CACHE-11: non-marketing list keys do not dispatch the sync job', function () {
    Queue::fake();

    $proId = (string) Str::uuid();
    $sub = new EmailSubscription;
    $sub->id = (string) Str::uuid();
    $sub->professional_id = $proId;
    $sub->list_key = 'sidest_updates';
    $sub->email = 'staff@example.test';
    $sub->email_lc = 'staff@example.test';
    $sub->status = 'subscribed';
    $sub->unsubscribe_token = EmailSubscription::newUnsubscribeToken();
    $sub->save();

    Queue::assertNotPushed(SyncCustomerMarketingOptInJob::class);
});

it('CACHE-11 job: updates Customer.marketing_opt_in_cached when the customer exists', function () {
    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.customers')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'email' => 'buyer@example.test',
        'full_name' => 'Buyer',
        'marketing_opt_in_cached' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    (new SyncCustomerMarketingOptInJob($proId, 'buyer@example.test', true))->handle();

    $customer = Customer::query()->where('professional_id', $proId)->where('email', 'buyer@example.test')->first();
    expect($customer->marketing_opt_in_cached)->toBeTrue();

    (new SyncCustomerMarketingOptInJob($proId, 'buyer@example.test', false))->handle();
    $customer->refresh();
    expect($customer->marketing_opt_in_cached)->toBeFalse();
});

it('CACHE-11 job: is a no-op when no matching Customer exists', function () {
    $proId = (string) Str::uuid();

    // Should neither throw nor insert a Customer row.
    (new SyncCustomerMarketingOptInJob($proId, 'ghost@example.test', true))->handle();

    expect(DB::connection('pgsql')->table('core.customers')->count())->toBe(0);
});

<?php

use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Test double exposing the protected helpers as public methods so
 * we can assert their behavior directly without inventing a real policy.
 */
class TestableBasePolicy extends BasePolicy
{
    public function checkAal2(): Response
    {
        return $this->requiresAal2();
    }

    public function checkFreshAal2(int $window): Response
    {
        return $this->requiresFreshAal2($window);
    }
}

it('requiresAal2 allows aal2 sessions', function () {
    request()->attributes->set('supabase_aal', 'aal2');

    expect((new TestableBasePolicy)->checkAal2()->allowed())->toBeTrue();
});

it('requiresAal2 denies aal1 sessions with 401', function () {
    request()->attributes->set('supabase_aal', 'aal1');

    $response = (new TestableBasePolicy)->checkAal2();
    expect($response->allowed())->toBeFalse();
    expect($response->status())->toBe(401);
});

it('requiresFreshAal2 allows when the most recent mfa entry is inside the window', function () {
    request()->attributes->set('supabase_aal', 'aal2');
    request()->attributes->set('supabase_amr', [
        ['method' => 'totp', 'timestamp' => time() - 60],
    ]);

    expect((new TestableBasePolicy)->checkFreshAal2(300)->allowed())->toBeTrue();
});

it('requiresFreshAal2 denies when the mfa entry is outside the window', function () {
    request()->attributes->set('supabase_aal', 'aal2');
    request()->attributes->set('supabase_amr', [
        ['method' => 'totp', 'timestamp' => time() - 1000],
    ]);

    $response = (new TestableBasePolicy)->checkFreshAal2(300);
    expect($response->allowed())->toBeFalse();
    expect($response->status())->toBe(401);
});

it('requiresFreshAal2 reads the most-recent mfa entry, ignoring later non-mfa entries', function () {
    request()->attributes->set('supabase_aal', 'aal2');
    request()->attributes->set('supabase_amr', [
        ['method' => 'token_refresh', 'timestamp' => time() - 5],   // newer, not mfa
        ['method' => 'totp', 'timestamp' => time() - 60],            // mfa, fresh
        ['method' => 'magiclink', 'timestamp' => time() - 120],
    ]);

    expect((new TestableBasePolicy)->checkFreshAal2(300)->allowed())->toBeTrue();
});

it('requiresFreshAal2 denies when amr has no mfa entries at all', function () {
    request()->attributes->set('supabase_aal', 'aal1');
    request()->attributes->set('supabase_amr', [
        ['method' => 'magiclink', 'timestamp' => time() - 10],
    ]);

    expect((new TestableBasePolicy)->checkFreshAal2(300)->allowed())->toBeFalse();
});

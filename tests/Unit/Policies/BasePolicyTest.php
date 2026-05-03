<?php

use App\Models\Core\Professional\Professional;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

// Concrete subclass purely for testing the protected helper.
class FakePolicy extends BasePolicy
{
    public function callDenyIfPendingDeletion(Professional $professional): ?Response
    {
        return $this->denyIfPendingDeletion($professional);
    }
}

it('returns null when the professional is active', function () {
    $pro = new Professional(['status' => 'active']);

    $result = (new FakePolicy)->callDenyIfPendingDeletion($pro);

    expect($result)->toBeNull();
});

it('returns a 423 deny response when the professional is pending deletion', function () {
    $pro = new Professional(['status' => 'pending_deletion']);

    $result = (new FakePolicy)->callDenyIfPendingDeletion($pro);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(423);
    expect($result->message())->toBe('Account is pending deletion.');
});

it('returns null when the professional has any other status', function () {
    $pro = new Professional(['status' => 'suspended']);

    $result = (new FakePolicy)->callDenyIfPendingDeletion($pro);

    expect($result)->toBeNull();
});

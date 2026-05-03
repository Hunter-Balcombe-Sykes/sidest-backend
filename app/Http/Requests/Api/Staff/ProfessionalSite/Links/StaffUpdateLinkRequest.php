<?php

namespace App\Http\Requests\Api\Staff\ProfessionalSite\Links;

use App\Http\Requests\Api\Professional\Site\UpdateLinkBlockRequest;

// V2: Staff-facing link update request — inherits link block validation from the professional UpdateLinkBlockRequest.
class StaffUpdateLinkRequest extends UpdateLinkBlockRequest
{
    // Inherits Professional Validation
}

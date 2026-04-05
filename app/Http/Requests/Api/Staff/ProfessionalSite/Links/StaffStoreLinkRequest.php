<?php

namespace App\Http\Requests\Api\Staff\ProfessionalSite\Links;

use App\Http\Requests\Api\Professional\Site\StoreLinkBlockRequest;

// V2: Staff-facing link creation request — inherits link block validation from the professional StoreLinkBlockRequest.
class StaffStoreLinkRequest extends StoreLinkBlockRequest
{
    // Inherits Professional Validation
}

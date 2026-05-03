<?php

namespace App\Http\Requests\Api\Staff\ProfessionalSite\Links;

use App\Http\Requests\Api\Professional\Site\ReorderBlocksRequest;

// V2: Staff-facing link reorder request — inherits block reorder validation from the professional ReorderBlocksRequest.
class StaffReorderLinkRequest extends ReorderBlocksRequest
{
    // Inherits Professional Validation
}

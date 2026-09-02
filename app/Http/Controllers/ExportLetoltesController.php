<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Export;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportLetoltesController
{
    public function __invoke(Export $export): StreamedResponse
    {
        abort_if($export->file_path === null, 404);
        abort_unless(Storage::disk('local')->exists($export->file_path), 404);

        return Storage::disk('local')->download($export->file_path, $export->file_name);
    }
}

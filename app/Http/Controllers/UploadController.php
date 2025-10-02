<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\UploadedFile;
use App\Services\TextExtractor;

class UploadController extends Controller
{
    public function upload(Request $request, TextExtractor $extractor)
    {   
        $request->validate([
            'cv'             => 'required|file|max:20480',
            'project_report' => 'required|file|max:20480',
        ]);

        $disk = config('filesystems.default', 'local');

        $save = function ($file) use ($disk, $extractor) {
            // 1) Store on the configured disk; keep relative path
            $path = Storage::disk($disk)->putFile('uploads', $file); // e.g. 'uploads/abc123.bin'

            // 2) Resolve absolute path and keep a temp fallback
            $absPath   = Storage::disk($disk)->path($path);          // <project>/storage/app/uploads/abc123.bin
            $tmpPath   = $file->getRealPath();                       // temp file path during this request
            $existsAbs = is_file($absPath);
            $existsTmp = is_file($tmpPath);
            $extractPath = $existsAbs ? $absPath : ($existsTmp ? $tmpPath : $absPath);

            // 3) Prefer server-detected MIME (fileinfo); fallback to client MIME
            $mime = $file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream';

            // 4) Persist metadata
            $model = UploadedFile::create([
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $mime,
                'path'          => $path, // store relative; resolve with Storage later
            ]);

            // 5) Extract text (robust extractor handles pdf/docx/txt + fallbacks)
            try {
                $text = $extractor->extract($extractPath, $mime);
            } catch (\Throwable $e) {
                throw $e;
            }

            $model->update(['text_extracted' => $text]);

            return $model->id;
        };

        return response()->json([
            'cv_file_id'        => $save($request->file('cv')),
            'project_file_id'   => $save($request->file('project_report')),
        ]);
    }
}

<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrivateReceipt
{
    public static function rules(): array
    {
        return ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];
    }

    public function store(UploadedFile $file): array
    {
        $mime = $file->getMimeType();
        $bytes = file_get_contents($file->getRealPath());
        if ($mime === 'application/pdf') {
            if (! str_starts_with($bytes, '%PDF-')) {
                throw ValidationException::withMessages(['receipt' => __('PDF nuk është i vlefshëm.')]);
            }
            $ext = 'pdf';
        } else {
            $size = @getimagesize($file->getRealPath());
            if (! $size || $size[0] > 3000 || $size[1] > 3000 || ! in_array($mime, ['image/jpeg', 'image/png'], true)) {
                throw ValidationException::withMessages(['receipt' => __('Përdor JPG/PNG deri në 3000 × 3000px ose PDF.')]);
            }
            $image = @imagecreatefromstring($bytes);
            if (! $image) {
                throw ValidationException::withMessages(['receipt' => __('Dëshmia nuk mund të lexohet.')]);
            }
            ob_start();
            try {
                imagesavealpha($image, true);
                $ok = $mime === 'image/png' ? imagepng($image) : imagejpeg($image, null, 88);
                $bytes = ob_get_contents();
            } finally {
                ob_end_clean();
                imagedestroy($image);
            }
            if (! $ok) {
                throw ValidationException::withMessages(['receipt' => __('Dëshmia nuk mund të përpunohet.')]);
            }
            $ext = $mime === 'image/png' ? 'png' : 'jpg';
        }
        if (strlen($bytes) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['receipt' => __('Dëshmia duhet të jetë deri në 5MB.')]);
        }
        $path = Str::uuid().'.'.$ext;
        try {
            if (! Storage::disk('receipts')->put($path, $bytes)) {
                throw ValidationException::withMessages(['receipt' => __('Dëshmia nuk u ruajt. Provo përsëri.')]);
            }
        } catch (\Throwable $e) {
            Storage::disk('receipts')->delete($path);
            throw $e;
        }

        return [$path, $mime];
    }

    public function delete(?string $path): void
    {
        if ($path && preg_match('/^[a-f0-9-]{36}\.(pdf|png|jpg)$/D', $path)) {
            Storage::disk('receipts')->delete($path);
        }
    }
}

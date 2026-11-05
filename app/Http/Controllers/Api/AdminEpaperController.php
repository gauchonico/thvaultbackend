<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EpaperEdition;
use App\Models\Publication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin console for the ePaper: newspaper titles and their editions.
 *
 * Edition PDFs (often 20–100 MB) are uploaded in chunks small enough for the
 * server's PHP upload limits, then joined into one file when the edition is saved.
 * Chunks go to the ePaper disk itself, so this also works with several app
 * instances behind a load balancer.
 */
class AdminEpaperController extends Controller
{
    // ─── Publications ─────────────────────────────────────────────────────────

    // GET /api/admin/epaper/publications
    public function publications()
    {
        return response()->json(
            Publication::withCount('editions')->with('latestEdition')->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    // POST /api/admin/epaper/publications
    public function storePublication(Request $request)
    {
        $data = $this->validatePublication($request);
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['sort_order'] ??= (int) Publication::max('sort_order') + 1;

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('epaper/logos', 'public');
        }

        return response()->json(Publication::create($data)->loadCount('editions'), 201);
    }

    // PUT /api/admin/epaper/publications/{publication}
    public function updatePublication(Request $request, Publication $publication)
    {
        $data = $this->validatePublication($request, $publication);

        if ($request->hasFile('logo')) {
            if ($old = $publication->getRawOriginal('logo')) Storage::disk('public')->delete($old);
            $data['logo'] = $request->file('logo')->store('epaper/logos', 'public');
        }

        $publication->update($data);

        return response()->json($publication->fresh()->loadCount('editions'));
    }

    // DELETE /api/admin/epaper/publications/{publication} — removes its editions' files too
    public function destroyPublication(Publication $publication)
    {
        $publication->editions->each(fn (EpaperEdition $edition) => $this->deleteFiles($edition));
        if ($logo = $publication->getRawOriginal('logo')) Storage::disk('public')->delete($logo);

        $publication->delete();

        return response()->json(['message' => 'Publication deleted']);
    }

    // ─── Editions ─────────────────────────────────────────────────────────────

    // GET /api/admin/epaper/editions?publication_id=&page=
    public function editions(Request $request)
    {
        return response()->json(
            EpaperEdition::with('publication:id,name,slug,accent_color')
                ->when($request->integer('publication_id'), fn ($q, $id) => $q->where('publication_id', $id))
                ->orderByDesc('issue_date')->orderByDesc('id')
                ->paginate(20)
        );
    }

    // POST /api/admin/epaper/editions — saves an edition from a finished chunked upload
    public function storeEdition(Request $request)
    {
        $data = $request->validate($this->editionRules() + [
            'publication_id' => 'required|exists:publications,id',
            'issue_date'     => 'required|date_format:Y-m-d',
            'upload_id'      => 'required|uuid',
            'total_chunks'   => 'required|integer|min:1|max:2000',
            'page_count'     => 'required|integer|min:1|max:2000',
        ]);

        $file = $this->assemble($data['upload_id'], $data['total_chunks'], $data['publication_id'], $data['issue_date']);
        if (is_string($file)) {
            return response()->json(['message' => $file], 422);
        }

        $edition = new EpaperEdition(collect($data)->except(['upload_id', 'total_chunks', 'cover'])->all());
        $edition->file_path = $file['path'];
        $edition->file_size = $file['size'];
        if ($request->hasFile('cover')) {
            $edition->cover = $request->file('cover')->store('epaper/covers', 'public');
        }
        $edition->save();

        return response()->json($edition->load('publication:id,name,slug,accent_color'), 201);
    }

    // PUT /api/admin/epaper/editions/{edition} — details, a new cover, or a replacement PDF
    public function updateEdition(Request $request, EpaperEdition $edition)
    {
        $data = $request->validate($this->editionRules() + [
            'publication_id' => 'sometimes|exists:publications,id',
            'issue_date'     => 'sometimes|date_format:Y-m-d',
            'upload_id'      => 'nullable|uuid|required_with:total_chunks',
            'total_chunks'   => 'nullable|integer|min:1|max:2000|required_with:upload_id',
            'page_count'     => 'sometimes|integer|min:1|max:2000',
        ]);

        if (!empty($data['upload_id'])) {
            $file = $this->assemble(
                $data['upload_id'], $data['total_chunks'],
                $data['publication_id'] ?? $edition->publication_id,
                $data['issue_date'] ?? $edition->issue_date->format('Y-m-d'),
            );
            if (is_string($file)) {
                return response()->json(['message' => $file], 422);
            }
            Storage::disk(EpaperEdition::disk())->delete($edition->file_path);
            $edition->file_path = $file['path'];
            $edition->file_size = $file['size'];
        }

        if ($request->hasFile('cover')) {
            if ($old = $edition->getRawOriginal('cover')) Storage::disk('public')->delete($old);
            $edition->cover = $request->file('cover')->store('epaper/covers', 'public');
        }

        $edition->fill(collect($data)->except(['upload_id', 'total_chunks', 'cover'])->all())->save();

        return response()->json($edition->fresh()->load('publication:id,name,slug,accent_color'));
    }

    // DELETE /api/admin/epaper/editions/{edition}
    public function destroyEdition(EpaperEdition $edition)
    {
        $this->deleteFiles($edition);
        $edition->delete();

        return response()->json(['message' => 'Edition deleted']);
    }

    // ─── Chunked PDF uploads ──────────────────────────────────────────────────

    // POST /api/admin/epaper/uploads — starts an upload and says how big each chunk may be
    public function startUpload()
    {
        return response()->json(['upload_id' => (string) Str::uuid(), 'chunk_size' => $this->chunkSize()]);
    }

    // POST /api/admin/epaper/uploads/{uploadId}/chunks
    public function uploadChunk(Request $request, string $uploadId)
    {
        abort_unless(Str::isUuid($uploadId), 404);

        $data = $request->validate([
            'index' => 'required|integer|min:0|max:1999',
            'chunk' => 'required|file',
        ]);

        $request->file('chunk')->storeAs($this->uploadDir($uploadId), (string) $data['index'], EpaperEdition::disk());

        return response()->json(['received' => $data['index']]);
    }

    // DELETE /api/admin/epaper/uploads/{uploadId} — the admin cancelled
    public function cancelUpload(string $uploadId)
    {
        abort_unless(Str::isUuid($uploadId), 404);
        Storage::disk(EpaperEdition::disk())->deleteDirectory($this->uploadDir($uploadId));

        return response()->json(['message' => 'Upload cancelled']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function validatePublication(Request $request, ?Publication $publication = null): array
    {
        $required = $publication ? 'sometimes' : 'required';

        return $request->validate([
            'name'         => "$required|string|max:120",
            'country'      => "$required|string|max:50",
            'language'     => 'sometimes|string|max:30',
            'tagline'      => 'nullable|string|max:255',
            'logo'         => 'nullable|image|max:2048',
            'accent_color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order'   => 'sometimes|integer|min:0',
            'is_active'    => 'sometimes|boolean',
        ]);
    }

    private function editionRules(): array
    {
        return [
            'title'        => 'nullable|string|max:120',
            'access_level' => ['sometimes', Rule::in(['free', 'subscribers'])],
            'is_published' => 'sometimes|boolean',
            'cover'        => 'nullable|image|max:5120',
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'publication';
        $slug = $base;
        for ($i = 2; Publication::where('slug', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }

        return $slug;
    }

    private function uploadDir(string $uploadId): string
    {
        return "epaper/uploads/$uploadId";
    }

    /**
     * Joins the uploaded chunks into the edition's PDF.
     *
     * @return array{path: string, size: int}|string the stored file, or an error message
     */
    private function assemble(string $uploadId, int $totalChunks, int $publicationId, string $issueDate): array|string
    {
        $disk = Storage::disk(EpaperEdition::disk());
        $dir = $this->uploadDir($uploadId);

        // Spills to a temp file past 8 MB, so a large paper never sits in memory.
        $joined = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+b');
        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $part = $disk->readStream("$dir/$i");
                if (!$part) {
                    return "The upload is incomplete (part " . ($i + 1) . " of $totalChunks is missing). Please upload the PDF again.";
                }
                stream_copy_to_stream($part, $joined);
                fclose($part);
            }

            $size = ftell($joined);
            rewind($joined);
            if (!str_contains((string) fread($joined, 1024), '%PDF-')) {
                $disk->deleteDirectory($dir);
                return 'That file is not a PDF.';
            }
            rewind($joined);

            $slug = Publication::whereKey($publicationId)->value('slug') ?? 'paper';
            $path = "epaper/$slug/$issueDate-" . Str::random(32) . '.pdf';
            if (!$disk->writeStream($path, $joined)) {
                return "Couldn't save the PDF. Please try again.";
            }
        } finally {
            fclose($joined);
        }

        $disk->deleteDirectory($dir);

        return ['path' => $path, 'size' => $size];
    }

    private function deleteFiles(EpaperEdition $edition): void
    {
        Storage::disk(EpaperEdition::disk())->delete($edition->file_path);
        if ($cover = $edition->getRawOriginal('cover')) Storage::disk('public')->delete($cover);
    }

    /** 75% of the smaller PHP upload limit, between 256 KB and 8 MB. */
    private function chunkSize(): int
    {
        $limit = min($this->iniBytes('upload_max_filesize'), $this->iniBytes('post_max_size'));

        return (int) max(256 * 1024, min(8 * 1024 * 1024, $limit * 0.75));
    }

    private function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        $bytes = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $bytes * 1024 ** 3,
            'm' => $bytes * 1024 ** 2,
            'k' => $bytes * 1024,
            default => $bytes ?: PHP_INT_MAX,
        };
    }
}

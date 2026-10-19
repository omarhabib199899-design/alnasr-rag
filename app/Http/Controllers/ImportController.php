<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Services\Inventory\ColumnMappingService;
use App\Services\Inventory\ExcelImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImportController extends Controller
{
    public function __construct(
        private readonly ExcelImportService $excel,
        private readonly ColumnMappingService $mappings,
    ) {}

    public function index()
    {
        $imports = Import::where('user_id', auth()->id())->latest()->paginate(15);

        return view('imports.index', compact('imports'));
    }

    public function create()
    {
        return view('imports.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'type' => ['required', 'string', 'in:inventory,sales,purchase_orders,returns'],
            'file' => [
                'required',
                'file',
                'max:20480',
                function ($attribute, $value, $fail) {
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
                        $fail('The file must be a .xlsx, .xls, or .csv export.');
                    }
                },
            ],
        ]);

        $type = $request->input('type');
        $file = $request->file('file');
        $storedPath = $file->store('imports');

        [$headers] = $this->excel->read(Storage::path($storedPath));

        if (empty($headers)) {
            Storage::delete($storedPath);

            return back()->withErrors(['file' => 'That file has no readable header row.']);
        }

        $signature = $this->mappings->computeSourceSignature($headers, $type);

        $import = Import::create([
            'user_id' => auth()->id(),
            'type' => $type,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'source_signature' => $signature,
            'status' => 'pending',
        ]);

        if ($this->mappings->isFullyMapped($signature, $headers)) {
            $mapping = $this->mappings->getRememberedMapping($signature);

            $missing = array_diff(ColumnMappingService::requiredFields($type), array_values($mapping));
            if (! empty($missing)) {
                $labels = collect($missing)->map(fn ($f) => ColumnMappingService::knownFields($type)[$f]['label'])->implode(', ');

                return redirect()->route('imports.mapping', $import)
                    ->withErrors(['mapping' => "Please map a column to each required field: {$labels}"]);
            }

            ProcessImportJob::dispatch($import, $mapping);

            return redirect()->route('imports.show', $import)
                ->with('status', 'Import queued — columns matched automatically from a previous upload.');
        }

        return redirect()->route('imports.mapping', $import);
    }

    public function mapping(Import $import)
    {
        $this->authorizeOwner($import);

        [$headers] = $this->excel->read(Storage::path($import->stored_path));
        $remembered = $this->mappings->getRememberedMapping($import->source_signature);

        $suggestions = [];
        foreach ($headers as $header) {
            $suggestions[$header] = $remembered[$header] ?? $this->mappings->guessField($header, $import->type) ?? 'ignore';
        }

        return view('imports.mapping', [
            'import' => $import,
            'suggestions' => $suggestions,
            'options' => ColumnMappingService::selectableOptions($import->type),
        ]);
    }

    public function confirmMapping(Request $request, Import $import)
    {
        $this->authorizeOwner($import);

        [$headers] = $this->excel->read(Storage::path($import->stored_path));

        $validOptions = array_keys(ColumnMappingService::selectableOptions($import->type));
        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['required', 'string', 'in:'.implode(',', $validOptions)],
        ]);

        $mapping = [];
        foreach ($headers as $header) {
            $mapping[$header] = $data['mapping'][$header] ?? 'ignore';
        }

        $missing = array_diff(
            ColumnMappingService::requiredFields($import->type),
            array_values($mapping)
        );

        if (! empty($missing)) {
            $labels = collect($missing)->map(fn ($f) => ColumnMappingService::knownFields($import->type)[$f]['label'])->implode(', ');

            return back()->withErrors(['mapping' => "Please map a column to each required field: {$labels}"])->withInput();
        }

        $this->mappings->saveMapping($import->source_signature, $mapping, auth()->id());

        ProcessImportJob::dispatch($import, $mapping);

        return redirect()->route('imports.show', $import)->with('status', 'Import queued.');
    }

    public function show(Import $import)
    {
        $this->authorizeOwner($import);

        $sample = match ($import->type) {
            'sales' => $import->saleItems()->limit(25)->get(),
            'purchase_orders' => $import->purchaseOrderItems()->limit(25)->get(),
            'returns' => $import->returnItems()->limit(25)->get(),
            default => $import->items()->limit(25)->get(),
        };

        return view('imports.show', compact('import', 'sample'));
    }

    public function destroy(Import $import)
    {
        $this->authorizeOwner($import);

        if ($import->stored_path) {
            Storage::delete($import->stored_path);
        }

        $import->delete();

        return redirect()->route('imports.index')->with('status', 'Import deleted.');
    }

    private function authorizeOwner(Import $import): void
    {
        abort_if($import->user_id !== auth()->id(), 403);
    }
}

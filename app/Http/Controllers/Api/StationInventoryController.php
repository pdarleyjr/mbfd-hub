<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\StationInventorySubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\PDF;
use Illuminate\Support\Facades\Storage;

class StationInventoryController extends Controller
{
    /**
     * Inventory categories configuration.
     */
    private array $categories = [
        [
            'id' => 'garbage_paper',
            'name' => 'Garbage/Paper goods',
            'items' => [
                ['id' => 'kitchen_liner', 'name' => 'Kitchen Liner (13 gal)', 'max' => 10],
                ['id' => 'kitchen_liner_30', 'name' => 'Kitchen Liner (30 gal)', 'max' => 10],
                ['id' => 'trash_can_liner', 'name' => 'Trash Can Liner', 'max' => 5],
                ['id' => 'paper_towels', 'name' => 'Paper Towels', 'max' => 20],
                ['id' => 'toilet_tissue', 'name' => 'Toilet Tissue', 'max' => 20],
                ['id' => 'facial_tissue', 'name' => 'Facial Tissue', 'max' => 10],
                ['id' => 'paper_plates', 'name' => 'Paper Plates', 'max' => 5],
                ['id' => 'plastic_cups', 'name' => 'Plastic Cups', 'max' => 5],
                ['id' => 'plastic_spoons', 'name' => 'Plastic Spoons', 'max' => 5],
                ['id' => 'plastic_forks', 'name' => 'Plastic Forks', 'max' => 5],
                ['id' => 'napkins', 'name' => 'Napkins', 'max' => 10],
            ],
        ],
        [
            'id' => 'floors',
            'name' => 'Floors',
            'items' => [
                ['id' => 'dust_mop', 'name' => 'Dust Mop', 'max' => 2],
                ['id' => 'wet_mop', 'name' => 'Wet Mop', 'max' => 2],
                ['id' => 'mop_bucket', 'name' => 'Mop Bucket', 'max' => 2],
                ['id' => 'floor_broom', 'name' => 'Floor Broom', 'max' => 2],
                ['id' => 'push_broom', 'name' => 'Push Broom', 'max' => 2],
                ['id' => 'floor_signs', 'name' => 'Wet Floor Signs', 'max' => 4],
                ['id' => 'vacuum_cleaner', 'name' => 'Vacuum Cleaner', 'max' => 2],
                ['id' => 'carpet_spotter', 'name' => 'Carpet Spotter', 'max' => 2],
            ],
        ],
        [
            'id' => 'laundry',
            'name' => 'Laundry',
            'items' => [
                ['id' => 'laundry_detergent', 'name' => 'Laundry Detergent', 'max' => 5],
                ['id' => 'bleach', 'name' => 'Bleach', 'max' => 3],
                ['id' => 'fabric_softener', 'name' => 'Fabric Softener', 'max' => 3],
                ['id' => 'laundry_basket', 'name' => 'Laundry Basket', 'max' => 2],
                ['id' => 'clothes_hangers', 'name' => 'Clothes Hangers (pack)', 'max' => 5],
            ],
        ],
        [
            'id' => 'bathroom_cleaners',
            'name' => 'Bathroom & Cleaners',
            'items' => [
                ['id' => 'toilet_bowl_cleaner', 'name' => 'Toilet Bowl Cleaner', 'max' => 3],
                ['id' => 'bathroom_cleaner', 'name' => 'Bathroom Cleaner', 'max' => 3],
                ['id' => 'glass_cleaner', 'name' => 'Glass Cleaner', 'max' => 3],
                ['id' => 'all_purpose_cleaner', 'name' => 'All Purpose Cleaner', 'max' => 5],
                ['id' => 'disinfectant_wipes', 'name' => 'Disinfectant Wipes', 'max' => 5],
                ['id' => 'hand_soap', 'name' => 'Hand Soap', 'max' => 10],
                ['id' => 'shower_curtain', 'name' => 'Shower Curtain', 'max' => 2],
                ['id' => 'toilet_brush', 'name' => 'Toilet Brush', 'max' => 2],
                ['id' => 'plunger', 'name' => 'Plunger', 'max' => 2],
            ],
        ],
        [
            'id' => 'kitchen',
            'name' => 'Kitchen',
            'items' => [
                ['id' => 'dish_soap', 'name' => 'Dish Soap', 'max' => 5],
                ['id' => 'dishwasher_detergent', 'name' => 'Dishwasher Detergent', 'max' => 3],
                ['id' => 'scouring_pads', 'name' => 'Scouring Pads', 'max' => 5],
                ['id' => 'sponges', 'name' => 'Sponges', 'max' => 5],
                ['id' => 'aluminum_foil', 'name' => 'Aluminum Foil', 'max' => 2],
                ['id' => 'plastic_wrap', 'name' => 'Plastic Wrap', 'max' => 2],
                ['id' => 'paper_bags', 'name' => 'Paper Bags', 'max' => 3],
                ['id' => 'freezer_bags', 'name' => 'Freezer Bags', 'max' => 3],
                ['id' => 'storage_containers', 'name' => 'Storage Containers', 'max' => 3],
            ],
        ],
    ];

    /**
     * Get inventory categories.
     */
    public function categories(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->categories,
        ]);
    }

    /**
     * Store a new station inventory submission with PDF generation.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => 'required|exists:stations,id',
            'items' => 'required|array',
        ]);

        $station = Station::findOrFail($validated['station_id']);
        $items = $validated['items'];

        // Filter items with quantity > 0
        $orderedItems = array_filter($items, fn($item) => ($item['quantity'] ?? 0) > 0);

        // Generate PDF
        $pdfData = [
            'station' => $station,
            'items' => $orderedItems,
            'categories' => $this->categories,
            'generated_at' => now()->format('M j, Y g:i A'),
            'generated_by' => $request->user()?->name ?? 'Unknown',
        ];

        $pdf = PDF::loadView('pdf.station-inventory', $pdfData);
        
        // Save PDF
        $filename = 'inventory-' . $station->id . '-' . time() . '.pdf';
        $pdfPath = 'inventory-submissions/' . $filename;
        Storage::disk('public')->put($pdfPath, $pdf->output());

        // Create submission record
        $submission = StationInventorySubmission::create([
            'station_id' => $validated['station_id'],
            'items' => $orderedItems,
            'pdf_path' => $pdfPath,
            'created_by' => $request->user()?->id ?? 1,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Inventory submission saved and PDF generated.',
            'data' => [
                'submission_id' => $submission->id,
                'pdf_url' => Storage::url($pdfPath),
            ],
        ], 201);
    }

    /**
     * Get inventory submissions for a station.
     */
    public function index(Station $station): JsonResponse
    {
        $submissions = $station->inventorySubmissions()
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $submissions,
        ]);
    }

    /**
     * Download PDF for a submission.
     */
    public function downloadPdf(StationInventorySubmission $submission): JsonResponse
    {
        if (!Storage::disk('public')->exists($submission->pdf_path)) {
            return response()->json([
                'success' => false,
                'message' => 'PDF file not found.',
            ], 404);
        }

        $pdfContent = Storage::disk('public')->get($submission->pdf_path);
        
        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="station-inventory-' . $submission->station->slug . '.pdf"');
    }
}
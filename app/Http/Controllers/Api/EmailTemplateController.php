<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\App;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    /**
     * Get all email templates with filters
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $query = EmailTemplate::with('app');

        // Filter by app
        if ($request->has('app_id')) {
            $query->where('app_id', $request->app_id);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Search by subject or type
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('type', 'like', "%{$search}%")
                  ->orWhere('body', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $templates = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    /**
     * Get single email template
     */
    public function show(EmailTemplate $emailTemplate)
    {
        $emailTemplate->load('app');

        return response()->json([
            'success' => true,
            'data' => $emailTemplate,
        ]);
    }

    /**
     * Create new email template
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'app_id' => 'required|exists:apps,id',
            'type' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'is_active' => 'boolean',
        ]);

        $template = EmailTemplate::create($validated);
        $template->load('app');

        return response()->json([
            'success' => true,
            'message' => 'Email template created successfully',
            'data' => $template,
        ], 201);
    }

    /**
     * Update email template
     */
    public function update(Request $request, EmailTemplate $emailTemplate)
    {
        $validated = $request->validate([
            'type' => 'sometimes|required|string|max:255',
            'subject' => 'sometimes|required|string|max:255',
            'body' => 'sometimes|required|string',
            'is_active' => 'boolean',
        ]);

        $emailTemplate->update($validated);
        $emailTemplate->load('app');

        return response()->json([
            'success' => true,
            'message' => 'Email template updated successfully',
            'data' => $emailTemplate,
        ]);
    }

    /**
     * Delete email template
     */
    public function destroy(EmailTemplate $emailTemplate)
    {
        $emailTemplate->delete();

        return response()->json([
            'success' => true,
            'message' => 'Email template deleted successfully',
        ]);
    }

    /**
     * Get email templates by app
     */
    public function byApp(Request $request, App $app)
    {
        $perPage = $request->get('per_page', 15);
        $query = $app->emailTemplates();

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('type', 'like', "%{$search}%");
            });
        }

        $templates = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    /**
     * Render email template with variables
     */
    public function render(Request $request, EmailTemplate $emailTemplate)
    {
        $validated = $request->validate([
            'variables' => 'nullable|array',
        ]);

        $variables = $validated['variables'] ?? [];
        $rendered = $emailTemplate->render($variables);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $emailTemplate->id,
                'type' => $emailTemplate->type,
                'rendered_subject' => $rendered['subject'],
                'rendered_body' => $rendered['body'],
                'original_subject' => $emailTemplate->subject,
                'original_body' => $emailTemplate->body,
            ],
        ]);
    }

    /**
     * Toggle email template active status
     */
    public function toggleActive(EmailTemplate $emailTemplate)
    {
        $emailTemplate->update([
            'is_active' => !$emailTemplate->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Email template status updated successfully',
            'data' => $emailTemplate,
        ]);
    }
}

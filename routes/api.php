<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AppController;
use App\Http\Controllers\Api\InstallationController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PricingPlanController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\FeatureController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Dashboard stats
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    
    // Apps routes
    Route::get('/apps', [AppController::class, 'index'])->middleware('permission:apps.view');
    Route::get('/apps/{app}', [AppController::class, 'show'])->middleware('permission:apps.view');
    Route::post('/apps', [AppController::class, 'store'])->middleware('permission:apps.add');
    Route::post('/apps/{app}/resync', [AppController::class, 'resync'])->middleware('permission:apps.add');
    Route::post('/apps/{app}/push-plans', [AppController::class, 'pushPlans'])->middleware('permission:pricing_plans.edit');
    Route::delete('/apps/{app}', [AppController::class, 'destroy'])->middleware('permission:apps.delete');
    Route::get('/apps/{app}/stats', [AppController::class, 'stats'])->middleware('permission:apps.view');
    
    // Installations routes
    Route::get('/installations/filters', [InstallationController::class, 'filters'])->middleware('permission:installations.view');
    Route::get('/installations', [InstallationController::class, 'index'])->middleware('permission:installations.view');
    Route::get('/installations/{installation}', [InstallationController::class, 'show'])->middleware('permission:installations.view');
    Route::post('/installations', [InstallationController::class, 'store'])->middleware('permission:installations.view');
    Route::put('/installations/{installation}', [InstallationController::class, 'update'])->middleware('permission:installations.view');
    Route::delete('/installations/{installation}', [InstallationController::class, 'destroy'])->middleware('permission:installations.view');
    
    // Export route (assuming this exists or will be added)
    // Route::get('/installations/export', [InstallationController::class, 'export'])->middleware('permission:installations.export');
    
    // Filter installations by app
    Route::get('/apps/{app}/installations', [InstallationController::class, 'byApp']);
    
    // Email Templates routes
    Route::get('/email-templates', [EmailTemplateController::class, 'index'])->middleware('permission:email_templates.view');
    Route::get('/email-templates/{emailTemplate}', [EmailTemplateController::class, 'show'])->middleware('permission:email_templates.view');
    Route::post('/email-templates', [EmailTemplateController::class, 'store'])->middleware('permission:email_templates.edit');
    Route::put('/email-templates/{emailTemplate}', [EmailTemplateController::class, 'update'])->middleware('permission:email_templates.edit');
    Route::delete('/email-templates/{emailTemplate}', [EmailTemplateController::class, 'destroy'])->middleware('permission:email_templates.edit');
    Route::post('/email-templates/{emailTemplate}/toggle-active', [EmailTemplateController::class, 'toggleActive'])->middleware('permission:email_templates.edit');
    Route::post('/email-templates/{emailTemplate}/render', [EmailTemplateController::class, 'render'])->middleware('permission:email_templates.view');
    
    // Filter email templates by app
    Route::get('/apps/{app}/email-templates', [EmailTemplateController::class, 'byApp']);

    // Pricing Plans routes
    Route::get('/pricing-plans', [PricingPlanController::class, 'index'])->middleware('permission:pricing_plans.view');
    Route::put('/pricing-plans/{pricingPlan}', [PricingPlanController::class, 'update'])->middleware('permission:pricing_plans.edit');
    Route::post('/pricing-plans/{pricingPlan}/toggle-active', [PricingPlanController::class, 'toggleActive'])->middleware('permission:pricing_plans.edit');
    Route::get('/pricing-plans/{pricingPlan}/features', [PricingPlanController::class, 'features'])->middleware('permission:pricing_plans.view');
    Route::put('/pricing-plans/{pricingPlan}/features', [PricingPlanController::class, 'updateFeatures'])->middleware('permission:pricing_plans.edit');

    // Integrations routes
    Route::get('/integrations', [IntegrationController::class, 'index'])->middleware('permission:integrations.view');
    Route::put('/integrations/{key}', [IntegrationController::class, 'update'])->middleware('permission:integrations.edit');

    // Features ("what's new") routes
    Route::get('/features', [FeatureController::class, 'index'])->middleware('permission:features.view');
    Route::get('/features/{feature}', [FeatureController::class, 'show'])->middleware('permission:features.view');
    Route::post('/features', [FeatureController::class, 'store'])->middleware('permission:features.add');
    Route::put('/features/{feature}', [FeatureController::class, 'update'])->middleware('permission:features.edit');
    Route::delete('/features/{feature}', [FeatureController::class, 'destroy'])->middleware('permission:features.delete');
    Route::post('/features/{feature}/toggle-published', [FeatureController::class, 'togglePublished'])->middleware('permission:features.edit');
    Route::post('/features/{feature}/image', [FeatureController::class, 'uploadImage'])->middleware('permission:features.edit');

    // Filter features by app
    Route::get('/apps/{app}/features', [FeatureController::class, 'byApp'])->middleware('permission:features.view');

    // ACL User routes
    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.add');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.edit');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.edit');

    // Role routes
    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:roles.add');
    Route::get('/roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.edit');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.edit');

    // Permission routes
    Route::get('/permissions', [PermissionController::class, 'index'])->middleware('permission:permissions.view');
    Route::post('/permissions', [PermissionController::class, 'store'])->middleware('permission:permissions.add');
    Route::get('/permissions/{permission}', [PermissionController::class, 'show'])->middleware('permission:permissions.view');
    Route::put('/permissions/{permission}', [PermissionController::class, 'update'])->middleware('permission:permissions.edit');
    Route::delete('/permissions/{permission}', [PermissionController::class, 'destroy'])->middleware('permission:permissions.edit');
});

// Webhook routes (public, no auth required)
Route::prefix('webhooks')->group(function () {
    Route::post('/install', [WebhookController::class, 'install']);
    Route::post('/uninstall', [WebhookController::class, 'uninstall']);
    Route::post('/plan-change', [WebhookController::class, 'planChange']);
});

// Public routes consumed by embedded Shopify apps (no auth required)
Route::prefix('public')->group(function () {
    Route::get('/apps/{app}/features', [FeatureController::class, 'publicByApp']);
});

<?php

namespace App\Docs;

use OpenApi\Attributes as OA;

/**
 * MandalHisab API — OpenAPI 3.0 Specification
 *
 * Ganpati Mandal Financial Management System.
 * Base URL: /api/v1
 */
#[OA\OpenApi(
    openapi: '3.0.0',
    info: new OA\Info(
        version: '1.0.0',
        title: 'MandalHisab API',
        description: 'Ganpati Mandal Financial Management System API. '
            . 'All responses use a standard envelope: `{success, statusCode, message, data, meta}`. '
            . 'Money amounts are INR with max 2 decimals. Calendar dates use `YYYY-MM-DD`, '
            . 'timestamps use ISO-8601 UTC.',
        contact: new OA\Contact(email: 'support@mandalhisab.in'),
        license: new OA\License(name: 'Proprietary'),
    ),
    servers: [
        new OA\Server(url: 'https://api.mandalhisab.in/api/v1', description: 'Production'),
        new OA\Server(url: 'http://localhost:8000/api/v1', description: 'Local Development'),
    ],
    security: [['bearerAuth' => []]],
    tags: [
        new OA\Tag(name: 'Authentication', description: 'Register, login, tokens, profile & security PIN'),
        new OA\Tag(name: 'Mandal & Festival', description: 'Mandal and festival management'),
        new OA\Tag(name: 'Dashboard', description: 'Financial dashboard & analytics'),
        new OA\Tag(name: 'Vargani', description: 'Collection receipts (वर्गणी)'),
        new OA\Tag(name: 'Receipt Books', description: 'Physical receipt book allocation & tracking'),
        new OA\Tag(name: 'Expenses', description: 'Expense management & bill upload'),
        new OA\Tag(name: 'Funds & Handovers', description: 'Cash, bank, UPI, fund transfers & handovers'),
        new OA\Tag(name: 'Members', description: 'Mandal members & role hierarchy'),
        new OA\Tag(name: 'Reports & Final Hisab', description: 'Reports, exports & final hisab signing'),
        new OA\Tag(name: 'Devices & Notifications', description: 'Device tokens, notifications & app config'),
        new OA\Tag(name: 'Sync & Realtime', description: 'Offline sync and server-sent events'),
        new OA\Tag(name: 'Public', description: 'Public endpoints'),
    ],
)]
class MandalHisabOpenApi
{
}

// ─────────────────────────────────────────────────────────────────────────────
// PUBLIC
// ─────────────────────────────────────────────────────────────────────────────

#[OA\Get(
    path: '/config/app',
    operationId: 'getAppConfig',
    summary: 'Get app configuration',
    description: 'Public configuration: version, maintenance mode, feature flags, support contacts.',
    tags: ['Devices & Notifications'],
    responses: [
        new OA\Response(response: 200, description: 'App configuration',
            content: new OA\JsonContent(ref: '#/components/schemas/AppConfig')),
        new OA\Response(response: 503, description: 'Maintenance mode active',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class GetAppConfig
{
}

#[OA\Get(
    path: '/public/receipts/{receiptNumber}',
    operationId: 'getPublicReceipt',
    summary: 'Public receipt page (limited fields)',
    tags: ['Public'],
    parameters: [
        new OA\Parameter(name: 'receiptNumber', in: 'path', required: true, description: 'Receipt number', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Public receipt data',
            content: new OA\JsonContent(ref: '#/components/schemas/PublicReceipt')),
        new OA\Response(response: 404, description: 'Receipt not found',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class GetPublicReceipt
{
}

// ─────────────────────────────────────────────────────────────────────────────
// AUTHENTICATION
// ─────────────────────────────────────────────────────────────────────────────

#[OA\Post(
    path: '/auth/register',
    operationId: 'authRegister',
    summary: 'Register a new account',
    tags: ['Authentication'],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(ref: '#/components/schemas/RegisterRequest')),
    responses: [
        new OA\Response(response: 201, description: 'Account created',
            content: new OA\JsonContent(ref: '#/components/schemas/AuthResponse')),
        new OA\Response(response: 422, description: 'Validation failed',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        new OA\Response(response: 409, description: 'User already exists',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class AuthRegister
{
}

#[OA\Post(
    path: '/auth/login',
    operationId: 'authLogin',
    summary: 'Login with username/phone and password',
    tags: ['Authentication'],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(ref: '#/components/schemas/LoginRequest')),
    responses: [
        new OA\Response(response: 200, description: 'Login successful',
            content: new OA\JsonContent(ref: '#/components/schemas/AuthResponse')),
        new OA\Response(response: 401, description: 'Invalid credentials',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class AuthLogin
{
}

#[OA\Post(
    path: '/auth/token/refresh',
    operationId: 'authRefresh',
    summary: 'Refresh access token (rotates refresh token)',
    tags: ['Authentication'],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'refreshToken', type: 'string', description: 'Valid refresh token'),
        ])),
    responses: [
        new OA\Response(response: 200, description: 'New tokens issued',
            content: new OA\JsonContent(ref: '#/components/schemas/TokenResponse')),
        new OA\Response(response: 401, description: 'Invalid/expired refresh token',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class AuthRefresh
{
}

#[OA\Post(
    path: '/auth/logout',
    operationId: 'authLogout',
    summary: 'Logout (revokes refresh token, optionally all sessions)',
    security: [['bearerAuth' => []]],
    tags: ['Authentication'],
    requestBody: new OA\RequestBody(required: false,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'refreshToken', type: 'string'),
            new OA\Property(property: 'deviceToken', type: 'string'),
            new OA\Property(property: 'allSessions', type: 'boolean', default: false),
        ])),
    responses: [
        new OA\Response(response: 200, description: 'Logged out'),
        new OA\Response(response: 401, description: 'Unauthorized',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class AuthLogout
{
}

#[OA\Get(
    path: '/auth/me',
    operationId: 'authMe',
    summary: 'Get current user profile',
    security: [['bearerAuth' => []]],
    tags: ['Authentication'],
    responses: [
        new OA\Response(response: 200, description: 'User profile',
            content: new OA\JsonContent(ref: '#/components/schemas/UserResponse')),
        new OA\Response(response: 401, description: 'Unauthorized',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class AuthMe
{
}

#[OA\Put(
    path: '/auth/me',
    operationId: 'authUpdateMe',
    summary: 'Update current user profile',
    security: [['bearerAuth' => []]],
    tags: ['Authentication'],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(ref: '#/components/schemas/UpdateProfileRequest')),
    responses: [
        new OA\Response(response: 200, description: 'Profile updated',
            content: new OA\JsonContent(ref: '#/components/schemas/UserResponse')),
        new OA\Response(response: 422, description: 'Validation failed',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class AuthUpdateMe
{
}

#[OA\Put(
    path: '/auth/security-pin',
    operationId: 'authSetPin',
    summary: 'Set or update 4-digit security PIN',
    security: [['bearerAuth' => []]],
    tags: ['Authentication'],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'pin', type: 'string', description: 'Exactly 4 digits', minLength: 4, maxLength: 4),
            new OA\Property(property: 'currentPassword', type: 'string'),
            new OA\Property(property: 'currentPin', type: 'string', description: 'Required when changing an existing PIN'),
        ])),
    responses: [
        new OA\Response(response: 200, description: 'PIN set successfully'),
        new OA\Response(response: 422, description: 'Validation failed',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class AuthSetPin
{
}

#[OA\Put(
    path: '/auth/password',
    operationId: 'authChangePassword',
    summary: 'Change account password',
    security: [['bearerAuth' => []]],
    tags: ['Authentication'],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'currentPassword', type: 'string'),
            new OA\Property(property: 'newPassword', type: 'string', minLength: 8),
        ])),
    responses: [
        new OA\Response(response: 200, description: 'Password changed'),
        new OA\Response(response: 422, description: 'Validation failed',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class AuthChangePassword
{
}

// ─────────────────────────────────────────────────────────────────────────────
// MANDAL & FESTIVAL
// ─────────────────────────────────────────────────────────────────────────────

#[OA\Get(
    path: '/mandals',
    operationId: 'listMandals',
    summary: 'List my mandals',
    security: [['bearerAuth' => []]],
    tags: ['Mandal & Festival'],
    responses: [
        new OA\Response(response: 200, description: 'List of mandals',
            content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/MandalListItem'))),
    ],
)]
class ListMandals
{
}

#[OA\Post(
    path: '/mandals',
    operationId: 'createMandal',
    summary: 'Create a new mandal (caller becomes ADMIN)',
    security: [['bearerAuth' => []]],
    tags: ['Mandal & Festival'],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(ref: '#/components/schemas/MandalRequest')),
    responses: [
        new OA\Response(response: 201, description: 'Mandal created',
            content: new OA\JsonContent(ref: '#/components/schemas/MandalResponse')),
        new OA\Response(response: 422, description: 'Validation failed',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class CreateMandal
{
}

#[OA\Get(
    path: '/mandals/{mandalId}',
    operationId: 'getMandal',
    summary: 'Get mandal details',
    security: [['bearerAuth' => []]],
    tags: ['Mandal & Festival'],
    parameters: [new OA\Parameter(name: 'mandalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'Mandal details',
            content: new OA\JsonContent(ref: '#/components/schemas/MandalResponse')),
        new OA\Response(response: 404, description: 'Not found',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class GetMandal
{
}

#[OA\Put(
    path: '/mandals/{mandalId}',
    operationId: 'updateMandal',
    summary: 'Update mandal (ADMIN only)',
    security: [['bearerAuth' => []]],
    tags: ['Mandal & Festival'],
    parameters: [new OA\Parameter(name: 'mandalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(ref: '#/components/schemas/MandalRequest')),
    responses: [
        new OA\Response(response: 200, description: 'Mandal updated',
            content: new OA\JsonContent(ref: '#/components/schemas/MandalResponse')),
        new OA\Response(response: 403, description: 'Forbidden',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class UpdateMandal
{
}

#[OA\Get(
    path: '/mandals/{mandalId}/festivals',
    operationId: 'listFestivals',
    summary: 'List festivals for a mandal',
    security: [['bearerAuth' => []]],
    tags: ['Mandal & Festival'],
    parameters: [
        new OA\Parameter(name: 'mandalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status',
            schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'UPCOMING', 'COMPLETED'])),
        new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'List of festivals',
            content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/FestivalResponse'))),
    ],
)]
class ListFestivals
{
}

#[OA\Post(
    path: '/mandals/{mandalId}/festivals',
    operationId: 'createFestival',
    summary: 'Create a festival (ADMIN/TREASURER)',
    security: [['bearerAuth' => []]],
    tags: ['Mandal & Festival'],
    parameters: [new OA\Parameter(name: 'mandalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(ref: '#/components/schemas/FestivalRequest')),
    responses: [
        new OA\Response(response: 201, description: 'Festival created',
            content: new OA\JsonContent(ref: '#/components/schemas/FestivalResponse')),
        new OA\Response(response: 422, description: 'Validation failed',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class CreateFestival
{
}

#[OA\Get(
    path: '/festivals/{festivalId}',
    operationId: 'getFestival',
    summary: 'Get festival details',
    security: [['bearerAuth' => []]],
    tags: ['Mandal & Festival'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'Festival details',
            content: new OA\JsonContent(ref: '#/components/schemas/FestivalResponse')),
        new OA\Response(response: 404, description: 'Not found',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class GetFestival
{
}

#[OA\Put(
    path: '/festivals/{festivalId}',
    operationId: 'updateFestival',
    summary: 'Update festival (ADMIN; TREASURER may edit budgetGoal/openingBalance)',
    security: [['bearerAuth' => []]],
    tags: ['Mandal & Festival'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(ref: '#/components/schemas/FestivalRequest')),
    responses: [
        new OA\Response(response: 200, description: 'Festival updated',
            content: new OA\JsonContent(ref: '#/components/schemas/FestivalResponse')),
        new OA\Response(response: 403, description: 'Forbidden',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class UpdateFestival
{
}

// ─────────────────────────────────────────────────────────────────────────────
// DASHBOARD
// ─────────────────────────────────────────────────────────────────────────────

#[OA\Get(
    path: '/festivals/{festivalId}/dashboard/summary',
    operationId: 'dashboardSummary',
    summary: 'Festival dashboard summary with metrics & recent transactions',
    security: [['bearerAuth' => []]],
    tags: ['Dashboard'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'Dashboard summary',
            content: new OA\JsonContent(ref: '#/components/schemas/DashboardSummary')),
        new OA\Response(response: 404, description: 'Not found',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class DashboardSummaryOperation
{
}

// ─────────────────────────────────────────────────────────────────────────────
// VARGANI
// ─────────────────────────────────────────────────────────────────────────────

#[OA\Get(
    path: '/festivals/{festivalId}/vargani',
    operationId: 'listVargani',
    summary: 'List vargani entries (paginated, filterable)',
    security: [['bearerAuth' => []]],
    tags: ['Vargani'],
    parameters: [
        new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100)),
        new OA\Parameter(name: 'search', in: 'query', description: 'Donor name / phone / receipt #', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'paymentMode', in: 'query', schema: new OA\Schema(type: 'string', enum: ['CASH', 'UPI', 'CHEQUE', 'NET_BANKING'])),
        new OA\Parameter(name: 'filter', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ALL', 'TODAY', 'CASH', 'UPI', 'CANCELLED'])),
        new OA\Parameter(name: 'area', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'collectorId', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'startDate', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'endDate', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'List of vargani entries',
            content: new OA\JsonContent(ref: '#/components/schemas/VarganiListResponse')),
    ],
)]
class ListVargani
{
}

#[OA\Post(
    path: '/festivals/{festivalId}/vargani',
    operationId: 'createVargani',
    summary: 'Create a vargani receipt (ADMIN/TREASURER/COLLECTOR)',
    description: 'Server assigns the next receipt number under a concurrency-safe lock. Sends Idempotency-Key recommended.',
    security: [['bearerAuth' => []]],
    tags: ['Vargani'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(ref: '#/components/schemas/VarganiRequest')),
    responses: [
        new OA\Response(response: 201, description: 'Receipt created',
            content: new OA\JsonContent(ref: '#/components/schemas/VarganiCreatedResponse')),
        new OA\Response(response: 422, description: 'Validation failed (e.g. RECEIPT_BOOK_EXHAUSTED)',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        new OA\Response(response: 409, description: 'Conflict',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class CreateVargani
{
}

#[OA\Get(
    path: '/festivals/{festivalId}/vargani/{varganiId}',
    operationId: 'getVargani',
    summary: 'Get a single vargani / digital receipt',
    security: [['bearerAuth' => []]],
    tags: ['Vargani'],
    parameters: [
        new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'varganiId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Vargani entry',
            content: new OA\JsonContent(ref: '#/components/schemas/VarganiResponse')),
        new OA\Response(response: 404, description: 'Not found',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class GetVargani
{
}

#[OA\Post(
    path: '/festivals/{festivalId}/vargani/{varganiId}/cancel',
    operationId: 'cancelVargani',
    summary: 'Cancel a vargani (ADMIN/TREASURER/owning COLLECTOR)',
    description: 'Keeps audit trail, reverses fund impact. Errors: ALREADY_CANCELLED, HISAB_LOCKED.',
    security: [['bearerAuth' => []]],
    tags: ['Vargani'],
    parameters: [
        new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'varganiId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'reason', type: 'string', description: 'Cancellation reason'),
        ])),
    responses: [
        new OA\Response(response: 200, description: 'Cancelled'),
        new OA\Response(response: 409, description: 'ALREADY_CANCELLED / HISAB_LOCKED',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class CancelVargani
{
}

#[OA\Post(
    path: '/festivals/{festivalId}/vargani/{varganiId}/signature',
    operationId: 'uploadVarganiSignature',
    summary: 'Upload signature image to a receipt (multipart)',
    security: [['bearerAuth' => []]],
    tags: ['Vargani'],
    parameters: [
        new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'varganiId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\MediaType(mediaType: 'multipart/form-data',
            schema: new OA\Schema(type: 'object', properties: [
                new OA\Property(property: 'signatureFile', type: 'string', format: 'binary',
                    description: 'Image file (jpeg/png, max 1 MB)'),
            ]))),
    responses: [
        new OA\Response(response: 200, description: 'Signature uploaded',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'signatureUrl', type: 'string'),
            ])),
        new OA\Response(response: 422, description: 'FILE_TOO_LARGE / UNSUPPORTED_MEDIA_TYPE',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class UploadVarganiSignature
{
}

#[OA\Get(
    path: '/festivals/{festivalId}/vargani/export',
    operationId: 'exportVargani',
    summary: 'Export vargani (xlsx/csv/pdf) — binary response',
    security: [['bearerAuth' => []]],
    tags: ['Vargani'],
    parameters: [
        new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'format', in: 'query', required: true,
            schema: new OA\Schema(type: 'string', enum: ['xlsx', 'csv', 'pdf'])),
        new OA\Parameter(name: 'startDate', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'endDate', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'paymentMode', in: 'query', schema: new OA\Schema(type: 'string', enum: ['CASH', 'UPI', 'CHEQUE', 'NET_BANKING'])),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Binary file stream'),
        new OA\Response(response: 403, description: 'ADMIN/TREASURER only',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class ExportVargani
{
}

#[OA\Get(
    path: '/festivals/{festivalId}/vargani/{varganiId}/pdf',
    operationId: 'varganiPdf',
    summary: 'Download receipt PDF — binary response',
    security: [['bearerAuth' => []]],
    tags: ['Vargani'],
    parameters: [
        new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'varganiId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'application/pdf'),
        new OA\Response(response: 404, description: 'Not found',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class VarganiPdf
{
}

// ─────────────────────────────────────────────────────────────────────────────
// RECEIPT BOOKS
// ─────────────────────────────────────────────────────────────────────────────

#[OA\Get(
    path: '/festivals/{festivalId}/receipt-books',
    operationId: 'listReceiptBooks',
    summary: 'List receipt books for a festival',
    security: [['bearerAuth' => []]],
    tags: ['Receipt Books'],
    parameters: [
        new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'COMPLETED', 'LOST', 'CANCELLED'])),
    ],
    responses: [
        new OA\Response(response: 200, description: 'List of receipt books',
            content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ReceiptBookResponse'))),
    ],
)]
class ListReceiptBooks
{
}

#[OA\Post(
    path: '/festivals/{festivalId}/receipt-books',
    operationId: 'createReceiptBooks',
    summary: 'Create / batch register receipt books (ADMIN/TREASURER)',
    security: [['bearerAuth' => []]],
    tags: ['Receipt Books'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'books', type: 'array',
                items: new OA\Items(ref: '#/components/schemas/ReceiptBookDraft')),
        ])),
    responses: [
        new OA\Response(response: 201, description: 'Books created',
            content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ReceiptBookResponse'))),
        new OA\Response(response: 409, description: 'Overlapping range CONFLICT',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class CreateReceiptBooks
{
}

#[OA\Get(
    path: '/receipt-books/{bookId}',
    operationId: 'getReceiptBook',
    summary: 'Get a single receipt book',
    security: [['bearerAuth' => []]],
    tags: ['Receipt Books'],
    parameters: [new OA\Parameter(name: 'bookId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'Receipt book',
            content: new OA\JsonContent(ref: '#/components/schemas/ReceiptBookResponse')),
        new OA\Response(response: 404, description: 'Not found',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class GetReceiptBook
{
}

#[OA\Post(
    path: '/receipt-books/{bookId}/assign',
    operationId: 'assignReceiptBook',
    summary: 'Assign/re-assign a book to a collector (ADMIN/TREASURER)',
    security: [['bearerAuth' => []]],
    tags: ['Receipt Books'],
    parameters: [new OA\Parameter(name: 'bookId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'collectorId', type: 'string'),
            new OA\Property(property: 'notes', type: 'string'),
        ])),
    responses: [
        new OA\Response(response: 200, description: 'Assigned'),
        new OA\Response(response: 404, description: 'Book not found',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class AssignReceiptBook
{
}

#[OA\Patch(
    path: '/receipt-books/{bookId}/status',
    operationId: 'updateReceiptBookStatus',
    summary: 'Update book status (ACTIVE→COMPLETED|LOST|CANCELLED)',
    security: [['bearerAuth' => []]],
    tags: ['Receipt Books'],
    parameters: [new OA\Parameter(name: 'bookId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'COMPLETED', 'LOST', 'CANCELLED']),
            new OA\Property(property: 'notes', type: 'string'),
        ])),
    responses: [
        new OA\Response(response: 200, description: 'Status updated'),
        new OA\Response(response: 422, description: 'Invalid transition',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class UpdateReceiptBookStatus
{
}

// ─────────────────────────────────────────────────────────────────────────────
// EXPENSES
// ─────────────────────────────────────────────────────────────────────────────

#[OA\Get(
    path: '/festivals/{festivalId}/expenses',
    operationId: 'listExpenses',
    summary: 'List expenses (paginated, filterable)',
    security: [['bearerAuth' => []]],
    tags: ['Expenses'],
    parameters: [
        new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100)),
        new OA\Parameter(name: 'category', in: 'query',
            schema: new OA\Schema(type: 'string', enum: ['STAGE_MANDAP', 'SOUND_LIGHTING', 'MURTI_DECORATION', 'POOJA_PRASAD', 'SECURITY_LOGISTICS', 'MISCELLANEOUS'])),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['PAID', 'PENDING'])),
        new OA\Parameter(name: 'hasBill', in: 'query', schema: new OA\Schema(type: 'string', enum: ['true', 'false'])),
        new OA\Parameter(name: 'filter', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ALL', 'TODAY', 'PENDING', 'PENDING_BILL'])),
    ],
    responses: [
        new OA\Response(response: 200, description: 'List of expenses',
            content: new OA\JsonContent(ref: '#/components/schemas/ExpenseListResponse')),
    ],
)]
class ListExpenses
{
}

#[OA\Post(
    path: '/festivals/{festivalId}/expenses',
    operationId: 'createExpense',
    summary: 'Create an expense (ADMIN/TREASURER) — multipart or JSON',
    description: 'Either billFile OR billPendingReason is required. Idempotency-Key recommended.',
    security: [['bearerAuth' => []]],
    tags: ['Expenses'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\MediaType(mediaType: 'multipart/form-data',
            schema: new OA\Schema(ref: '#/components/schemas/ExpenseRequest'))),
    responses: [
        new OA\Response(response: 201, description: 'Expense created',
            content: new OA\JsonContent(ref: '#/components/schemas/ExpenseResponse')),
        new OA\Response(response: 422, description: 'Validation failed',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class CreateExpense
{
}

#[OA\Get(
    path: '/festivals/{festivalId}/expenses/{expenseId}',
    operationId: 'getExpense',
    summary: 'Get a single expense',
    security: [['bearerAuth' => []]],
    tags: ['Expenses'],
    parameters: [
        new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'expenseId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Expense details',
            content: new OA\JsonContent(ref: '#/components/schemas/ExpenseResponse')),
        new OA\Response(response: 404, description: 'Not found',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class GetExpense
{
}

#[OA\Patch(
    path: '/festivals/{festivalId}/expenses/{expenseId}',
    operationId: 'updateExpense',
    summary: 'Update non-destructive expense fields (ADMIN/TREASURER)',
    description: 'Blocked when HISAB_LOCKED. Amount/paymentMode may only change while PENDING.',
    security: [['bearerAuth' => []]],
    tags: ['Expenses'],
    parameters: [
        new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'expenseId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'category', type: 'string', enum: ['STAGE_MANDAP', 'SOUND_LIGHTING', 'MURTI_DECORATION', 'POOJA_PRASAD', 'SECURITY_LOGISTICS', 'MISCELLANEOUS']),
            new OA\Property(property: 'paidTo', type: 'string'),
            new OA\Property(property: 'notes', type: 'string'),
            new OA\Property(property: 'billPendingReason', type: 'string'),
        ])),
    responses: [
        new OA\Response(response: 200, description: 'Expense updated',
            content: new OA\JsonContent(ref: '#/components/schemas/ExpenseResponse')),
        new OA\Response(response: 409, description: 'HISAB_LOCKED',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class UpdateExpense
{
}

#[OA\Post(
    path: '/festivals/{festivalId}/expenses/{expenseId}/bill',
    operationId: 'attachExpenseBill',
    summary: 'Attach a bill image/PDF (multipart)',
    security: [['bearerAuth' => []]],
    tags: ['Expenses'],
    parameters: [
        new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'expenseId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\MediaType(mediaType: 'multipart/form-data',
            schema: new OA\Schema(type: 'object', properties: [
                new OA\Property(property: 'billFile', type: 'string', format: 'binary',
                    description: 'Image or PDF, max 2 MB'),
            ]))),
    responses: [
        new OA\Response(response: 200, description: 'Bill attached, billPendingReason cleared'),
        new OA\Response(response: 422, description: 'FILE_TOO_LARGE',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class AttachExpenseBill
{
}

#[OA\Patch(
    path: '/festivals/{festivalId}/expenses/{expenseId}/mark-paid',
    operationId: 'markExpensePaid',
    summary: 'Mark a pending expense as PAID (ADMIN/TREASURER)',
    security: [['bearerAuth' => []]],
    tags: ['Expenses'],
    parameters: [
        new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'expenseId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Marked paid, fund buckets updated'),
        new OA\Response(response: 404, description: 'Not found',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class MarkExpensePaid
{
}

#[OA\Get(
    path: '/festivals/{festivalId}/expenses/export',
    operationId: 'exportExpenses',
    summary: 'Export expenses (xlsx/csv/pdf) — binary response',
    security: [['bearerAuth' => []]],
    tags: ['Expenses'],
    parameters: [
        new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'format', in: 'query', required: true,
            schema: new OA\Schema(type: 'string', enum: ['xlsx', 'csv', 'pdf'])),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Binary file stream'),
    ],
)]
class ExportExpenses
{
}

// ─────────────────────────────────────────────────────────────────────────────
// FUNDS & HANDOVERS
// ─────────────────────────────────────────────────────────────────────────────

#[OA\Get(
    path: '/festivals/{festivalId}/funds/summary',
    operationId: 'fundSummary',
    summary: 'Fund balance summary (treasurer/collectors/bank/UPI)',
    security: [['bearerAuth' => []]],
    tags: ['Funds & Handovers'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'Fund summary',
            content: new OA\JsonContent(ref: '#/components/schemas/FundSummary')),
    ],
)]
class FundSummaryOperation
{
}

#[OA\Get(
    path: '/festivals/{festivalId}/funds/money-trail',
    operationId: 'moneyTrail',
    summary: 'Append-only money trail ledger (ADMIN/TREASURER)',
    security: [['bearerAuth' => []]],
    tags: ['Funds & Handovers'],
    parameters: [
        new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100)),
        new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string',
            enum: ['CASH_RECEIVED', 'UPI_RECEIVED', 'BANK_DEPOSIT', 'BANK_WITHDRAWAL', 'CASH_EXPENSE', 'CASH_HANDOVER', 'FUND_TRANSFER', 'OTHER_INCOME'])),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Money trail entries',
            content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/MoneyTrailEntry'))),
    ],
)]
class MoneyTrail
{
}

#[OA\Get(
    path: '/festivals/{festivalId}/funds/handovers',
    operationId: 'listHandovers',
    summary: 'List cash handovers (COLLECTOR sees own)',
    security: [['bearerAuth' => []]],
    tags: ['Funds & Handovers'],
    parameters: [
        new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['PENDING_APPROVAL', 'VERIFIED_ACCEPTED', 'REJECTED'])),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'List of handovers',
            content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/CashHandover'))),
    ],
)]
class ListHandovers
{
}

#[OA\Post(
    path: '/festivals/{festivalId}/funds/handovers',
    operationId: 'submitHandover',
    summary: 'Submit a cash handover (COLLECTOR/ADMIN) — multipart with PIN',
    security: [['bearerAuth' => []]],
    tags: ['Funds & Handovers'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\MediaType(mediaType: 'multipart/form-data',
            schema: new OA\Schema(type: 'object', properties: [
                new OA\Property(property: 'fromUserId', type: 'string'),
                new OA\Property(property: 'toUserId', type: 'string'),
                new OA\Property(property: 'amount', type: 'number', format: 'double'),
                new OA\Property(property: 'linkedReceiptIds', type: 'string', description: 'JSON array of vargani IDs'),
                new OA\Property(property: 'notes', type: 'string'),
                new OA\Property(property: 'pin', type: 'string', description: '4-digit security PIN'),
                new OA\Property(property: 'photoFile', type: 'string', format: 'binary', description: 'Optional, max 2 MB'),
            ]))),
    responses: [
        new OA\Response(response: 201, description: 'Handover submitted for verification',
            content: new OA\JsonContent(ref: '#/components/schemas/CashHandover')),
        new OA\Response(response: 422, description: 'INVALID_PIN / validation failed',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class SubmitHandover
{
}

#[OA\Get(
    path: '/funds/handovers/{handoverId}',
    operationId: 'getHandover',
    summary: 'Get a handover (participants + ADMIN + TREASURER)',
    security: [['bearerAuth' => []]],
    tags: ['Funds & Handovers'],
    parameters: [new OA\Parameter(name: 'handoverId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'Handover details',
            content: new OA\JsonContent(ref: '#/components/schemas/CashHandover')),
        new OA\Response(response: 404, description: 'Not found',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class GetHandover
{
}

#[OA\Post(
    path: '/funds/handovers/{handoverId}/verify',
    operationId: 'verifyHandover',
    summary: 'Verify (approve/reject) a handover — TREASURER exclusive',
    security: [['bearerAuth' => []]],
    tags: ['Funds & Handovers'],
    parameters: [new OA\Parameter(name: 'handoverId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'action', type: 'string', enum: ['APPROVE', 'REJECT']),
            new OA\Property(property: 'authMethod', type: 'string', enum: ['BIOMETRIC', 'PIN']),
            new OA\Property(property: 'authSignature', type: 'string', description: 'Biometric attestation signature'),
            new OA\Property(property: 'treasurerPin', type: 'string', description: '4-digit PIN (when authMethod=PIN)'),
            new OA\Property(property: 'verificationNotes', type: 'string'),
        ])),
    responses: [
        new OA\Response(response: 200, description: 'Handover verified'),
        new OA\Response(response: 409, description: 'HANDOVER_NOT_PENDING',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        new OA\Response(response: 422, description: 'INVALID_PIN / BIOMETRIC_ATTESTATION_FAILED',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class VerifyHandover
{
}

#[OA\Get(
    path: '/festivals/{festivalId}/funds/bank-accounts',
    operationId: 'listBankAccounts',
    summary: 'List bank accounts (ADMIN/TREASURER)',
    security: [['bearerAuth' => []]],
    tags: ['Funds & Handovers'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'List of bank accounts',
            content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/BankAccount'))),
    ],
)]
class ListBankAccounts
{
}

#[OA\Post(
    path: '/festivals/{festivalId}/funds/bank-accounts',
    operationId: 'storeBankAccount',
    summary: 'Add a bank account (ADMIN/TREASURER)',
    security: [['bearerAuth' => []]],
    tags: ['Funds & Handovers'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(ref: '#/components/schemas/BankAccountRequest')),
    responses: [
        new OA\Response(response: 201, description: 'Account added (accountNumber masked in response)',
            content: new OA\JsonContent(ref: '#/components/schemas/BankAccount')),
        new OA\Response(response: 422, description: 'Validation failed',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class StoreBankAccount
{
}

#[OA\Patch(
    path: '/funds/bank-accounts/{accountId}',
    operationId: 'updateBankAccount',
    summary: 'Update/deactivate a bank account (ADMIN/TREASURER)',
    security: [['bearerAuth' => []]],
    tags: ['Funds & Handovers'],
    parameters: [new OA\Parameter(name: 'accountId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'bankName', type: 'string'),
            new OA\Property(property: 'ifsc', type: 'string'),
            new OA\Property(property: 'upiId', type: 'string'),
            new OA\Property(property: 'isActive', type: 'boolean'),
        ])),
    responses: [
        new OA\Response(response: 200, description: 'Account updated'),
        new OA\Response(response: 404, description: 'Not found',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class UpdateBankAccount
{
}

#[OA\Post(
    path: '/festivals/{festivalId}/funds/transfers',
    operationId: 'transferFunds',
    summary: 'Fund transfer / bank deposit / withdrawal (ADMIN/TREASURER)',
    description: 'Idempotency-Key required. Creates FundTransfer + MoneyTrailEntry.',
    security: [['bearerAuth' => []]],
    tags: ['Funds & Handovers'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'fromBucket', type: 'string', enum: ['CASH_TREASURER', 'CASH_COLLECTORS', 'BANK', 'UPI']),
            new OA\Property(property: 'toBucket', type: 'string', enum: ['CASH_TREASURER', 'CASH_COLLECTORS', 'BANK', 'UPI']),
            new OA\Property(property: 'bankAccountId', type: 'string', description: 'Required when BANK involved'),
            new OA\Property(property: 'amount', type: 'number', format: 'double', exclusiveMinimum: 0),
            new OA\Property(property: 'notes', type: 'string'),
        ])),
    responses: [
        new OA\Response(response: 201, description: 'Transfer recorded'),
        new OA\Response(response: 422, description: 'Insufficient balance / validation failed',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class TransferFunds
{
}

#[OA\Get(
    path: '/festivals/{festivalId}/other-income',
    operationId: 'listOtherIncome',
    summary: 'List other income entries',
    security: [['bearerAuth' => []]],
    tags: ['Funds & Handovers'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'List of other income',
            content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/OtherIncome'))),
    ],
)]
class ListOtherIncome
{
}

#[OA\Post(
    path: '/festivals/{festivalId}/other-income',
    operationId: 'storeOtherIncome',
    summary: 'Record other income (ADMIN/TREASURER)',
    security: [['bearerAuth' => []]],
    tags: ['Funds & Handovers'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'amount', type: 'number', format: 'double'),
            new OA\Property(property: 'date', type: 'string', format: 'date'),
            new OA\Property(property: 'notes', type: 'string'),
        ])),
    responses: [
        new OA\Response(response: 201, description: 'Income recorded',
            content: new OA\JsonContent(ref: '#/components/schemas/OtherIncome')),
        new OA\Response(response: 422, description: 'Validation failed',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class StoreOtherIncome
{
}

// ─────────────────────────────────────────────────────────────────────────────
// MEMBERS
// ─────────────────────────────────────────────────────────────────────────────

#[OA\Get(
    path: '/mandals/{mandalId}/members',
    operationId: 'listMembers',
    summary: 'List mandal members',
    security: [['bearerAuth' => []]],
    tags: ['Members'],
    parameters: [
        new OA\Parameter(name: 'mandalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'role', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ADMIN', 'TREASURER', 'COLLECTOR', 'MEMBER', 'ALL'])),
    ],
    responses: [
        new OA\Response(response: 200, description: 'List of members',
            content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Member'))),
    ],
)]
class ListMembers
{
}

#[OA\Post(
    path: '/mandals/{mandalId}/members',
    operationId: 'addMember',
    summary: 'Invite/add a member (ADMIN) — links existing user or creates stub',
    security: [['bearerAuth' => []]],
    tags: ['Members'],
    parameters: [new OA\Parameter(name: 'mandalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'phone', type: 'string', description: '10-digit Indian mobile'),
            new OA\Property(property: 'role', type: 'string', enum: ['ADMIN', 'TREASURER', 'COLLECTOR', 'MEMBER']),
            new OA\Property(property: 'area', type: 'string'),
        ])),
    responses: [
        new OA\Response(response: 201, description: 'Member added',
            content: new OA\JsonContent(ref: '#/components/schemas/Member')),
        new OA\Response(response: 422, description: 'Validation failed',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class AddMember
{
}

#[OA\Get(
    path: '/mandals/{mandalId}/members/{memberUserId}',
    operationId: 'getMember',
    summary: 'Get a single member',
    security: [['bearerAuth' => []]],
    tags: ['Members'],
    parameters: [
        new OA\Parameter(name: 'mandalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'memberUserId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Member details',
            content: new OA\JsonContent(ref: '#/components/schemas/Member')),
        new OA\Response(response: 404, description: 'Not found',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class GetMember
{
}

#[OA\Patch(
    path: '/mandals/{mandalId}/members/{memberUserId}',
    operationId: 'updateMember',
    summary: 'Update member role/area/active state (ADMIN)',
    security: [['bearerAuth' => []]],
    tags: ['Members'],
    parameters: [
        new OA\Parameter(name: 'mandalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'memberUserId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'role', type: 'string', enum: ['ADMIN', 'TREASURER', 'COLLECTOR', 'MEMBER']),
            new OA\Property(property: 'area', type: 'string'),
            new OA\Property(property: 'isActive', type: 'boolean'),
        ])),
    responses: [
        new OA\Response(response: 200, description: 'Member updated',
            content: new OA\JsonContent(ref: '#/components/schemas/Member')),
        new OA\Response(response: 422, description: 'Cannot remove last ADMIN',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class UpdateMember
{
}

#[OA\Get(
    path: '/mandals/{mandalId}/members/{memberUserId}/financial-summary',
    operationId: 'memberFinancialSummary',
    summary: 'Member financial summary (collections, handovers, assigned book)',
    security: [['bearerAuth' => []]],
    tags: ['Members'],
    parameters: [
        new OA\Parameter(name: 'mandalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'memberUserId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'festivalId', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Financial summary',
            content: new OA\JsonContent(ref: '#/components/schemas/MemberFinancialSummary')),
    ],
)]
class MemberFinancialSummaryOperation
{
}

#[OA\Post(
    path: '/mandals/{mandalId}/members/{memberUserId}/deactivate',
    operationId: 'deactivateMember',
    summary: 'Soft-deactivate a member (ADMIN)',
    security: [['bearerAuth' => []]],
    tags: ['Members'],
    parameters: [
        new OA\Parameter(name: 'mandalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'memberUserId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Member deactivated'),
        new OA\Response(response: 422, description: 'Cannot deactivate last ADMIN',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class DeactivateMember
{
}

// ─────────────────────────────────────────────────────────────────────────────
// REPORTS & FINAL HISAB
// ─────────────────────────────────────────────────────────────────────────────

#[OA\Get(
    path: '/festivals/{festivalId}/reports/overview',
    operationId: 'reportsOverview',
    summary: 'Reports overview with income/expense chart points',
    security: [['bearerAuth' => []]],
    tags: ['Reports & Final Hisab'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'Overview data',
            content: new OA\JsonContent(ref: '#/components/schemas/ReportsOverview')),
    ],
)]
class ReportsOverviewOperation
{
}

#[OA\Get(
    path: '/festivals/{festivalId}/reports/final-hisab',
    operationId: 'finalHisab',
    summary: 'Final hisab statement with audit signatures (ADMIN/TREASURER)',
    security: [['bearerAuth' => []]],
    tags: ['Reports & Final Hisab'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'Final hisab statement',
            content: new OA\JsonContent(ref: '#/components/schemas/FinalHisab')),
    ],
)]
class FinalHisabOperation
{
}

#[OA\Post(
    path: '/festivals/{festivalId}/reports/final-hisab/sign',
    operationId: 'signFinalHisab',
    summary: 'Sign final hisab (ADMIN president / TREASURER). Locks when both signed.',
    security: [['bearerAuth' => []]],
    tags: ['Reports & Final Hisab'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'role', type: 'string', enum: ['ADMIN', 'TREASURER']),
            new OA\Property(property: 'authMethod', type: 'string', enum: ['BIOMETRIC', 'PIN']),
            new OA\Property(property: 'authSignature', type: 'string'),
            new OA\Property(property: 'pin', type: 'string'),
            new OA\Property(property: 'approvalNotes', type: 'string'),
        ])),
    responses: [
        new OA\Response(response: 200, description: 'Signed. isLocked=true when both parties signed'),
        new OA\Response(response: 422, description: 'INVALID_PIN / validation failed',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class SignFinalHisab
{
}

#[OA\Get(
    path: '/festivals/{festivalId}/reports/final-hisab/pdf',
    operationId: 'finalHisabPdf',
    summary: 'Download final hisab PDF — binary response',
    security: [['bearerAuth' => []]],
    tags: ['Reports & Final Hisab'],
    parameters: [new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'application/pdf'),
    ],
)]
class FinalHisabPdf
{
}

#[OA\Get(
    path: '/festivals/{festivalId}/reports/{reportType}',
    operationId: 'typedReport',
    summary: 'Typed sub-report or binary export (?format=xlsx|csv|pdf)',
    security: [['bearerAuth' => []]],
    tags: ['Reports & Final Hisab'],
    parameters: [
        new OA\Parameter(name: 'festivalId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'reportType', in: 'path', required: true,
            schema: new OA\Schema(type: 'string', enum: ['income-expense', 'collections', 'expenses', 'collectors', 'receipt-books', 'cash'])),
        new OA\Parameter(name: 'startDate', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'endDate', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'format', in: 'query', schema: new OA\Schema(type: 'string', enum: ['xlsx', 'csv', 'pdf'], description: 'Omit for JSON')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Report data (JSON) or binary export'),
        new OA\Response(response: 403, description: 'Forbidden',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class TypedReport
{
}

// ─────────────────────────────────────────────────────────────────────────────
// DEVICES & NOTIFICATIONS
// ─────────────────────────────────────────────────────────────────────────────

#[OA\Put(
    path: '/devices/token',
    operationId: 'registerDeviceToken',
    summary: 'Register / update FCM device token',
    security: [['bearerAuth' => []]],
    tags: ['Devices & Notifications'],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'token', type: 'string', description: 'FCM token'),
            new OA\Property(property: 'platform', type: 'string', enum: ['android', 'ios', 'web', 'windows']),
        ])),
    responses: [
        new OA\Response(response: 200, description: 'Token registered'),
        new OA\Response(response: 401, description: 'Unauthorized',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class RegisterDeviceToken
{
}

#[OA\Get(
    path: '/notifications',
    operationId: 'listNotifications',
    summary: 'List in-app notifications',
    security: [['bearerAuth' => []]],
    tags: ['Devices & Notifications'],
    parameters: [
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100)),
        new OA\Parameter(name: 'unreadOnly', in: 'query', schema: new OA\Schema(type: 'boolean')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'List of notifications',
            content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Notification'))),
    ],
)]
class ListNotifications
{
}

#[OA\Patch(
    path: '/notifications/{notificationId}/read',
    operationId: 'markNotificationRead',
    summary: 'Mark a notification as read',
    security: [['bearerAuth' => []]],
    tags: ['Devices & Notifications'],
    parameters: [new OA\Parameter(name: 'notificationId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'Marked read'),
        new OA\Response(response: 404, description: 'Not found',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class MarkNotificationRead
{
}

#[OA\Post(
    path: '/notifications/read-all',
    operationId: 'markAllNotificationsRead',
    summary: 'Mark all notifications as read',
    security: [['bearerAuth' => []]],
    tags: ['Devices & Notifications'],
    responses: [
        new OA\Response(response: 200, description: 'All marked read'),
    ],
)]
class MarkAllNotificationsRead
{
}

// ─────────────────────────────────────────────────────────────────────────────
// SYNC & REALTIME
// ─────────────────────────────────────────────────────────────────────────────

#[OA\Post(
    path: '/sync/batch',
    operationId: 'syncBatchPush',
    summary: 'Push offline queued operations (idempotent by clientUuid)',
    security: [['bearerAuth' => []]],
    tags: ['Sync & Realtime'],
    requestBody: new OA\RequestBody(required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'operations', type: 'array',
                items: new OA\Items(properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['vargani', 'expense', 'handover']),
                    new OA\Property(property: 'clientUuid', type: 'string'),
                    new OA\Property(property: 'payload', type: 'object'),
                ])),
        ])),
    responses: [
        new OA\Response(response: 200, description: 'Sync results per operation',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'results', type: 'array',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'clientUuid', type: 'string'),
                        new OA\Property(property: 'status', type: 'string', enum: ['created', 'updated', 'conflict', 'skipped']),
                        new OA\Property(property: 'serverId', type: 'string'),
                    ])),
            ])),
        new OA\Response(response: 409, description: 'Conflict',
            content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
    ],
)]
class SyncBatchPush
{
}

#[OA\Get(
    path: '/sync/pull',
    operationId: 'syncPullDelta',
    summary: 'Pull incremental changes since timestamp',
    security: [['bearerAuth' => []]],
    tags: ['Sync & Realtime'],
    parameters: [
        new OA\Parameter(name: 'since', in: 'query', required: true, description: 'ISO-8601 UTC timestamp',
            schema: new OA\Schema(type: 'string', format: 'date-time')),
        new OA\Parameter(name: 'festivalId', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Delta changes',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'vargani', type: 'array', items: new OA\Items(ref: '#/components/schemas/VarganiResponse')),
                new OA\Property(property: 'expenses', type: 'array', items: new OA\Items(ref: '#/components/schemas/ExpenseResponse')),
                new OA\Property(property: 'handovers', type: 'array', items: new OA\Items(ref: '#/components/schemas/CashHandover')),
                new OA\Property(property: 'serverTime', type: 'string', format: 'date-time'),
            ])),
    ],
)]
class SyncPullDelta
{
}

#[OA\Get(
    path: '/stream/events',
    operationId: 'streamEvents',
    summary: 'Server-Sent Events stream (handover/vargani/expense events)',
    security: [['bearerAuth' => []]],
    tags: ['Sync & Realtime'],
    responses: [
        new OA\Response(response: 200, description: 'text/event-stream',
            content: new OA\MediaType(mediaType: 'text/event-stream')),
    ],
)]
class StreamEvents
{
}

// ─────────────────────────────────────────────────────────────────────────────
// COMPONENTS: REQUESTS
// ─────────────────────────────────────────────────────────────────────────────

#[OA\Schema(schema: 'RegisterRequest', type: 'object', required: ['fullName', 'usernameOrPhone', 'password'],
    properties: [
        new OA\Property(property: 'fullName', type: 'string', minLength: 2, maxLength: 80),
        new OA\Property(property: 'usernameOrPhone', type: 'string', description: 'Phone number or username'),
        new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
        new OA\Property(property: 'mandalName', type: 'string', nullable: true),
        new OA\Property(property: 'deviceToken', type: 'string', nullable: true),
        new OA\Property(property: 'platform', type: 'string', enum: ['android', 'ios', 'web', 'windows'], nullable: true),
    ])]
class RegisterRequest
{
}

#[OA\Schema(schema: 'LoginRequest', type: 'object', required: ['usernameOrPhone', 'password'],
    properties: [
        new OA\Property(property: 'usernameOrPhone', type: 'string'),
        new OA\Property(property: 'password', type: 'string', format: 'password'),
        new OA\Property(property: 'deviceToken', type: 'string', nullable: true),
        new OA\Property(property: 'platform', type: 'string', enum: ['android', 'ios', 'web', 'windows'], nullable: true),
    ])]
class LoginRequest
{
}

#[OA\Schema(schema: 'UpdateProfileRequest', type: 'object', properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 80),
    new OA\Property(property: 'email', type: 'string', format: 'email'),
    new OA\Property(property: 'defaultLanguage', type: 'string', enum: ['en', 'mr']),
    new OA\Property(property: 'isBiometricEnabled', type: 'boolean'),
    new OA\Property(property: 'activeFestivalId', type: 'string', nullable: true),
    new OA\Property(property: 'avatarBase64', type: 'string', description: 'data:image/...;base64,...'),
])]
class UpdateProfileRequest
{
}

#[OA\Schema(schema: 'MandalRequest', type: 'object', required: ['name', 'address', 'city', 'contactNumber', 'pincode'],
    properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'registrationNumber', type: 'string', nullable: true),
        new OA\Property(property: 'establishedYear', type: 'integer', minimum: 1800, nullable: true),
        new OA\Property(property: 'address', type: 'string'),
        new OA\Property(property: 'city', type: 'string'),
        new OA\Property(property: 'pincode', type: 'string', description: '6 digits'),
        new OA\Property(property: 'wardNumber', type: 'string', nullable: true),
        new OA\Property(property: 'contactNumber', type: 'string', description: '10 digits'),
        new OA\Property(property: 'upiId', type: 'string', nullable: true),
        new OA\Property(property: 'logoBase64', type: 'string', nullable: true),
    ])]
class MandalRequest
{
}

#[OA\Schema(schema: 'FestivalRequest', type: 'object', required: ['name', 'year', 'startDate', 'endDate', 'budgetGoal'],
    properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'year', type: 'string', example: '2026'),
        new OA\Property(property: 'startDate', type: 'string', format: 'date'),
        new OA\Property(property: 'endDate', type: 'string', format: 'date'),
        new OA\Property(property: 'budgetGoal', type: 'number', format: 'double', minimum: 0),
        new OA\Property(property: 'openingBalance', type: 'number', format: 'double', default: 0),
        new OA\Property(property: 'description', type: 'string', maxLength: 500, nullable: true),
    ])]
class FestivalRequest
{
}

#[OA\Schema(schema: 'VarganiRequest', type: 'object', required: ['donorName', 'amount', 'paymentMode', 'area', 'receiptType'],
    properties: [
        new OA\Property(property: 'donorName', type: 'string', minLength: 2, maxLength: 60),
        new OA\Property(property: 'mobileNumber', type: 'string', nullable: true, description: '10 digits'),
        new OA\Property(property: 'amount', type: 'number', format: 'double', exclusiveMinimum: 0),
        new OA\Property(property: 'paymentMode', type: 'string', enum: ['CASH', 'UPI', 'CHEQUE', 'NET_BANKING']),
        new OA\Property(property: 'area', type: 'string'),
        new OA\Property(property: 'address', type: 'string', nullable: true),
        new OA\Property(property: 'collectorId', type: 'string', description: 'Server maps to collector'),
        new OA\Property(property: 'receiptType', type: 'string', enum: ['DIGITAL', 'PHYSICAL_BOOK']),
        new OA\Property(property: 'receiptBookId', type: 'string', nullable: true, description: 'Required if PHYSICAL_BOOK'),
        new OA\Property(property: 'notes', type: 'string', maxLength: 250, nullable: true),
        new OA\Property(property: 'offlineSyncId', type: 'string', nullable: true, description: 'Client UUID for offline idempotency'),
    ])]
class VarganiRequest
{
}

#[OA\Schema(schema: 'ReceiptBookDraft', type: 'object', required: ['bookNumber', 'startNumber', 'endNumber'],
    properties: [
        new OA\Property(property: 'bookNumber', type: 'string', example: 'Book #04'),
        new OA\Property(property: 'startNumber', type: 'integer'),
        new OA\Property(property: 'endNumber', type: 'integer'),
        new OA\Property(property: 'assignedToId', type: 'string', nullable: true),
    ])]
class ReceiptBookDraft
{
}

#[OA\Schema(schema: 'ExpenseRequest', type: 'object', required: ['title', 'category', 'amount', 'paymentMode', 'paidTo', 'date'],
    properties: [
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'category', type: 'string', enum: ['STAGE_MANDAP', 'SOUND_LIGHTING', 'MURTI_DECORATION', 'POOJA_PRASAD', 'SECURITY_LOGISTICS', 'MISCELLANEOUS']),
        new OA\Property(property: 'amount', type: 'number', format: 'double', exclusiveMinimum: 0),
        new OA\Property(property: 'paymentMode', type: 'string', enum: ['CASH', 'UPI', 'CHEQUE', 'NET_BANKING']),
        new OA\Property(property: 'paidTo', type: 'string'),
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'status', type: 'string', enum: ['PAID', 'PENDING'], default: 'PAID'),
        new OA\Property(property: 'billPendingReason', type: 'string', nullable: true, description: 'Required if no bill'),
        new OA\Property(property: 'billFile', type: 'string', format: 'binary', description: 'Multipart only, max 2 MB'),
        new OA\Property(property: 'notes', type: 'string', maxLength: 250, nullable: true),
        new OA\Property(property: 'offlineSyncId', type: 'string', nullable: true),
    ])]
class ExpenseRequest
{
}

#[OA\Schema(schema: 'BankAccountRequest', type: 'object', required: ['bankName', 'accountNumber', 'ifsc', 'accountType'],
    properties: [
        new OA\Property(property: 'bankName', type: 'string'),
        new OA\Property(property: 'accountNumber', type: 'string'),
        new OA\Property(property: 'ifsc', type: 'string'),
        new OA\Property(property: 'accountType', type: 'string', enum: ['CURRENT', 'SAVINGS']),
        new OA\Property(property: 'upiId', type: 'string', nullable: true),
        new OA\Property(property: 'openingBalance', type: 'number', format: 'double', default: 0),
    ])]
class BankAccountRequest
{
}

// ─────────────────────────────────────────────────────────────────────────────
// COMPONENTS: RESPONSES
// ─────────────────────────────────────────────────────────────────────────────

#[OA\Schema(schema: 'TokenResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'accessToken', type: 'string'),
        new OA\Property(property: 'refreshToken', type: 'string'),
        new OA\Property(property: 'expiresIn', type: 'integer', example: 1800),
    ]),
])]
class TokenResponse
{
}

#[OA\Schema(schema: 'User', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'phone', type: 'string', nullable: true),
    new OA\Property(property: 'email', type: 'string', nullable: true),
    new OA\Property(property: 'initials', type: 'string'),
    new OA\Property(property: 'defaultLanguage', type: 'string', enum: ['en', 'mr']),
    new OA\Property(property: 'isBiometricEnabled', type: 'boolean'),
    new OA\Property(property: 'activeFestivalId', type: 'string', nullable: true),
    new OA\Property(property: 'avatarUrl', type: 'string', nullable: true),
    new OA\Property(property: 'mandals', type: 'array', items: new OA\Items(ref: '#/components/schemas/MandalListItem'), description: 'Register/login only'),
])]
class User
{
}

#[OA\Schema(schema: 'AuthResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'accessToken', type: 'string'),
        new OA\Property(property: 'refreshToken', type: 'string'),
        new OA\Property(property: 'expiresIn', type: 'integer', example: 1800),
        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
    ]),
])]
class AuthResponse
{
}

#[OA\Schema(schema: 'UserResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', ref: '#/components/schemas/User'),
])]
class UserResponse
{
}

#[OA\Schema(schema: 'ErrorResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: false),
    new OA\Property(property: 'statusCode', type: 'integer', example: 422),
    new OA\Property(property: 'error', type: 'object', properties: [
        new OA\Property(property: 'code', type: 'string',
            description: 'One of: VALIDATION_FAILED, UNAUTHORIZED, TOKEN_EXPIRED, FORBIDDEN, NOT_FOUND, CONFLICT, ALREADY_CANCELLED, HANDOVER_NOT_PENDING, HISAB_LOCKED, RECEIPT_BOOK_EXHAUSTED, INVALID_PIN, BIOMETRIC_ATTESTATION_FAILED, FILE_TOO_LARGE, UNSUPPORTED_MEDIA_TYPE, RATE_LIMITED, MAINTENANCE_MODE, INTERNAL_ERROR'),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'details', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'field', type: 'string'),
            new OA\Property(property: 'issue', type: 'string'),
        ])),
    ]),
    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
])]
class ErrorResponse
{
}

#[OA\Schema(schema: 'PaginationMeta', type: 'object', properties: [
    new OA\Property(property: 'page', type: 'integer'),
    new OA\Property(property: 'limit', type: 'integer'),
    new OA\Property(property: 'totalRecords', type: 'integer'),
    new OA\Property(property: 'totalPages', type: 'integer'),
    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
])]
class PaginationMeta
{
}

#[OA\Schema(schema: 'AppConfig', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'minSupportedVersion', type: 'string'),
        new OA\Property(property: 'latestVersion', type: 'string'),
        new OA\Property(property: 'forceUpdate', type: 'boolean'),
        new OA\Property(property: 'maintenanceMode', type: 'boolean'),
        new OA\Property(property: 'supportPhone', type: 'string'),
        new OA\Property(property: 'supportEmail', type: 'string'),
        new OA\Property(property: 'features', type: 'object', properties: [
            new OA\Property(property: 'offlineSyncEnabled', type: 'boolean'),
            new OA\Property(property: 'biometricSecurityEnabled', type: 'boolean'),
            new OA\Property(property: 'whatsappReceiptsEnabled', type: 'boolean'),
        ]),
    ]),
])]
class AppConfig
{
}

#[OA\Schema(schema: 'PublicReceipt', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'receiptNumber', type: 'string'),
        new OA\Property(property: 'mandalName', type: 'string'),
        new OA\Property(property: 'festivalName', type: 'string'),
        new OA\Property(property: 'donorName', type: 'string'),
        new OA\Property(property: 'amount', type: 'number', format: 'double'),
        new OA\Property(property: 'paymentMode', type: 'string', enum: ['CASH', 'UPI', 'CHEQUE', 'NET_BANKING']),
        new OA\Property(property: 'issuedAt', type: 'string', format: 'date-time'),
    ]),
])]
class PublicReceipt
{
}

#[OA\Schema(schema: 'MandalListItem', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'city', type: 'string'),
    new OA\Property(property: 'role', type: 'string', enum: ['ADMIN', 'TREASURER', 'COLLECTOR', 'MEMBER']),
    new OA\Property(property: 'isDefault', type: 'boolean'),
    new OA\Property(property: 'logoUrl', type: 'string', nullable: true),
])]
class MandalListItem
{
}

#[OA\Schema(schema: 'MandalResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'registrationNumber', type: 'string', nullable: true),
        new OA\Property(property: 'establishedYear', type: 'integer', nullable: true),
        new OA\Property(property: 'address', type: 'string'),
        new OA\Property(property: 'city', type: 'string'),
        new OA\Property(property: 'pincode', type: 'string'),
        new OA\Property(property: 'wardNumber', type: 'string', nullable: true),
        new OA\Property(property: 'contactNumber', type: 'string'),
        new OA\Property(property: 'logoUrl', type: 'string', nullable: true),
        new OA\Property(property: 'upiId', type: 'string', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]),
])]
class MandalResponse
{
}

#[OA\Schema(schema: 'FestivalResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'mandalId', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'year', type: 'string'),
        new OA\Property(property: 'startDate', type: 'string', format: 'date'),
        new OA\Property(property: 'endDate', type: 'string', format: 'date'),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'UPCOMING', 'COMPLETED']),
        new OA\Property(property: 'budgetGoal', type: 'number', format: 'double'),
        new OA\Property(property: 'openingBalance', type: 'number', format: 'double'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'totalCollection', type: 'number', format: 'double'),
        new OA\Property(property: 'totalExpense', type: 'number', format: 'double'),
        new OA\Property(property: 'closingBalance', type: 'number', format: 'double'),
    ]),
])]
class FestivalResponse
{
}

#[OA\Schema(schema: 'DashboardSummary', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'festival', type: 'object', properties: [
            new OA\Property(property: 'id', type: 'string'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'UPCOMING', 'COMPLETED']),
        ]),
        new OA\Property(property: 'metrics', type: 'object', properties: [
            new OA\Property(property: 'totalCollected', type: 'number', format: 'double'),
            new OA\Property(property: 'budgetGoal', type: 'number', format: 'double'),
            new OA\Property(property: 'progressPercentage', type: 'number', format: 'double'),
            new OA\Property(property: 'totalExpenses', type: 'number', format: 'double'),
            new OA\Property(property: 'netBalance', type: 'number', format: 'double'),
            new OA\Property(property: 'cashInHand', type: 'number', format: 'double'),
            new OA\Property(property: 'cashTreasurer', type: 'number', format: 'double'),
            new OA\Property(property: 'cashCollectors', type: 'number', format: 'double'),
            new OA\Property(property: 'bankBalance', type: 'number', format: 'double'),
            new OA\Property(property: 'upiBalance', type: 'number', format: 'double'),
            new OA\Property(property: 'totalDonorsCount', type: 'integer'),
            new OA\Property(property: 'totalReceiptsIssued', type: 'integer'),
            new OA\Property(property: 'pendingCashWithCollectors', type: 'number', format: 'double'),
        ]),
        new OA\Property(property: 'recentTransactions', type: 'array', items: new OA\Items(
            properties: [
                new OA\Property(property: 'id', type: 'string'),
                new OA\Property(property: 'type', type: 'string', enum: ['VARGANI', 'EXPENSE', 'HANDOVER']),
                new OA\Property(property: 'title', type: 'string'),
                new OA\Property(property: 'subtitle', type: 'string'),
                new OA\Property(property: 'amount', type: 'number', format: 'double'),
                new OA\Property(property: 'paymentMode', type: 'string', enum: ['CASH', 'UPI', 'CHEQUE', 'NET_BANKING']),
                new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
            ])),
    ]),
])]
class DashboardSummary
{
}

#[OA\Schema(schema: 'VarganiResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'receiptNumber', type: 'string'),
        new OA\Property(property: 'donorName', type: 'string'),
        new OA\Property(property: 'mobileNumber', type: 'string', nullable: true),
        new OA\Property(property: 'amount', type: 'number', format: 'double'),
        new OA\Property(property: 'paymentMode', type: 'string', enum: ['CASH', 'UPI', 'CHEQUE', 'NET_BANKING']),
        new OA\Property(property: 'area', type: 'string'),
        new OA\Property(property: 'address', type: 'string', nullable: true),
        new OA\Property(property: 'collectorName', type: 'string'),
        new OA\Property(property: 'collectorId', type: 'string'),
        new OA\Property(property: 'receiptType', type: 'string', enum: ['DIGITAL', 'PHYSICAL_BOOK']),
        new OA\Property(property: 'receiptBookId', type: 'string', nullable: true),
        new OA\Property(property: 'receiptBookLabel', type: 'string', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'isCancelled', type: 'boolean'),
        new OA\Property(property: 'receiptUrl', type: 'string', nullable: true),
        new OA\Property(property: 'signatureUrl', type: 'string', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]),
])]
class VarganiResponse
{
}

#[OA\Schema(schema: 'VarganiListResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/VarganiItem')),
    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
])]
class VarganiListResponse
{
}

#[OA\Schema(schema: 'VarganiItem', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string'),
    new OA\Property(property: 'donorName', type: 'string'),
    new OA\Property(property: 'mobileNumber', type: 'string', nullable: true),
    new OA\Property(property: 'amount', type: 'number', format: 'double'),
    new OA\Property(property: 'paymentMode', type: 'string', enum: ['CASH', 'UPI', 'CHEQUE', 'NET_BANKING']),
    new OA\Property(property: 'area', type: 'string'),
    new OA\Property(property: 'collectorName', type: 'string'),
    new OA\Property(property: 'receiptType', type: 'string', enum: ['DIGITAL', 'PHYSICAL_BOOK']),
    new OA\Property(property: 'receiptBookId', type: 'string', nullable: true),
    new OA\Property(property: 'receiptBookLabel', type: 'string', nullable: true),
    new OA\Property(property: 'receiptNumber', type: 'string'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'isCancelled', type: 'boolean'),
    new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
])]
class VarganiItem
{
}

#[OA\Schema(schema: 'VarganiCreatedResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 201),
    new OA\Property(property: 'message', type: 'string'),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'receiptNumber', type: 'string'),
        new OA\Property(property: 'donorName', type: 'string'),
        new OA\Property(property: 'amount', type: 'number', format: 'double'),
        new OA\Property(property: 'paymentMode', type: 'string', enum: ['CASH', 'UPI', 'CHEQUE', 'NET_BANKING']),
        new OA\Property(property: 'isCancelled', type: 'boolean'),
        new OA\Property(property: 'receiptUrl', type: 'string'),
        new OA\Property(property: 'whatsappMessage', type: 'string'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]),
])]
class VarganiCreatedResponse
{
}

#[OA\Schema(schema: 'ReceiptBookResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'festivalId', type: 'string'),
        new OA\Property(property: 'bookNumber', type: 'string', example: 'Book #01'),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'COMPLETED', 'LOST', 'CANCELLED']),
        new OA\Property(property: 'startNumber', type: 'integer'),
        new OA\Property(property: 'endNumber', type: 'integer'),
        new OA\Property(property: 'receiptRange', type: 'string', example: '#1001 - #1100'),
        new OA\Property(property: 'assignedTo', type: 'string', nullable: true),
        new OA\Property(property: 'assignedToId', type: 'string', nullable: true),
        new OA\Property(property: 'usedCount', type: 'integer'),
        new OA\Property(property: 'cancelledCount', type: 'integer'),
        new OA\Property(property: 'availableCount', type: 'integer'),
        new OA\Property(property: 'assignedDate', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]),
])]
class ReceiptBookResponse
{
}

#[OA\Schema(schema: 'ExpenseResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'festivalId', type: 'string'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'category', type: 'string', enum: ['STAGE_MANDAP', 'SOUND_LIGHTING', 'MURTI_DECORATION', 'POOJA_PRASAD', 'SECURITY_LOGISTICS', 'MISCELLANEOUS']),
        new OA\Property(property: 'categoryLabel', type: 'string'),
        new OA\Property(property: 'amount', type: 'number', format: 'double'),
        new OA\Property(property: 'paymentMode', type: 'string', enum: ['CASH', 'UPI', 'CHEQUE', 'NET_BANKING']),
        new OA\Property(property: 'paidTo', type: 'string'),
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'status', type: 'string', enum: ['PAID', 'PENDING']),
        new OA\Property(property: 'billUrl', type: 'string', nullable: true),
        new OA\Property(property: 'hasBill', type: 'boolean'),
        new OA\Property(property: 'billPendingReason', type: 'string', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'createdByUserId', type: 'string'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]),
])]
class ExpenseResponse
{
}

#[OA\Schema(schema: 'ExpenseListResponse', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ExpenseItem')),
    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
])]
class ExpenseListResponse
{
}

#[OA\Schema(schema: 'ExpenseItem', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'category', type: 'string', enum: ['STAGE_MANDAP', 'SOUND_LIGHTING', 'MURTI_DECORATION', 'POOJA_PRASAD', 'SECURITY_LOGISTICS', 'MISCELLANEOUS']),
    new OA\Property(property: 'categoryLabel', type: 'string'),
    new OA\Property(property: 'amount', type: 'number', format: 'double'),
    new OA\Property(property: 'paymentMode', type: 'string', enum: ['CASH', 'UPI', 'CHEQUE', 'NET_BANKING']),
    new OA\Property(property: 'paidTo', type: 'string'),
    new OA\Property(property: 'date', type: 'string', format: 'date'),
    new OA\Property(property: 'status', type: 'string', enum: ['PAID', 'PENDING']),
    new OA\Property(property: 'billUrl', type: 'string', nullable: true),
    new OA\Property(property: 'hasBill', type: 'boolean'),
    new OA\Property(property: 'billPendingReason', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
class ExpenseItem
{
}

#[OA\Schema(schema: 'FundSummary', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'totalFunds', type: 'number', format: 'double'),
        new OA\Property(property: 'cashTreasurer', type: 'number', format: 'double'),
        new OA\Property(property: 'cashCollectors', type: 'number', format: 'double'),
        new OA\Property(property: 'bankBalance', type: 'number', format: 'double'),
        new OA\Property(property: 'upiBalance', type: 'number', format: 'double'),
        new OA\Property(property: 'pendingWithCollectors', type: 'number', format: 'double'),
        new OA\Property(property: 'lastUpdatedAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'bankAccounts', type: 'array', items: new OA\Items(ref: '#/components/schemas/BankAccount')),
    ]),
])]
class FundSummary
{
}

#[OA\Schema(schema: 'BankAccount', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string'),
    new OA\Property(property: 'bankName', type: 'string'),
    new OA\Property(property: 'accountNumberMasked', type: 'string', example: '•••• •••• 4589'),
    new OA\Property(property: 'ifsc', type: 'string'),
    new OA\Property(property: 'accountType', type: 'string', enum: ['CURRENT', 'SAVINGS']),
    new OA\Property(property: 'balance', type: 'number', format: 'double'),
    new OA\Property(property: 'upiId', type: 'string', nullable: true),
    new OA\Property(property: 'isActive', type: 'boolean'),
    new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
])]
class BankAccount
{
}

#[OA\Schema(schema: 'MoneyTrailEntry', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string'),
    new OA\Property(property: 'date', type: 'string', format: 'date-time'),
    new OA\Property(property: 'type', type: 'string', enum: ['CASH_RECEIVED', 'UPI_RECEIVED', 'BANK_DEPOSIT', 'BANK_WITHDRAWAL', 'CASH_EXPENSE', 'CASH_HANDOVER', 'FUND_TRANSFER', 'OTHER_INCOME']),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'amount', type: 'number', format: 'double'),
    new OA\Property(property: 'isPositive', type: 'boolean'),
    new OA\Property(property: 'flow', type: 'string'),
    new OA\Property(property: 'verifiedBy', type: 'string', nullable: true),
    new OA\Property(property: 'referenceId', type: 'string'),
])]
class MoneyTrailEntry
{
}

#[OA\Schema(schema: 'CashHandover', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'festivalId', type: 'string'),
        new OA\Property(property: 'fromUserId', type: 'string'),
        new OA\Property(property: 'fromName', type: 'string'),
        new OA\Property(property: 'fromRole', type: 'string', enum: ['ADMIN', 'TREASURER', 'COLLECTOR', 'MEMBER']),
        new OA\Property(property: 'toUserId', type: 'string'),
        new OA\Property(property: 'toName', type: 'string'),
        new OA\Property(property: 'toRole', type: 'string', enum: ['ADMIN', 'TREASURER', 'COLLECTOR', 'MEMBER']),
        new OA\Property(property: 'amount', type: 'number', format: 'double'),
        new OA\Property(property: 'linkedEntryIds', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'linkedEntriesCount', type: 'integer'),
        new OA\Property(property: 'linkedDateRange', type: 'string'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'photoUrl', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['PENDING_APPROVAL', 'VERIFIED_ACCEPTED', 'REJECTED']),
        new OA\Property(property: 'authMethod', type: 'string', enum: ['BIOMETRIC', 'PIN'], nullable: true),
        new OA\Property(property: 'verificationNotes', type: 'string', nullable: true),
        new OA\Property(property: 'verifiedAt', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]),
])]
class CashHandover
{
}

#[OA\Schema(schema: 'OtherIncome', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'festivalId', type: 'string'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'amount', type: 'number', format: 'double'),
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'createdByUserId', type: 'string'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]),
])]
class OtherIncome
{
}

#[OA\Schema(schema: 'Member', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', description: 'User ID'),
        new OA\Property(property: 'membershipId', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'initials', type: 'string'),
        new OA\Property(property: 'phone', type: 'string'),
        new OA\Property(property: 'role', type: 'string', enum: ['ADMIN', 'TREASURER', 'COLLECTOR', 'MEMBER']),
        new OA\Property(property: 'collectedAmount', type: 'number', format: 'double'),
        new OA\Property(property: 'cashPending', type: 'number', format: 'double'),
        new OA\Property(property: 'area', type: 'string'),
        new OA\Property(property: 'avatarUrl', type: 'string', nullable: true),
        new OA\Property(property: 'isActive', type: 'boolean'),
        new OA\Property(property: 'joinedAt', type: 'string', format: 'date-time', nullable: true),
    ]),
])]
class Member
{
}

#[OA\Schema(schema: 'MemberFinancialSummary', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'memberId', type: 'string'),
        new OA\Property(property: 'totalCollected', type: 'number', format: 'double'),
        new OA\Property(property: 'cashAmount', type: 'number', format: 'double'),
        new OA\Property(property: 'upiAmount', type: 'number', format: 'double'),
        new OA\Property(property: 'cashSubmitted', type: 'number', format: 'double'),
        new OA\Property(property: 'cashPending', type: 'number', format: 'double'),
        new OA\Property(property: 'assignedBookNumber', type: 'string', nullable: true),
        new OA\Property(property: 'assignedBookDate', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'recentCollections', type: 'array', items: new OA\Items(
            properties: [
                new OA\Property(property: 'id', type: 'string'),
                new OA\Property(property: 'timeText', type: 'string'),
                new OA\Property(property: 'amount', type: 'number', format: 'double'),
            ])),
        new OA\Property(property: 'recentHandovers', type: 'array', items: new OA\Items(
            properties: [
                new OA\Property(property: 'id', type: 'string'),
                new OA\Property(property: 'dateText', type: 'string'),
                new OA\Property(property: 'amount', type: 'number', format: 'double'),
            ])),
    ]),
])]
class MemberFinancialSummary
{
}

#[OA\Schema(schema: 'ReportsOverview', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'totalIncome', type: 'number', format: 'double'),
        new OA\Property(property: 'totalExpenses', type: 'number', format: 'double'),
        new OA\Property(property: 'closingBalance', type: 'number', format: 'double'),
        new OA\Property(property: 'chartPoints', type: 'array', items: new OA\Items(
            properties: [
                new OA\Property(property: 'label', type: 'string'),
                new OA\Property(property: 'date', type: 'string', format: 'date'),
                new OA\Property(property: 'incomeValue', type: 'number', format: 'double', description: 'Lakhs'),
                new OA\Property(property: 'expenseValue', type: 'number', format: 'double', description: 'Lakhs'),
                new OA\Property(property: 'barHeightFactor', type: 'number', format: 'double'),
            ])),
    ]),
])]
class ReportsOverview
{
}

#[OA\Schema(schema: 'FinalHisab', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'festival', type: 'object', properties: [
            new OA\Property(property: 'id', type: 'string'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'mandalName', type: 'string'),
            new OA\Property(property: 'period', type: 'string'),
        ]),
        new OA\Property(property: 'statement', type: 'object', properties: [
            new OA\Property(property: 'openingBalance', type: 'number', format: 'double'),
            new OA\Property(property: 'varganiCollections', type: 'number', format: 'double'),
            new OA\Property(property: 'otherIncome', type: 'number', format: 'double'),
            new OA\Property(property: 'totalIncome', type: 'number', format: 'double'),
            new OA\Property(property: 'totalExpenses', type: 'number', format: 'double'),
            new OA\Property(property: 'closingBalance', type: 'number', format: 'double'),
            new OA\Property(property: 'incomeInLakhs', type: 'number', format: 'double'),
            new OA\Property(property: 'expensesInLakhs', type: 'number', format: 'double'),
        ]),
        new OA\Property(property: 'expenseBreakdown', type: 'array', items: new OA\Items(
            properties: [
                new OA\Property(property: 'category', type: 'string'),
                new OA\Property(property: 'categoryLabel', type: 'string'),
                new OA\Property(property: 'amount', type: 'number', format: 'double'),
                new OA\Property(property: 'percentage', type: 'number', format: 'double'),
            ])),
        new OA\Property(property: 'auditSignatures', type: 'object', properties: [
            new OA\Property(property: 'president', type: 'object', properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'role', type: 'string'),
                new OA\Property(property: 'isSigned', type: 'boolean'),
                new OA\Property(property: 'signedAt', type: 'string', format: 'date-time', nullable: true),
            ]),
            new OA\Property(property: 'treasurer', type: 'object', properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'role', type: 'string'),
                new OA\Property(property: 'isSigned', type: 'boolean'),
                new OA\Property(property: 'signedAt', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'authMethod', type: 'string', enum: ['BIOMETRIC', 'PIN'], nullable: true),
            ]),
        ]),
        new OA\Property(property: 'isLocked', type: 'boolean'),
    ]),
])]
class FinalHisab
{
}

#[OA\Schema(schema: 'Notification', type: 'object', properties: [
    new OA\Property(property: 'success', type: 'boolean', example: true),
    new OA\Property(property: 'statusCode', type: 'integer', example: 200),
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'userId', type: 'string'),
        new OA\Property(property: 'mandalId', type: 'string', nullable: true),
        new OA\Property(property: 'festivalId', type: 'string', nullable: true),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'body', type: 'string'),
        new OA\Property(property: 'type', type: 'string', enum: ['HANDOVER_INITIATED', 'HANDOVER_APPROVED', 'HANDOVER_REJECTED', 'VARGANI_CREATED', 'EXPENSE_CREATED', 'FINAL_HISAB_SIGNED', 'GENERAL']),
        new OA\Property(property: 'referenceId', type: 'string', nullable: true),
        new OA\Property(property: 'isRead', type: 'boolean'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
    ]),
])]
class Notification
{
}

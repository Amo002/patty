<?php

use App\Exceptions\Domain\DomainException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Middleware\PosKey;
use App\Http\Requests\V1\PaginationRequest;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use Spatie\Activitylog\Models\Activity;

/*
| Everything below the tests is test-only scaffolding: a throwaway table, a
| model, a resource, a request, an exception and a controller. They exist so the
| foundation (envelope, error rendering, middleware, audit, pagination) is
| proven without depending on the real domain models, and the routes are
| registered here, never in routes/.
*/

class FoundationWidget extends Model
{
    protected $table = 'foundation_widgets';

    protected $fillable = ['ulid', 'name'];
}

class FoundationWidgetResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->ulid, 'name' => $this->name];
    }
}

class FoundationConflict extends DomainException
{
    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'invalid_transition';
    }
}

class FoundationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string']];
    }
}

class FoundationListRequest extends PaginationRequest {}

class FoundationProbeController extends ApiController
{
    public function conflict(): never
    {
        throw new FoundationConflict('Cannot move from draft to received.');
    }

    public function store(FoundationStoreRequest $request): JsonResponse
    {
        return $this->created($request->validated(), 'Created.');
    }

    public function show(string $id): JsonResponse
    {
        return $this->success(new FoundationWidgetResource(FoundationWidget::where('ulid', $id)->firstOrFail()));
    }

    public function boom(): never
    {
        throw new RuntimeException('SQLSTATE secret table detail');
    }

    public function index(FoundationListRequest $request): JsonResponse
    {
        return $this->paginated(
            FoundationWidget::orderBy('id')->paginate($request->perPage()),
            FoundationWidgetResource::class,
        );
    }

    public function audit(): JsonResponse
    {
        $widget = FoundationWidget::create(['ulid' => (string) Str::ulid(), 'name' => 'Audited']);
        Audit::record('widget.touched', $widget, ['note' => 'x']);

        return $this->success(null, 'Audited.');
    }

    public function ping(): JsonResponse
    {
        return $this->success(null, 'pong');
    }
}

beforeEach(function () {
    // The 500 test reports an exception; keep that out of storage/logs.
    config(['logging.default' => 'null']);

    Schema::create('foundation_widgets', function ($table) {
        $table->id();
        $table->string('ulid')->unique();
        $table->string('name');
        $table->timestamps();
    });

    Route::middleware('api')->prefix('api/v1/__foundation')->group(function () {
        Route::get('conflict', [FoundationProbeController::class, 'conflict']);
        Route::post('store', [FoundationProbeController::class, 'store']);
        Route::get('widgets', [FoundationProbeController::class, 'index']);
        Route::get('widgets/{id}', [FoundationProbeController::class, 'show']);
        Route::get('boom', [FoundationProbeController::class, 'boom']);
        Route::post('audit', [FoundationProbeController::class, 'audit']);
        Route::get('pos-only', [FoundationProbeController::class, 'ping'])->middleware(PosKey::class);
        Route::get('limited', [FoundationProbeController::class, 'ping'])->middleware('throttle:pos');
    });
});

function seedWidgets(int $count): void
{
    foreach (range(1, $count) as $n) {
        FoundationWidget::create(['ulid' => (string) Str::ulid(), 'name' => "Widget {$n}"]);
    }
}

it('T15a: health returns the success envelope', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonStructure(['success', 'message', 'data', 'meta' => ['generated_at']]);
    expect($response->json())->assertNoIntegerIds();
});

it('T15b: a domain exception renders its own status and code', function () {
    $this->getJson('/api/v1/__foundation/conflict')
        ->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'invalid_transition')
        ->assertJsonPath('message', 'Cannot move from draft to received.');
});

it('T15c: a failed FormRequest renders 422 validation_failed with field errors', function () {
    $this->postJson('/api/v1/__foundation/store', [])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrorFor('name', 'errors');
});

it('T15d: an unknown record and an unknown route both render the 404 envelope', function () {
    $this->getJson('/api/v1/__foundation/widgets/01JA8ZK3W5Y7Q2M4N6P8R0T2V4')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found')
        ->assertJsonPath('success', false);

    $this->get('/api/v1/nope')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found')
        ->assertHeader('X-API-Version', '1');
});

it('renders a wrong verb as the 405 envelope', function () {
    $this->postJson('/api/v1/health')
        ->assertStatus(405)
        ->assertJsonPath('code', 'method_not_allowed');
});

it('T15e: an unexpected exception renders a generic 500 without leaking its text', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/v1/__foundation/boom');

    $response->assertStatus(500)
        ->assertJsonPath('code', 'server_error')
        ->assertJsonPath('success', false);
    expect($response->getContent())->not->toContain('SQLSTATE')->not->toContain('secret table detail');
});

it('T17: every response carries no-store, security headers and a request id', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeader('X-API-Version', '1');
    expect($response->headers->get('X-Request-Id'))->not->toBeEmpty();
});

it('T17: the same headers cover web pages, not only the API', function () {
    $this->get('/')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Request-Id')
        ->assertHeaderMissing('X-API-Version');
});

it('T17: a sent X-Request-Id is echoed, and an unsafe one is replaced', function () {
    $this->getJson('/api/v1/health', ['X-Request-Id' => 'req-abc_123'])
        ->assertHeader('X-Request-Id', 'req-abc_123');

    $unsafe = "bad id\nwith newline";
    $echoed = $this->getJson('/api/v1/health', ['X-Request-Id' => $unsafe])->headers->get('X-Request-Id');

    expect($echoed)->not->toBe($unsafe)->and($echoed)->toMatch('/^[A-Za-z0-9._-]{1,64}$/');
});

it('T18: Audit::record stores the channel, request id and ip of the request', function () {
    $this->postJson('/api/v1/__foundation/audit', [], ['X-Patty-Channel' => 'pos', 'X-Request-Id' => 'audit-1'])
        ->assertOk();

    $entry = Activity::query()->latest('id')->firstOrFail();

    expect($entry->event)->toBe('widget.touched')
        ->and($entry->subject_type)->toBe(FoundationWidget::class)
        ->and($entry->properties['channel'])->toBe('pos')
        ->and($entry->properties['request_id'])->toBe('audit-1')
        ->and($entry->properties['ip'])->not->toBeEmpty()
        ->and($entry->properties['note'])->toBe('x');
});

it('T18: the audit channel defaults to api, and an unknown channel is not trusted', function () {
    $this->postJson('/api/v1/__foundation/audit')->assertOk();
    expect(Activity::query()->latest('id')->firstOrFail()->properties['channel'])->toBe('api');

    $this->postJson('/api/v1/__foundation/audit', [], ['X-Patty-Channel' => 'hacker'])->assertOk();
    expect(Activity::query()->latest('id')->firstOrFail()->properties['channel'])->toBe('api');
});

it('rejects per_page above 100 with 422', function () {
    $this->getJson('/api/v1/__foundation/widgets?per_page=101')
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrorFor('per_page', 'errors');

    $this->getJson('/api/v1/__foundation/widgets?per_page=100')->assertOk();
});

it('reports pagination meta, with has_more false on the last page', function () {
    seedWidgets(5);

    $first = $this->getJson('/api/v1/__foundation/widgets?per_page=2&page=1');
    $first->assertOk()
        ->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 2, 'total' => 5, 'last_page' => 3, 'has_more' => true])
        ->assertJsonCount(2, 'data');

    $last = $this->getJson('/api/v1/__foundation/widgets?per_page=2&page=3');
    $last->assertOk()
        ->assertJsonPath('meta.pagination', ['page' => 3, 'per_page' => 2, 'total' => 5, 'last_page' => 3, 'has_more' => false])
        ->assertJsonCount(1, 'data');

    // data is a plain list: withoutWrapping() means no data.data.
    expect($last->json('data.0'))->toHaveKeys(['id', 'name']);
    expect($last->json())->assertNoIntegerIds();
});

it('T22: PosKey is open when no key is configured', function () {
    config(['services.pos.api_key' => '']);

    $this->getJson('/api/v1/__foundation/pos-only')->assertOk();
});

it('T22: PosKey rejects a missing or wrong key and accepts the right one', function () {
    config(['services.pos.api_key' => 'secret-key']);

    $this->getJson('/api/v1/__foundation/pos-only')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthorized')
        ->assertJsonPath('success', false);

    $this->getJson('/api/v1/__foundation/pos-only', ['X-POS-Key' => 'wrong'])->assertUnauthorized();
    $this->getJson('/api/v1/__foundation/pos-only', ['X-POS-Key' => 'secret-key'])->assertOk();
});

it('applies the pos rate limiter at 120 per minute and renders 429 in the envelope', function () {
    foreach (range(1, 120) as $ignored) {
        $this->getJson('/api/v1/__foundation/limited')->assertOk();
    }

    $this->getJson('/api/v1/__foundation/limited')
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests')
        ->assertJsonPath('success', false);
});

it('assertNoIntegerIds fails on an integer id or *_id and passes on strings', function () {
    expect(['data' => ['id' => '01JA8ZK3W5Y7Q2M4N6P8R0T2V4', 'supplier_id' => '01JA8ZK3W5Y7Q2M4N6P8R0T2V5', 'quantity' => 5]])
        ->assertNoIntegerIds();

    expect(fn () => expect(['data' => [['lines' => [['ingredient_id' => 7]]]]])->assertNoIntegerIds())
        ->toThrow(AssertionFailedError::class);

    expect(fn () => expect(['id' => 3])->assertNoIntegerIds())
        ->toThrow(AssertionFailedError::class);
});

<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\CreatePlatformAdminAction;
use App\Actions\Platform\DeactivatePlatformAdminAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlatformAdminRequest;
use App\Http\Resources\Platform\PlatformUserResource;
use App\Models\Platform\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class PlatformAdminController extends Controller
{
    public function index(): mixed
    {
        return PlatformUserResource::collection(
            PlatformUser::query()->with('roles')->withMax('sessions', 'last_seen_at')->orderBy('email')->get()
        );
    }

    public function store(StorePlatformAdminRequest $request, CreatePlatformAdminAction $create): JsonResponse
    {
        $generated = ! $request->filled('password');
        $password = $generated ? Str::password(16) : (string) $request->validated('password');

        $user = $create->execute([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $password,
            'must_change_password' => true,
        ]);

        $payload = (new PlatformUserResource($user->load('roles')))->resolve();
        $payload['temporary_password'] = $generated ? $password : null;

        return response()->json($payload, 201);
    }

    public function deactivate(string $adminUlid, DeactivatePlatformAdminAction $deactivate): PlatformUserResource
    {
        $user = PlatformUser::query()->where('ulid', $adminUlid)->first();
        if (! $user) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return new PlatformUserResource($deactivate->execute($user)->load('roles'));
    }
}

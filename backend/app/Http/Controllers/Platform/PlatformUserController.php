<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\ActivatePlatformUserAction;
use App\Actions\Platform\CreatePlatformUserAction;
use App\Actions\Platform\DeactivatePlatformAdminAction;
use App\Actions\Platform\ForceLogoutPlatformUserAction;
use App\Actions\Platform\ResetPlatformUserPasswordAction;
use App\Actions\Platform\SyncPlatformUserRolesAction;
use App\Actions\Platform\UpdatePlatformUserAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlatformUserRequest;
use App\Http\Requests\Platform\SyncPlatformUserRolesRequest;
use App\Http\Requests\Platform\UpdatePlatformUserRequest;
use App\Http\Resources\Platform\PlatformUserResource;
use App\Models\Platform\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class PlatformUserController extends Controller
{
    public function index(): mixed
    {
        return PlatformUserResource::collection(
            PlatformUser::query()
                ->with('roles')
                ->withMax('sessions', 'last_seen_at')
                ->orderBy('email')
                ->get()
        );
    }

    public function store(StorePlatformUserRequest $request, CreatePlatformUserAction $create): JsonResponse
    {
        $generated = ! $request->filled('password');
        $password = $generated ? Str::password(16) : (string) $request->validated('password');

        $user = $create->execute($request, [
            ...$request->safe()->except('password'),
            'password' => $password,
        ]);

        $payload = (new PlatformUserResource($user->load('roles')))->resolve();
        $payload['temporary_password'] = $generated ? $password : null;

        return response()->json($payload, 201);
    }

    public function show(string $userUlid): PlatformUserResource
    {
        return new PlatformUserResource(
            PlatformUser::query()
                ->with('roles')
                ->withMax('sessions', 'last_seen_at')
                ->where('ulid', $userUlid)
                ->first() ?? throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404)
        );
    }

    public function update(UpdatePlatformUserRequest $request, string $userUlid, UpdatePlatformUserAction $update): PlatformUserResource
    {
        return new PlatformUserResource(
            $update->execute($request, $this->findUser($userUlid), $request->validated())->load('roles')
        );
    }

    public function syncRoles(SyncPlatformUserRolesRequest $request, string $userUlid, SyncPlatformUserRolesAction $sync): PlatformUserResource
    {
        return new PlatformUserResource(
            $sync->execute(
                $request,
                $this->findUser($userUlid),
                $request->validated('role_ulids'),
                $request->validated('reason'),
            )->load('roles')
        );
    }

    public function activate(string $userUlid, ActivatePlatformUserAction $activate): PlatformUserResource
    {
        return new PlatformUserResource($activate->execute($this->findUser($userUlid))->load('roles'));
    }

    public function deactivate(string $userUlid, DeactivatePlatformAdminAction $deactivate): PlatformUserResource
    {
        return new PlatformUserResource($deactivate->execute($this->findUser($userUlid))->load('roles'));
    }

    public function resetPassword(Request $request, string $userUlid, ResetPlatformUserPasswordAction $reset): JsonResponse
    {
        $data = $request->validate([
            'password' => ['nullable', 'string', Password::min(8)],
        ]);
        $result = $reset->execute($this->findUser($userUlid), $data['password'] ?? null);
        $payload = (new PlatformUserResource($result['user']->load('roles')))->resolve();
        $payload['temporary_password'] = $result['temporary_password'];

        return response()->json($payload);
    }

    public function forceLogout(string $userUlid, ForceLogoutPlatformUserAction $force): PlatformUserResource
    {
        return new PlatformUserResource($force->execute($this->findUser($userUlid))->load('roles'));
    }

    private function findUser(string $userUlid): PlatformUser
    {
        $user = PlatformUser::query()->where('ulid', $userUlid)->first();
        if (! $user) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $user;
    }
}

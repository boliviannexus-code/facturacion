<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\AssignRolePermissionsRequest;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Repositories\RoleRepository;
use App\Services\RoleService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RoleService $roles
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        return $this->successResponse(RoleResource::collection($this->roles->paginate()));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create($request->validated());

        return $this->successResponse(RoleResource::make($role), 'Rol creado correctamente.', 201);
    }

    public function show(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        $role = app(RoleRepository::class)
            ->withUsersCount($role->load('permissions')->loadCount('permissions'));

        return $this->successResponse(RoleResource::make($role));
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $role = $this->roles->update($role, $request->validated());

        return $this->successResponse(RoleResource::make($role), 'Rol actualizado correctamente.');
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        $this->roles->delete($role);

        return $this->successResponse(null, 'Rol eliminado correctamente.');
    }

    public function assignPermissions(AssignRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $this->authorize('assignPermissions', $role);

        $role = $this->roles->syncPermissions($role, $request->validated('permissions') ?? []);

        return $this->successResponse(RoleResource::make($role), 'Permisos del rol actualizados correctamente.');
    }
}
